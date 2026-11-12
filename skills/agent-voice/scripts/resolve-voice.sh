#!/usr/bin/env bash
# resolve-voice.sh — print the voice an agent uses for human-read prose: `relaxed` or `technical`.
#
# The voice is cosmetic, so every failure falls back to the default and never stops a run.
#
# Usage
#   resolve-voice.sh            read the manifest from the default branch (skills/_shared/read-manifest.sh)
#   resolve-voice.sh --stdin    read the manifest JSON from standard input
#   resolve-voice.sh --self-test
#
# Output (stdout)
#   One voice name. `relaxed` when the key `voice` is absent, is not a string, names an unknown voice,
#   or the manifest cannot be read. The user's own instruction in the session overrides this value;
#   SKILL.md step 1 states that order, because the script never sees the session.
#
# Exit codes
#   0  a voice was printed
#   1  usage error
set -uo pipefail

DEFAULT_VOICE="relaxed"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

voice_of_manifest() {
  local manifest="$1" voice

  voice="$(printf '%s' "$manifest" | jq -r 'if (type == "object" and (.voice | type) == "string") then .voice else "" end' 2>/dev/null)" || voice=""

  case "$voice" in
  relaxed | technical) printf '%s\n' "$voice" ;;
  *) printf '%s\n' "$DEFAULT_VOICE" ;;
  esac
}

self_test() {
  local failed=0 name manifest expected got

  while IFS='|' read -r name manifest expected; do
    got="$(voice_of_manifest "$manifest")"
    if [[ "$got" != "$expected" ]]; then
      echo "FAIL $name: expected $expected, got $got" >&2
      failed=1
    fi
  done <<'CASES'
absent|{}|relaxed
relaxed|{"voice":"relaxed"}|relaxed
technical|{"voice":"technical"}|technical
unknown|{"voice":"sarcastic"}|relaxed
wrong case|{"voice":"Technical"}|relaxed
not a string|{"voice":true}|relaxed
null|{"voice":null}|relaxed
not json|not json|relaxed
not an object|[]|relaxed
CASES

  [[ "$failed" -eq 0 ]] && echo "resolve-voice.sh self-test passed"
  return "$failed"
}

case "${1:-}" in
"")
  manifest="$("$HERE/../../_shared/read-manifest.sh" 2>/dev/null)" || manifest="{}"
  voice_of_manifest "$manifest"
  ;;
--stdin)
  voice_of_manifest "$(cat)"
  ;;
--self-test)
  self_test
  ;;
*)
  echo "Usage: resolve-voice.sh [--stdin | --self-test]" >&2
  exit 1
  ;;
esac
