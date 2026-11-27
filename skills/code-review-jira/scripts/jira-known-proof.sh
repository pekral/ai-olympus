#!/usr/bin/env bash
# Read-only REST projection for the canonical JIRA loader's review-decision mode.
# The API credential goes only to the acli profile's verified site. Keep it out of argv and output.

jira_known_get() {
  local auth="$1" site="$2" path="$3"
  printf 'header = "Authorization: Basic %s"\n' "$auth" |
    curl -q --config - --silent --fail --proto '=https' --noproxy '*' --max-time 30 \
      --header 'Accept: application/json' "https://$site/rest/api/3/$path"
}

jira_known_view() (
  set +o xtrace +o verbose
  local key="$1" host="$2" configured="$3" acli_view="$4"
  local auth_status site email raw token basic myself actor issue id
  # shellcheck source=jira-actor.sh
  . "$SCRIPT_DIR/jira-actor.sh"
  auth_status="$(acli jira auth status 2>/dev/null)" || return 1
  site="$(jira_auth_status_field "$auth_status" site | LC_ALL=C tr '[:upper:]' '[:lower:]')"
  site="${site#https://}"
  site="${site%/}"
  [[ "$site" =~ ^[a-z0-9][a-z0-9-]*\.atlassian\.net$ && "$site" == "$host" && "$site" == "$configured" ]] || return 1

  email="$(jira_auth_status_field "$auth_status" email | LC_ALL=C tr '[:upper:]' '[:lower:]')"
  [[ -n "$email" ]] || return 1
  raw="${JIRA_API_TOKEN:-}"
  if [[ -z "$raw" ]]; then
    [[ -r "${HOME}/.config/acli/jira_api_token" ]] || return 1
    raw="$(head -n1 "${HOME}/.config/acli/jira_api_token")" || return 1
  fi
  token="$raw"
  if [[ "$raw" == *:* ]]; then
    [[ "$(printf '%s' "${raw%%:*}" | LC_ALL=C tr '[:upper:]' '[:lower:]')" == "$email" ]] || return 1
    token="${raw#*:}"
  elif [[ -n "${JIRA_API_EMAIL:-}" ]]; then
    [[ "$(printf '%s' "$JIRA_API_EMAIL" | LC_ALL=C tr '[:upper:]' '[:lower:]')" == "$email" ]] || return 1
  fi
  [[ -n "$token" ]] || return 1
  basic="$(printf '%s:%s' "$email" "$token" | base64 | tr -d '\n')" || return 1
  myself="$(jira_known_get "$basic" "$site" myself)" || return 1
  actor="$(jq -er --arg email "$email" 'select(.active == true and .accountType == "atlassian"
    and ((.emailAddress // "") | ascii_downcase) == $email) | .accountId | select(type == "string" and length > 0)' <<<"$myself")" || return 1

  id="$(jq -er --arg key "$key" 'select(.key == $key) | .id | select(type == "string" and test("^[1-9][0-9]*$"))' <<<"$acli_view")" || return 1
  issue="$(jira_known_get "$basic" "$site" "issue/$id?fields=*all&expand=changelog")" || return 1
  jq -ce --arg site "$site" --arg key "$key" --arg id "$id" --arg actor "$actor" '
    select(.key == $key and .id == $id and .self == "https://\($site)/rest/api/3/issue/\($id)")
    | select(.fields | has("description") and has("resolution") and has("assignee")
        and (.subtasks | type) == "array" and (.issuelinks | type) == "array"
        and (.status.statusCategory.key | type) == "string"
        and (.updated | type) == "string")
    | select(.changelog | .startAt == 0 and (.total | type) == "number"
        and (.histories | type) == "array" and .total == (.histories | length)
        and .total == ([.histories[].id] | unique | length)
        and all(.histories[]; (.id | type) == "string"
          and (.author.accountId | type) == "string" and (.items | type) == "array"
          and all(.items[]; ((.fieldId // .field) | type) == "string")))
    | . + {knownSite: $site, knownActor: $actor}' <<<"$issue"
)
