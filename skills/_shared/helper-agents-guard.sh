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
#   helper-agents-guard.sh config-hash [--root <dir>]
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
# branch and whose tracked paths below `.claude`, `.codex`, or `.agents`, in any letter case, differ from the
# default branch. It also refuses a copy that already exists: the copies are taken once per run.
#
# read-role prints the copy when its SHA-256 equals --sha256 and refuses (exit 3) every other case.
#
# config-hash prints `config-hash=<hex>`: one SHA-256 over the list and the content of the agent configuration that a
# later session loads, below --root (default: the current directory): `.claude/agents`, `.claude/skills`,
# `.claude/rules`, `.claude/commands`, `.claude/settings*.json`, `.codex` (without `.codex/run` and
# `.codex/worktrees`), and `.agents`. A symbolic link counts by its target. The output names no file and no value, so
# a settings file is hashed and never shown. The session runs it before and after each review stage and compares.
#
# Exit codes
#   0  done
#   1  usage error
#   2  a required tool is missing (git, and sha256sum or shasum)
#   3  the trusted role definition is not available: the stage stops with
#      `Blocked: helper agents forbidden — trusted role definition not available`
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$HERE/$(basename "${BASH_SOURCE[0]}")"
BLOCKED='Blocked: helper agents forbidden — trusted role definition not available'

usage() {
  echo "Usage: helper-agents-guard.sh copy-roles --run-dir <dir> --slug <slug> --role <role>... | read-role --run-dir <dir> --slug <slug> --role <role> --sha256 <hex> | config-hash [--root <dir>] | --self-test" >&2
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

# True when the tracked paths below .claude, .codex, and .agents, in any letter case, equal the default branch.
changes_no_configuration_path() {
  local ref
  ref="$(g symbolic-ref --quiet refs/remotes/origin/HEAD 2>/dev/null)" || return 1
  g diff --quiet --cached "$ref" -- ':(icase).claude' ':(icase).codex' ':(icase).agents' 2>/dev/null \
    && g diff --quiet "$ref" -- ':(icase).claude' ':(icase).codex' ':(icase).agents' 2>/dev/null
}

copy_roles() {
  local run_dir="$1" slug="$2"
  shift 2
  local roles_dir dest role src copy digest
  roles_dir="$(roles_dir_of_run_dir "$run_dir")"
  dest="${run_dir%/}/$slug.roles"

  [[ -d "$run_dir" ]] || refuse "the run directory does not exist"
  if [[ "$(g rev-parse --is-inside-work-tree 2>/dev/null)" == "true" ]] && ! on_default_branch; then
    changes_no_configuration_path || refuse "the tree is not on the default branch and changes a tracked path below .claude, .codex, or .agents"
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

config_hash() {
  local root="$1" sha_cmd path
  local -a paths files
  cd -- "$root" 2>/dev/null || { echo "helper-agents-guard.sh: cannot enter the root directory" >&2; exit 1; }
  if command -v sha256sum >/dev/null 2>&1; then sha_cmd=(sha256sum); else sha_cmd=(shasum -a 256); fi

  paths=()
  for path in .claude/agents .claude/skills .claude/rules .claude/commands .claude/settings*.json .codex .agents; do
    [[ -e "$path" || -L "$path" ]] && paths+=("$path")
  done

  {
    if [[ ${#paths[@]} -gt 0 ]]; then
      files=()
      while IFS= read -r -d '' path; do
        if [[ -L "$path" ]]; then
          printf 'l %s %s\n' "$(readlink -- "$path")" "$path"
        else
          files+=("$path")
        fi
      done < <(find -P "${paths[@]}" \( -path .codex/run -o -path .codex/worktrees \) -prune -o \( -type f -o -type l \) -print0 | LC_ALL=C sort -z)
      [[ ${#files[@]} -eq 0 ]] || "${sha_cmd[@]}" -- "${files[@]}" </dev/null | sed 's/^/f /'
    fi
  } | LC_ALL=C sort | sha256_stdin | sed 's/^/config-hash=/'
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
  for config_path in .Claude/settings.json .claude/hooks/h.sh .Codex/config.toml .AGENTS/skills/x/SKILL.md; do
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
  done
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
config-hash)
  root="."
  if [[ $# -gt 0 ]]; then
    [[ $# -eq 2 && "$1" == "--root" && -n "$2" ]] || usage
    root="$2"
  fi
  require_tools
  config_hash "$root"
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
