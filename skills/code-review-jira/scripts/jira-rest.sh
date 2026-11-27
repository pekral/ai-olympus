#!/usr/bin/env bash
# Shared Jira attachment credentials. Only the attachment transport resolves these values.
jira_rest_resolve_auth() {
  local token_file="${1:-}" raw="" default
  default="${HOME}/.config/acli/jira_api_token"
  if [[ -z "$token_file" && -n "${JIRA_API_TOKEN:-}" ]]; then
    raw="$JIRA_API_TOKEN"
  else
    token_file="${token_file:-$default}"
    if [[ -e "$token_file" || -L "$token_file" ]]; then
      local reader
      reader="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/read-attachment-token.py"
      raw="$(python3 -B "$reader" "$token_file")" || return 2
    fi
  fi
  if [[ -z "$raw" ]]; then
    echo "${PROG:-jira-rest.sh}: no JIRA API token found." >&2
    echo "  Provide one via --token-file FILE, JIRA_API_TOKEN, or ~/.config/acli/jira_api_token (chmod 600)." >&2
    echo "  Create a token at https://id.atlassian.com/manage-profile/security/api-tokens" >&2
    return 2
  fi
  JIRA_REST_EMAIL="${JIRA_API_EMAIL:-}"
  JIRA_REST_TOKEN="$raw"
  # Accept the `email:token` convenience form from the token source.
  if [[ -z "$JIRA_REST_EMAIL" && "$raw" == *:* ]]; then
    JIRA_REST_EMAIL="${raw%%:*}"
    JIRA_REST_TOKEN="${raw#*:}"
  fi
  if [[ -z "$JIRA_REST_EMAIL" ]]; then
    echo "${PROG:-jira-rest.sh}: account email not set. Export JIRA_API_EMAIL or use an 'email:token' token file." >&2
    return 2
  fi
}

jira_rest_write_config() {
  local basic
  : >"$1"
  chmod 600 "$1"
  basic="$(printf '%s:%s' "$JIRA_REST_EMAIL" "$JIRA_REST_TOKEN" | base64 | tr -d '\n')"
  printf 'header = "Authorization: Basic %s"\n' "$basic" >"$1"
}
