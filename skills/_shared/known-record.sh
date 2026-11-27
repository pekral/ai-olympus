#!/usr/bin/env bash
# Parse the decisions of a trusted review record outside quotes and code. Read only.
# shellcheck source=cr-header.sh
. "${BASH_SOURCE[0]%/*}/cr-header.sh"

known_record_read() {
  local line digest='' shape='^\*\*Review decisions:\*\* ([a-f0-9]{64})$'
  cr_header_fence_reset
  while IFS= read -r line || [[ -n "$line" ]]; do
    cr_header_fence_step "$line" && continue
    case "$line" in
      '**Review decisions:**'*)
        [[ -z "$digest" && "$line" =~ $shape ]] || return 1
        digest="${BASH_REMATCH[1]}"
        ;;
    esac
  done <"$1"
  printf '%s\n' "$digest"
}

known_record_rows() {
  local line section='' n='[1-9][0-9]{0,2}'
  local regular="^[[:blank:]]{1,4}\\*\\*Linked issue:\\*\\* (https://[^[:space:]]+)$"
  local owned="^$n\\. \\*\\*Security point\\*\\* — \\*\\*Linked issue:\\*\\* (https://[^[:space:]]+)$"
  cr_header_fence_reset
  while IFS= read -r line || [[ -n "$line" ]]; do
    cr_header_fence_step "$line" && continue
    case "$line" in
      '## Known points') section=known; continue ;;
      '## '*) section=''; continue ;;
    esac
    if [[ "$section" == known ]]; then
      if [[ "$line" =~ $owned ]]; then
        printf 'owned-security\t%s\n' "${BASH_REMATCH[1]}"
      elif [[ "$line" =~ $regular ]]; then
        printf 'linked\t%s\n' "${BASH_REMATCH[1]}"
      fi
    fi
  done <"$1"
}

known_record_rows_match() {
  local observed expected
  observed="$(known_record_rows "$1" | LC_ALL=C sort)" || return 1
  expected="$(jq -r '(.known_points // [])[] | [.category, .url] | @tsv' "$2" | LC_ALL=C sort)" || return 1
  [[ "$observed" == "$expected" ]]
}
