#!/usr/bin/env bash
# jira-write-guards.sh — sourced by the JIRA write helpers; defines functions only.
#
# One definition of the checks every JIRA write helper runs before and after its write, so the
# transition helpers and the assign helper never carry copies that drift apart (the pattern of
# jira-actor.sh).
#
#   jira_extract_key <KEY|URL>
#       Print the JIRA key of a bare key, a /browse/<KEY> URL, or a URL that carries
#       ?selectedIssue=<KEY>. Prints nothing when the input carries no key.
#
#   jira_name_in_list <lower-cased name> <comma-separated list>
#       Exit 0 when the name equals one entry of the list, each entry trimmed and lower-cased.
#       Exit 1 otherwise, and for an empty list. The helpers read their synonym lists with it.
#
#   jira_issue_matches_jql <KEY> <JQL predicate>
#       Exit 0 when the search `key = <KEY> AND <predicate>` finds the issue, 1 when it does not,
#       and 3 when the search fails. It prints nothing, so a predicate that names a person never
#       reaches stdout or stderr. The search requests summary, not key: key is a top-level
#       attribute, and acli answers a search whose only requested field is key with [null], so a
#       matching issue would read as no match.

jira_extract_key() {
  local input="${1:-}"

  if [[ "$input" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ ]]; then
    printf '%s' "$input"
  elif [[ "$input" == *"/browse/"* ]]; then
    printf '%s' "$input" | sed -nE 's#.*/browse/([A-Z][A-Z0-9_]+-[0-9]+).*#\1#p'
  elif [[ "$input" == *"selectedIssue="* ]]; then
    printf '%s' "$input" | sed -nE 's#.*selectedIssue=([A-Z][A-Z0-9_]+-[0-9]+).*#\1#p'
  fi
}

jira_name_in_list() {
  local name="$1" list="$2" entry entry_trimmed
  local -a entries

  [[ -z "$list" ]] && return 1

  IFS=',' read -ra entries <<<"$list"
  for entry in "${entries[@]}"; do
    entry_trimmed="$(printf '%s' "$entry" | sed -E 's#^[[:space:]]+|[[:space:]]+$##g' | tr '[:upper:]' '[:lower:]')"
    if [[ -n "$entry_trimmed" && "$name" == "$entry_trimmed" ]]; then
      return 0
    fi
  done

  return 1
}

jira_issue_matches_jql() {
  local key="$1" predicate="$2" result

  if ! result="$(acli jira workitem search --jql "key = $key AND $predicate" --fields summary --limit 1 --json 2>/dev/null)"; then
    return 3
  fi

  printf '%s' "$result" | jq -e --arg key "$key" '[.. | objects | .key? // empty] | index($key) != null' >/dev/null
}
