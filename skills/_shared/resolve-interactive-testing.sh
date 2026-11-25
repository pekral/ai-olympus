#!/usr/bin/env bash
# resolve-interactive-testing.sh — print whether interactive testing is switched on for this project.
#
# The project owner switches interactive testing off with `interactive-testing: false` in the
# `extra.ai-olympus` manifest of composer.json (@rules/general/general.md *Project manifest*). The setting is
# read from the default branch through skills/_shared/read-manifest.sh, never from the working tree, so a
# pull request under review cannot switch off its own testing until it is merged.
#
# This script is the interactive-testing entry of skills/_shared/resolve-project-switch.sh, which owns the
# reading of every project switch. It keeps its own name and its output contract for its callers.
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

exec "$HERE/resolve-project-switch.sh" --key interactive-testing "$@"
