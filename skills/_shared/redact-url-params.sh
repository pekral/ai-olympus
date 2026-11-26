#!/usr/bin/env bash
# redact-url-params.sh — print a step URL that is safe to publish.
#
# Why this exists
#   The URL of the step where a defect appeared goes into a handoff, a finding, and from there into a public comment
#   (skills/interactive-testing/references/defect-screenshots.md *Evidence URL*). A query string often carries a
#   signed link, a session token, or an API key, and a URL can carry a password before the host. This script is the
#   one place that removes them.
#
# Usage
#   redact-url-params.sh <url>
#   redact-url-params.sh --self-test
#
# What it does
#   Replaces the value of every query parameter whose name, lower-cased, contains token, signature, sig, key, code,
#   expires, secret, or password with `<redacted>`; drops `user:password@` before the host; and replaces a fragment
#   with `#<redacted>`. The scheme, host, path, and every other parameter stay. A value that is not an http(s) URL is
#   refused (exit 1). Nothing is executed or expanded.
#
# Exit codes: 0 printed, 1 not an http(s) URL, 2 usage error.
set -euo pipefail

SENSITIVE_RE='token|signature|sig|key|code|expires|secret|password'

redact_url() {
  local url="$1" base query="" pair name lowered out="" sep="" fragment=""
  [[ "$url" =~ ^https?://[^[:space:]]+$ ]] || return 1
  if [[ "$url" == *'#'* ]]; then
    fragment='#<redacted>'
    url="${url%%#*}"
  fi
  base="${url%%\?*}"
  if [[ "$url" == *'?'* ]]; then
    query="${url#*\?}"
  fi
  # user:password@host -> host
  base="$(printf '%s' "$base" | sed -E 's#^(https?://)[^/@]*@#\1#')"
  if [[ -n "$query" ]]; then
    local -a pairs
    IFS='&' read -r -a pairs <<<"$query"
    for pair in "${pairs[@]}"; do
      name="${pair%%=*}"
      lowered="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')"
      if [[ "$pair" == *=* && "$lowered" =~ $SENSITIVE_RE ]]; then
        out="${out}${sep}${name}=<redacted>"
      else
        out="${out}${sep}${pair}"
      fi
      sep='&'
    done
    base="${base}?${out}"
  fi
  printf '%s%s\n' "$base" "$fragment"
}

self_test() {
  local failures=0 total=0 got
  expect() {
    total=$((total + 1))
    got="$(redact_url "$2" 2>/dev/null || echo REFUSED)"
    if [[ "$got" != "$3" ]]; then
      echo "FAIL: $1 — expected [$3], got [$got]" >&2
      failures=$((failures + 1))
    fi
  }
  expect 'a plain URL stays' 'http://localhost:8000/orders/5?page=2' 'http://localhost:8000/orders/5?page=2'
  expect 'token is redacted' 'http://localhost:8000/a?token=abc123&page=2' 'http://localhost:8000/a?token=<redacted>&page=2'
  expect 'signature is redacted' 'http://localhost/a?signature=deadbeef' 'http://localhost/a?signature=<redacted>'
  expect 'key is redacted' 'http://localhost/a?api_key=k1&x=1' 'http://localhost/a?api_key=<redacted>&x=1'
  expect 'code is redacted' 'http://localhost/cb?code=zzz&state=ok' 'http://localhost/cb?code=<redacted>&state=ok'
  expect 'expires is redacted' 'http://localhost/a?expires=1700000000&signature=s' 'http://localhost/a?expires=<redacted>&signature=<redacted>'
  expect 'the name is matched in any letter case' 'http://localhost/a?X-Amz-Signature=s' 'http://localhost/a?X-Amz-Signature=<redacted>'
  expect 'a password before the host is dropped' 'https://user:pw@localhost/a' 'https://localhost/a'
  expect 'a fragment is replaced' 'http://localhost/a#access_token=abc' 'http://localhost/a#<redacted>'
  expect 'a flag parameter with no value stays' 'http://localhost/a?debug&token=1' 'http://localhost/a?debug&token=<redacted>'
  expect 'a value that is not an http URL is refused' 'file:///etc/passwd' 'REFUSED'
  expect 'a value with a space is refused' 'http://localhost/a b' 'REFUSED'
  if [[ "$failures" -gt 0 ]]; then
    echo "redact-url-params.sh self-test: $failures of $total checks failed" >&2
    return 1
  fi
  echo "redact-url-params.sh self-test: $total checks passed"
}

case "${1:-}" in
  --self-test) self_test ;;
  '') echo "usage: redact-url-params.sh <url>" >&2; exit 2 ;;
  *)
    [[ $# -eq 1 ]] || { echo "usage: redact-url-params.sh <url>" >&2; exit 2; }
    redact_url "$1" || { echo "redact-url-params.sh: the value is not an http(s) URL" >&2; exit 1; }
    ;;
esac
