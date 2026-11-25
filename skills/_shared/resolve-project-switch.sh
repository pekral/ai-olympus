#!/usr/bin/env bash
# resolve-project-switch.sh — print the state of one project switch of the manifest.
#
# A project owner switches a part of this package off in the `extra.ai-olympus` manifest of composer.json
# (@rules/general/general.md *Project manifest*). Only the keys of this fixed list are switches:
#
#   key                  state on   state off   owner of the rule
#   interactive-testing  on         off         skills/interactive-testing/SKILL.md *Project setting*
#   helper-agents        allowed    forbidden   skills/_shared/orchestration/helper-agents.md
#
# The setting is read from the default branch through skills/_shared/read-manifest.sh, never from the working
# tree, so a pull request under review cannot change its own switch until it is merged. Only the JSON boolean
# `false` switches a key off. Every other value, and every manifest that cannot be read, keeps the key on.
#
# Usage
#   resolve-project-switch.sh --key <key>            read the manifest from the default branch
#   resolve-project-switch.sh --key <key> --stdin    read the manifest JSON from standard input
#   resolve-project-switch.sh --self-test
#
# Output (stdout), two lines
#   <key>=<state on>|<state off>
#   source=manifest|default|invalid|unreadable
#
#   manifest    the manifest holds the JSON boolean `true` or `false`; that value decides
#   default     the key is absent, or the project has no manifest: the state on
#   invalid     the key holds a value that is not a JSON boolean: the state on, and the caller says so in its report
#   unreadable  the manifest could not be read: the state on, and the report of read-manifest.sh stays on stderr
#
# Exit codes
#   0  the two lines were printed
#   1  usage error, an unknown key included
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$HERE/$(basename "${BASH_SOURCE[0]}")"

usage() {
  echo "Usage: resolve-project-switch.sh --key <interactive-testing|helper-agents> [--stdin] | --self-test" >&2
  exit 1
}

# Prints the state names of a key as "<on> <off>", or nothing for a key outside the list.
states_of_key() {
  case "$1" in
  interactive-testing) echo "on off" ;;
  helper-agents) echo "allowed forbidden" ;;
  esac
}

# Prints the two output lines for one key and one manifest document. A document that is not an object counts as no
# manifest. The key reaches jq only as an --arg value, never inside the filter text.
setting_of_manifest() {
  local key="$1" manifest="$2" source on off state

  read -r on off <<<"$(states_of_key "$key")"

  source="$(printf '%s' "$manifest" | jq -r --arg key "$key" '
    if type != "object" or (has($key) | not) then "default"
    elif (.[$key] | type) == "boolean" then "manifest"
    else "invalid" end' 2>/dev/null)" || source="default"
  [[ -n "$source" ]] || source="default"

  state="$on"
  if [[ "$source" == "manifest" && "$(printf '%s' "$manifest" | jq -r --arg key "$key" '.[$key]')" == "false" ]]; then
    state="$off"
  fi

  printf '%s=%s\nsource=%s\n' "$key" "$state" "$source"
}

self_test() {
  local failed=0 name key manifest expected got

  while IFS='|' read -r name key manifest expected; do
    got="$(setting_of_manifest "$key" "$manifest" | paste -sd ' ' -)"
    if [[ "$got" != "$expected" ]]; then
      echo "FAIL $name: expected $expected, got $got" >&2
      failed=1
    fi
  done <<'CASES'
absent|interactive-testing|{}|interactive-testing=on source=default
true|interactive-testing|{"interactive-testing":true}|interactive-testing=on source=manifest
false|interactive-testing|{"interactive-testing":false}|interactive-testing=off source=manifest
string false|interactive-testing|{"interactive-testing":"false"}|interactive-testing=on source=invalid
string off|interactive-testing|{"interactive-testing":"off"}|interactive-testing=on source=invalid
number zero|interactive-testing|{"interactive-testing":0}|interactive-testing=on source=invalid
null|interactive-testing|{"interactive-testing":null}|interactive-testing=on source=invalid
array|interactive-testing|{"interactive-testing":[]}|interactive-testing=on source=invalid
object|interactive-testing|{"interactive-testing":{}}|interactive-testing=on source=invalid
other key|interactive-testing|{"voice":"technical"}|interactive-testing=on source=default
not json|interactive-testing|not json|interactive-testing=on source=default
not an object|interactive-testing|[]|interactive-testing=on source=default
helper absent|helper-agents|{}|helper-agents=allowed source=default
helper true|helper-agents|{"helper-agents":true}|helper-agents=allowed source=manifest
helper false|helper-agents|{"helper-agents":false}|helper-agents=forbidden source=manifest
helper string false|helper-agents|{"helper-agents":"false"}|helper-agents=allowed source=invalid
helper string forbidden|helper-agents|{"helper-agents":"forbidden"}|helper-agents=allowed source=invalid
helper number zero|helper-agents|{"helper-agents":0}|helper-agents=allowed source=invalid
helper null|helper-agents|{"helper-agents":null}|helper-agents=allowed source=invalid
helper array|helper-agents|{"helper-agents":[]}|helper-agents=allowed source=invalid
helper object|helper-agents|{"helper-agents":{}}|helper-agents=allowed source=invalid
helper other switch off|helper-agents|{"interactive-testing":false}|helper-agents=allowed source=default
helper not json|helper-agents|not json|helper-agents=allowed source=default
helper not an object|helper-agents|[]|helper-agents=allowed source=default
CASES

  for key in voice '.' '"a"' 'helper-agents|.'; do
    if "$SELF" --key "$key" --stdin </dev/null >/dev/null 2>&1; then
      echo "FAIL unknown key $key: expected a usage error" >&2
      failed=1
    fi
  done

  [[ "$failed" -eq 0 ]] && echo "resolve-project-switch.sh self-test passed"
  return "$failed"
}

key=""
if [[ "${1:-}" == "--key" ]]; then
  [[ $# -ge 2 ]] || usage
  key="$2"
  shift 2
fi

case "${1:-}" in
--self-test)
  [[ $# -eq 1 ]] || usage
  self_test
  exit $?
  ;;
esac

[[ -n "$(states_of_key "$key")" ]] || usage

case "${1:-}" in
"")
  manifest="$("$HERE/read-manifest.sh" 2>/dev/null)"
  status=$?
  if [[ "$status" -ne 0 ]]; then
    # Name the report of the reader on stderr, and keep the default.
    "$HERE/read-manifest.sh" >/dev/null || true
    read -r on _ <<<"$(states_of_key "$key")"
    printf '%s=%s\nsource=unreadable\n' "$key" "$on"
  else
    setting_of_manifest "$key" "$manifest"
  fi
  ;;
--stdin)
  [[ $# -eq 1 ]] || usage
  setting_of_manifest "$key" "$(cat)"
  ;;
*)
  usage
  ;;
esac
