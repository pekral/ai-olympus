#!/usr/bin/env bash
# resolve-interactive-testing.sh — print whether interactive testing is switched on for this project.
#
# The project owner switches interactive testing off with `interactive-testing: false` in the
# `extra.ai-olympus` manifest of composer.json (@rules/general/general.md *Project manifest*). The setting is
# read from the default branch through skills/_shared/read-manifest.sh, never from the working tree, so a
# pull request under review cannot switch off its own testing until it is merged.
#
# Usage
#   resolve-interactive-testing.sh            read the manifest from the default branch
#   resolve-interactive-testing.sh --stdin    read the manifest JSON from standard input
#   resolve-interactive-testing.sh --self-test
#
# Output (stdout), two lines
#   interactive-testing=on|off
#   source=manifest|default|invalid|unreadable
#
#   manifest    the manifest holds the JSON boolean `true` or `false`; that value decides
#   default     the key is absent, or the project has no manifest: `on`
#   invalid     the key holds a value that is not a JSON boolean: `on`, and the caller says so in its report
#   unreadable  the manifest could not be read: `on`, and the report of read-manifest.sh stays on stderr
#
# Exit codes
#   0  the two lines were printed
#   1  usage error
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Prints the two output lines for one manifest document. A document that is not an object counts as no manifest.
setting_of_manifest() {
  local manifest="$1" source state

  source="$(printf '%s' "$manifest" | jq -r '
    if type != "object" or (has("interactive-testing") | not) then "default"
    elif (.["interactive-testing"] | type) == "boolean" then "manifest"
    else "invalid" end' 2>/dev/null)" || source="default"
  [[ -n "$source" ]] || source="default"

  state="on"
  if [[ "$source" == "manifest" && "$(printf '%s' "$manifest" | jq -r '.["interactive-testing"]')" == "false" ]]; then
    state="off"
  fi

  printf 'interactive-testing=%s\nsource=%s\n' "$state" "$source"
}

self_test() {
  local failed=0 name manifest expected got

  while IFS='|' read -r name manifest expected; do
    got="$(setting_of_manifest "$manifest" | paste -sd ' ' -)"
    if [[ "$got" != "$expected" ]]; then
      echo "FAIL $name: expected $expected, got $got" >&2
      failed=1
    fi
  done <<'CASES'
absent|{}|interactive-testing=on source=default
true|{"interactive-testing":true}|interactive-testing=on source=manifest
false|{"interactive-testing":false}|interactive-testing=off source=manifest
string false|{"interactive-testing":"false"}|interactive-testing=on source=invalid
string off|{"interactive-testing":"off"}|interactive-testing=on source=invalid
number zero|{"interactive-testing":0}|interactive-testing=on source=invalid
null|{"interactive-testing":null}|interactive-testing=on source=invalid
array|{"interactive-testing":[]}|interactive-testing=on source=invalid
object|{"interactive-testing":{}}|interactive-testing=on source=invalid
other key|{"voice":"technical"}|interactive-testing=on source=default
not json|not json|interactive-testing=on source=default
not an object|[]|interactive-testing=on source=default
CASES

  [[ "$failed" -eq 0 ]] && echo "resolve-interactive-testing.sh self-test passed"
  return "$failed"
}

case "${1:-}" in
"")
  manifest="$("$HERE/read-manifest.sh" 2>/dev/null)"
  status=$?
  if [[ "$status" -ne 0 ]]; then
    # Name the report of the reader on stderr, and keep the default.
    "$HERE/read-manifest.sh" >/dev/null || true
    printf 'interactive-testing=on\nsource=unreadable\n'
  else
    setting_of_manifest "$manifest"
  fi
  ;;
--stdin)
  setting_of_manifest "$(cat)"
  ;;
--self-test)
  self_test
  ;;
*)
  echo "Usage: resolve-interactive-testing.sh [--stdin | --self-test]" >&2
  exit 1
  ;;
esac
