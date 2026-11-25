#!/usr/bin/env bash
# operator-lines.sh — sourced by the review-merge helpers; defines functions only.
#
# One definition of "a line the operator added to a review comment", so the plan of skills/merge-review-comments and
# the two `--review-merge` delete helpers can never disagree on it. skills/_shared/carry-operator-lines.php owns the
# fingerprint: a unit of the comment whose fingerprint its own `cr-comment` line record does not know was added by a
# person. A comment without a line record reports no unit, exactly as the carry does.
#
#   operator_lines <markdown|adf> <comment-file> [<merged-file>]
#       Print, one per line, each unit the operator added to the review comment in <comment-file>: a line of a GitHub
#       Markdown body, or the text of a top-level node of a JIRA ADF document (of each child of a collapsed `expand`
#       section). With <merged-file>, a body in the same
#       format, print only the units that the merged body does not carry verbatim. Returns non-zero when php fails.

operator_lines() {
  local format="$1" comment="$2" merged="${3:-}" empty="" report rc=0
  if [[ -z "$merged" ]]; then
    empty="$(mktemp)"
    if [[ "$format" == "adf" ]]; then
      printf '%s' '{"type":"doc","version":1,"content":[]}' >"$empty"
    fi
    merged="$empty"
  fi
  report="$(php "$(dirname "${BASH_SOURCE[0]}")/carry-operator-lines.php" "$format" cr-comment "$merged" "$comment" 2>&1 >/dev/null)" || rc=$?
  [[ -z "$empty" ]] || rm -f "$empty"
  [[ "$rc" -eq 0 ]] || return "$rc"
  sed -n 's/^carried: //p' <<<"$report"
}
