#!/usr/bin/env bash
# inventory-open-prs.sh — classify every open pull request as adopt or ignore.
#
# Why this exists
#   The batch finishes in-flight work before it opens new work, and the
#   adopt/ignore decision is the one place where a wrong guess touches somebody
#   else's pull request. The rule is therefore mechanical rather than
#   judgemental: a PR is adopted only on positive evidence of the label — on
#   the PR itself, or on an issue it closes — and everything else is ignored
#   and left untouched. A PR whose linked issue cannot be resolved is ignored,
#   never adopted on a guess.
#
# Usage (executed by the agent, not read)
#   scripts/inventory-open-prs.sh [LABEL] [LIMIT]
#
#   LABEL   auto-resolve label; default Resolve_by_AI. Matched
#           case-insensitively, the way GitHub itself treats label names.
#   LIMIT   how many open PRs to inspect; default 100.
#
# Prints one JSON object on stdout:
#   {"adopt":[{"pr":12,"url":"…","title":"…","isDraft":false,"issues":[7]}],
#    "ignore":[{"pr":13,"url":"…","title":"…","reason":"not linked to a … issue"}]}
#
# Read-only: two `gh` list reads, nothing else. See _lib.sh for the full
# safety contract and the shared exit codes.
#
# Exit codes: 0 · 1 usage · 2 missing tool · 3 not authenticated / no GitHub repo
set -euo pipefail
# shellcheck source=_lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_lib.sh"

LABEL="${1:-Resolve_by_AI}"
LIMIT="${2:-100}"

[[ $# -le 2 ]] || die 1 "usage: $PROG [LABEL] [LIMIT]"
[[ -n "$LABEL" ]] || die 1 "LABEL must not be empty"
assert_count "$LIMIT" "LIMIT"

require_tools gh jq
require_github_repo

# The set of issue numbers carrying the label, resolved in ONE read. The
# alternative — `gh issue view` per linked issue — costs a request per PR and
# rate-limits a large backlog for no added accuracy.
#
# 500 is a ceiling on the auto-resolve backlog, not on the repository: a
# project holding more than 500 open labelled issues at once has a triage
# problem this script cannot fix, and the value is stated here rather than
# left as a bare number. It bounds only which PRs are recognised as adopted;
# an unrecognised PR is IGNORED, which is the safe direction — it is left
# untouched rather than acted on.
LABELLED_ISSUES="$(gh issue list --label "$LABEL" --state open --limit 500 --json number)"

OPEN_PRS="$(gh pr list --state open --limit "$LIMIT" \
  --json number,url,title,isDraft,labels,closingIssuesReferences)"

jq -n \
  --argjson prs "$OPEN_PRS" \
  --argjson labelled "$LABELLED_ISSUES" \
  --arg label "$LABEL" \
  "$JQ_CLEAN"'
  ($label | ascii_downcase) as $want
  | ([$labelled[].number]) as $labelled_numbers
  | [$prs[]
      | . as $pr
      # Positive evidence, in both places it can legitimately live.
      | (any($pr.labels[]?; (.name // "" | ascii_downcase) == $want)) as $pr_carries
      | ([$pr.closingIssuesReferences[]?.number]
          | map(select(. as $n | $labelled_numbers | index($n)))) as $linked
      | {
          pr: $pr.number,
          url: $pr.url,
          title: ($pr.title // "" | clean),
          isDraft: $pr.isDraft,
          issues: $linked,
          adopt: ($pr_carries or (($linked | length) > 0))
        }
    ] as $classified
  | {
      adopt: [$classified[] | select(.adopt) | del(.adopt)],
      ignore: [$classified[] | select(.adopt | not)
                | {pr, url, title, reason: ("not linked to a " + $label + " issue")}]
    }'
