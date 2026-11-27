#!/usr/bin/env bash
# Raw local URLs can identify private resources in any component. Emit only a fixed step label.
set -euo pipefail
if [[ "${1:-}" == --self-test ]]; then
  [[ "$(printf '%s\n' 'https://user:pw@private.example/secret?%74oken=value#private' | bash "$0")" == 'Local UI step' ]]
elif [[ $# == 0 ]] && IFS= read -r source_url && [[ "$source_url" =~ ^https?://[^[:space:]]+$ ]]; then
  echo 'Local UI step'
else
  exit 1
fi
