#!/usr/bin/env bash
# Sourced by upsert-comment.sh after converting the body to ADF and resolving the authenticated actor.
# This byline classifies machine output. It never establishes ownership of a previous comment.
# A partial or unfamiliar envelope cannot prove a comment did not already land.
read_summary_comments() {
  local raw
  raw="$(acli jira workitem view "$KEY" --fields comment --json 2>"$LIST_STDERR")" || return 1
  jq -sce --arg site "${MEDIA_SITE:-}" --arg key "$KEY" '
      select(length == 1)
      | select($site == "" or (.[0] | .key == $key and (.id|tostring|test("^[1-9][0-9]*$"))
        and ((.self == ("https://" + $site + "/rest/api/3/issue/" + (.id|tostring)))
          or (.self == ("https://" + $site + "/rest/api/2/issue/" + (.id|tostring))))))
      | .[0].fields.comment as $page
      | select(($page | type) == "object" and ($page.comments | type) == "array"
          and ($page.total | type) == "number" and $page.startAt == 0
          and $page.total == ($page.comments | length)
          and ($page.comments | all((.id | tostring | test("^[1-9][0-9]*$"))
              and (.body.type == "doc" and .body.version == 1 and (.body.content | type) == "array")))
          and ($page.comments | map(.id | tostring) | unique | length) == $page.total)
      | $page.comments' <<<"$raw"
}

publish_summary() {
  # acli does not support a conditional update. The migration requires a quiet operator-approved window.
  local action=created id="" result="" rc=0 before="" matches="" attempt
  # These mktemp files are private to this invocation and removed by the entry point's EXIT trap.
  printf '%s' "$EMAIL" >"$SENT_TEXT_TMP"
  printf '%s' "$ACCOUNT_ID" >"$CARRIED_ADF_TMP"
  local -a identity=(-L "$SCRIPT_DIR/../../_shared" --rawfile email "$SENT_TEXT_TMP" --rawfile account "$CARRIED_ADF_TMP" --arg marker "$MARKER_TEXT")
  # jq owns the variables in its program.
  # shellcheck disable=SC2016
  local query='include "review-trust";
    def legacy_summary_marker:
      .body.content[-1] as $block | $block.type == "expand" and ($block.content | length) == 1
      and ($block.content[0] | .type == "paragraph" and (.content | length) == 1
        and (.content[0] | .type == "text" and .marks == [{"type":"em"}]
          and (.text | test("^" + $marker + "( lines=([0-9a-f]{8}(,[0-9a-f]{8})*)?)?\\z"))));
    def summary_author: ($account | length) > 0 and .author.accountId == $account
      and ((.author.emailAddress // "") as $address | $address == "" or $address == $email);
    def same_adf($adf): .body == $adf;
    def valid_id: (.id | tostring) | test("^[1-9][0-9]*$");
  '
  if [[ -z "$ACCOUNT_ID" ]]; then
    echo "upsert-comment.sh: cannot verify the authenticated account ID; no summary write" >&2
    return 3
  fi
  if [[ -n "$TARGET_COMMENT_ID" ]]; then
    action=updated
    id="$TARGET_COMMENT_ID"
    # Ordinary migration requires the original marker. Only an explicit operator edit can update an
    # attributed summary and it must match the exact original ADF, ID, and authenticated author.
    if ! read_summary_comments | jq -e "${identity[@]}" --arg id "$id" --argjson edit "$OPERATOR_EDIT" --slurpfile prior "$PREVIOUS_ADF" "$query"'
      select(($prior | length) == 1)
      | [.[] | select((.id | tostring) == $id and summary_author and same_adf($prior[0]))
          | select(if $edit == 1 then (.body.content[0] | .type == "paragraph"
                and (.content[0] | .type == "text" and (.text | jira_summary)))
              else legacy_summary_marker end)] | length == 1
    ' >/dev/null; then
      echo "upsert-comment.sh: the named comment, its author or its previously verified body could not be confirmed; no update" >&2
      return 3
    fi
    acli jira workitem comment update --key "$KEY" --id "$id" --body-adf "$ADF_FILE_TMP" >/dev/null 2>"$UPDATE_STDERR" || rc=$?
  else
    # A retry after an uncertain create must never duplicate the already stored body. No write on match.
    if ! before="$(jq -ce '[.[] | .id | tostring]' <<<"$COMMENTS_JSON")" ||
       ! jq -e -L "$SCRIPT_DIR" --slurpfile sent "$ADF_FILE_TMP" 'include "summary-content"; ($sent[0] | summary_content) as $source | all(.[]; (.body | summary_content) != $source)' <<<"$COMMENTS_JSON" >/dev/null; then
      echo "upsert-comment.sh: an identical or undecidable comment exists; inspect the ticket before retrying" >&2
      return 3
    fi
    if [[ -n "${MEDIA_SITE:-}" ]]; then
      local attempt_key digest ledger="${AUDIT_TRAIL_FILE:-}"
      [[ -n "$ledger" && -f "$ledger" && ! -L "$ledger" ]] || return 3
      digest="$(jq -cS -L "$SCRIPT_DIR" 'include "summary-content"; summary_content' "$ADF_FILE_TMP" | shasum -a 256 | cut -d' ' -f1)" || return 3
      attempt_key="jira media summary $SITE/$KEY $digest"
      # Reserve a single durable attempt even if two callers observed the same empty snapshot.
      if ! (
        mkdir "${ledger}.summary-intent.lock" || exit 1
        trap 'rmdir "${ledger}.summary-intent.lock"' EXIT
        local prior_rc=0
        grep -Fq "|$attempt_key|" "$ledger" || prior_rc=$?
        [[ "$prior_rc" == 1 ]] || exit 1
        bash "$AUDIT_TRAIL" record --file "$ledger" --target "$attempt_key" --status 3 --detail "create intent; no automatic retry"
      ); then
        echo "upsert-comment.sh: overlapping or previous media summary attempt requires reconciliation" >&2
        return 3
      fi
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
    matches="$(read_summary_comments | jq -rc "${identity[@]}" --arg id "$id" --argjson before "${before:-[]}" --slurpfile sent "$ADF_FILE_TMP" "$query"'
      [.[] | select(valid_id and summary_author and same_adf($sent[0])) | (.id | tostring) as $candidate
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
