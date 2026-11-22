#!/usr/bin/env bash
# The caller and its skill resources must come from the same distribution.
# Resolve relative to this library, independently of the operator's current checkout.
command_skill_root() {
  local shared root candidate
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
    */.claude/ai-olympus-commands/_shared|*/.agents/ai-olympus-commands/_shared)
      candidate="${shared%/ai-olympus-commands/_shared}/skills"
      ;;
    */.claude/command-support/_shared)
      root="${shared%/.claude/command-support/_shared}"
      candidate="$root/.claude/skills"
      ;;
    */.codex/commands/_shared)
      root="${shared%/.codex/commands/_shared}"
      [[ ! -L "$root/.agents" ]] || return 1
      candidate="$root/.agents/skills"
      ;;
    */commands/_shared)
      root="${shared%/commands/_shared}"
      candidate="$root/skills"
      ;;
    *) return 1 ;;
  esac
  [[ -d "$candidate/_shared" && ! -L "$candidate" && ! -L "$candidate/_shared" ]] || return 1
  printf '%s\n' "$candidate"
}
