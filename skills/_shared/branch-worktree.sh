#!/usr/bin/env bash
# branch-worktree.sh — open the detached worktree in which a review or a walkthrough reads a branch under review.
#
# The main working tree is never checked out on a branch under review
# (skills/code-review/references/branch-checkout-gate.md). A review and a walkthrough read the branch in a detached
# worktree below `.claude/worktrees/` instead. This script is the one owner of the git options that keep the branch
# from running anything while the worktree is written or read: no git hook, no file-system monitor, and no submodule
# checkout. Every caller runs these git commands only through this script.
#
# Usage (run in the main working tree, at its root)
#   branch-worktree.sh open --path <path> --head <sha>
#   branch-worktree.sh git --path <path> -- <git subcommand> [<argument>...]
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
# git runs one git command in a worktree of this repository with the same options. The subcommand comes first, so no
# argument can set a git option of its own.
#
# remove removes a worktree of this repository with the same options, never with `--force`. It refuses a worktree
# whose `git status --porcelain` prints anything, and a locked worktree whose lock reason does not name
# `pid <--lock-pid>`. It unlocks only that lock, removes the worktree, prunes the metadata, and prints one line:
#   worktree=<path> removed=yes      (or removed=absent when the path is already gone; it then only prunes)
# The caller probes the liveness of the lock holder first; this script never decides that a holder is dead.
#
# The options, on every git command of this script:
#   -c core.hooksPath=/dev/null   no hook runs, whatever directory the configuration names
#   -c core.fsmonitor=false       no file-system monitor runs
#   -c submodule.recurse=false    no submodule is checked out or updated (and `checkout --no-recurse-submodules`)
#
# Exit codes
#   0  done
#   1  usage error
#   2  git is missing
#   3  refused: a symbolic link in the path, a path that exists and is not a worktree of this repository, a change to
#      a tracked file in the worktree (any change for remove), a head that is not a commit of this repository, or a
#      lock that does not name the pid of --lock-pid
#   4  a git command failed, or `HEAD` of the worktree is not <sha> afterwards
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$HERE/$(basename "${BASH_SOURCE[0]}")"
SAFE=(-c core.hooksPath=/dev/null -c core.fsmonitor=false -c submodule.recurse=false)

usage() {
  echo "Usage: branch-worktree.sh open --path <path> --head <sha> | git --path <path> -- <subcommand> [<argument>...] | remove --path <path> [--lock-pid <pid>] | --self-test" >&2
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

  [[ "$failed" -eq 0 ]] && echo "branch-worktree.sh self-test passed"
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
  open_worktree "$path" "$head"
  ;;
git)
  [[ $# -ge 4 && "$1" == --path && "$3" == -- ]] || usage
  path="$2"
  shift 3
  if ! valid_path "$path" || [[ ! "$1" =~ ^[a-z][a-z-]*$ ]]; then
    usage
  fi
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
  remove_worktree "$path" "$pid"
  ;;
*) usage ;;
esac
