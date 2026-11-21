#!/usr/bin/env bash
# Resolve against this skill installation, even when the caller works in another repository.
resolve_command_helper() {
  local name="$1" shared root support component path
  [[ "$name" =~ ^[a-z][a-z0-9-]*[.]sh$ ]] || return 1
  shared="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)" || return 1
  case "$shared" in
    */.claude/skills/_shared) root="${shared%/.claude/skills/_shared}"; support=.claude/command-support/_shared ;;
    */.agents/skills/_shared) root="${shared%/.agents/skills/_shared}"; support=.codex/commands/_shared ;;
    */skills/_shared) root="${shared%/skills/_shared}"; support=commands/_shared ;;
    *) return 1 ;;
  esac
  local segments=()
  IFS=/ read -r -a segments <<<"$support"
  path="$root"
  for component in "${segments[@]}" "$name"; do
    path="$path/$component"
    [[ ! -L "$path" ]] || return 1
  done
  [[ -f "$path" ]] || return 1
  printf '%s\n' "$path"
}
