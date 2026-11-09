#!/usr/bin/env bash
# state-path.sh — shared library: where a helper may write its state file.
#
# Sourced, not executed, by `review-state.sh` and `quiet-run-check.sh`. Both write a scratch state file for one
# run, and both must refuse the same paths, so the guard exists here once.
#
# state_path_problem <file> [create]
#   Prints the problem on stdout and returns 1 when the helper must not write <file>. Prints nothing and returns 0
#   when the path is allowed. The caller decides the exit code; this function never exits.
#   A state path is refused when it is a symbolic link, when it is outside `.claude/run/` and `.codex/run/` of the
#   git toplevel, or when git tracks it.
#   Without `create` the state directory must exist. With `create` the directory may be missing, because the caller
#   is about to create it: the nearest existing directory above it is checked instead, and a path with a `..`
#   component is refused, so a missing directory can never lead out of the run directory.

state_path_display() {
  printf '%s' "$1" | LC_ALL=C tr -cd '[:print:]' | cut -c1-160
}

state_path_problem() {
  local orig="$1" file="$1" create="${2:-}" dir probe real rest intended top

  if [[ -L "$file" ]]; then
    printf 'the state file is a symbolic link: %s' "$(state_path_display "$orig")"
    return 1
  fi

  case "$file" in
  /*) ;;
  *) file="$PWD/$file" ;;
  esac
  dir="$(dirname -- "$file")"
  probe="$dir"
  if [[ "$create" == create ]]; then
    case "$file/" in
    */../*)
      printf 'the state path holds a ".." component: %s' "$(state_path_display "$orig")"
      return 1
      ;;
    esac
    while [[ ! -d "$probe" ]]; do
      probe="$(dirname -- "$probe")"
    done
  fi
  if [[ ! -d "$probe" ]]; then
    printf 'the state directory does not exist: %s' "$(state_path_display "$dir")"
    return 1
  fi

  real="$(cd -- "$probe" && pwd -P)"
  rest="${dir#"$probe"}"
  rest="${rest#/}"
  intended="${real%/}${rest:+/$rest}"
  if ! top="$(cd -- "$probe" && git rev-parse --show-toplevel 2>/dev/null)"; then
    printf 'the state file is not inside a git checkout'
    return 1
  fi
  top="$(cd -- "$top" && pwd -P)"
  case "$intended/" in
  "$top/.claude/run/"* | "$top/.codex/run/"*) ;;
  *)
    printf 'the state must live under .claude/run/ or .codex/run/ of the git toplevel, got: %s' "$(state_path_display "$orig")"
    return 1
    ;;
  esac
  if git -C "$top" ls-files --error-unmatch -- "$intended/$(basename -- "$file")" >/dev/null 2>&1; then
    printf 'the state file is tracked by git: %s' "$(state_path_display "$orig")"
    return 1
  fi
  return 0
}
