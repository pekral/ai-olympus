#!/usr/bin/env bash
# Parse the decisions of a trusted review record outside quotes and code. Read only.
# shellcheck source=cr-header.sh
. "${BASH_SOURCE[0]%/*}/cr-header.sh"

known_record_read() {
  local line digest='' header=1 shape='^\*\*Review decisions:\*\* ([a-f0-9]{64})$'
  cr_header_fence_reset
  while IFS= read -r line || [[ -n "$line" ]]; do
    cr_header_fence_step "$line" && continue
    [[ -z "$CR_HTML_END" ]] || continue
    case "$line" in
      '## '*) header=0 ;;
      '**Review decisions:**'*)
        [[ "$header" -eq 1 && "$CR_PREV_TEXT" -ne 2 && -z "$digest" && "$line" =~ $shape ]] || return 1
        digest="${BASH_REMATCH[1]}"
        ;;
    esac
  done <"$1"
  printf '%s\n' "$digest"
}

known_record_rows() {
  local line section='' n='[1-9][0-9]{0,2}'
  local tracked="^$n\\. \\*\\*(High|Moderate|Low)\\*\\* — Already on the default branch\\. Fix tracked in (https://[^[:space:]]+)$"

  local regular="^[[:blank:]]{1,4}\\*\\*Linked issue:\\*\\* (https://[^[:space:]]+)$"
  local owned="^$n\\. \\*\\*Security point\\*\\* — \\*\\*Linked issue:\\*\\* (https://[^[:space:]]+)$"
  cr_header_fence_reset
  while IFS= read -r line || [[ -n "$line" ]]; do
    cr_header_fence_step "$line" && continue
    [[ -z "$CR_HTML_END" ]] || continue
    case "$line" in
      '## Known points') section=known; continue ;;
      '## Tracked security gaps') section=tracked; continue ;;
      '## '*) section=''; continue ;;
    esac
    if [[ "$section" == known ]]; then
      if [[ "$line" =~ $owned ]]; then
        printf 'owned-security\t%s\n' "${BASH_REMATCH[1]}"
      elif [[ "$line" =~ $regular ]]; then
        printf 'linked\t%s\n' "${BASH_REMATCH[1]}"
      fi
    elif [[ "$section" == tracked && "$line" =~ $tracked ]]; then
      printf 'tracked-old:%s\t%s\n' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}"
    fi
  done <"$1"
}

known_record_rows_match() {
  local observed expected
  observed="$(known_record_rows "$1" | LC_ALL=C sort)" || return 1
  expected="$(jq -r '(.known_points // [])[] | [(if .category == "tracked-old" then .category + ":" +
    (if .marked.severity == "high" then "High" elif .marked.severity == "medium" then "Moderate" else "Low" end) else .category end), .url] | @tsv' "$2" | LC_ALL=C sort)" || return 1
  [[ "$observed" == "$expected" ]]
}
