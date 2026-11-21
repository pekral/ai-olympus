#!/usr/bin/env bash
# Resolve against this skill installation, even when the caller works in another repository.
resolve_command_helper() {
  local name="$1" shared root support component path
  [[ "$name" =~ ^[a-z][a-z0-9-]*[.]sh$ ]] || return 1
  # A symlink install links individual source files. Resolve this already-loaded library first.
  local origin="${BASH_SOURCE[0]}" target
  while [[ -L "$origin" ]]; do
    target="$(readlink "$origin")" || return 1
    case "$target" in
      /*) origin="$target" ;;
      *) origin="$(dirname -- "$origin")/$target" ;;
    esac
  done
  shared="$(cd -- "$(dirname -- "$origin")" && pwd -P)" || return 1
  case "$shared" in
    */.claude/skills/_shared) root="${shared%/.claude/skills/_shared}"; support=.claude/command-support/_shared ;;
    */.agents/skills/_shared) root="${shared%/.agents/skills/_shared}"; support=.codex/commands/_shared ;;
    */skills/_shared) root="${shared%/skills/_shared}"; support=commands/_shared ;;
    *) return 1 ;;
  esac
  # Global skills have their own private command distribution, outside command discovery.
  if [[ -n "${HOME:-}" && "$root" -ef "$HOME" ]]; then
    case "$shared" in
      */.claude/skills/_shared) [[ ! -d "$root/.claude/ai-olympus-commands" ]] || support=.claude/ai-olympus-commands/_shared ;;
      */.agents/skills/_shared) [[ ! -d "$root/.agents/ai-olympus-commands" ]] || support=.agents/ai-olympus-commands/_shared ;;
    esac
  fi
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
