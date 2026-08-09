#!/usr/bin/env bash
# select-candidates.sh — take the batch's candidate issues highest-priority first.
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
#   COUNT        how many candidates to take; default 5.
#   LABEL        auto-resolve label; default Resolve_by_AI.
#   CLAIM_LABEL  in-progress claim label; default Resolve_by_AI:in-progress.
#   EXCLUDE      comma-separated issue numbers already covered by an adopted
#                PR (from inventory-open-prs.sh); default none.
#
# Selection is PRIORITY-DRIVEN and deterministic, not random: eligible issues
# are ordered by their `priority:P0`…`priority:P3` label (P0 first), and issues
# sharing a priority are ordered oldest `createdAt` first. The earlier random
# draw (`sort -R`) is deliberately gone — a batch that samples the backlog
# uniformly will start a P3 docs tidy-up while a P0 credential leak waits, which
# is exactly what the priority labels exist to prevent.
#
# An issue carrying NO priority label sorts as P2, because `priority:P2` is the
# declared default level. It therefore neither jumps ahead of triaged work nor
# sinks below it — an untriaged issue stays reachable instead of starving behind
# every labeled one, while never outranking an explicit P0/P1.
#
# Fewer eligible issues than COUNT yields a shorter list; the pool is never
# widened to unlabeled issues to reach the number.
#
# Prints a JSON array on stdout, one object per selected candidate, already in
# execution order:
#   [{"number":7,"url":"…","title":"…","createdAt":"…","priority":"P0"}]
#
# `priority` is the resolved label ("P0".."P3") or null when the issue carries
# none — reported so the batch plan can state why each task holds its position
# rather than asserting an order the caller cannot verify.
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

# 500 bounds the pool, matching inventory-open-prs.sh. Beyond that the ordering
# is over the 500 issues gh returned rather than the whole backlog.
#
# The priority rank is read from the FIRST `priority:p<n>` label found. Two
# priority labels on one issue is a triage mistake, not a case worth encoding a
# tie-break for; taking the first keeps the rank total and deterministic instead
# of failing the whole run over one mislabeled issue.
#
# `sort_by` is a stable total order over [rank, createdAt], so the output is
# fully deterministic — the same backlog yields the same batch every run, which
# is what makes a deferred task predictably reachable on the next one.
gh issue list --label "$LABEL" --state open --limit 500 \
  --json number,url,title,createdAt,labels |
  jq --arg claim "$CLAIM_LABEL" --argjson exclude "$EXCLUDE_JSON" --argjson n "$COUNT" "$JQ_CLEAN"'
    def priority_label:
      [ .labels[]?.name // ""
        | ascii_downcase
        | select(test("^priority:p[0-3]$"))
      ] | first // null;

    ($claim | ascii_downcase) as $claimed
    | [ .[]
        | select(any(.labels[]?; (.name // "" | ascii_downcase) == $claimed) | not)
        | select(.number as $num | $exclude | index($num) | not)
        | priority_label as $p
        | {
            number,
            url,
            title: (.title // "" | clean),
            createdAt,
            priority: (if $p == null then null else ($p | ltrimstr("priority:") | ascii_upcase) end),
            rank: (if $p == null then 2 else ($p | ltrimstr("priority:p") | tonumber) end),
          }
      ]
    | sort_by([.rank, .createdAt])
    | .[:$n]
    | map(del(.rank))'
