#!/usr/bin/env bash
# select-candidates.sh — draw the batch's candidate issues at random.
#
# Why this exists
#   Three filters have to hold at once — carries the label, does not carry the
#   claim label, is not already covered by an adopted pull request — and each
#   one has a concrete failure behind it: an unlabeled issue is work nobody
#   asked for, a claimed issue is another run's task picked up a second time,
#   and an already-covered issue duplicates the pull request that exists. Doing
#   this by hand per run is where one of the three quietly goes missing.
#
# Usage (executed by the agent, not read)
#   scripts/select-candidates.sh [COUNT] [LABEL] [CLAIM_LABEL] [EXCLUDE]
#
#   COUNT        how many candidates to draw; default 5.
#   LABEL        auto-resolve label; default Resolve_by_AI.
#   CLAIM_LABEL  in-progress claim label; default Resolve_by_AI:in-progress.
#   EXCLUDE      comma-separated issue numbers already covered by an adopted
#                PR (from inventory-open-prs.sh); default none.
#
# Selection is RANDOM, not oldest-first, drawn with `sort -R` — `shuf` is not
# installed on macOS. Fewer eligible issues than COUNT yields a shorter list;
# the pool is never widened to unlabeled issues to reach the number.
#
# Prints a JSON array on stdout, one object per drawn candidate:
#   [{"number":7,"url":"…","title":"…","createdAt":"…"}]
#
# Read-only: one `gh issue list` read. See _lib.sh for the full safety
# contract and the shared exit codes.
#
# Exit codes: 0 · 1 usage · 2 missing tool · 3 not authenticated / no GitHub repo
set -euo pipefail
# shellcheck source=_lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_lib.sh"

COUNT="${1:-5}"
LABEL="${2:-Resolve_by_AI}"
CLAIM_LABEL="${3:-Resolve_by_AI:in-progress}"
EXCLUDE="${4:-}"

[[ $# -le 4 ]] || die 1 "usage: $PROG [COUNT] [LABEL] [CLAIM_LABEL] [EXCLUDE]"
assert_count "$COUNT" "COUNT"
[[ -n "$LABEL" ]] || die 1 "LABEL must not be empty"
[[ -n "$CLAIM_LABEL" ]] || die 1 "CLAIM_LABEL must not be empty"

# Validated rather than trusted: EXCLUDE is the only argument assembled from a
# previous script's output, so a malformed value means the pipeline went wrong
# somewhere upstream and the run must stop rather than silently select from an
# unfiltered pool.
EXCLUDE_JSON='[]'
if [[ -n "$EXCLUDE" ]]; then
  [[ "$EXCLUDE" =~ ^[0-9]+(,[0-9]+)*$ ]] || die 1 "EXCLUDE must be comma-separated issue numbers, got: $EXCLUDE"
  EXCLUDE_JSON="[${EXCLUDE}]"
fi

require_tools gh jq
require_github_repo

# 500 bounds the pool the draw samples from, matching inventory-open-prs.sh.
# Beyond that the draw is over the 500 issues gh returned rather than the whole
# backlog — still a valid random batch, just a narrower urn.
ELIGIBLE="$(gh issue list --label "$LABEL" --state open --limit 500 \
  --json number,url,title,createdAt,labels |
  jq --arg claim "$CLAIM_LABEL" --argjson exclude "$EXCLUDE_JSON" "$JQ_CLEAN"'
    ($claim | ascii_downcase) as $claimed
    | [.[]
        | select(any(.labels[]?; (.name // "" | ascii_downcase) == $claimed) | not)
        | select(.number as $n | $exclude | index($n) | not)
        | {number, url, title: (.title // "" | clean), createdAt}
      ]')"

# Shuffle over the compact one-line-per-issue form, then reassemble. Keeping
# each object on its own line is what makes `sort -R` a line shuffle rather
# than a corrupting sort of pretty-printed JSON.
#
# The slice happens in jq, not in `head`: under `set -o pipefail`, a `head -n`
# that closes the pipe early kills `sort` with SIGPIPE and the whole script
# exits 141 — which fires precisely when there are MORE eligible issues than
# slots, the common case.
printf '%s' "$ELIGIBLE" | jq -c '.[]' | sort -R | jq -s --argjson n "$COUNT" '.[:$n]'
