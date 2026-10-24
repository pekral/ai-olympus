#!/usr/bin/env bash
# json-string.sh — the one definition of json_string, the JSON string-literal escaper of the shell helpers.
#
# Why this exists
#   check-handoff.sh, run-validation.sh, and check-cr-header.sh each carried their own copy, and the copies
#   had drifted: only the one in run-validation.sh escaped a tab. A raw tab inside a JSON string is invalid
#   JSON, so the other two could emit an unparseable document for a value that carried one. This file is the
#   only place that decides how a value is escaped. Each of the three scripts sources it and defines no copy.
#
# Usage
#   source json-string.sh      # then: json_string "<value>"
#   json-string.sh --self-test
#
# What it does
#   Prints the value escaped for use between double quotes in a JSON document: a backslash, a double quote,
#   and a tab are escaped, and a newline and a carriage return are removed. It does not print the quotes. It
#   needs no jq, because it runs on the refusal paths where jq already declined to read the value. The value
#   is data: no part of it is executed or expanded.

json_string() {
  # The tab is written as $'\t' so the sed expression carries a real tab; a `\t` in a sed pattern is not
  # portable between GNU and BSD sed.
  printf '%s' "$1" | LC_ALL=C sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e $'s/\t/\\\\t/g' | tr -d '\n\r'
}

json_string_self_test() {
  local failures=0

  expect() {
    local label="$1" input="$2" expected="$3" actual
    actual="$(json_string "$input")"
    if [[ "$actual" != "$expected" ]]; then
      echo "FAIL: $label — expected [$expected], got [$actual]" >&2
      failures=$((failures + 1))
    fi
  }

  expect 'a plain value is unchanged' 'plain text' 'plain text'
  expect 'a double quote is escaped' 'say "hi"' 'say \"hi\"'
  expect 'a backslash is escaped' 'a\b' 'a\\b'
  expect 'a tab is escaped' $'a\tb' 'a\tb'
  expect 'a newline and a carriage return are removed' $'a\nb\rc' 'abc'
  expect 'a backslash is escaped before a quote' '\"' '\\\"'

  if command -v jq >/dev/null 2>&1; then
    local value=$'q" b\\ t\t n\n r\r end' decoded
    decoded="$(printf '{"v": "%s"}' "$(json_string "$value")" | jq -r '.v' 2>/dev/null || true)"
    if [[ "$decoded" != $'q" b\\ t\t n r end' ]]; then
      echo "FAIL: the escaped value does not decode as JSON to the expected string [$decoded]" >&2
      failures=$((failures + 1))
    fi
  fi

  if [[ "$failures" -gt 0 ]]; then
    echo "json-string self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'json-string self-test: PASS'
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -euo pipefail
  if [[ "${1:-}" == "--self-test" ]]; then
    json_string_self_test
    exit $?
  fi
  echo 'Usage: json-string.sh --self-test (the file is meant to be sourced)' >&2
  exit 1
fi
