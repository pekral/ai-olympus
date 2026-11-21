#!/usr/bin/env bash
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/skill-path.sh" || exit 3
skill_root="$(command_skill_root)" || exit 3
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  exec bash "$skill_root/_shared/json-string.sh" "$@"
fi
# shellcheck source=../../skills/_shared/json-string.sh
source "$skill_root/_shared/json-string.sh"
