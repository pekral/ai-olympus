#!/usr/bin/env bash
# The caller and its skill resources must come from the same distribution.
# Resolve relative to this library, independently of the operator's current checkout.
command_skill_root() {
  local shared root candidate
  shared="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)" || return 1
  case "$shared" in
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
