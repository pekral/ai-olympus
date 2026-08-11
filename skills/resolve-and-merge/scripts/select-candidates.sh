#!/usr/bin/env bash
# select-candidates.sh — take the batch's candidate issues highest-priority first.
#
# Why this exists
#   Three filters have to hold at once — carries the label, does not carry the
#   claim label, is not already covered by an adopted pull request — and each
#   one has a concrete failure behind it: an unlabeled issue is work nobody
#   asked for, a claimed issue is another run's task picked up a second time,
#   and an already-covered issue duplicates the pull request that exists. Doing
#   this by hand per run is where one of the three quietly goes missing.
#
# Usage (executed by the agent, not read)
#   scripts/select-candidates.sh [COUNT] [LABEL] [CLAIM_LABEL] [EXCLUDE]
#   scripts/select-candidates.sh --self-test
#
#   COUNT        how many candidates to take; default 5.
#   LABEL        auto-resolve label; default Resolve_by_AI.
#   CLAIM_LABEL  in-progress claim label; default Resolve_by_AI:in-progress.
#   EXCLUDE      comma-separated issue numbers already covered by an adopted
#                PR (from inventory-open-prs.sh); default none.
#   --self-test  run the offline test suite (no network, `gh` is stubbed).
#
# Selection is PRIORITY-DRIVEN and deterministic, not random: eligible issues
# are ordered by their priority label — `priority: critical`, `priority: high`,
# `priority: medium`, `priority: low`, critical first — and issues sharing a
# priority are ordered oldest `createdAt` first. The earlier random draw
# (`sort -R`) is deliberately gone — a batch that samples the backlog uniformly
# will start a `priority: low` docs tidy-up while a `priority: critical`
# credential leak waits, which is exactly what the priority labels exist to
# prevent.
#
# Those four are the taxonomy `skills/github-issue-triage/` seeds and maintains,
# and they are the only labels this script ranks by. It read a `priority:P0`…
# `priority:P3` set of its own until #216, which no repository using the triage
# skill has ever carried — so every issue fell back to the default level and the
# ordering silently degraded to oldest-first.
#
# An issue carrying NO priority label sorts as `priority: medium`, the declared
# default level. It therefore neither jumps ahead of triaged work nor sinks
# below it — an untriaged issue stays reachable instead of starving behind every
# labeled one, while never outranking an explicit critical/high.
#
# Fewer eligible issues than COUNT yields a shorter list; the pool is never
# widened to unlabeled issues to reach the number.
#
# Prints a JSON array on stdout, one object per selected candidate, already in
# execution order:
#   [{"number":7,"url":"…","title":"…","createdAt":"…","priority":"critical"}]
#
# `priority` is the resolved level ("critical", "high", "medium", "low") or null
# when the issue carries none — reported so the batch plan can state why each
# task holds its position rather than asserting an order the caller cannot
# verify.
#
# Read-only: one `gh issue list` read. See _lib.sh for the full safety
# contract and the shared exit codes.
#
# Exit codes: 0 · 1 usage or a failing self-test · 2 missing tool · 3 not
# authenticated / no GitHub repo
set -euo pipefail
# shellcheck source=_lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_lib.sh"

# --- self-test ----------------------------------------------------------------
#
# The ranking is the whole reason this script is not a one-line `gh` call, and
# it is the part that can be wrong for months without failing anything: #216
# found the filter matching a label format this repository never used, so every
# issue fell back to the default level and the ordering quietly degraded to
# oldest-first. Reading the filter is what missed that, so it is proven by
# RUNNING the script over fixture backlogs with `gh` stubbed — the precedent is
# `skills/github-issue-triage/scripts/assign-priorities.sh --self-test`, which
# owns the other half of this taxonomy.

# Removed on exit by cleanup_self_test. Global, not local: the EXIT trap fires
# after the function's locals are gone.
SELF_TEST_TMP=''

cleanup_self_test() {
  if [[ -n "$SELF_TEST_TMP" ]]; then
    rm -rf "$SELF_TEST_TMP"
  fi
  return 0
}

self_test() {
  local script stub keys rc failures=0 checks=0

  # Only `gh` is stubbed; the run is the real script, so it needs real jq.
  require_tools jq

  script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"

  SELF_TEST_TMP="$(mktemp -d)"
  trap cleanup_self_test EXIT

  stub="$SELF_TEST_TMP/bin"
  mkdir -p "$stub"

  cat >"$stub/gh" <<'STUB'
#!/usr/bin/env bash
# Stand-in for the three read-only `gh` calls this script makes: `auth status`
# and `repo view` satisfy require_github_repo, and `issue list` answers from the
# fixture backlog.
set -euo pipefail

case "${1:-} ${2:-}" in
  'auth status') ;;
  'repo view') printf '{"nameWithOwner":"stub-owner/stub-repo"}\n' ;;
  'issue list') cat "$GH_STUB_ISSUES" ;;
  *)
    echo "gh stub: unexpected call: $*" >&2
    exit 1
    ;;
esac
STUB
  chmod +x "$stub/gh"

  # Runs the real script over a fixture backlog and compares the [number,
  # priority] projection of its output — which IS the ordering contract — with
  # the expected one. Everything after the expected value is passed to the
  # script as its own arguments.
  expect_order() {
    local label="$1" issues="$2" expected="$3"
    shift 3
    local out status actual

    printf '%s' "$issues" >"$SELF_TEST_TMP/issues.json"

    set +e
    out="$(PATH="$stub:$PATH" GH_STUB_ISSUES="$SELF_TEST_TMP/issues.json" "$script" "$@" 2>&1)"
    status=$?
    set -e

    checks=$((checks + 1))

    if [[ "$status" -ne 0 ]]; then
      echo "self-test FAIL: $label -> exit $status" >&2
      echo "$out" >&2
      failures=$((failures + 1))
      return 0
    fi

    if ! actual="$(printf '%s' "$out" | jq -c '[.[] | {number, priority}]' 2>&1)"; then
      echo "self-test FAIL: $label -> output is not JSON: $out" >&2
      failures=$((failures + 1))
      return 0
    fi

    if [[ "$actual" != "$expected" ]]; then
      echo "self-test FAIL: $label" >&2
      echo "  expected: $expected" >&2
      echo "  actual:   $actual" >&2
      failures=$((failures + 1))
    fi
  }

  # `createdAt` deliberately runs counter to the priority order — the oldest
  # issue here is the lowest-priority one — so an ordering that fails to read
  # the labels produces very nearly the reverse of the expected result rather
  # than accidentally agreeing with it.
  local mixed='[
    {"number":1,"url":"u/1","title":"docs tidy-up","createdAt":"2019-01-01T00:00:00Z","labels":[{"name":"priority: low"}]},
    {"number":2,"url":"u/2","title":"untriaged","createdAt":"2021-01-01T00:00:00Z","labels":[]},
    {"number":3,"url":"u/3","title":"credential leak","createdAt":"2026-01-01T00:00:00Z","labels":[{"name":"priority: critical"}]},
    {"number":4,"url":"u/4","title":"broken import","createdAt":"2025-01-01T00:00:00Z","labels":[{"name":"PRIORITY: High"}]},
    {"number":5,"url":"u/5","title":"new flag","createdAt":"2020-01-01T00:00:00Z","labels":[{"name":"priority: medium"}]}
  ]'

  # The whole contract in one case: the space after the colon resolves, the
  # match is case-insensitive, the order is critical > high > medium > low, an
  # unlabeled issue ranks as `priority: medium` (so it lands between the
  # explicit medium it is younger than and the low), and no amount of age
  # promotes a low over a critical seven years its junior.
  expect_order 'critical, high, medium, unlabeled-as-medium, low' "$mixed" \
    '[{"number":3,"priority":"critical"},{"number":4,"priority":"high"},{"number":5,"priority":"medium"},{"number":2,"priority":null},{"number":1,"priority":"low"}]' \
    5

  # COUNT slices the ordered list, so it takes the two most urgent issues and
  # not the first two the backlog happened to list.
  expect_order 'COUNT truncates after the ordering, not before' "$mixed" \
    '[{"number":3,"priority":"critical"},{"number":4,"priority":"high"}]' \
    2

  # The regression #216 is about: `priority:P0`…`P3` was this script's own
  # taxonomy and matches nothing now, so an issue still carrying it is
  # untriaged — never silently ranked as if the old label still meant something.
  expect_order 'the retired priority:P0 taxonomy is not a priority label' \
    '[
      {"number":6,"url":"u/6","title":"stale label","createdAt":"2019-01-01T00:00:00Z","labels":[{"name":"priority:P0"}]},
      {"number":7,"url":"u/7","title":"real critical","createdAt":"2026-01-01T00:00:00Z","labels":[{"name":"priority: critical"}]}
    ]' \
    '[{"number":7,"priority":"critical"},{"number":6,"priority":null}]' \
    5

  # The canonical label carries a space after the colon, but the regex accepts
  # the spaceless form too. That leniency is a promise the CHANGELOG makes, so a
  # fixture holds it — an unpinned promise is how #216 started.
  expect_order 'a priority label written without the space still ranks' \
    '[
      {"number":13,"url":"u/13","title":"spaceless critical","createdAt":"2019-01-01T00:00:00Z","labels":[{"name":"priority:critical"}]},
      {"number":14,"url":"u/14","title":"medium","createdAt":"2026-01-01T00:00:00Z","labels":[{"name":"priority: medium"}]}
    ]' \
    '[{"number":13,"priority":"critical"},{"number":14,"priority":"medium"}]' \
    5

  # Two priority labels: the first match wins and the rank follows it, so the
  # reported priority and the position it holds can never disagree.
  expect_order 'two priority labels on one issue keep the first match' \
    '[
      {"number":8,"url":"u/8","title":"two priorities","createdAt":"2019-01-01T00:00:00Z","labels":[{"name":"priority: low"},{"name":"priority: critical"}]},
      {"number":9,"url":"u/9","title":"medium","createdAt":"2026-01-01T00:00:00Z","labels":[{"name":"priority: medium"}]}
    ]' \
    '[{"number":9,"priority":"medium"},{"number":8,"priority":"low"}]' \
    5

  # Ranking never widens the pool: the claimed and the adopted issue are
  # dropped even though both outrank the one that survives.
  expect_order 'the claim-label and EXCLUDE filters still hold' \
    '[
      {"number":10,"url":"u/10","title":"claimed","createdAt":"2019-01-01T00:00:00Z","labels":[{"name":"priority: critical"},{"name":"Resolve_by_AI:in-progress"}]},
      {"number":11,"url":"u/11","title":"adopted","createdAt":"2020-01-01T00:00:00Z","labels":[{"name":"priority: critical"}]},
      {"number":12,"url":"u/12","title":"eligible","createdAt":"2021-01-01T00:00:00Z","labels":[{"name":"priority: low"}]}
    ]' \
    '[{"number":12,"priority":"low"}]' \
    5 Resolve_by_AI 'Resolve_by_AI:in-progress' 11

  # `rank` is an internal sort key: the documented output carries the resolved
  # level, never the number the ordering was computed from.
  printf '%s' "$mixed" >"$SELF_TEST_TMP/issues.json"
  set +e
  keys="$(PATH="$stub:$PATH" GH_STUB_ISSUES="$SELF_TEST_TMP/issues.json" "$script" 1 2>&1 |
    jq -c '.[0] | keys_unsorted' 2>&1)"
  rc=$?
  set -e

  checks=$((checks + 1))
  if [[ "$rc" -ne 0 || "$keys" != '["number","url","title","createdAt","priority"]' ]]; then
    echo "self-test FAIL: the emitted object carries exactly the documented fields -> $keys" >&2
    failures=$((failures + 1))
  fi

  if [[ "$failures" -gt 0 ]]; then
    echo "$PROG: self-test failed ($failures of $checks checks)" >&2
    return 1
  fi

  echo "$PROG: self-test passed ($checks checks)"
}

if [[ "${1:-}" == '--self-test' ]]; then
  [[ $# -eq 1 ]] || die 1 "--self-test takes no further arguments"
  self_test || exit 1
  exit 0
fi

COUNT="${1:-5}"
LABEL="${2:-Resolve_by_AI}"
CLAIM_LABEL="${3:-Resolve_by_AI:in-progress}"
EXCLUDE="${4:-}"

[[ $# -le 4 ]] || die 1 "usage: $PROG [COUNT] [LABEL] [CLAIM_LABEL] [EXCLUDE]"
assert_count "$COUNT" "COUNT"
[[ -n "$LABEL" ]] || die 1 "LABEL must not be empty"
[[ -n "$CLAIM_LABEL" ]] || die 1 "CLAIM_LABEL must not be empty"

# Validated rather than trusted: EXCLUDE is the only argument assembled from a
# previous script's output, so a malformed value means the pipeline went wrong
# somewhere upstream and the run must stop rather than silently select from an
# unfiltered pool.
EXCLUDE_JSON='[]'
if [[ -n "$EXCLUDE" ]]; then
  [[ "$EXCLUDE" =~ ^[0-9]+(,[0-9]+)*$ ]] || die 1 "EXCLUDE must be comma-separated issue numbers, got: $EXCLUDE"
  EXCLUDE_JSON="[${EXCLUDE}]"
fi

require_tools gh jq
require_github_repo

# 500 bounds the pool, matching inventory-open-prs.sh. Beyond that the ordering
# is over the 500 issues gh returned rather than the whole backlog.
#
# The priority level is read from the FIRST `priority: <level>` label found. Two
# priority labels on one issue is a triage mistake, not a case worth encoding a
# tie-break for; taking the first keeps the rank total and deterministic instead
# of failing the whole run over one mislabeled issue.
#
# `sort_by` is a stable total order over [rank, createdAt], so the output is
# fully deterministic — the same backlog yields the same batch every run, which
# is what makes a deferred task predictably reachable on the next one.
gh issue list --label "$LABEL" --state open --limit 500 \
  --json number,url,title,createdAt,labels |
  jq --arg claim "$CLAIM_LABEL" --argjson exclude "$EXCLUDE_JSON" --argjson n "$COUNT" "$JQ_CLEAN"'
    # The space after the colon is how github-issue-triage writes these labels.
    # ` *` also accepts a variant written without one, so a repository whose
    # labels drifted is still ranked rather than silently defaulted — the exact
    # failure #216 was about.
    def priority_level:
      [ .labels[]?.name // ""
        | ascii_downcase
        | select(test("^priority: *(critical|high|medium|low)$"))
        | sub("^priority: *"; "")
      ] | first // null;

    def rank_of: {"critical": 0, "high": 1, "medium": 2, "low": 3}[.];

    ($claim | ascii_downcase) as $claimed
    | [ .[]
        | select(any(.labels[]?; (.name // "" | ascii_downcase) == $claimed) | not)
        | select(.number as $num | $exclude | index($num) | not)
        | priority_level as $p
        | {
            number,
            url,
            title: (.title // "" | clean),
            createdAt,
            priority: $p,
            rank: ((if $p == null then "medium" else $p end) | rank_of),
          }
      ]
    | sort_by([.rank, .createdAt])
    | .[:$n]
    | map(del(.rank))'
