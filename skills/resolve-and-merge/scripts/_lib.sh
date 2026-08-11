#!/usr/bin/env bash
# _lib.sh — shared guards for the resolve-and-merge scripts.
#
# Sourced, never executed directly.
#
# Safety contract every script in this directory inherits
#   READ-ONLY. These scripts run `gh` and `git` reads only. They never write to
#   the tracker (no `gh issue edit`, no `gh pr comment`, no label write), never
#   merge, never touch the working tree, and never create a temporary file. The
#   batch this skill drives is destructive by nature — it merges into the
#   default branch — so the deterministic parts of it are the ones that must be
#   provably incapable of causing the damage. Selection, inventory, and
#   preflight are those parts; every write stays in the delegated skills that
#   own it and can be reasoned about individually.
#
#   That contract binds every path the skill executes. A script's own
#   `--self-test` flag is the one exception and is not such a path: it runs
#   offline with `gh` stubbed, is invoked by `composer build` and never by the
#   skill, and creates a private temporary directory (removed on exit) to hold
#   that stub and its fixtures.
#
#   NO SHELL INTERPOLATION INTO A FILTER. Every caller-supplied value reaches
#   `jq` through `--arg`, never through string concatenation into the filter
#   body. A label is arbitrary user-authored text — it may contain quotes,
#   backslashes, or an emoji — and concatenating it into a filter is both an
#   injection vector and a needless restriction on which labels work.
#
#   UNTRUSTED TEXT IS SANITIZED BEFORE IT IS PRINTED. Issue and PR titles are
#   written by anyone who can open one, and they land in a report a human reads
#   to decide what gets merged. Control characters, ANSI escapes, and bidi
#   overrides are stripped so a title cannot rewrite or reverse the line that
#   reports it (the persisted-Trojan-Source shape, CVE-2021-42574).
#
# Exit codes shared by every script here:
#   0  success
#   1  usage error (missing or malformed argument)
#   2  missing required tool (gh, git, or jq)
#   3  not authenticated, or the checkout has no GitHub repository
set -euo pipefail

PROG="${0##*/}"

die() {
  local code="$1"
  shift
  printf '%s: %s\n' "$PROG" "$*" >&2
  exit "$code"
}

require_tools() {
  local tool
  for tool in "$@"; do
    command -v "$tool" >/dev/null 2>&1 || die 2 "required tool not found: $tool"
  done
}

# Confirm an authenticated gh session against a GitHub repository reachable
# from this checkout. `gh repo view` needs both, so one call answers both
# questions — and it is a read.
require_github_repo() {
  gh auth status >/dev/null 2>&1 || die 3 "gh is not authenticated — run: gh auth login"
  gh repo view --json nameWithOwner >/dev/null 2>&1 ||
    die 3 "no GitHub repository resolves from this checkout"
}

# A positive integer within a sane bound. Used for counts and issue numbers,
# both of which reach `head -n` or a comparison where a negative or huge value
# is never meaningful.
assert_count() {
  [[ "$1" =~ ^[0-9]{1,4}$ && "$1" -ge 1 ]] || die 1 "$2 must be an integer between 1 and 9999, got: $1"
}

# jq definition that strips what must never survive into a printed report:
# C0/C1 controls, zero-width and directional-formatting characters, and the
# bidi overrides and isolates. Applied to every title these scripts emit.
#
# Codepoint filtering, not a regex character class. The `\uXXXX` range form is
# read by jq's own string escaping before the regex engine ever sees it, and
# the resulting class silently matched ordinary letters — every ASCII letter
# was stripped out of the titles. `explode` works on codepoints directly, so
# each boundary below means exactly what it says and accented characters
# (U+00E8 and up) are never in range.
# Consumed by the scripts that source this library (`inventory-open-prs.sh`, `select-candidates.sh`),
# which ShellCheck cannot see from the defining file — a library variable is never "unused" here.
# shellcheck disable=SC2034
JQ_CLEAN='def clean: explode | map(select(
    . > 31                          # C0 controls
    and . != 127                    # DEL
    and (. < 128 or . > 159)        # C1 controls
    and (. < 8203 or . > 8207)      # U+200B-200F zero-width, LRM/RLM
    and (. < 8232 or . > 8238)      # U+2028-202E separators, bidi overrides
    and (. < 8294 or . > 8297)      # U+2066-2069 bidi isolates
  )) | implode;'
