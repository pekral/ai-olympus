#!/usr/bin/env bash
# Sourced by upsert-comment.sh after converting the body to ADF and resolving the authenticated actor.
# This byline classifies machine output. It never establishes ownership of a previous comment.
publish_summary() {
  local action=created id="" result="" rc=0 before="" matches="" attempt
  local -a identity=(--arg email "$EMAIL" --arg account "$ACCOUNT_ID" --arg marker "$MARKER_TEXT")
  local query="$AUTHOR_JQ"'
    def same_adf($adf): .body == $adf;
    def valid_id: (.id | tostring) | test("^[1-9][0-9]*$");
  '
  if [[ -n "$TARGET_COMMENT_ID" ]]; then
    action=updated
    id="$TARGET_COMMENT_ID"
    # A previous read confirming this exact author's marker and body is essential. The caller supplies
    # the stored ADF from that read. Refuse manual edits and newly attributed, unmarked summaries.
    if ! read_comments | jq -e "${identity[@]}" --arg id "$id" --slurpfile prior "$PREVIOUS_ADF" "$query"'
      [.[] | select((.id | tostring) == $id and owned and marked and same_adf($prior[0]))] | length == 1
    ' >/dev/null; then
      echo "upsert-comment.sh: the marked comment or its previously verified body changed; no update" >&2
      return 3
    fi
    acli jira workitem comment update --key "$KEY" --id "$id" --body-adf "$ADF_FILE_TMP" >/dev/null 2>"$UPDATE_STDERR" || rc=$?
  else
    # A retry after an uncertain create must never duplicate the already stored body. No write on match.
    if ! before="$(jq -ce '[.[] | .id | tostring]' <<<"$COMMENTS_JSON")" ||
       ! jq -e --slurpfile sent "$ADF_FILE_TMP" '[.[] | select(.body == $sent[0])] | length == 0' <<<"$COMMENTS_JSON" >/dev/null; then
      echo "upsert-comment.sh: an identical or undecidable comment exists; inspect the ticket before retrying" >&2
      return 3
    fi
    result="$(acli jira workitem comment create --key "$KEY" --body-file "$ADF_FILE_TMP" --json 2>"$CREATE_STDERR")" || rc=$?
    # Restrict the response to documented comment ID envelopes. A nested user or issue ID identifies no comment.
    id="$(jq -r '(.comment.id? // .commentId? // .id? // empty) | tostring | select(test("^[1-9][0-9]*$"))' <<<"$result" 2>/dev/null || true)"
  fi
  bash "$AUDIT_TRAIL" record --target "jira summary ${id:-create} on $KEY" --status "$rc" --detail "$action; awaiting ADF readback" || true
  if [[ "$rc" != 0 ]]; then
    echo "upsert-comment.sh: uncertain Jira $action on $KEY; inspect the ticket before retrying" >&2
    return 3
  fi
  for attempt in 1 2 3; do
    matches="$(read_comments | jq -rc "${identity[@]}" --arg id "$id" --argjson before "${before:-[]}" --slurpfile sent "$ADF_FILE_TMP" "$query"'
      [.[] | select(valid_id and owned and same_adf($sent[0])) | (.id | tostring) as $candidate
       | select(($id == "" or $candidate == $id) and ($before | index($candidate)) == null) | $candidate]
      | if length == 1 then .[0] else empty end' 2>/dev/null || true)"
    if [[ "$matches" =~ ^[1-9][0-9]*$ ]]; then
      bash "$AUDIT_TRAIL" record --target "https://${SITE}/browse/${KEY}?focusedCommentId=${matches}" --status 0 --detail "$action, exact ADF verified" || true
      echo "https://${SITE}/browse/${KEY}?focusedCommentId=${matches}"
      echo "action=${action} id=${matches}" >&2
      return 0
    fi
    [[ "$attempt" == 3 ]] || sleep 1
  done
  echo "upsert-comment.sh: cannot confirm the stored $action summary on $KEY (ID ${id:-unknown}); inspect it before any retry" >&2
  return 3
}
