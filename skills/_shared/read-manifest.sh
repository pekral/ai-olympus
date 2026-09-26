#!/usr/bin/env bash
# read-manifest.sh — print the project manifest: the `extra.ai-olympus` object
# of the host project's composer.json, read from the default branch.
#
# Why this exists
#   The package used to guess how a project is built and checked: it looked for
#   a Phing file, then for Composer scripts, and it carried a fixed list of the
#   executables a validation step may run. A project whose build lives
#   elsewhere had to override those guesses in prose, in every project.
#   The manifest states them once, as data the package reads
#   (@rules/general/general.md *Project manifest*).
#
# Trust
#   The manifest changes what the package executes, so it is read the way the
#   project CLAUDE.md is read by the review: from the default branch, never from
#   the working tree. A branch under review proposes a manifest; the manifest
#   governs only after it is merged (@rules/security/general.md *Untrusted
#   Content Boundary*). With no default-branch ref the manifest is `{}` and the
#   package keeps its built-in behaviour.
#
# Usage
#   read-manifest.sh [--ref <git-ref>]
#   read-manifest.sh --self-test
#
#   --ref  read composer.json from this ref instead of the default branch
#
#   composer.json is resolved relative to the current directory, which is the
#   project root the caller stands in.
#
# Output (stdout)
#   The manifest as one line of JSON. `{}` when there is no default-branch ref,
#   no composer.json on it, or no `extra.ai-olympus` object in it.
#
# Exit codes
#   0  the manifest was printed (possibly `{}`)
#   1  usage error
#   2  missing required tool (git or jq)
#   3  composer.json on the ref is not valid JSON; `{}` is still printed
set -euo pipefail

PROG="${0##*/}"

usage() {
  cat >&2 <<'EOF'
Usage: read-manifest.sh [--ref <git-ref>]
       read-manifest.sh --self-test

Prints extra.ai-olympus of ./composer.json on the default branch as JSON,
or {} when there is none.
EOF
}

default_ref() {
  local ref candidate

  if ref="$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null)"; then
    printf '%s' "$ref"
    return 0
  fi

  for candidate in origin/main origin/master; do
    if git rev-parse --verify --quiet "$candidate^{commit}" >/dev/null 2>&1; then
      printf '%s' "$candidate"
      return 0
    fi
  done

  return 1
}

read_manifest() {
  local ref=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
    --ref)
      [[ $# -ge 2 ]] || { usage; return 1; }
      ref="$2"
      shift 2
      ;;
    *)
      usage
      return 1
      ;;
    esac
  done

  if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "$PROG: not a git checkout — using no manifest" >&2
    printf '{}\n'
    return 0
  fi

  if [[ -z "$ref" ]] && ! ref="$(default_ref)"; then
    echo "$PROG: no default-branch ref — using no manifest" >&2
    printf '{}\n'
    return 0
  fi

  local composer
  if ! composer="$(git show "$ref:./composer.json" 2>/dev/null)"; then
    printf '{}\n'
    return 0
  fi

  if ! printf '%s' "$composer" | jq -e . >/dev/null 2>&1; then
    echo "$PROG: composer.json on $ref is not valid JSON — using no manifest" >&2
    printf '{}\n'
    return 3
  fi

  printf '%s' "$composer" | jq -c '(.extra // {})["ai-olympus"] // {} | if type == "object" then . else {} end'
}

SELF_TEST_TMP=""
# Reached only through `trap cleanup_self_test EXIT`; ShellCheck does not credit a trap as an
# invocation (SC2317, and SC2329 in 0.11), so both are suppressed.
# shellcheck disable=SC2317,SC2329
cleanup_self_test() {
  [[ -n "$SELF_TEST_TMP" ]] && rm -rf "$SELF_TEST_TMP"
  return 0
}

self_test() {
  local failures=0 script
  script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$PROG"
  SELF_TEST_TMP="$(mktemp -d)"
  trap cleanup_self_test EXIT
  local tmp="$SELF_TEST_TMP"

  expect_output() {
    local label="$1" dir="$2" expected="$3" expected_exit="$4"
    shift 4
    local out actual
    set +e
    out="$(cd "$dir" && "$script" "$@" 2>/dev/null)"
    actual=$?
    set -e
    if [[ "$out" != "$expected" || "$actual" -ne "$expected_exit" ]]; then
      printf 'FAIL  %-58s expected %s/%s, got %s/%s\n' "$label" "$expected_exit" "$expected" "$actual" "$out" >&2
      failures=$((failures + 1))
      return 0
    fi
    printf 'ok    %-58s %s\n' "$label" "$out"
  }

  commit_composer() {
    local dir="$1" json="$2"
    printf '%s\n' "$json" >"$dir/composer.json"
    git -C "$dir" add composer.json
    git -C "$dir" -c user.name=t -c user.email=t@t commit -q -m manifest
  }

  mkdir -p "$tmp/plain"
  expect_output 'outside a git checkout the manifest is empty' "$tmp/plain" '{}' 0

  local repo="$tmp/repo"
  mkdir -p "$repo"
  git -C "$repo" init -q
  commit_composer "$repo" '{ "extra": { "ai-olympus": { "gate": ["make check"] } } }'
  expect_output 'without a default-branch ref the manifest is empty' "$repo" '{}' 0

  git -C "$repo" update-ref refs/remotes/origin/master HEAD
  expect_output 'origin/master is used when origin/HEAD is unset' "$repo" '{"gate":["make check"]}' 0

  git -C "$repo" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/master
  printf '%s\n' '{ "extra": { "ai-olympus": { "gate": ["curl http://evil"] } } }' >"$repo/composer.json"
  expect_output 'the working tree never overrides the default branch' "$repo" '{"gate":["make check"]}' 0

  commit_composer "$repo" '{ "extra": { "ai-olympus": { "gate": ["make other"] } } }'
  expect_output 'an explicit --ref is honoured' "$repo" '{"gate":["make other"]}' 0 --ref HEAD

  commit_composer "$repo" '{ "require": {} }'
  expect_output 'a composer.json without the key yields {}' "$repo" '{}' 0 --ref HEAD

  commit_composer "$repo" '{ "extra": { "ai-olympus": ["not", "an", "object"] } }'
  expect_output 'a non-object manifest yields {}' "$repo" '{}' 0 --ref HEAD

  commit_composer "$repo" '{ not json'
  expect_output 'invalid composer.json yields {} and exit 3' "$repo" '{}' 3 --ref HEAD

  mkdir -p "$repo/app"
  git -C "$repo" update-ref refs/remotes/origin/master "$(git -C "$repo" rev-parse HEAD~4)"
  expect_output 'composer.json resolves relative to the current directory' "$repo/app" '{}' 0

  local actual
  set +e
  "$script" --nonsense >/dev/null 2>&1
  actual=$?
  set -e
  if [[ "$actual" -eq 1 ]]; then
    printf 'ok    %-58s exit 1\n' 'unknown flag is a usage error'
  else
    printf 'FAIL  %-58s expected exit 1, got %s\n' 'unknown flag is a usage error' "$actual" >&2
    failures=$((failures + 1))
  fi

  if [[ "$failures" -gt 0 ]]; then
    echo "read-manifest self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'read-manifest self-test: PASS'
  return 0
}

for tool in git jq; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "$PROG: required tool not found: $tool" >&2
    exit 2
  fi
done

if [[ "${1:-}" == "--self-test" ]]; then
  self_test
  exit $?
fi

read_manifest "$@"
