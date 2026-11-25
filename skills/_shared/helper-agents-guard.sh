#!/usr/bin/env bash
# helper-agents-guard.sh — the trusted role copies of a run whose project forbids helper agents.
#
# When the project forbids helper agents (skills/_shared/orchestration/helper-agents.md), the session runs every
# stage of the route itself and applies the role definition of each stage. That definition must be the copy the
# session started with, never a copy that a branch under review can write (@rules/security/general.md *Trusted
# sources*). The gather phase copies each role definition into the run directory once and records its SHA-256. Every
# stage reads its role only through `read-role`, which checks the hash and prints the copy in the same read.
#
# Usage (run in the tree the session started in, never in a unit, review, or gate worktree)
#   helper-agents-guard.sh copy-roles --run-dir <dir> --slug <slug> --role <role> [--role <role>]...
#   helper-agents-guard.sh read-role  --run-dir <dir> --slug <slug> --role <role> --sha256 <hex>
#   helper-agents-guard.sh config-diff --base <rev> --target <rev>
#   helper-agents-guard.sh config-hash [--root <dir>] [--user]
#   helper-agents-guard.sh --self-test
#
#   --run-dir   the run directory of the platform: a path that ends in `.claude/run` (the role is read from
#               `.claude/agents/<role>.md`) or in `.codex/run` (`.codex/agent-instructions/<role>.md`)
#   --slug      the source slug of the run, as in the name of its brief
#   --role      a role name: lower-case letters, digits, and dashes
#   --sha256    the SHA-256 that copy-roles printed for the role
#
# copy-roles copies each definition to `<run-dir>/<slug>.roles/<role>.md` and prints one line per role:
#   role=<role> sha256=<hex> copy=<path>
# It refuses (exit 3) a definition that is missing, that is not a regular file, that has a symbolic link in its
# path, that a git tree tracks under any letter case while its HEAD is not the default branch, or that a git tree
# tracks under any letter case with a change against HEAD. It also refuses a git tree that is not on the default
# branch and that changes, against the default branch, a path that a later session loads (as config-diff defines it).
# It also refuses a copy that already exists: the copies are taken once per run.
#
# read-role prints the copy when its SHA-256 equals --sha256 and refuses (exit 3) every other case.
#
# config-diff compares two commits for the branch checkout gate (skills/code-review/references/branch-checkout-gate.md)
# and exits 3 when a changed path holds a byte outside ASCII or the byte 0x01, or when a component of a changed path,
# at any depth and in any letter case, is one of CONFIG_NAMES. It exits 0 when no changed path does, and 4 when a
# revision cannot be resolved or the diff cannot be listed. CONFIG_NAMES is the one list of the names that a later
# session loads.
#
# config-hash prints `config-hash=<hex>`: one SHA-256 over the list and the content of the configuration that a later
# session or git loads, below --root (default: the current directory): every entry of CONFIG_NAMES at the root, without
# `.claude/run`, `.claude/worktrees`, the session lock `.claude/scheduled_tasks.lock` that the harness rewrites,
# `.codex/run`, and `.codex/worktrees`; the git hooks directory; and every local git setting except the `branch.*`
# entries a checkout writes. --user adds the user-level files below the home directory: `.claude/CLAUDE.md`,
# `.claude/settings.json`, `.claude/settings.local.json`, `.claude/rules`, `.claude/agents`, `.claude/skills`,
# `.claude/commands`, `.claude/hooks`, `.claude/output-styles`, `.codex/config.toml`, `.codex/AGENTS.md`,
# `.codex/prompts`, `.codex/agents`, `.agents`, and `.gitconfig`, and the user git configuration
# `${XDG_CONFIG_HOME:-$HOME/.config}/git/config`. A symbolic link counts by its target path and by the content of its
# target. The output names no file and no value, so a settings file is hashed and never shown. The session runs it
# before and after each review stage and compares.
#
# Exit codes
#   0  done
#   1  usage error
#   2  a required tool is missing (git, and sha256sum or shasum)
#   3  the trusted role definition is not available: the stage stops with
#      `Blocked: helper agents forbidden — trusted role definition not available`; for config-diff: the target changes
#      a path that a later session loads, and the main tree is not checked out
#   4  config-hash cannot compute the hash (a root it cannot enter, a path it cannot read, more than 100000 entries):
#      it prints no hash, and the round stops as not converged; for config-diff: the diff cannot be computed
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$HERE/$(basename "${BASH_SOURCE[0]}")"
BLOCKED='Blocked: helper agents forbidden — trusted role definition not available'
CONFIG_NAMES=(.claude .codex .agents CLAUDE.md CLAUDE.local.md AGENTS.md .mcp.json)

usage() {
  echo "Usage: helper-agents-guard.sh copy-roles --run-dir <dir> --slug <slug> --role <role>... | read-role --run-dir <dir> --slug <slug> --role <role> --sha256 <hex> | config-diff --base <rev> --target <rev> | config-hash [--root <dir>] [--user] | --self-test" >&2
  exit 1
}

refuse() {
  echo "$BLOCKED: $1" >&2
  exit 3
}

g() {
  git -c core.fsmonitor=false -c core.hooksPath=/dev/null "$@"
}

sha256_stdin() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum | awk '{print $1}'
  else
    shasum -a 256 | awk '{print $1}'
  fi
}

require_tools() {
  command -v git >/dev/null 2>&1 || { echo "helper-agents-guard.sh: git is required" >&2; exit 2; }
  command -v sha256sum >/dev/null 2>&1 || command -v shasum >/dev/null 2>&1 \
    || { echo "helper-agents-guard.sh: sha256sum or shasum is required" >&2; exit 2; }
}

# Prints the directory of the installed role definitions for a run directory, or nothing for another path.
roles_dir_of_run_dir() {
  local run_dir="${1%/}"
  case "$run_dir" in
  .claude/run | */.claude/run) echo ".claude/agents" ;;
  .codex/run | */.codex/run) echo ".codex/agent-instructions" ;;
  esac
}

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

# True when the current tree has `HEAD` on the branch that `origin/HEAD` names.
on_default_branch() {
  local ref head
  ref="$(g symbolic-ref --quiet refs/remotes/origin/HEAD 2>/dev/null)" || return 1
  [[ "$ref" == refs/remotes/origin/?* ]] || return 1
  head="$(g symbolic-ref --quiet --short HEAD 2>/dev/null)" || return 1
  [[ "$head" == "${ref#refs/remotes/origin/}" ]]
}

# Returns 0 when no path that `git diff <args>` lists holds a byte outside ASCII or has a component that is one of
# CONFIG_NAMES under any letter case, 1 when one does, and 2 when git cannot list the diff. It writes no file. The
# empty record after the listing marks a diff that git completed, because a path is never empty.
configuration_free() {
  local name rest component known rc=0 listed=0
  shopt -s nocasematch
  while IFS= read -r -d '' name; do
    if [[ -z "$name" ]]; then
      listed=1
      continue
    fi
    [[ $rc -eq 0 ]] || continue
    [[ "$name" == *$'\001'* ]] && rc=1
    rest="$name"
    while [[ $rc -eq 0 ]]; do
      component="${rest%%/*}"
      for known in "${CONFIG_NAMES[@]}"; do
        [[ "$component" == "$known" ]] && rc=1
      done
      [[ "$rest" == */* ]] || break
      rest="${rest#*/}"
    done
  done < <({ g diff --no-renames --name-only -z "$@" 2>/dev/null && printf '\0'; } | LC_ALL=C tr '\200-\377' '\001')
  shopt -u nocasematch
  [[ $listed -eq 1 ]] || return 2
  return "$rc"
}

# True when the index and the working tree change no path that a later session loads against the default branch.
changes_no_configuration_path() {
  local ref
  ref="$(g symbolic-ref --quiet refs/remotes/origin/HEAD 2>/dev/null)" || return 1
  configuration_free --cached "$ref" -- && configuration_free "$ref" --
}

config_diff() {
  local base="$1" target="$2" base_sha target_sha rc=0
  if ! base_sha="$(g rev-parse --verify --quiet "$base^{commit}" 2>/dev/null)" \
    || ! target_sha="$(g rev-parse --verify --quiet "$target^{commit}" 2>/dev/null)"; then
    echo "helper-agents-guard.sh: config-diff: a revision cannot be resolved" >&2
    exit 4
  fi
  configuration_free "$base_sha" "$target_sha" -- || rc=$?
  case "$rc" in
  0) exit 0 ;;
  1)
    echo "helper-agents-guard.sh: config-diff: the target changes a path that a later session loads" >&2
    exit 3
    ;;
  *)
    echo "helper-agents-guard.sh: config-diff: the diff cannot be listed" >&2
    exit 4
    ;;
  esac
}

copy_roles() {
  local run_dir="$1" slug="$2"
  shift 2
  local roles_dir dest role src copy digest
  roles_dir="$(roles_dir_of_run_dir "$run_dir")"
  dest="${run_dir%/}/$slug.roles"

  [[ -d "$run_dir" ]] || refuse "the run directory does not exist"
  if [[ "$(g rev-parse --is-inside-work-tree 2>/dev/null)" == "true" ]] && ! on_default_branch; then
    changes_no_configuration_path || refuse "the tree is not on the default branch and changes a tracked path that a later session loads"
  fi
  for role in "$@"; do
    src="$roles_dir/$role.md"
    no_link_in_path "$src" || refuse "the definition of $role has a symbolic link in its path"
    [[ -f "$src" ]] || refuse "the definition of $role is missing or is not a regular file"
    if [[ -n "$(g ls-files -- ":(icase)$src" 2>/dev/null)" ]]; then
      on_default_branch || refuse "the definition of $role is tracked in a tree that is not on the default branch"
      g diff --quiet HEAD -- ":(icase)$src" 2>/dev/null || refuse "the definition of $role differs from its committed copy"
    fi
    [[ -e "$dest/$role.md" || -L "$dest/$role.md" ]] && refuse "the copy of $role already exists; the copies are taken once per run"
  done

  (umask 077 && mkdir -p -- "$dest") || refuse "the copy directory cannot be created"
  [[ -L "$dest" ]] && refuse "the copy directory is a symbolic link"
  for role in "$@"; do
    src="$roles_dir/$role.md"
    copy="$dest/$role.md"
    (umask 077 && cp -- "$src" "$copy.tmp.$$" && mv -- "$copy.tmp.$$" "$copy") || refuse "the definition of $role cannot be copied"
    digest="$(sha256_stdin <"$copy")"
    printf 'role=%s sha256=%s copy=%s\n' "$role" "$digest" "$copy"
  done
}

read_role() {
  local run_dir="$1" slug="$2" role="$3" expected="$4" copy content digest
  copy="${run_dir%/}/$slug.roles/$role.md"

  [[ -L "${run_dir%/}/$slug.roles" || -L "$copy" ]] && refuse "the copy of $role is a symbolic link"
  [[ -f "$copy" ]] || refuse "the copy of $role is missing"
  content="$(cat -- "$copy" && printf 'x')" || refuse "the copy of $role cannot be read"
  content="${content%x}"
  digest="$(printf '%s' "$content" | sha256_stdin)"
  [[ "$digest" == "$expected" ]] || refuse "the copy of $role does not match its recorded SHA-256"
  printf '%s' "$content"
}

hash_fails() {
  echo "helper-agents-guard.sh: config-hash: $1" >&2
  exit 4
}

# Prints one hash line per entry of the local git configuration, without the `branch.*` entries that a checkout writes.
git_config_lines() {
  local entry
  while IFS= read -r -d '' entry; do
    [[ "$entry" == branch.* ]] || printf 'c %s\n' "$(printf '%s' "$entry" | sha256_stdin)"
  done < <(g config --local --null --list 2>/dev/null)
}

config_hash() {
  local root="$1" with_user="$2" tmp path count=0
  local -a paths
  cd -- "$root" 2>/dev/null || hash_fails "the root directory cannot be entered"
  HASH_TMP="$(mktemp -d)" || hash_fails "no temporary directory"
  trap 'rm -rf "$HASH_TMP"' EXIT
  tmp="$HASH_TMP"

  paths=()
  for path in "${CONFIG_NAMES[@]}"; do
    [[ -e "$path" || -L "$path" ]] && paths+=("$path")
  done
  if [[ "$(g rev-parse --is-inside-work-tree 2>/dev/null)" == "true" ]]; then
    for path in "$(g rev-parse --git-common-dir 2>/dev/null)/hooks" "$(git -c core.fsmonitor=false rev-parse --git-path hooks 2>/dev/null)"; do
      [[ "$path" != /hooks && -e "$path" ]] && paths+=("$path")
    done
    git_config_lines >"$tmp/lines" || hash_fails "the local git configuration cannot be read"
  fi
  if [[ "$with_user" == 1 ]]; then
    [[ -n "${HOME:-}" && -d "$HOME" ]] || hash_fails "the home directory is not available"
    for path in .claude/CLAUDE.md .claude/settings.json .claude/settings.local.json .claude/rules .claude/agents \
      .claude/skills .claude/commands .claude/hooks .claude/output-styles .codex/config.toml .codex/AGENTS.md \
      .codex/prompts .codex/agents .agents .gitconfig; do
      [[ -e "$HOME/$path" || -L "$HOME/$path" ]] && paths+=("$HOME/$path")
    done
    path="${XDG_CONFIG_HOME:-$HOME/.config}/git/config"
    [[ -e "$path" || -L "$path" ]] && paths+=("$path")
  fi

  : >>"$tmp/lines"
  : >"$tmp/files"
  if [[ ${#paths[@]} -gt 0 ]]; then
    while IFS= read -r -d '' path; do
      count=$((count + 1))
      [[ $count -le 100000 ]] || hash_fails "more than 100000 entries"
      if [[ -L "$path" ]]; then
        printf 'b %s %s\n' "$path" "$(readlink -- "$path")" >>"$tmp/lines"
        [[ -f "$path" ]] && printf '%s\0' "$path" >>"$tmp/files"
      else
        printf '%s\0' "$path" >>"$tmp/files"
      fi
    done < <(find -L "${paths[@]}" \( -path .claude/run -o -path .claude/worktrees -o -path .claude/scheduled_tasks.lock \
      -o -path .codex/run -o -path .codex/worktrees \) -prune \
      -o \( -type f -o -type l \) -print0 2>"$tmp/find.err")
    [[ -s "$tmp/find.err" ]] && hash_fails "a path cannot be read"
    if [[ -s "$tmp/files" ]]; then
      if command -v sha256sum >/dev/null 2>&1; then
        xargs -0 sha256sum -- <"$tmp/files" >"$tmp/sums" 2>/dev/null || hash_fails "a file cannot be hashed"
      else
        xargs -0 shasum -a 256 -- <"$tmp/files" >"$tmp/sums" 2>/dev/null || hash_fails "a file cannot be hashed"
      fi
      sed 's/^/f /' "$tmp/sums" >>"$tmp/lines"
    fi
  fi
  LC_ALL=C sort "$tmp/lines" | sha256_stdin | sed 's/^/config-hash=/'
}

self_test() {
  local failed=0 tmp repo out hash rc
  tmp="$(mktemp -d)" || return 1
  trap 'rm -rf "$tmp"' RETURN

  check() {
    if [[ "$2" != "yes" ]]; then
      echo "FAIL $1" >&2
      failed=1
    fi
  }
  run() { (cd "$repo" && "$SELF" "$@"); }

  repo="$tmp/repo"
  mkdir -p "$repo/.claude/agents" "$repo/.claude/run" "$repo/.codex/agent-instructions" "$repo/.codex/run"
  (
    cd "$repo" || exit 1
    g init -q -b main && g config user.email t@example.com && g config user.name t
    printf '.claude/\n.codex/\n' >.gitignore
    g add .gitignore && g commit -q -m init
    g update-ref refs/remotes/origin/main HEAD
    g symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main
  ) >/dev/null 2>&1 || { echo "FAIL fixture repository" >&2; return 1; }
  printf 'leonardo role\n' >"$repo/.claude/agents/leonardo.md"
  printf 'splinter role\n' >"$repo/.claude/agents/splinter.md"
  printf 'codex leonardo role\n' >"$repo/.codex/agent-instructions/leonardo.md"

  out="$(run copy-roles --run-dir .claude/run --slug gh-1 --role leonardo --role splinter)"
  rc=$?
  hash="$(printf 'leonardo role\n' | sha256_stdin)"
  check 'copy-roles prints one line per role with the hash of the copy' \
    "$([[ $rc -eq 0 && "$(printf '%s\n' "$out" | head -n 1)" == "role=leonardo sha256=$hash copy=.claude/run/gh-1.roles/leonardo.md" && "$(printf '%s\n' "$out" | wc -l | tr -d ' ')" == 2 ]] && echo yes || echo no)"
  check 'read-role prints the copy when the hash matches' \
    "$([[ "$(run read-role --run-dir .claude/run --slug gh-1 --role leonardo --sha256 "$hash")" == 'leonardo role' ]] && echo yes || echo no)"

  printf 'changed by a branch\n' >"$repo/.claude/agents/leonardo.md"
  check 'a later change of the installed definition does not reach the copy' \
    "$([[ "$(run read-role --run-dir .claude/run --slug gh-1 --role leonardo --sha256 "$hash")" == 'leonardo role' ]] && echo yes || echo no)"
  run read-role --run-dir .claude/run --slug gh-1 --role leonardo --sha256 "$(printf 'x' | sha256_stdin)" >/dev/null 2>&1
  check 'read-role refuses a hash that does not match' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  printf 'edited copy\n' >"$repo/.claude/run/gh-1.roles/leonardo.md"
  run read-role --run-dir .claude/run --slug gh-1 --role leonardo --sha256 "$hash" >/dev/null 2>&1
  check 'read-role refuses an edited copy' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  run read-role --run-dir .claude/run --slug gh-1 --role casey --sha256 "$hash" >/dev/null 2>&1
  check 'read-role refuses a missing copy' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  run copy-roles --run-dir .claude/run --slug gh-1 --role splinter >/dev/null 2>&1
  check 'copy-roles refuses a second copy of a role in one run' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  run copy-roles --run-dir .claude/run --slug gh-2 --role casey >/dev/null 2>&1
  check 'copy-roles refuses a missing definition' "$([[ $? -eq 3 && ! -e "$repo/.claude/run/gh-2.roles" ]] && echo yes || echo no)"

  ln -s ../../outside.md "$repo/.claude/agents/raphael.md"
  printf 'outside\n' >"$tmp/outside.md"
  run copy-roles --run-dir .claude/run --slug gh-3 --role raphael >/dev/null 2>&1
  check 'copy-roles refuses a definition that is a symbolic link' "$([[ $? -eq 3 ]] && echo yes || echo no)"

  out="$(run copy-roles --run-dir .codex/run --slug gh-4 --role leonardo)"
  hash="$(printf 'codex leonardo role\n' | sha256_stdin)"
  check 'a Codex run directory takes the Codex role definition' \
    "$([[ "$out" == "role=leonardo sha256=$hash copy=.codex/run/gh-4.roles/leonardo.md" && "$(run read-role --run-dir .codex/run --slug gh-4 --role leonardo --sha256 "$hash")" == 'codex leonardo role' ]] && echo yes || echo no)"

  (
    cd "$repo" || exit 1
    printf '!.claude/agents/\n.claude/run/\n' >>.gitignore
    printf 'tracked role\n' >.claude/agents/casey.md
    g add -f .gitignore .claude/agents/casey.md && g commit -q -m tracked
    g update-ref refs/remotes/origin/main HEAD
  ) >/dev/null 2>&1
  rc=0
  run copy-roles --run-dir .claude/run --slug gh-5 --role casey >/dev/null 2>&1 || rc=$?
  check 'a tracked definition on the default branch is copied' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  printf 'uncommitted change\n' >"$repo/.claude/agents/casey.md"
  run copy-roles --run-dir .claude/run --slug gh-6 --role casey >/dev/null 2>&1
  check 'a tracked definition with an uncommitted change is refused' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  (cd "$repo" && g checkout -q -- .claude/agents/casey.md && g checkout -q -b feature) >/dev/null 2>&1
  run copy-roles --run-dir .claude/run --slug gh-7 --role casey >/dev/null 2>&1
  check 'a tracked definition in a tree that is not on the default branch is refused' "$([[ $? -eq 3 ]] && echo yes || echo no)"
  rc=0
  run copy-roles --run-dir .claude/run --slug gh-off-1 --role leonardo >/dev/null 2>&1 || rc=$?
  check 'a tree off the default branch that changes no configuration path is copied' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  local config_path m=0
  for config_path in .Claude/settings.json .claude/hooks/h.sh .Codex/config.toml .AGENTS/skills/x/SKILL.md \
    CLAUDE.local.md .mcp.json claude.md AGENTS.md docs/CLAUDE.md packages/app/.claude/skills/x/SKILL.md \
    $'.agent\xc5\xbf/skills/x/SKILL.md' $'src/caf\xc3\xa9.php'; do
    m=$((m + 1))
    (
      cd "$repo" || exit 1
      blob="$(printf 'branch configuration\n' | g hash-object -w --stdin)"
      g read-tree main && g update-index --add --cacheinfo "100644,$blob,$config_path"
      g update-ref "refs/heads/config-$m" "$(g commit-tree "$(g write-tree)" -p main -m "track $config_path")"
      g symbolic-ref HEAD "refs/heads/config-$m"
    ) >/dev/null 2>&1
    rc=0
    run copy-roles --run-dir .claude/run --slug "gh-config-$m" --role leonardo >/dev/null 2>&1 || rc=$?
    check "a tree off the default branch that changes $config_path is refused" "$([[ $rc -eq 3 ]] && echo yes || echo no)"
    rc=0
    (cd "$repo" && "$SELF" config-diff --base main --target "config-$m") >/dev/null 2>&1 || rc=$?
    check "config-diff refuses a target that changes $config_path" "$([[ $rc -eq 3 ]] && echo yes || echo no)"
  done
  (
    cd "$repo" || exit 1
    blob="$(printf 'code\n' | g hash-object -w --stdin)"
    g read-tree main && g update-index --add --cacheinfo "100644,$blob,src/Feature.php"
    g update-ref refs/heads/code-only "$(g commit-tree "$(g write-tree)" -p main -m 'change code')"
    g symbolic-ref HEAD refs/heads/code-only
  ) >/dev/null 2>&1
  rc=0
  run copy-roles --run-dir .claude/run --slug gh-code-only --role leonardo >/dev/null 2>&1 || rc=$?
  check 'a tree off the default branch that changes only code is copied' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  rc=0
  (cd "$repo" && "$SELF" config-diff --base main --target code-only) >/dev/null 2>&1 || rc=$?
  check 'config-diff passes a target that changes only code' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  rc=0
  (cd "$repo" && "$SELF" config-diff --base main --target no-such-ref) >/dev/null 2>&1 || rc=$?
  check 'config-diff exits 4 on a target it cannot resolve' "$([[ $rc -eq 4 ]] && echo yes || echo no)"
  rc=0
  (cd "$repo" && "$SELF" config-diff --base main --target --output=x) >/dev/null 2>&1 || rc=$?
  check 'config-diff refuses an option in place of a revision' "$([[ $rc -eq 1 && ! -e "$repo/x" ]] && echo yes || echo no)"
  (cd "$repo" && g symbolic-ref HEAD refs/heads/feature && g read-tree feature) >/dev/null 2>&1

  local variant n=0
  for variant in .Claude/agents/splinter.md .claude/Agents/splinter.md .claude/agents/SPLINTER.md .CLAUDE/AGENTS/Splinter.md; do
    n=$((n + 1))
    (
      cd "$repo" || exit 1
      blob="$(printf 'branch role\n' | g hash-object -w --stdin)"
      g read-tree main && g update-index --add --cacheinfo "100644,$blob,$variant"
      g update-ref "refs/heads/case-$n" "$(g commit-tree "$(g write-tree)" -p main -m "track $variant")"
      g symbolic-ref HEAD "refs/heads/case-$n"
    ) >/dev/null 2>&1
    rc=0
    run copy-roles --run-dir .claude/run --slug "gh-case-$n" --role splinter >/dev/null 2>&1 || rc=$?
    check "a definition the branch tracks as $variant is refused" "$([[ $rc -eq 3 ]] && echo yes || echo no)"
  done
  (
    cd "$repo" || exit 1
    g symbolic-ref HEAD refs/heads/main && g read-tree main
    blob="$(printf 'committed role\n' | g hash-object -w --stdin)"
    g update-index --add --cacheinfo "100644,$blob,.Claude/agents/splinter.md"
    g update-ref refs/heads/main "$(g commit-tree "$(g write-tree)" -p main -m 'track a case variant')"
    g update-ref refs/remotes/origin/main refs/heads/main
  ) >/dev/null 2>&1
  rc=0
  run copy-roles --run-dir .claude/run --slug gh-case-default --role splinter >/dev/null 2>&1 || rc=$?
  check 'a definition the default branch tracks under another letter case with a change is refused' "$([[ $rc -eq 3 ]] && echo yes || echo no)"

  run copy-roles --run-dir run --slug gh-8 --role leonardo >/dev/null 2>&1
  check 'a run directory of no platform is a usage error' "$([[ $? -eq 1 ]] && echo yes || echo no)"
  run copy-roles --run-dir .claude/run --slug '../x' --role leonardo >/dev/null 2>&1
  check 'a slug with a path component is a usage error' "$([[ $? -eq 1 ]] && echo yes || echo no)"
  run copy-roles --run-dir .claude/run --slug gh-9 --role '../krang' >/dev/null 2>&1
  check 'a role with a path component is a usage error' "$([[ $? -eq 1 ]] && echo yes || echo no)"
  run read-role --run-dir .claude/run --slug gh-1 --role leonardo --sha256 'not-a-hash' >/dev/null 2>&1
  check 'a hash that is not 64 hex digits is a usage error' "$([[ $? -eq 1 ]] && echo yes || echo no)"

  local cfg first
  cfg="$tmp/cfg"
  mkdir -p "$cfg/.claude/skills/x" "$cfg/.claude/run" "$cfg/.codex/run"
  printf 'skill\n' >"$cfg/.claude/skills/x/SKILL.md"
  printf '{"token":"never-shown"}\n' >"$cfg/.claude/settings.local.json"
  first="$("$SELF" config-hash --root "$cfg")"
  check 'config-hash prints one hash line and no value' \
    "$([[ "$first" =~ ^config-hash=[0-9a-f]{64}$ && "$first" != *never-shown* ]] && echo yes || echo no)"
  check 'config-hash is stable for an unchanged tree' "$([[ "$("$SELF" config-hash --root "$cfg")" == "$first" ]] && echo yes || echo no)"
  printf 'brief\n' >"$cfg/.claude/run/brief.md"
  printf 'state\n' >"$cfg/.codex/run/state"
  check 'a write to a run directory leaves the hash unchanged' "$([[ "$("$SELF" config-hash --root "$cfg")" == "$first" ]] && echo yes || echo no)"
  printf '{"token":"changed"}\n' >"$cfg/.claude/settings.local.json"
  check 'a changed settings file changes the hash' "$([[ "$("$SELF" config-hash --root "$cfg")" != "$first" ]] && echo yes || echo no)"
  first="$("$SELF" config-hash --root "$cfg")"
  ln -s ../elsewhere "$cfg/.claude/skills/link"
  check 'a new symbolic link changes the hash' "$([[ "$("$SELF" config-hash --root "$cfg")" != "$first" ]] && echo yes || echo no)"
  first="$("$SELF" config-hash --root "$cfg")"
  mkdir -p "$cfg/.agents/skills/y"
  printf 'codex skill\n' >"$cfg/.agents/skills/y/SKILL.md"
  check 'a new Codex skill changes the hash' "$([[ "$("$SELF" config-hash --root "$cfg")" != "$first" ]] && echo yes || echo no)"
  check 'an empty tree has a hash too' "$([[ "$("$SELF" config-hash --root "$tmp/repo/.git")" =~ ^config-hash=[0-9a-f]{64}$ ]] && echo yes || echo no)"

  local loaded
  for loaded in .claude/CLAUDE.md CLAUDE.local.md CLAUDE.md AGENTS.md .mcp.json .claude/hooks/h.sh .claude/output-styles/o.md .claude/new-kind/x; do
    first="$("$SELF" config-hash --root "$cfg")"
    mkdir -p "$cfg/$(dirname "$loaded")"
    printf 'written by a stage\n' >>"$cfg/$loaded"
    check "a write to $loaded changes the hash" "$([[ "$("$SELF" config-hash --root "$cfg")" != "$first" ]] && echo yes || echo no)"
  done
  mkdir -p "$tmp/linked-target"
  printf 'skill\n' >"$tmp/linked-target/SKILL.md"
  ln -s "$tmp/linked-target" "$cfg/.claude/skills/linked"
  first="$("$SELF" config-hash --root "$cfg")"
  printf 'changed through the link\n' >>"$tmp/linked-target/SKILL.md"
  check 'a write to the target of a linked skill changes the hash' "$([[ "$("$SELF" config-hash --root "$cfg")" != "$first" ]] && echo yes || echo no)"
  local linked="$tmp/linked-root"
  mkdir -p "$linked/.claude/skills/a" "$linked/shared"
  printf '{}\n' >"$linked/shared/settings.json"
  printf 'instructions\n' >"$linked/shared/CLAUDE.md"
  printf 'skill\n' >"$linked/shared/SKILL.md"
  ln -s ../shared/settings.json "$linked/.claude/settings.json"
  ln -s shared/CLAUDE.md "$linked/CLAUDE.md"
  ln -s ../../../shared/SKILL.md "$linked/.claude/skills/a/SKILL.md"
  for loaded in settings.json CLAUDE.md SKILL.md; do
    first="$("$SELF" config-hash --root "$linked")"
    printf 'written through the link\n' >>"$linked/shared/$loaded"
    check "a write to the target of a linked $loaded changes the hash" "$([[ "$("$SELF" config-hash --root "$linked")" != "$first" ]] && echo yes || echo no)"
  done
  first="$("$SELF" config-hash --root "$linked")"
  printf '{"sessionId":"a","pid":1}\n' >"$linked/.claude/scheduled_tasks.lock"
  check 'a new session lock of the harness leaves the hash unchanged' "$([[ "$("$SELF" config-hash --root "$linked")" == "$first" ]] && echo yes || echo no)"
  printf '{"sessionId":"b","pid":2}\n' >"$linked/.claude/scheduled_tasks.lock"
  check 'a rewrite of the session lock of the harness leaves the hash unchanged' "$([[ "$("$SELF" config-hash --root "$linked")" == "$first" ]] && echo yes || echo no)"
  printf '{"changed":true}\n' >"$linked/.claude/settings.local.json"
  check 'a settings write beside the session lock still changes the hash' "$([[ "$("$SELF" config-hash --root "$linked")" != "$first" ]] && echo yes || echo no)"
  mkdir -p "$cfg/.claude/worktrees/agent-cr-x"
  first="$("$SELF" config-hash --root "$cfg")"
  printf 'review checkout\n' >"$cfg/.claude/worktrees/agent-cr-x/file"
  check 'a write to a review worktree leaves the hash unchanged' "$([[ "$("$SELF" config-hash --root "$cfg")" == "$first" ]] && echo yes || echo no)"

  first="$("$SELF" config-hash --root "$repo")"
  printf '#!/bin/sh\n' >"$repo/.git/hooks/pre-commit"
  check 'a new git hook changes the hash' "$([[ "$("$SELF" config-hash --root "$repo")" != "$first" ]] && echo yes || echo no)"
  first="$("$SELF" config-hash --root "$repo")"
  (cd "$repo" && g config core.hooksPath elsewhere) >/dev/null 2>&1
  check 'a changed local git setting changes the hash' "$([[ "$("$SELF" config-hash --root "$repo")" != "$first" ]] && echo yes || echo no)"
  first="$("$SELF" config-hash --root "$repo")"
  (cd "$repo" && g config branch.feature.remote origin) >/dev/null 2>&1
  check 'the upstream of a checked-out branch leaves the hash unchanged' "$([[ "$("$SELF" config-hash --root "$repo")" == "$first" ]] && echo yes || echo no)"

  local home="$tmp/home"
  mkdir -p "$home/.claude"
  user_hash() { HOME="$home" XDG_CONFIG_HOME="$home/.config" "$SELF" config-hash --root "$cfg" --user; }
  first="$(user_hash)"
  printf 'user instruction\n' >"$home/.claude/CLAUDE.md"
  check 'with --user a write to the user CLAUDE.md changes the hash' "$([[ "$(user_hash)" != "$first" ]] && echo yes || echo no)"
  first="$(user_hash)"
  mkdir -p "$home/.codex"
  printf 'model = "x"\n' >"$home/.codex/config.toml"
  check 'with --user a write to the Codex configuration changes the hash' "$([[ "$(user_hash)" != "$first" ]] && echo yes || echo no)"
  first="$(user_hash)"
  HOME="$home" XDG_CONFIG_HOME="$home/.config" git config --global core.fsmonitor "$tmp/monitor" >/dev/null 2>&1
  check 'with --user a git setting in the user git configuration changes the hash' \
    "$([[ -f "$home/.gitconfig" && "$(user_hash)" != "$first" ]] && echo yes || echo no)"
  first="$(user_hash)"
  mkdir -p "$home/.config/git"
  printf '[core]\n\thooksPath = %s\n' "$tmp/hooks" >"$home/.config/git/config"
  check 'with --user a git setting in the XDG git configuration changes the hash' "$([[ "$(user_hash)" != "$first" ]] && echo yes || echo no)"
  mkdir -p "$home/dotfiles"
  printf '{}\n' >"$home/dotfiles/settings.json"
  ln -s ../dotfiles/settings.json "$home/.claude/settings.json"
  first="$(user_hash)"
  printf '{"hooks":{}}\n' >"$home/dotfiles/settings.json"
  check 'with --user a write to the target of a linked user settings file changes the hash' "$([[ "$(user_hash)" != "$first" ]] && echo yes || echo no)"

  out="$("$SELF" config-hash --root "$tmp/missing" 2>/dev/null)"
  rc=$?
  check 'a missing root exits 4 and prints no hash' "$([[ $rc -eq 4 && -z "$out" ]] && echo yes || echo no)"
  mkdir -p "$cfg/.claude/rules/closed"
  printf 'rule\n' >"$cfg/.claude/rules/closed/r.md"
  chmod 000 "$cfg/.claude/rules/closed"
  out="$("$SELF" config-hash --root "$cfg" 2>/dev/null)"
  rc=$?
  chmod 700 "$cfg/.claude/rules/closed"
  check 'a directory it cannot read exits 4 and prints no hash' "$([[ $rc -eq 4 && -z "$out" ]] && echo yes || echo no)"

  [[ "$failed" -eq 0 ]] && echo "helper-agents-guard.sh self-test passed"
  return "$failed"
}

valid_slug() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$ && "$1" != *..* ]]; }
valid_role() { [[ "$1" =~ ^[a-z][a-z0-9-]{0,39}$ ]]; }

command="${1:-}"
[[ -n "$command" ]] || usage
shift

case "$command" in
--self-test)
  [[ $# -eq 0 ]] || usage
  require_tools
  self_test
  exit $?
  ;;
copy-roles | read-role) ;;
config-diff)
  base="" target=""
  while [[ $# -gt 0 ]]; do
    [[ $# -ge 2 && -n "$2" && "$2" != -* ]] || usage
    case "$1" in
    --base) base="$2" ;;
    --target) target="$2" ;;
    *) usage ;;
    esac
    shift 2
  done
  [[ -n "$base" && -n "$target" ]] || usage
  require_tools
  config_diff "$base" "$target"
  ;;
config-hash)
  root="." with_user=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --root)
      [[ $# -ge 2 && -n "$2" ]] || usage
      root="$2"
      shift 2
      ;;
    --user)
      with_user=1
      shift
      ;;
    *) usage ;;
    esac
  done
  require_tools
  config_hash "$root" "$with_user"
  exit $?
  ;;
*) usage ;;
esac

run_dir="" slug="" sha=""
roles=()
while [[ $# -gt 0 ]]; do
  [[ $# -ge 2 ]] || usage
  case "$1" in
  --run-dir) run_dir="$2" ;;
  --slug) slug="$2" ;;
  --role) roles+=("$2") ;;
  --sha256) sha="$2" ;;
  *) usage ;;
  esac
  shift 2
done

[[ -n "$(roles_dir_of_run_dir "$run_dir")" && "$run_dir" != *$'\n'* ]] || usage
valid_slug "$slug" || usage
[[ ${#roles[@]} -ge 1 ]] || usage
for role in "${roles[@]}"; do
  valid_role "$role" || usage
done
require_tools

if [[ "$command" == "copy-roles" ]]; then
  [[ -z "$sha" ]] || usage
  copy_roles "$run_dir" "$slug" "${roles[@]}"
else
  [[ ${#roles[@]} -eq 1 && "$sha" =~ ^[0-9a-f]{64}$ ]] || usage
  read_role "$run_dir" "$slug" "${roles[0]}" "$sha"
fi
