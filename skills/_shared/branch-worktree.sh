#!/usr/bin/env bash
# branch-worktree.sh — open the detached worktree in which a review or a walkthrough reads a branch under review.
#
# The main working tree is never checked out on a branch under review
# (skills/code-review/references/branch-checkout-gate.md). A review and a walkthrough read the branch in a detached
# worktree below `.claude/worktrees/` instead. This script is the one owner of the git options that keep the branch
# from running a program while the worktree is written, read, or removed: the table below lists every git mechanism
# that runs an external program or a shell during the operations of this script. Every caller runs these git
# commands only through this script.
#
# Usage (run in the main working tree, at its root)
#   branch-worktree.sh open --path <path> --head <sha>
#   branch-worktree.sh git --path <path> -- <read subcommand> [<argument>...]
#   branch-worktree.sh remove --path <path> [--lock-pid <pid>]
#   branch-worktree.sh --self-test
#
#   --path   the worktree: `.claude/worktrees/agent-<name>`, where <name> holds only letters, digits, `.`, `_`, `-`
#   --head   the full SHA of the commit to read, as the loaded context gives it (40 or 64 hex digits)
#   --lock-pid  the `pid <N>` token of the lock reason that the caller may release (1 to 7 digits)
#
# open creates the worktree detached at <sha> when the path does not exist. When the path is a worktree of this
# repository, it moves that worktree to <sha>, detached, and refuses a worktree with a change to a tracked file.
# It then requires `HEAD` of the worktree to equal <sha> and prints one line:
#   worktree=<path> head=<sha>
# It never locks the worktree: the caller locks it with its own reason.
#
# git runs one read in a worktree of this repository with the same options. The subcommand comes first, so no
# argument can set a git option of its own, and it is one of: status, diff, show, log, rev-parse, ls-files, ls-tree,
# cat-file, rev-list, diff-tree, merge-base. Every other subcommand, and every alias, is a usage error.
#
# remove removes a worktree of this repository with the same options, never with `--force`. It refuses a worktree
# whose `git status --porcelain` prints anything, and a locked worktree whose lock reason does not name
# `pid <--lock-pid>`. It unlocks only that lock, removes the worktree, prunes the metadata, and prints one line:
#   worktree=<path> removed=yes      (or removed=absent when the path is already gone; it then only prunes)
# The caller probes the liveness of the lock holder first; this script never decides that a holder is dead.
#
# The operations are: worktree list, add, unlock, remove, and prune; checkout; status; cat-file; and the reads above.
# Each mechanism of git that runs a program during them, and what this script does with it:
#   hooks: the hooks directory, core.hooksPath       -c core.hooksPath=/dev/null
#   hooks: hook.<name>.command of the configuration  -c hook.<name>.enabled=false for each configured name, and the
#                                                    event switch off for post-checkout, reference-transaction, and
#                                                    post-index-change; a name it cannot pass is refused (exit 3)
#   core.fsmonitor                                   -c core.fsmonitor=false
#   filter.<driver>.clean, .smudge, .process;        the empty tree as the attribute source (--attr-source and
#   diff.<driver>.textconv, .command;                GIT_ATTR_SOURCE, which a nested git inherits), so no file of
#   merge.<driver>.driver: the attributes select     the branch selects a driver; -c core.attributesFile=/dev/null;
#   them                                             a git without an attribute source is refused (exit 2), and an
#                                                    info/attributes file with a rule is refused (exit 3)
#   diff.external                                    refused (exit 3); GIT_EXTERNAL_DIFF is unset
#   core.pager, pager.<command>                      --no-pager and GIT_PAGER=cat
#   gpg.program, gpg.<format>.program                -c log.showSignature=false and each program set to its default
#   submodule.recurse, submodule.<name>.update       -c submodule.recurse=false and checkout --no-recurse-submodules
#   a lazy fetch of a partial clone, which runs      GIT_NO_LAZY_FETCH=1; a partial clone with a git older than 2.45
#   remote.<name>.uploadpack, core.sshCommand,       is refused (exit 3)
#   core.gitProxy, credential.helper, core.askPass
#   alias.<name>, core.editor, sequence.editor,      never run: the git command accepts only the reads above, and no
#   difftool and mergetool programs, interactive.    other operation of this script edits, merges, fetches, or pushes
#   diffFilter, uploadpack.packObjectsHook,
#   core.alternateRefsCommand, protocol and url
#   settings of a fetch or a push
# The --self-test command has one fixture for each row that this script switches off or refuses: a plain git command
# runs the program of the branch, and the same command through this script does not. Two rows have none: the
# submodule row, because this script never initialises a submodule, and the git version refusals, which need an
# older git than the one that runs the self-test.
#
# Exit codes
#   0  done
#   1  usage error
#   2  git is missing, or it cannot use the empty tree as the attribute source
#   3  refused: a symbolic link in the path, a path that exists and is not a worktree of this repository, a change to
#      a tracked file in the worktree (any change for remove), a head that is not a commit of this repository, a
#      lock that does not name the pid of --lock-pid, or a configuration of the table above that it cannot switch off
#   4  a git command failed, or `HEAD` of the worktree is not <sha> afterwards
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$HERE/$(basename "${BASH_SOURCE[0]}")"
SAFE=(--no-pager -c core.hooksPath=/dev/null -c core.fsmonitor=false -c submodule.recurse=false
  -c core.attributesFile=/dev/null -c log.showSignature=false -c gpg.program=gpg -c gpg.openpgp.program=gpg
  -c gpg.x509.program=gpgsm -c gpg.ssh.program=ssh-keygen -c hook.post-checkout.enabled=false
  -c hook.reference-transaction.enabled=false -c hook.post-index-change.enabled=false)
READS='^(status|diff|show|log|rev-parse|ls-files|ls-tree|cat-file|rev-list|diff-tree|merge-base)$'

usage() {
  echo "Usage: branch-worktree.sh open --path <path> --head <sha> | git --path <path> -- <read subcommand> [<argument>...] | remove --path <path> [--lock-pid <pid>] | --self-test" >&2
  exit 1
}

refuse() {
  echo "branch-worktree.sh: refused: $1" >&2
  exit 3
}

fails() {
  echo "branch-worktree.sh: $1" >&2
  exit 4
}

# True when the version of git is at least <major>.<minor>.
git_at_least() {
  [[ "$(git version 2>/dev/null)" =~ ([0-9]+)\.([0-9]+) ]] || return 1
  ((BASH_REMATCH[1] > $1 || (BASH_REMATCH[1] == $1 && BASH_REMATCH[2] >= $2)))
}

# Sets up the git environment of the table in the header, and refuses a configuration it cannot switch off. Runs in
# the main tree before any other git command of this script.
prepare_git() {
  local empty common record name rc
  unset GIT_EXTERNAL_DIFF
  export GIT_PAGER=cat GIT_NO_LAZY_FETCH=1
  git rev-parse --git-dir >/dev/null 2>&1 || fails "this is not a git repository"
  empty="$(git hash-object -t tree /dev/null 2>/dev/null)" || fails "the empty tree cannot be computed here"
  git "--attr-source=$empty" rev-parse --git-dir >/dev/null 2>&1 \
    || { echo "branch-worktree.sh: git must accept the empty tree as the attribute source (--attr-source)" >&2; exit 2; }
  export GIT_ATTR_SOURCE="$empty"
  SAFE=("--attr-source=$empty" "${SAFE[@]}")
  [[ -z "$(git "${SAFE[@]}" config --get diff.external 2>/dev/null)" ]] \
    || refuse "the git configuration names an external diff program (diff.external)"
  common="$(git "${SAFE[@]}" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
    || fails "the git directory cannot be read"
  if [[ -e "$common/info/attributes" || -L "$common/info/attributes" ]]; then
    grep -qvE '^[[:space:]]*(#|$)' "$common/info/attributes" 2>/dev/null
    rc=$?
    [[ "$rc" -eq 1 ]] || refuse "the info/attributes file of the repository assigns attributes"
  fi
  if [[ -n "$(git "${SAFE[@]}" config --get extensions.partialClone 2>/dev/null)" ]] && ! git_at_least 2 45; then
    refuse "a partial clone needs git 2.45 or later, which can switch off the lazy fetch"
  fi
  while IFS= read -r -d '' record; do
    name="${record%%$'\n'*}"
    name="${name#hook.}"
    name="${name%.command}"
    [[ "$name" =~ ^[A-Za-z0-9._-]+$ ]] || refuse "the git configuration names a hook that cannot be switched off"
    SAFE+=(-c "hook.$name.enabled=false")
  done < <(git "${SAFE[@]}" config --null --get-regexp '^hook\..*\.command$' 2>/dev/null)
}

valid_path() { [[ "$1" =~ ^\.claude/worktrees/agent-[A-Za-z0-9._-]{1,120}$ && "$1" != *..* ]]; }
valid_head() { [[ "$1" =~ ^[0-9a-f]{40}$ || "$1" =~ ^[0-9a-f]{64}$ ]]; }

# True when no component of the relative path is a symbolic link.
no_link_in_path() {
  local path="$1" prefix="" component
  local -a parts
  IFS='/' read -r -a parts <<<"$path"
  for component in "${parts[@]}"; do
    prefix="${prefix:+$prefix/}$component"
    [[ -L "$prefix" ]] && return 1
  done
  return 0
}

# True when the path is a worktree that `git worktree list` of this repository names.
registered_worktree() {
  local real entry
  real="$(cd -- "$1" 2>/dev/null && pwd -P)" || return 1
  while IFS= read -r entry; do
    [[ "$entry" == "worktree $real" ]] && return 0
  done < <(git "${SAFE[@]}" worktree list --porcelain 2>/dev/null)
  return 1
}

# Prints the lock reason of the worktree with a leading space. Returns 1 when the worktree is not locked.
lock_reason() {
  local real entry mine=0
  real="$(cd -- "$1" 2>/dev/null && pwd -P)" || return 1
  while IFS= read -r entry; do
    if [[ "$entry" == "worktree "* ]]; then
      mine=0
      [[ "$entry" == "worktree $real" ]] && mine=1
    elif [[ "$mine" -eq 1 && ( "$entry" == locked || "$entry" == "locked "* ) ]]; then
      printf '%s\n' "${entry#locked}"
      return 0
    fi
  done < <(git "${SAFE[@]}" worktree list --porcelain 2>/dev/null)
  return 1
}

remove_worktree() {
  local path="$1" pid="$2" changes reason
  no_link_in_path "$path" || refuse "the path has a symbolic link in it"
  if [[ ! -e "$path" ]]; then
    git "${SAFE[@]}" worktree prune >&2 || fails "the worktree metadata cannot be pruned"
    printf 'worktree=%s removed=absent\n' "$path"
    return 0
  fi
  registered_worktree "$path" || refuse "the path is not a worktree of this repository"
  changes="$(git -C "$path" "${SAFE[@]}" status --porcelain 2>/dev/null)" || fails "the status of the worktree cannot be read"
  [[ -z "$changes" ]] || refuse "the worktree has a change"
  if reason="$(lock_reason "$path")"; then
    [[ -n "$pid" && "$reason " == *" pid $pid "* ]] || refuse "the lock of the worktree does not name the pid of --lock-pid"
    git "${SAFE[@]}" worktree unlock "$path" >&2 || fails "the worktree cannot be unlocked"
  fi
  git "${SAFE[@]}" worktree remove "$path" >&2 || fails "the worktree cannot be removed"
  git "${SAFE[@]}" worktree prune >&2 || fails "the worktree metadata cannot be pruned"
  printf 'worktree=%s removed=yes\n' "$path"
}

open_worktree() {
  local path="$1" head="$2" now
  no_link_in_path "$path" || refuse "the path has a symbolic link in it"
  git "${SAFE[@]}" cat-file -e "$head^{commit}" 2>/dev/null || refuse "the head is not a commit of this repository"
  if [[ -e "$path" || -L "$path" ]]; then
    registered_worktree "$path" || refuse "the path exists and is not a worktree of this repository"
    [[ -z "$(git -C "$path" "${SAFE[@]}" status --porcelain --untracked-files=no 2>/dev/null)" ]] \
      || refuse "the worktree has a change to a tracked file"
    git -C "$path" "${SAFE[@]}" checkout -q --detach --no-recurse-submodules "$head" >&2 \
      || fails "the worktree cannot be moved to the head"
  else
    git "${SAFE[@]}" worktree add -q --detach "$path" "$head" >&2 || fails "the worktree cannot be created"
  fi
  now="$(git -C "$path" "${SAFE[@]}" rev-parse HEAD 2>/dev/null)" || fails "the head of the worktree cannot be read"
  [[ "$now" == "$head" ]] || fails "the head of the worktree is not the requested commit"
  printf 'worktree=%s head=%s\n' "$path" "$head"
}

run_git() {
  local path="$1"
  shift
  no_link_in_path "$path" || refuse "the path has a symbolic link in it"
  registered_worktree "$path" || refuse "the path is not a worktree of this repository"
  exec git -C "$path" "${SAFE[@]}" "$@"
}

self_test() {
  local failed=0 tmp repo proof head_main head_branch out rc
  export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
  tmp="$(mktemp -d)" || return 1
  trap 'rm -rf "$tmp"' RETURN

  check() {
    if [[ "$2" != "yes" ]]; then
      echo "FAIL $1" >&2
      failed=1
    fi
  }

  repo="$tmp/repo"
  proof="$tmp/proof"
  mkdir -p "$repo"
  (
    cd "$repo" || exit 1
    git init -q -b main && git config user.email t@example.com && git config user.name t
    printf '.claude/\n' >.gitignore
    printf 'main\n' >file.txt
    git add .gitignore file.txt && git commit -q -m init
    git config core.hooksPath .githooks
    git config core.fsmonitor ./monitor.sh
    git checkout -q -b branch
    mkdir -p .githooks
    printf '#!/bin/sh\necho hook >>%s\n' "$proof" >.githooks/post-checkout
    printf '#!/bin/sh\necho monitor >>%s\nexit 1\n' "$proof" >monitor.sh
    chmod +x .githooks/post-checkout monitor.sh
    printf 'branch\n' >file.txt
    git -c core.hooksPath=/dev/null add .githooks monitor.sh file.txt
    git -c core.hooksPath=/dev/null -c core.fsmonitor=false commit -q -m 'branch hooks'
    git -c core.hooksPath=/dev/null -c core.fsmonitor=false checkout -q main
  ) >/dev/null 2>&1 || { echo "FAIL fixture repository" >&2; return 1; }
  rm -f "$proof"
  head_main="$(git -C "$repo" rev-parse main)"
  head_branch="$(git -C "$repo" rev-parse branch)"
  run() { (cd "$repo" && "$SELF" "$@"); }

  out="$(run open --path .claude/worktrees/agent-cr-gh-1-leonardo --head "$head_branch" 2>/dev/null)"
  rc=$?
  check 'open creates the worktree detached at the head' \
    "$([[ $rc -eq 0 && "$out" == "worktree=.claude/worktrees/agent-cr-gh-1-leonardo head=$head_branch" && "$(git -C "$repo/.claude/worktrees/agent-cr-gh-1-leonardo" rev-parse HEAD)" == "$head_branch" ]] && echo yes || echo no)"
  check 'open leaves the main tree on its branch and its files unchanged' \
    "$([[ "$(git -C "$repo" -c core.hooksPath=/dev/null symbolic-ref --short HEAD)" == main && "$(cat "$repo/file.txt")" == main && ! -e "$repo/monitor.sh" ]] && echo yes || echo no)"
  check 'creating the worktree runs no hook and no file-system monitor of the branch' \
    "$([[ ! -e "$proof" ]] && echo yes || echo no)"

  run open --path .claude/worktrees/agent-cr-gh-1-leonardo --head "$head_main" >/dev/null 2>&1
  rc=$?
  check 'open moves an existing worktree to another head' \
    "$([[ $rc -eq 0 && "$(git -C "$repo/.claude/worktrees/agent-cr-gh-1-leonardo" rev-parse HEAD)" == "$head_main" ]] && echo yes || echo no)"
  run open --path .claude/worktrees/agent-cr-gh-1-leonardo --head "$head_branch" >/dev/null 2>&1
  check 'moving the worktree runs no hook of the branch' "$([[ ! -e "$proof" ]] && echo yes || echo no)"
  run git --path .claude/worktrees/agent-cr-gh-1-leonardo -- status --porcelain >/dev/null 2>&1
  rc=$?
  check 'a read through the script runs no file-system monitor of the branch' \
    "$([[ $rc -eq 0 && ! -e "$proof" ]] && echo yes || echo no)"
  git -C "$repo/.claude/worktrees/agent-cr-gh-1-leonardo" checkout -q --detach "$head_main" >/dev/null 2>&1
  git -C "$repo/.claude/worktrees/agent-cr-gh-1-leonardo" checkout -q --detach "$head_branch" >/dev/null 2>&1
  check 'the fixture is sound: a plain git checkout in the worktree runs the hook of the branch' \
    "$([[ -e "$proof" ]] && grep -q hook "$proof" && echo yes || echo no)"
  rm -f "$proof"

  printf 'changed\n' >"$repo/.claude/worktrees/agent-cr-gh-1-leonardo/file.txt"
  run open --path .claude/worktrees/agent-cr-gh-1-leonardo --head "$head_main" >/dev/null 2>&1
  rc=$?
  check 'open refuses a worktree with a change to a tracked file and keeps the change' \
    "$([[ $rc -eq 3 && "$(cat "$repo/.claude/worktrees/agent-cr-gh-1-leonardo/file.txt")" == changed ]] && echo yes || echo no)"

  mkdir -p "$repo/.claude/worktrees/agent-plain"
  run open --path .claude/worktrees/agent-plain --head "$head_branch" >/dev/null 2>&1
  check 'open refuses a directory that is not a worktree of this repository' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  run git --path .claude/worktrees/agent-plain -- status >/dev/null 2>&1
  check 'git refuses a directory that is not a worktree of this repository' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  mkdir -p "$tmp/elsewhere"
  ln -s "$tmp/elsewhere" "$repo/.claude/worktrees/agent-link"
  run open --path .claude/worktrees/agent-link --head "$head_branch" >/dev/null 2>&1
  check 'open refuses a path with a symbolic link in it' "$([[ $? -eq 3 && -z "$(ls -A "$tmp/elsewhere")" ]] && echo yes || echo no)"
  run open --path .claude/worktrees/agent-new --head "$(printf '%040d' 0)" >/dev/null 2>&1
  check 'open refuses a head that is not a commit' "$([[ $? -eq 3 && ! -e "$repo/.claude/worktrees/agent-new" ]] && echo yes || echo no)"

  run open --path . --head "$head_branch" >/dev/null 2>&1
  check 'the main tree is not a valid path' "$([[ $? -eq 1 ]] && echo yes || echo no)"
  run open --path .claude/worktrees/agent-x/../.. --head "$head_branch" >/dev/null 2>&1
  check 'a path with a parent component is a usage error' "$([[ $? -eq 1 ]] && echo yes || echo no)"
  run open --path .claude/worktrees/agent-x --head main >/dev/null 2>&1
  check 'a head that is not a full SHA is a usage error' "$([[ $? -eq 1 ]] && echo yes || echo no)"
  run git --path .claude/worktrees/agent-cr-gh-1-leonardo -- -c core.hooksPath=.githooks status >/dev/null 2>&1
  check 'git refuses an option in place of the subcommand' "$([[ $? -eq 1 ]] && echo yes || echo no)"

  local wt=.claude/worktrees/agent-test-gh-1-raphael
  rm -f "$proof"
  run open --path "$wt" --head "$head_branch" >/dev/null 2>&1
  git -C "$repo" worktree lock --reason "pid 4242 agent raphael slug gh-1" "$wt" >/dev/null 2>&1
  run remove --path "$wt" --lock-pid 1 >/dev/null 2>&1
  rc=$?
  check 'remove refuses a lock that names another pid and keeps the worktree' "$([[ $rc -eq 3 && -d "$repo/$wt" ]] && echo yes || echo no)"
  run remove --path "$wt" >/dev/null 2>&1
  rc=$?
  check 'remove refuses a locked worktree without the pid of its lock' "$([[ $rc -eq 3 && -d "$repo/$wt" ]] && echo yes || echo no)"
  printf 'changed\n' >"$repo/$wt/file.txt"
  run remove --path "$wt" --lock-pid 4242 >/dev/null 2>&1
  rc=$?
  check 'remove refuses a worktree with a change and keeps the change' \
    "$([[ $rc -eq 3 && "$(cat "$repo/$wt/file.txt")" == changed ]] && echo yes || echo no)"
  printf 'branch\n' >"$repo/$wt/file.txt"
  out="$(run remove --path "$wt" --lock-pid 4242 2>/dev/null)"
  rc=$?
  check 'remove unlocks the lock of the named pid and removes the worktree' \
    "$([[ $rc -eq 0 && "$out" == "worktree=$wt removed=yes" && ! -e "$repo/$wt" ]] && ! git -C "$repo" worktree list | grep -q agent-test && echo yes || echo no)"
  check 'remove runs no hook and no file-system monitor of the branch' "$([[ ! -e "$proof" ]] && echo yes || echo no)"
  out="$(run remove --path "$wt" 2>/dev/null)"
  check 'remove of a path that is gone is done' "$([[ $? -eq 0 && "$out" == "worktree=$wt removed=absent" ]] && echo yes || echo no)"
  run open --path "$wt" --head "$head_branch" >/dev/null 2>&1
  out="$(run remove --path "$wt" 2>/dev/null)"
  check 'remove removes an unlocked worktree' "$([[ $? -eq 0 && ! -e "$repo/$wt" && ! -e "$proof" ]] && echo yes || echo no)"
  run remove --path .claude/worktrees/agent-plain >/dev/null 2>&1
  check 'remove refuses a directory that is not a worktree of this repository' \
    "$([[ $? -eq 3 && -d "$repo/.claude/worktrees/agent-plain" ]] && echo yes || echo no)"
  run remove --path .claude/worktrees/agent-link >/dev/null 2>&1
  check 'remove refuses a path with a symbolic link in it' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  run remove --path . >/dev/null 2>&1
  check 'remove refuses the main tree as a usage error' "$([[ $? -eq 1 ]] && echo yes || echo no)"
  run remove --path "$wt" --lock-pid 'x 1' >/dev/null 2>&1
  check 'remove refuses a lock pid that is not a number' "$([[ $? -eq 1 ]] && echo yes || echo no)"

  run open --path "$wt" --head "$head_branch" >/dev/null 2>&1
  git -C "$repo/$wt" status --porcelain >/dev/null 2>&1
  check 'the fixture is sound: a plain git status in the worktree runs the file-system monitor of the branch' \
    "$([[ -e "$proof" ]] && grep -q monitor "$proof" && echo yes || echo no)"
  rm -f "$proof"
  git -C "$repo" worktree remove "$wt" >/dev/null 2>&1
  check 'the fixture is sound: a plain git worktree remove runs the file-system monitor of the branch' \
    "$([[ -e "$proof" ]] && grep -q monitor "$proof" && echo yes || echo no)"

  self_test_programs || failed=1

  [[ "$failed" -eq 0 ]] && echo "branch-worktree.sh self-test passed"
  return "$failed"
}

# Runs a command with a terminal as its standard output, so git starts its pager.
on_terminal() {
  python3 -c 'import os, pty, sys
pid, fd = pty.fork()
if pid == 0:
    os.execvp(sys.argv[1], sys.argv[1:])
while True:
    try:
        data = os.read(fd, 4096)
    except OSError:
        break
    if not data:
        break
os.waitpid(pid, 0)' "$@"
}

# One fixture per mechanism of the header table: a plain git command runs the program of the branch (the check that
# the fixture is sound), and the same command through this script does not.
self_test_programs() {
  local failed=0 tmp prog proof head_main head_signed wt out rc hooks=no
  tmp="$(mktemp -d)" || return 1
  prog="$tmp/prog"
  proof="$tmp/proof"
  wt=.claude/worktrees/agent-cr-gh-2-leonardo
  git help -c 2>/dev/null | grep -qx 'hook\.<friendly-name>\.command' && hooks=yes

  check() {
    if [[ "$2" != "yes" ]]; then
      echo "FAIL $1" >&2
      failed=1
    fi
  }
  ran() { [[ -e "$proof" ]] && grep -qx -- "$1" "$proof"; }
  quiet() { [[ ! -e "$proof" ]]; }

  mkdir -p "$prog"
  (
    cd "$prog" || exit 1
    git init -q -b main && git config user.email t@example.com && git config user.name t
    printf '.claude/\n' >.gitignore
    printf 'main\n' | tee y.dat w.md z.prc >z.txt
    git add . && git commit -q -m init
    git checkout -q -b branch
    # shellcheck disable=SC2016 # the tool script expands its own arguments
    printf '#!/bin/sh\necho "$1" >>%s\ncase "$1" in textconv) cat "$2" ;; clean | smudge | pager) cat ;; esac\nexit 0\n' \
      "$proof" >tool.sh
    chmod +x tool.sh
    printf '*.txt filter=x diff=x\n*.dat diff=y\n*.prc filter=p\n' >.gitattributes
    printf 'branch\n' | tee y.dat w.md z.prc >z.txt
    git -c core.hooksPath=/dev/null add . && git -c core.hooksPath=/dev/null commit -q -m 'branch programs'
    git cat-file commit HEAD | awk '{ print } /^committer / && !done { print "gpgsig -----BEGIN PGP SIGNATURE-----"; print " iQ=="; print " -----END PGP SIGNATURE-----"; done = 1 }' \
      | git hash-object -t commit -w --stdin | xargs git update-ref refs/heads/signed
    git -c core.hooksPath=/dev/null checkout -q main
    printf '*.md diff=x\n' >"$tmp/attributes"
    git config filter.x.clean './tool.sh clean'
    git config filter.x.smudge './tool.sh smudge'
    git config filter.p.process './tool.sh process'
    git config diff.x.textconv './tool.sh textconv'
    git config diff.y.command './tool.sh command'
    git config core.attributesFile "$tmp/attributes"
    git config core.pager './tool.sh pager'
    git config log.showSignature true
    git config gpg.program ./tool.sh
    if [[ "$hooks" == yes ]]; then
      git config hook.review.command './tool.sh hook'
      git config hook.review.event post-checkout
    fi
  ) >/dev/null 2>&1 || { echo "FAIL program fixture repository" >&2; rm -rf "$tmp"; return 1; }
  head_main="$(git -C "$prog" rev-parse main)"
  head_signed="$(git -C "$prog" rev-parse signed)"
  run() { (cd "$prog" && "$SELF" "$@"); }
  dirty() { touch -t 203001010000 "$prog/$wt/z.txt" "$prog/$wt/z.prc"; }
  process_filter() {
    if [[ "$1" == on ]]; then
      git -C "$prog" config filter.p.process './tool.sh process'
    else
      git -C "$prog" config --unset filter.p.process
    fi
  }

  run open --path "$wt" --head "$head_signed" >/dev/null 2>&1
  rc=$?
  check 'open runs no filter, process filter, or configured hook of the branch' "$([[ $rc -eq 0 ]] && quiet && echo yes || echo no)"
  dirty
  run git --path "$wt" -- status --porcelain >/dev/null 2>&1
  rc=$?
  check 'status through the script runs no clean or process filter of the branch' "$([[ $rc -eq 0 ]] && quiet && echo yes || echo no)"
  run git --path "$wt" -- diff "$head_main" "$head_signed" >/dev/null 2>&1
  rc=$?
  check 'diff through the script runs no text conversion or diff driver of the branch' "$([[ $rc -eq 0 ]] && quiet && echo yes || echo no)"
  run git --path "$wt" -- show "$head_signed" >/dev/null 2>&1
  rc=$?
  check 'show through the script runs no program of the branch' "$([[ $rc -eq 0 ]] && quiet && echo yes || echo no)"
  run git --path "$wt" -- log -1 >/dev/null 2>&1
  rc=$?
  check 'log through the script runs no signature program of the branch' "$([[ $rc -eq 0 ]] && quiet && echo yes || echo no)"
  (cd "$prog" && on_terminal "$SELF" git --path "$wt" -- log -1 --format=%H) >/dev/null 2>&1
  check 'log on a terminal through the script runs no pager of the branch' "$(quiet && echo yes || echo no)"
  GIT_EXTERNAL_DIFF=./tool.sh run git --path "$wt" -- diff "$head_main" "$head_signed" >/dev/null 2>&1
  check 'diff through the script runs no external diff program that the environment names' "$(quiet && echo yes || echo no)"

  # A process filter that speaks no protocol aborts a plain git (fatal on git 2.54, an error on git 2.56) before the
  # next filter or hook runs, so the control checks of the other rows run without it, and its own check runs last.
  process_filter off
  git -C "$prog/$wt" status --porcelain >/dev/null 2>&1
  check 'the fixture is sound: a plain git status runs the clean filter of the branch' "$(ran clean && echo yes || echo no)"
  rm -f "$proof"
  git -C "$prog/$wt" diff "$head_main" "$head_signed" -- z.txt >/dev/null 2>&1
  check 'the fixture is sound: a plain git diff runs the text conversion of the branch' "$(ran textconv && echo yes || echo no)"
  rm -f "$proof"
  git -C "$prog/$wt" diff "$head_main" "$head_signed" -- y.dat >/dev/null 2>&1
  check 'the fixture is sound: a plain git diff runs the diff driver of the branch' "$(ran command && echo yes || echo no)"
  rm -f "$proof"
  git -C "$prog/$wt" diff "$head_main" "$head_signed" -- w.md >/dev/null 2>&1
  check 'the fixture is sound: the attributes file of the configuration selects a driver of the branch' "$(ran textconv && echo yes || echo no)"
  rm -f "$proof"
  git -C "$prog/$wt" log -1 >/dev/null 2>&1
  check 'the fixture is sound: a plain git log runs the signature program of the branch' \
    "$([[ -e "$proof" ]] && grep -q -- '^--' "$proof" && echo yes || echo no)"
  rm -f "$proof"
  on_terminal git -C "$prog/$wt" -c log.showSignature=false log -1 --format=%H >/dev/null 2>&1
  check 'the fixture is sound: a plain git log on a terminal runs the pager of the branch' "$(ran pager && echo yes || echo no)"
  rm -f "$proof"
  GIT_EXTERNAL_DIFF=./tool.sh git -C "$prog/$wt" diff "$head_main" "$head_signed" >/dev/null 2>&1
  check 'the fixture is sound: a plain git diff runs the external diff program the environment names' "$([[ -e "$proof" ]] && echo yes || echo no)"
  rm -f "$proof"
  git -C "$prog/$wt" -c core.hooksPath=/dev/null checkout -q --detach "$head_main" >/dev/null 2>&1
  git -C "$prog/$wt" -c core.hooksPath=/dev/null checkout -q --detach "$head_signed" >/dev/null 2>&1
  check 'the fixture is sound: a plain git checkout runs the smudge filter of the branch' "$(ran smudge && echo yes || echo no)"
  if [[ "$hooks" == yes ]]; then
    check 'the fixture is sound: a plain git checkout runs a hook the configuration names, whatever core.hooksPath says' \
      "$(ran hook && echo yes || echo no)"
  fi
  rm -f "$proof"
  process_filter on
  git -C "$prog/$wt" -c core.hooksPath=/dev/null checkout -q --detach "$head_main" >/dev/null 2>&1
  git -C "$prog/$wt" -c core.hooksPath=/dev/null checkout -q --detach "$head_signed" >/dev/null 2>&1
  check 'the fixture is sound: a plain git checkout starts the process filter of the branch' "$(ran process && echo yes || echo no)"
  rm -f "$proof"
  process_filter off
  git -C "$prog/$wt" -c core.hooksPath=/dev/null checkout -q --detach "$head_signed" >/dev/null 2>&1
  rm -f "$proof"
  process_filter on

  git -C "$prog" config diff.external './tool.sh external'
  run git --path "$wt" -- diff "$head_main" "$head_signed" >/dev/null 2>&1
  rc=$?
  check 'the script refuses a configuration that names an external diff program' "$([[ $rc -eq 3 ]] && quiet && echo yes || echo no)"
  git -C "$prog/$wt" diff "$head_main" "$head_signed" >/dev/null 2>&1
  check 'the fixture is sound: a plain git diff runs the external diff program of the branch' "$(ran external && echo yes || echo no)"
  rm -f "$proof"
  git -C "$prog" config --unset diff.external

  mkdir -p "$prog/.git/info"
  printf '# a comment\n\n' >"$prog/.git/info/attributes"
  run git --path "$wt" -- status --porcelain >/dev/null 2>&1
  rc=$?
  check 'the script accepts an info/attributes file with comments only' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  printf '*.txt filter=x\n' >>"$prog/.git/info/attributes"
  dirty
  run git --path "$wt" -- status --porcelain >/dev/null 2>&1
  rc=$?
  check 'the script refuses an info/attributes file that assigns an attribute' "$([[ $rc -eq 3 ]] && quiet && echo yes || echo no)"
  run remove --path "$wt" >/dev/null 2>&1
  check 'remove refuses an info/attributes file that assigns an attribute and keeps the worktree' \
    "$([[ $? -eq 3 && -d "$prog/$wt" ]] && quiet && echo yes || echo no)"
  process_filter off
  git -C "$prog/$wt" status --porcelain >/dev/null 2>&1
  check 'the fixture is sound: info/attributes selects the clean filter of the branch' "$(ran clean && echo yes || echo no)"
  process_filter on
  rm -f "$proof" "$prog/.git/info/attributes"

  git -C "$prog" config alias.st '!./tool.sh alias'
  local subcommand
  for subcommand in st difftool fetch config checkout submodule grep; do
    run git --path "$wt" -- "$subcommand" >/dev/null 2>&1
    check "git refuses the subcommand $subcommand, which is not a read" "$([[ $? -eq 1 ]] && quiet && echo yes || echo no)"
  done
  git -C "$prog/$wt" st >/dev/null 2>&1
  check 'the fixture is sound: a plain git alias runs the program of the branch' "$(ran alias && echo yes || echo no)"
  rm -f "$proof"
  git -C "$prog" config --unset alias.st

  if [[ "$hooks" == yes ]]; then
    git -C "$prog" config 'hook.a b.command' './tool.sh hook'
    run git --path "$wt" -- status --porcelain >/dev/null 2>&1
    check 'the script refuses a configured hook whose name it cannot switch off' "$([[ $? -eq 3 ]] && echo yes || echo no)"
    git -C "$prog" config --unset 'hook.a b.command'
  fi

  dirty
  run remove --path "$wt" >/dev/null 2>&1
  rc=$?
  check 'remove runs no filter or configured hook of the branch' "$([[ $rc -eq 0 && ! -e "$prog/$wt" ]] && quiet && echo yes || echo no)"

  self_test_lazy_fetch "$tmp" || failed=1
  rm -rf "$tmp"
  return "$failed"
}

# A partial clone fetches a missing object on demand, and that fetch runs the upload-pack program of the configuration.
self_test_lazy_fetch() {
  local tmp="$1" origin="$1/origin" clone="$1/clone" proof="$1/lazy-proof" wt=.claude/worktrees/agent-cr-gh-3-leonardo
  local with_tool secret failed=0
  (
    git init -q -b main "$origin" && cd "$origin" || exit 1
    git config user.email t@example.com && git config user.name t
    git config uploadpack.allowFilter true && git config uploadpack.allowAnySHA1InWant true
    printf '.claude/\n' >.gitignore && git add .gitignore && git commit -q -m init
    git checkout -q -b branch
    # shellcheck disable=SC2016 # the tool script expands its own arguments
    printf '#!/bin/sh\necho "$1" >>%s\nexit 1\n' "$proof" >tool.sh && chmod +x tool.sh
    git add tool.sh && git -c core.hooksPath=/dev/null commit -q -m tool
    printf 'secret\n' >secret.txt && git add secret.txt && git -c core.hooksPath=/dev/null commit -q -m secret
    git -c core.hooksPath=/dev/null checkout -q main
    git clone -q --no-local --filter=blob:none "file://$origin" "$clone"
  ) >/dev/null 2>&1 || { echo "FAIL lazy-fetch fixture repository" >&2; return 1; }
  with_tool="$(git -C "$origin" rev-parse branch~1)"
  secret="$(git -C "$origin" rev-parse branch)"
  git -C "$clone" -c core.hooksPath=/dev/null worktree add -q --detach "$wt" "$with_tool" >/dev/null 2>&1
  git -C "$clone" config remote.origin.uploadpack './tool.sh upload'
  (cd "$clone" && "$SELF" git --path "$wt" -- show "$secret:secret.txt") >/dev/null 2>&1
  if [[ -e "$proof" ]]; then
    echo "FAIL a read through the script in a partial clone runs no fetch program of the branch" >&2
    failed=1
  fi
  git -C "$clone/$wt" show "$secret:secret.txt" >/dev/null 2>&1
  if [[ ! -e "$proof" ]] || ! grep -qx upload "$proof"; then
    echo "FAIL the fixture is sound: a plain git read in a partial clone runs the fetch program of the branch" >&2
    failed=1
  fi
  return "$failed"
}

command="${1:-}"
[[ -n "$command" ]] || usage
shift
command -v git >/dev/null 2>&1 || { echo "branch-worktree.sh: git is required" >&2; exit 2; }

case "$command" in
--self-test)
  [[ $# -eq 0 ]] || usage
  self_test
  exit $?
  ;;
open)
  path="" head=""
  while [[ $# -gt 0 ]]; do
    [[ $# -ge 2 ]] || usage
    case "$1" in
    --path) path="$2" ;;
    --head) head="$2" ;;
    *) usage ;;
    esac
    shift 2
  done
  if ! valid_path "$path" || ! valid_head "$head"; then
    usage
  fi
  prepare_git
  open_worktree "$path" "$head"
  ;;
git)
  [[ $# -ge 4 && "$1" == --path && "$3" == -- ]] || usage
  path="$2"
  shift 3
  if ! valid_path "$path" || [[ ! "$1" =~ $READS ]]; then
    usage
  fi
  prepare_git
  run_git "$path" "$@"
  ;;
remove)
  path="" pid=""
  while [[ $# -gt 0 ]]; do
    [[ $# -ge 2 ]] || usage
    case "$1" in
    --path) path="$2" ;;
    --lock-pid) pid="$2" ;;
    *) usage ;;
    esac
    shift 2
  done
  if ! valid_path "$path" || [[ -n "$pid" && ! "$pid" =~ ^[0-9]{1,7}$ ]]; then
    usage
  fi
  prepare_git
  remove_worktree "$path" "$pid"
  ;;
*) usage ;;
esac
