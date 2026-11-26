#!/usr/bin/env bash
# jira-rest.sh — sourced by the JIRA helpers that call the REST API with `curl`; defines functions only.
#
# One definition of the API token handling, so the helper that downloads attachments and the helpers that upload them
# never carry two copies. The token is never passed in argv and never printed: it is written only into a 0600 curl
# `--config` file. Without a usable token the caller exits with a setup hint — nothing falls back to an
# unauthenticated or silent attempt.
#
#   jira_rest_resolve_auth [<token-file>]
#       Resolves the token in this order (first hit wins): the token file (a token, or an `email:token` line),
#       env JIRA_API_TOKEN, ~/.config/acli/jira_api_token. The account e-mail is env JIRA_API_EMAIL, or the
#       `email:token` form. Sets JIRA_REST_EMAIL and JIRA_REST_TOKEN. Returns 2 and prints the hint on stderr (prefixed with
#       $PROG) when the token or the e-mail is missing.
#
#   jira_rest_write_config <file>
#       Writes the 0600 curl config with the Basic authorization header into <file>.
#
#   jira_rest_site_is_plain <site>
#       Returns 0 when <site> is a bare host name (letters, digits, dots, hyphens): the one host the token is ever sent to.
#
#   jira_rest_call <config> <outfile> <method> <url> [curl arguments...]
#       One request with `curl --config <config>`, https only, no redirect followed, 60 s at most. The response body goes
#       to <outfile>. Prints the HTTP status code. A redirect is therefore a status 3xx, never a second request to
#       another host.

jira_rest_resolve_auth() {
  local token_file="${1:-}" raw="" default
  default="${HOME}/.config/acli/jira_api_token"
  if [[ -n "$token_file" ]]; then
    [[ -r "$token_file" ]] || { echo "${PROG:-jira-rest.sh}: --token-file not readable: $token_file" >&2; return 2; }
    raw="$(head -n1 "$token_file")"
  elif [[ -n "${JIRA_API_TOKEN:-}" ]]; then
    raw="$JIRA_API_TOKEN"
  elif [[ -r "$default" ]]; then
    raw="$(head -n1 "$default")"
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

jira_rest_site_is_plain() {
  [[ "${1:-}" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]
}

jira_rest_call() {
  local config="$1" out="$2" method="$3" url="$4"
  shift 4
  curl --silent --show-error --proto '=https' --max-redirs 0 --max-time 60 --config "$config" \
    --request "$method" --output "$out" --write-out '%{http_code}' "$@" "$url"
}
