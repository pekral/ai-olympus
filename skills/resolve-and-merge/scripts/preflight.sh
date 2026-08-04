#!/usr/bin/env bash
# preflight.sh — prove the checkout is safe to run a merge batch on.
#
# Why this exists
#   The batch merges into the default branch, once per task, unattended. Every
#   precondition it depends on is cheap to check and expensive to discover
#   halfway through: an expired `gh` session strands a task after its branch
#   exists, and a dirty working tree gets swept into the first task's commit —
#   an unrelated local edit shipped to the default branch under a task's name.
#
# Usage (executed by the agent, not read)
#   scripts/preflight.sh
#
# Prints one JSON object on stdout:
#   {"repo":"owner/name","base":"master","branch":"master","clean":true}
#
# Exit codes: 0 ready · 1 usage · 2 missing tool · 3 not authenticated / no
# GitHub repo · 4 working tree is dirty (see `clean:false` in the payload)
set -euo pipefail
# shellcheck source=_lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_lib.sh"

[[ $# -eq 0 ]] || die 1 "takes no arguments"

require_tools gh git jq
require_github_repo

REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner)"
BASE="$(gh repo view --json defaultBranchRef -q .defaultBranchRef.name)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"

# --porcelain lists tracked modifications and untracked files alike; both would
# ride along into a task's commit, so both count as dirty here.
CLEAN=true
[[ -z "$(git status --porcelain)" ]] || CLEAN=false

jq -n --arg repo "$REPO" --arg base "$BASE" --arg branch "$BRANCH" --argjson clean "$CLEAN" \
  '{repo: $repo, base: $base, branch: $branch, clean: $clean}'

[[ "$CLEAN" == true ]] || die 4 "working tree is dirty — commit or stash before starting a batch"
