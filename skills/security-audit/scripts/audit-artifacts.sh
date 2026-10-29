#!/usr/bin/env bash
# audit-artifacts.sh — the deterministic half of the security-audit skill: the execution budget,
# the coverage inventory check, the root-cause fingerprint, the working-tree snapshot, and the
# validation of the four artefacts a run writes.
#
# Why this exists
#   The skill promises things a model cannot promise about itself: hunting never consumes the
#   capacity reserved for verifiers and coverage critics, the inventory exists before the first
#   hunter opens, a hunter never verifies its own candidate, parallel workers leave the working
#   tree untouched, two candidates merge only on the same root-cause fingerprint, and the JSON
#   artefacts follow their schemas. All of it is checked here, by a script, so the answer does not
#   depend on the run that produced the artefacts.
#
#   No JSON Schema library is installed in this package, and the check must not add a dependency.
#   The validator below is a jq program that applies the subset of JSON Schema the three schemas
#   in ../schemas use: type, const, enum, required, properties, additionalProperties: false, items,
#   contains, minItems, minLength, pattern, minimum, maximum, $ref into #/$defs, allOf, anyOf, and
#   if/then/else. A schema keyword outside that subset is ignored, so a schema change that needs a
#   new keyword must extend the validator and its --self-test in the same change.
#
# Usage
#   audit-artifacts.sh budget --profile <quick|standard|deep> [--total <passes>]
#   audit-artifacts.sh snapshot [<dir>]
#   audit-artifacts.sh fingerprint <boundary> <source> <sink> <root_cause>
#   audit-artifacts.sh inventory <run-dir>
#   audit-artifacts.sh validate <run-dir>
#   audit-artifacts.sh --self-test
#
# budget
#   Prints the execution budget as one line of JSON. The unit is a pass: one bounded worker pass
#   (hunt one coverage unit, verify one candidate, or one coverage-critic review). The verifier
#   reserve and the coverage-critic reserve are taken from the total first, each at least one pass,
#   and the remainder is the most hunting may spend. Exits 1 when the total is too small to leave
#   hunting one pass.
#
# snapshot
#   Prints the SHA-256 of the state of the git working tree that holds <dir> (default: the current
#   directory): HEAD, the index, and the type, mode, and raw bytes of every tracked file and every
#   untracked, not ignored file. Git-ignored files, the run directory among them, do not count.
#   The same tree prints the same value. A write to a tracked file or to an untracked, not ignored
#   file changes it. A write to a git-ignored path or outside the work tree does not, so an
#   unchanged value is no proof that a worker stayed read-only. The snapshot runs no program the
#   repository configures: it never runs `git status` or `git diff`, which run clean filters,
#   external diff programs, and hooks; it turns core.fsmonitor off; and it hashes raw bytes with
#   `git hash-object --no-filters`. Exits 1 outside a git work tree with a commit.
#
# fingerprint
#   Prints the 16-hex-digit fingerprint of a finding: the first 16 hex digits of the SHA-256 of
#   `boundary|source|sink|root_cause`, exactly as given. Each value must match its pattern in
#   findings.schema.json `$defs/fingerprint_basis`; exits 1 when one does not.
#
# inventory
#   Checks <run-dir>/coverage-ledger.json as the coverage inventory a run writes before the first
#   hunter opens: the run directory is git-ignored, the schema holds, every unit is `queued` or
#   `out_of_scope`, no unit has an agent or a finding yet, no wave is recorded, and every
#   coverage_id is unique. A run directory that is not git-ignored is refused, because the
#   coordinator's own writes to it would change the working-tree snapshot.
#
# validate
#   Checks <run-dir>/metadata.json, coverage-ledger.json, findings.json, and REPORT.md. Each JSON
#   artefact must follow its schema, then the cross-artefact rules must hold: hunting spent within
#   hunting_max, the reserves intact, the counts equal to findings.json, unique fingerprints and
#   coverage ids, every fingerprint the hash of its fingerprint_basis, no unit left queued or
#   candidate, every fingerprint a unit lists present in findings.json and hunted by that unit's
#   agent, the waves numbered in order with a fresh critic each and the working tree unchanged
#   after each, one hunter per unit, one verifier per finding, and the workers within the passes
#   spent. The budget in metadata.json must equal the split `budget` prints for its profile and
#   total. A file holding more than one JSON document is not valid JSON. REPORT.md must carry
#   every heading the template defines.
#
# Output (stdout, JSON for inventory and validate)
#   { "valid": true, "run_dir": "…", "problems": [] }
#
# Exit codes
#   0  success
#   1  usage error
#   2  a required tool is missing (jq, sha256sum or shasum)
#   3  the run directory is invalid
set -euo pipefail

PROG="${0##*/}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHEMA_DIR="$HERE/../schemas"

scratch=''
tmp=''

REPORT_HEADINGS=(
  '## Scope'
  '## Budget'
  '## Confirmed findings'
  '## Needs validation'
  '## Rejected candidates'
  '## Coverage'
)

usage() {
  cat >&2 <<'EOF'
Usage: audit-artifacts.sh budget --profile <quick|standard|deep> [--total <passes>]
       audit-artifacts.sh snapshot [<dir>]
       audit-artifacts.sh fingerprint <boundary> <source> <sink> <root_cause>
       audit-artifacts.sh inventory <run-dir>
       audit-artifacts.sh validate <run-dir>
       audit-artifacts.sh --self-test

Exit 3 when the run directory breaks a schema, a budget rule, or a cross-artefact rule.
EOF
}

require_tools() {
  if ! command -v jq >/dev/null 2>&1; then
    echo "$PROG: required tool not found: jq" >&2
    exit 2
  fi
  if ! command -v sha256sum >/dev/null 2>&1 && ! command -v shasum >/dev/null 2>&1; then
    echo "$PROG: required tool not found: sha256sum or shasum" >&2
    exit 2
  fi
}

# sha256_hex — the SHA-256 of stdin as 64 lowercase hex digits.
sha256_hex() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum | cut -c1-64
  else
    shasum -a 256 | cut -c1-64
  fi
}

# printable <value> — the value reduced to printable bytes, for an error message.
printable() {
  printf '%s' "$1" | LC_ALL=C tr -cd '[:print:]' | cut -c1-160
}

# --- budget ---------------------------------------------------------------------------------------

# profile_params <profile> — sets DEFAULT_TOTAL, VERIFIER_PCT, and CRITIC_PCT. The profiles are audit
# depths. They are not the risk tiers FAST, STANDARD, and CRITICAL of classify-risk.sh.
profile_params() {
  case "$1" in
  quick)
    DEFAULT_TOTAL=20
    VERIFIER_PCT=30
    CRITIC_PCT=10
    ;;
  standard)
    DEFAULT_TOTAL=60
    VERIFIER_PCT=30
    CRITIC_PCT=15
    ;;
  deep)
    DEFAULT_TOTAL=180
    VERIFIER_PCT=35
    CRITIC_PCT=20
    ;;
  *) return 1 ;;
  esac
}

# ceil_share <total> <percent> — the share rounded up, never below one pass.
ceil_share() {
  local share=$((($1 * $2 + 99) / 100))
  [[ $share -lt 1 ]] && share=1
  printf '%s' "$share"
}

cmd_budget() {
  local profile='' total=''
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --profile)
      [[ $# -ge 2 ]] || { usage; exit 1; }
      profile="$2"
      shift 2
      ;;
    --total)
      [[ $# -ge 2 ]] || { usage; exit 1; }
      total="$2"
      shift 2
      ;;
    *)
      usage
      exit 1
      ;;
    esac
  done

  if ! profile_params "$profile"; then
    echo "$PROG: unknown profile '$(printable "$profile" | cut -c1-40)' (expected quick, standard, or deep)" >&2
    exit 1
  fi
  total="${total:-$DEFAULT_TOTAL}"
  if [[ ! "$total" =~ ^[1-9][0-9]{0,5}$ ]]; then
    echo "$PROG: --total must be a whole number of passes without a leading zero" >&2
    exit 1
  fi

  local verifier critic hunting
  verifier="$(ceil_share "$total" "$VERIFIER_PCT")"
  critic="$(ceil_share "$total" "$CRITIC_PCT")"
  hunting=$((total - verifier - critic))
  if [[ $hunting -lt 1 ]]; then
    echo "$PROG: a total of $total passes leaves hunting no pass after the reserves of $verifier and $critic" >&2
    exit 1
  fi

  jq -cn --argjson total "$total" --argjson hunting "$hunting" --argjson verifier "$verifier" --argjson critic "$critic" \
    '{unit: "passes", total: $total, hunting_max: $hunting, verifier_reserve: $verifier, coverage_critic_reserve: $critic}'
}

# --- snapshot -------------------------------------------------------------------------------------

# git_plain <dir> <git-args...> — git in <dir> with core.fsmonitor off, so it starts no fsmonitor hook.
git_plain() {
  local dir="$1"
  shift
  git -c core.fsmonitor=false -C "$dir" "$@"
}

cmd_snapshot() {
  [[ $# -le 1 ]] || { usage; exit 1; }
  local dir="${1:-.}" top
  if ! top="$(git_plain "$dir" rev-parse --show-toplevel 2>/dev/null)" || ! git_plain "$top" rev-parse -q --verify HEAD >/dev/null 2>&1; then
    echo "$PROG: not a git work tree with a commit: $(printable "$dir")" >&2
    exit 1
  fi
  {
    git_plain "$top" rev-parse HEAD
    git_plain "$top" ls-files -s -z
    git_plain "$top" ls-files -z --cached --others --exclude-standard | snapshot_files "$top"
  } | sha256_hex
}

# snapshot_files <top> — for every NUL-separated path on stdin: its type, its path, and the hash of
# its raw bytes, or the target of a symbolic link.
snapshot_files() {
  local top="$1" file path
  local -a batch=()
  while IFS= read -r -d '' file; do
    path="$top/$file"
    if [[ -L "$path" ]]; then
      printf 'link\0%s\0%s\0' "$file" "$(readlink "$path")"
    elif [[ ! -f "$path" ]]; then
      printf 'absent\0%s\0' "$file"
    else
      if [[ -x "$path" ]]; then printf 'exec\0%s\0' "$file"; else printf 'file\0%s\0' "$file"; fi
      if [[ "$file" == *$'\n'* || "$file" == '"'* ]]; then
        git_plain "$top" hash-object --no-filters -- "$file"
      else
        batch+=("$file")
      fi
    fi
  done
  if [[ ${#batch[@]} -gt 0 ]]; then
    printf '%s\n' "${batch[@]}" | git_plain "$top" hash-object --no-filters --stdin-paths
  fi
}

# --- fingerprint ----------------------------------------------------------------------------------

# fingerprint_of <basis> — the first 16 hex digits of the SHA-256 of `boundary|source|sink|root_cause`.
fingerprint_of() {
  printf '%s' "$1" | sha256_hex | cut -c1-16
}

cmd_fingerprint() {
  [[ $# -eq 4 ]] || { usage; exit 1; }
  local found
  found="$(schema_problems "$SCHEMA_DIR/findings.schema.json" \
    <(jq -n --arg b "$1" --arg so "$2" --arg si "$3" --arg r "$4" '{boundary: $b, source: $so, sink: $si, root_cause: $r}') \
    "#/\$defs/fingerprint_basis")"
  if [[ -n "$found" ]]; then
    printf '%s: fingerprint basis is invalid\n%s\n' "$PROG" "$found" >&2
    exit 1
  fi
  fingerprint_of "$1|$2|$3|$4"
}

# --- validation -----------------------------------------------------------------------------------

# The program text below ships with this script. An artefact never contributes to it.
JQ_SCHEMA=''
IFS= read -r -d '' JQ_SCHEMA <<'EOF' || true
def typeok($v; $t):
  ($v | type) as $jt
  | if $t == "integer" then ($jt == "number" and $v == ($v | floor))
    elif $t == "number" then $jt == "number"
    else $jt == $t end;

def errs($root; $s0; $v; $p):
  (if $s0 | has("$ref") then ($root | getpath($s0["$ref"] | ltrimstr("#/") | split("/"))) else $s0 end) as $s
  | ($v | type) as $vt
  | [
      (if $s | has("type") then
         ([$s["type"]] | flatten) as $ts
         | if any($ts[]; typeok($v; .)) then empty else "\($p): expected \($ts | join(" or ")), got \($vt)" end
       else empty end),
      (if $s | has("const") then
         (if $v == $s["const"] then empty else "\($p): must equal \($s["const"] | tojson), got \($v | tojson)" end)
       else empty end),
      (if $s | has("enum") then
         (if any($s["enum"][]; . == $v) then empty else "\($p): must be one of \($s["enum"] | map(tojson) | join(", ")), got \($v | tojson)" end)
       else empty end),
      (if $vt == "string" then
         (if ($s | has("minLength")) and (($v | length) < $s["minLength"]) then "\($p): shorter than \($s["minLength"])" else empty end),
         (if ($s | has("pattern")) and (($v | test($s["pattern"])) | not) then "\($p): does not match \($s["pattern"])" else empty end)
       else empty end),
      (if $vt == "number" then
         (if ($s | has("minimum")) and ($v < $s["minimum"]) then "\($p): below the minimum \($s["minimum"])" else empty end),
         (if ($s | has("maximum")) and ($v > $s["maximum"]) then "\($p): above the maximum \($s["maximum"])" else empty end)
       else empty end),
      (if $vt == "array" then
         (if ($s | has("minItems")) and (($v | length) < $s["minItems"]) then "\($p): fewer than \($s["minItems"]) items" else empty end),
         (if $s | has("items") then (range(0; $v | length) as $i | errs($root; $s["items"]; $v[$i]; "\($p)[\($i)]")[]) else empty end),
         (if ($s | has("contains")) and (all($v[]; errs($root; $s["contains"]; .; $p) | length > 0)) then "\($p): no item matches \($s["contains"] | tojson)" else empty end)
       else empty end),
      (if $vt == "object" then
         (if $s | has("required") then ($s["required"][] | select(. as $k | ($v | has($k)) | not) | "\($p): missing required property \(.)") else empty end),
         (if $s | has("properties") then ($s["properties"] | to_entries[] | select(. as $e | $v | has($e.key)) | . as $e | errs($root; $e.value; $v[$e.key]; "\($p).\($e.key)")[]) else empty end),
         (if ($s | has("additionalProperties")) and ($s["additionalProperties"] == false) then
            ($v | keys[] | select(. as $k | ($s["properties"] // {}) | has($k) | not) | "\($p): unexpected property \(.)")
          else empty end)
       else empty end),
      (if $s | has("allOf") then ($s["allOf"][] | errs($root; .; $v; $p)[]) else empty end),
      (if ($s | has("anyOf")) and (all($s["anyOf"][]; errs($root; .; $v; $p) | length > 0)) then "\($p): matches none of the anyOf alternatives" else empty end),
      (if $s | has("if") then
         (if (errs($root; $s["if"]; $v; $p) | length) == 0 then
            (if $s | has("then") then errs($root; $s["then"]; $v; $p)[] else empty end)
          else
            (if $s | has("else") then errs($root; $s["else"]; $v; $p)[] else empty end)
          end)
       else empty end)
    ];

errs($schema[0]; (if $start == "" then $schema[0] else {"$ref": $start} end); $doc[0]; "$")[]
EOF

# The inventory rules JSON Schema cannot express: the ledger is a plan, nothing has been hunted yet.
JQ_INVENTORY=''
IFS= read -r -d '' JQ_INVENTORY <<'EOF' || true
$lg[0].entries as $es
| ($es | map(.coverage_id)) as $ids
| (
    (if ($ids | length) != ($ids | unique | length) then "coverage: a coverage_id appears more than once" else empty end),
    ($es[] | select(.status != "queued" and .status != "out_of_scope") | "inventory: \(.coverage_id) is \(.status) before the first hunter opened"),
    ($es[] | select(.agent != null) | "inventory: \(.coverage_id) already names agent \(.agent)"),
    ($es[] | select((.findings | length) > 0) | "inventory: \(.coverage_id) already lists findings"),
    (if ($lg[0].waves | length) > 0 then "inventory: a hunting wave is recorded before the inventory was checked" else empty end)
  )
EOF

# The rules JSON Schema cannot express: they relate fields, or relate the three artefacts.
JQ_CROSS=''
IFS= read -r -d '' JQ_CROSS <<'EOF' || true
$md[0].budget as $b
| $md[0].spent as $sp
| $fd[0].findings as $fs
| $lg[0].entries as $es
| $lg[0].waves as $ws
| ($fs | map(.fingerprint)) as $fps
| ($es | map(.coverage_id)) as $ids
| ([$ws[].hunters[]]) as $wh
| ($es | map(.agent | select(. != null))) as $agents
| ($fs | map(.verifier | select(. != null))) as $vs
| ($ws | map(.critic)) as $cs
| (
    (if ($b.hunting_max + $b.verifier_reserve + $b.coverage_critic_reserve) > $b.total
     then "budget: hunting_max plus both reserves exceed the total" else empty end),
    (if $sp.hunting > $b.hunting_max
     then "budget: hunting spent \($sp.hunting) passes, above hunting_max \($b.hunting_max), so it consumed the reserve for verifiers and coverage critics" else empty end),
    (if ($sp.hunting + $sp.verification + $sp.coverage_critic) > $b.total
     then "budget: \($sp.hunting + $sp.verification + $sp.coverage_critic) passes spent, above the total \($b.total)" else empty end),
    (["confirmed", "needs_validation", "rejected"][] as $v
     | ($fs | map(select(.verdict == $v)) | length) as $n
     | if $md[0].counts[$v] != $n then "metadata: counts.\($v) is \($md[0].counts[$v]) but findings.json holds \($n)" else empty end),
    (if ($fps | length) != ($fps | unique | length) then "findings: a fingerprint appears more than once" else empty end),
    (if ($ids | length) != ($ids | unique | length) then "coverage: a coverage_id appears more than once" else empty end),
    ($es[] | select(.status == "queued" or .status == "candidate") | "coverage: \(.coverage_id) is still \(.status) in a finished run"),
    ($es[] | .coverage_id as $id | .findings[] | . as $f | select(any($fps[]; . == $f) | not) | "coverage: \($id) lists fingerprint \($f) that findings.json does not hold"),
    ($es[] | . as $e | .findings[] | . as $f | ($fs | map(select(.fingerprint == $f)) | first) as $x
     | select($x != null and ($e.agent == null or (any($x.hunters[]; . == $e.agent) | not)))
     | "coverage: \($e.coverage_id) lists \($f), but its agent is not one of that finding's hunters"),
    (if ($ws | map(.wave)) != [range(1; ($ws | length) + 1)] then "coverage: the waves are not numbered 1 to \($ws | length) in order" else empty end),
    (if ($cs | length) != ($cs | unique | length) then "critic: a coverage critic reviewed more than one wave; every wave gets a fresh critic" else empty end),
    (if ($ws | length) > $sp.coverage_critic then "critic: \($ws | length) waves but \($sp.coverage_critic) coverage-critic passes spent; every wave needs its own critic pass" else empty end),
    (if ($wh | length) != ($wh | unique | length) then "coverage: a hunter is opened more than once; one hunter is one pass on one coverage unit" else empty end),
    (if ($wh | length) > $sp.hunting then "coverage: the waves open \($wh | length) hunters but spent.hunting is \($sp.hunting)" else empty end),
    (if ($agents | length) != ($agents | unique | length) then "coverage: a hunter holds more than one coverage unit" else empty end),
    ($es[] | select(.agent != null) | . as $e | select(any($wh[]; . == $e.agent) | not) | "coverage: \(.coverage_id) names agent \(.agent), which no wave opened"),
    ($ws[] | .wave as $n | .gaps[] | select(has("coverage_id")) | .coverage_id as $c | select(any($ids[]; . == $c) | not)
     | "critic: wave \($n) closes a gap with \($c), which the ledger does not hold"),
    ($ws[] | select(.worktree != $md[0].worktree_baseline) | "parallel: the working tree changed during wave \(.wave); every worker must stay read-only"),
    (if ($vs | length) != ($vs | unique | length) then "findings: a verifier verified more than one finding; every candidate gets a new verifier" else empty end),
    (if ($vs | length) > $sp.verification then "findings: \($vs | length) verifiers but spent.verification is \($sp.verification)" else empty end),
    ($fs[] | .fingerprint as $f | .hunters[] | . as $h | select(any($wh[]; . == $h) | not) | "findings: \($f) names hunter \($h), which no wave opened")
  )
EOF

# schema_problems <schema-file> <document-file> [<$ref to start at>] — one problem per line on stdout.
schema_problems() {
  jq -n -r --slurpfile schema "$1" --slurpfile doc "$2" --arg start "${3:-}" "$JQ_SCHEMA"
}

# json_document <file> — exits non-zero unless the file holds exactly one JSON document.
json_document() {
  [[ "$(jq -s 'length' "$1" 2>/dev/null)" == 1 ]]
}

# emit_result <run-dir> <problems> — prints the result document and exits 3 on any problem.
emit_result() {
  local problems valid=true
  problems="$(printf '%s' "$2" | sed '/^$/d')"
  [[ -n "$problems" ]] && valid=false
  printf '%s' "$problems" | jq -Rsc --arg dir "$1" --argjson valid "$valid" \
    '{valid: $valid, run_dir: $dir, problems: (split("\n") | map(select(length > 0)))}'
  [[ "$valid" == true ]] || exit 3
}

require_run_dir() {
  if [[ ! -d "$1" ]]; then
    echo "$PROG: not a directory: $(printable "$1")" >&2
    exit 1
  fi
}

cmd_inventory() {
  [[ $# -eq 1 ]] || { usage; exit 1; }
  local dir="$1" file problems='' found
  require_run_dir "$dir"
  file="$dir/coverage-ledger.json"
  if [[ ! -f "$file" ]]; then
    problems="coverage-ledger.json: missing"
  elif ! git_plain "$dir" check-ignore -q coverage-ledger.json 2>/dev/null; then
    problems="inventory: the run directory is not git-ignored, so the coordinator's own writes would change the working-tree snapshot"
  elif ! json_document "$file"; then
    problems="coverage-ledger.json: not valid JSON"
  else
    found="$(schema_problems "$SCHEMA_DIR/coverage.schema.json" "$file")"
    if [[ -n "$found" ]]; then
      problems="$(printf '%s\n' "$found" | sed 's|^|coverage-ledger.json |')"
    else
      problems="$(jq -n -r --slurpfile lg "$file" "$JQ_INVENTORY")"
    fi
  fi
  emit_result "$dir" "$problems"
}

# fingerprint_problems <findings-file> — one line per finding whose fingerprint is not the hash of
# its fingerprint_basis.
fingerprint_problems() {
  local count i fingerprint basis expected
  count="$(jq '.findings | length' "$1")"
  for ((i = 0; i < count; i++)); do
    fingerprint="$(jq -r --argjson i "$i" '.findings[$i].fingerprint' "$1")"
    basis="$(jq -r --argjson i "$i" '.findings[$i].fingerprint_basis | [.boundary, .source, .sink, .root_cause] | join("|")' "$1")"
    expected="$(fingerprint_of "$basis")"
    if [[ "$fingerprint" != "$expected" ]]; then
      printf 'findings: %s is not the fingerprint of its fingerprint_basis, which hashes to %s\n' "$fingerprint" "$expected"
    fi
  done
}

cmd_validate() {
  [[ $# -eq 1 ]] || { usage; exit 1; }
  local dir="$1" problems='' ok_json=true name file schema found
  require_run_dir "$dir"

  for name in metadata coverage-ledger findings; do
    file="$dir/$name.json"
    if [[ ! -f "$file" ]]; then
      problems+="$name.json: missing"$'\n'
      ok_json=false
      continue
    fi
    if ! json_document "$file"; then
      problems+="$name.json: not valid JSON"$'\n'
      ok_json=false
      continue
    fi
    case "$name" in
    metadata) schema="$SCHEMA_DIR/metadata.schema.json" ;;
    coverage-ledger) schema="$SCHEMA_DIR/coverage.schema.json" ;;
    *) schema="$SCHEMA_DIR/findings.schema.json" ;;
    esac
    found="$(schema_problems "$schema" "$file")"
    if [[ -n "$found" ]]; then
      problems+="$(printf '%s\n' "$found" | sed "s|^|$name.json |")"$'\n'
      ok_json=false
    fi
  done

  if [[ "$ok_json" == true ]]; then
    local expected
    expected="$(cmd_budget --profile "$(jq -r .profile "$dir/metadata.json")" --total "$(jq -r .budget.total "$dir/metadata.json")" 2>/dev/null)" || expected=''
    jq -e --argjson want "${expected:-null}" '.budget == $want' "$dir/metadata.json" >/dev/null \
      || problems+="metadata: budget is not the split audit-artifacts.sh budget prints for this profile and total"$'\n'
    problems+="$(jq -n -r --slurpfile md "$dir/metadata.json" --slurpfile lg "$dir/coverage-ledger.json" --slurpfile fd "$dir/findings.json" "$JQ_CROSS")"$'\n'
    problems+="$(fingerprint_problems "$dir/findings.json")"$'\n'
  fi

  if [[ ! -s "$dir/REPORT.md" ]]; then
    problems+="REPORT.md: missing or empty"$'\n'
  else
    local heading
    for heading in "${REPORT_HEADINGS[@]}"; do
      grep -qxF "$heading" "$dir/REPORT.md" || problems+="REPORT.md: missing heading '$heading'"$'\n'
    done
  fi

  emit_result "$dir" "$problems"
}

# --- self-test ------------------------------------------------------------------------------------

self_test() {
  require_tools
  local checks=0 failed=0
  scratch="$(mktemp -d)"
  trap 'rm -rf "$scratch"' EXIT
  # Every fixture run directory lives in the git-ignored run directory of a scratch project, as a
  # real run does, so inventory accepts it.
  git init -q "$scratch/project"
  printf '.claude/run/\n' >"$scratch/project/.gitignore"
  tmp="$scratch/project/.claude/run/security-audit"
  mkdir -p "$tmp"

  pass() {
    checks=$((checks + 1))
    printf 'ok    %s\n' "$1"
  }
  fail() {
    failed=$((failed + 1))
    printf 'FAIL  %s\n' "$1" >&2
  }

  # expect_exit <label> <expected> <command...>
  expect_exit() {
    local label="$1" want="$2" rc=0
    shift 2
    ("$@") >"$tmp/out" 2>"$tmp/err" || rc=$?
    if [[ "$rc" -eq "$want" ]]; then pass "$label"; else fail "$label (exit $rc, wanted $want)"; fi
  }

  # expect_problem <label> <text> — the last command's output names the problem.
  expect_problem() {
    if grep -qF "$2" "$tmp/out"; then pass "$1"; else fail "$1 (no problem containing '$2')"; fi
  }

  local baseline='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'
  local basis_a='unauthenticated_to_authenticated|POST /login|src/Auth/Login.php#LoginController::store|CWE-89'
  local basis_b='user_to_administrator|GET /admin/users|src/Admin/Users.php#UsersController::index|CWE-285'
  local basis_c='tenant_to_tenant|GET /invoices/{id}|src/Billing/Invoices.php#InvoiceRepository::find|CWE-639'
  local fp_a fp_b fp_c
  fp_a="$(fingerprint_of "$basis_a")"
  fp_b="$(fingerprint_of "$basis_b")"
  fp_c="$(fingerprint_of "$basis_c")"

  # build_run <dir> <profile> — a valid run directory for the profile: four coverage units, two
  # waves with a fresh critic each, and one finding per verdict. Finding A was found by two hunters
  # and merged on its fingerprint. All three findings share the same prose and differ in their basis.
  build_run() {
    local dir="$1" profile="$2" budget
    mkdir -p "$dir"
    budget="$(cmd_budget --profile "$profile")"
    jq -n --arg profile "$profile" --argjson budget "$budget" --arg baseline "$baseline" '{
      schema_version: 2, run_id: ("audit-" + $profile), profile: $profile,
      scope: {kind: "repository", target: "."}, commit: "0123456789abcdef0123456789abcdef01234567",
      worktree_baseline: $baseline,
      started_at: "2026-01-01T10:00:00Z", finished_at: "2026-01-01T11:00:00Z",
      budget: $budget,
      spent: {hunting: ($budget.hunting_max - 1), verification: 3, coverage_critic: 2},
      counts: {confirmed: 1, needs_validation: 1, rejected: 1}}' >"$dir/metadata.json"
    jq -n --arg a "$fp_a" --arg b "$fp_b" --arg c "$fp_c" --arg baseline "$baseline" '{schema_version: 2, entries: [
      {coverage_id: "C1", surface: "http_entrypoint", boundary: "unauthenticated_to_authenticated", subsystem: "Auth",
       attack_class: "injection_input", paths: ["src/Auth/Login.php"], status: "covered", agent: "hunter-1", findings: [$a, $b]},
      {coverage_id: "C2", surface: "ci_deploy", boundary: "repository_to_ci_runner", subsystem: "Docs build",
       attack_class: "wildcard", paths: ["docs"], status: "out_of_scope", agent: null, findings: [],
       reason: "the user scoped the audit to the application code"},
      {coverage_id: "C3", surface: "authorization", boundary: "user_to_administrator", subsystem: "Admin",
       attack_class: "auth_authz", paths: ["src/Admin"], status: "covered", agent: "hunter-2", findings: [$a, $c]},
      {coverage_id: "C4", surface: "http_entrypoint", boundary: "user_to_administrator", subsystem: "Admin",
       attack_class: "injection_input", paths: ["src/Admin/Export.php"], status: "covered", agent: "hunter-3", findings: []}],
      waves: [
        {wave: 1, hunters: ["hunter-1", "hunter-2"], critic: "critic-1", worktree: $baseline, gaps: [
          {kind: "missing_attack_class", detail: "no unit hunted the admin export for injection",
           resolution: "opened C4 and hunted it in wave 2", coverage_id: "C4"}]},
        {wave: 2, hunters: ["hunter-3"], critic: "critic-2", worktree: $baseline, gaps: []}]}' >"$dir/coverage-ledger.json"
    jq -n --arg ba "$basis_a" --arg bb "$basis_b" --arg bc "$basis_c" --arg a "$fp_a" --arg b "$fp_b" --arg c "$fp_c" '
      def basis($s): ($s | split("|")) as $p | {boundary: $p[0], source: $p[1], sink: $p[2], root_cause: $p[3]};
      def base($fp; $s; $verdict; $severity; $demonstrated; $hunters; $verifier; $controls): {
        fingerprint: $fp, fingerprint_basis: basis($s), verdict: $verdict, severity: $severity,
        boundary: "unauthenticated request to account data",
        entrypoint: "POST /login", sink: "DB::select with an interpolated email", root_cause: "the email is concatenated into the query",
        evidence: [{location: "src/Auth/Login.php:42", observation: "the request email reaches the query string"}],
        remediation: "bind the email as a parameter", boundary_failure_demonstrated: $demonstrated,
        hunters: $hunters, verifier: $verifier, controls: $controls};
      def walked: [
        {kind: "middleware", effect: "does_not_stop", location: "routes/web.php:12", observation: "the route carries only the throttle middleware"},
        {kind: "policy", effect: "absent"}, {kind: "framework_protection", effect: "absent"},
        {kind: "validation", effect: "absent"}, {kind: "sanitization", effect: "absent"}, {kind: "configuration", effect: "absent"}];
      {schema_version: 2, findings: [
        base($a; $ba; "confirmed"; "high"; true; ["hunter-1", "hunter-2"]; "verifier-1"; walked),
        (base($b; $bb; "needs_validation"; "unrated"; false; ["hunter-1"]; "verifier-2";
          [{kind: "policy", effect: "unclear", location: "src/Policies/UserPolicy.php:20", observation: "the policy reads a role the code does not set"}])
          + {validation_needed: "confirm the route is reachable without a session"}),
        (base($c; $bc; "rejected"; "unrated"; false; ["hunter-2"]; "verifier-3";
          [{kind: "framework_protection", effect: "stops", location: "src/Billing/Invoices.php:30", observation: "the global tenant scope filters the query"}])
          + {rejection_reason: "the global tenant scope stops the cross-tenant read"})]}' >"$dir/findings.json"
    {
      printf '# Security audit — fixture\n\n'
      local heading
      for heading in "${REPORT_HEADINGS[@]}"; do
        printf '%s\n\nNone.\n\n' "$heading"
      done
    } >"$dir/REPORT.md"
  }

  # mutate <dir> <file> <jq-filter> — rewrites one artefact of a run directory.
  mutate() {
    jq "$3" "$1/$2" >"$1/$2.new" && mv "$1/$2.new" "$1/$2"
  }

  # fresh <label> — a new valid standard run in $tmp/<label>.
  fresh() {
    build_run "$tmp/$1" standard
  }

  # inventory_of <label> — the inventory the fixture run wrote before the first hunter opened.
  inventory_of() {
    fresh "$1"
    mutate "$tmp/$1" coverage-ledger.json '.waves = [] | .entries |= map(.findings = [] | if .status == "out_of_scope" then . else (.status = "queued" | .agent = null) end)'
  }

  local profile
  for profile in quick standard deep; do
    build_run "$tmp/$profile" "$profile"
    expect_exit "profile $profile: four artefacts exist and validate" 0 cmd_validate "$tmp/$profile"
    if [[ -f "$tmp/$profile/metadata.json" && -f "$tmp/$profile/coverage-ledger.json" && -f "$tmp/$profile/findings.json" && -f "$tmp/$profile/REPORT.md" ]]; then
      pass "profile $profile: metadata.json, coverage-ledger.json, findings.json, REPORT.md written"
    else
      fail "profile $profile: an artefact is missing"
    fi
    if jq -e '.budget | .hunting_max >= 1 and .verifier_reserve >= 1 and .coverage_critic_reserve >= 1
      and (.hunting_max + .verifier_reserve + .coverage_critic_reserve) == .total' "$tmp/$profile/metadata.json" >/dev/null; then
      pass "profile $profile: budget keeps both reserves and sums to the total"
    else
      fail "profile $profile: budget reserves"
    fi
  done

  expect_exit 'budget: an unknown profile is refused' 1 cmd_budget --profile exhaustive
  expect_exit 'budget: the risk tier names are not profiles' 1 cmd_budget --profile CRITICAL
  expect_exit 'budget: a total that leaves hunting nothing is refused' 1 cmd_budget --profile standard --total 2
  expect_exit 'budget: a non-numeric total is refused' 1 cmd_budget --profile standard --total many
  expect_exit 'budget: a total with a leading zero is refused, not read as octal' 1 cmd_budget --profile standard --total 010
  expect_exit 'budget: a total of 08 is refused with the usage message' 1 cmd_budget --profile standard --total 08
  expect_exit 'budget: a total of zero is refused' 1 cmd_budget --profile standard --total 0
  expect_exit 'budget: the smallest total that leaves hunting one pass is accepted' 0 cmd_budget --profile quick --total 3
  if [[ "$(cmd_budget --profile quick --total 3)" == '{"unit":"passes","total":3,"hunting_max":1,"verifier_reserve":1,"coverage_critic_reserve":1}' ]]; then
    pass 'budget: an explicit total is split deterministically'
  else
    fail 'budget: explicit total split'
  fi
  if [[ "$(cmd_budget --profile deep --total 100)" == '{"unit":"passes","total":100,"hunting_max":45,"verifier_reserve":35,"coverage_critic_reserve":20}' ]]; then
    pass 'budget: deep reserves 35 and 20 of 100 passes'
  else
    fail 'budget: deep split'
  fi

  fresh hunting-exhausts
  mutate "$tmp/hunting-exhausts" metadata.json '.spent.hunting = .budget.total'
  expect_exit 'edge: hunting cannot spend the whole budget' 3 cmd_validate "$tmp/hunting-exhausts"
  expect_problem 'edge: the problem names the consumed reserve' 'consumed the reserve'

  fresh hunting-over-max
  mutate "$tmp/hunting-over-max" metadata.json '.spent.hunting = .budget.hunting_max + 1'
  expect_exit 'edge: hunting cannot pass hunting_max by one pass' 3 cmd_validate "$tmp/hunting-over-max"

  fresh no-reserve
  mutate "$tmp/no-reserve" metadata.json '.budget.verifier_reserve = 0'
  expect_exit 'edge: a budget with no verifier reserve is invalid' 3 cmd_validate "$tmp/no-reserve"

  fresh no-critic-reserve
  mutate "$tmp/no-critic-reserve" metadata.json '.budget.coverage_critic_reserve = 0'
  expect_exit 'edge: a budget with no coverage-critic reserve is invalid' 3 cmd_validate "$tmp/no-critic-reserve"

  fresh overspent
  mutate "$tmp/overspent" metadata.json '.spent.verification = .budget.total'
  expect_exit 'edge: spending above the total is invalid' 3 cmd_validate "$tmp/overspent"

  fresh severity-without-proof
  mutate "$tmp/severity-without-proof" findings.json '.findings[1].severity = "high"'
  expect_exit 'edge: needs_validation with a definitive severity and no demonstrated failure is invalid' 3 cmd_validate "$tmp/severity-without-proof"

  fresh severity-with-proof
  mutate "$tmp/severity-with-proof" findings.json '.findings[1].severity = "high" | .findings[1].boundary_failure_demonstrated = true'
  expect_exit 'edge: needs_validation with a demonstrated boundary failure may carry a severity' 0 cmd_validate "$tmp/severity-with-proof"

  fresh confirmed-unrated
  mutate "$tmp/confirmed-unrated" findings.json '.findings[0].severity = "unrated"'
  expect_exit 'lifecycle: a confirmed finding needs a definitive severity' 3 cmd_validate "$tmp/confirmed-unrated"

  fresh confirmed-undemonstrated
  mutate "$tmp/confirmed-undemonstrated" findings.json '.findings[0].boundary_failure_demonstrated = false'
  expect_exit 'lifecycle: a confirmed finding needs a demonstrated boundary failure' 3 cmd_validate "$tmp/confirmed-undemonstrated"

  fresh needs-no-step
  mutate "$tmp/needs-no-step" findings.json 'del(.findings[1].validation_needed)'
  expect_exit 'lifecycle: needs_validation names what must be validated' 3 cmd_validate "$tmp/needs-no-step"

  fresh rejected-no-reason
  mutate "$tmp/rejected-no-reason" findings.json 'del(.findings[2].rejection_reason)'
  expect_exit 'lifecycle: a rejected finding states why' 3 cmd_validate "$tmp/rejected-no-reason"

  fresh rejected-demonstrated
  mutate "$tmp/rejected-demonstrated" findings.json '.findings[2].boundary_failure_demonstrated = true'
  expect_exit 'lifecycle: a rejected finding cannot claim a demonstrated boundary failure' 3 cmd_validate "$tmp/rejected-demonstrated"

  fresh budget-resplit
  mutate "$tmp/budget-resplit" metadata.json '.budget.hunting_max = 58 | .budget.verifier_reserve = 1 | .budget.coverage_critic_reserve = 1'
  expect_exit 'cross: the budget is the split the budget command prints for the profile and total' 3 cmd_validate "$tmp/budget-resplit"
  expect_problem 'cross: the problem names the budget mismatch' 'budget is not the split'

  fresh budget-total-zero
  mutate "$tmp/budget-total-zero" metadata.json '.budget.total = 0'
  expect_exit 'cross: a total the budget command refuses is a mismatch, not a crash' 3 cmd_validate "$tmp/budget-total-zero"

  fresh bad-verdict
  mutate "$tmp/bad-verdict" findings.json '.findings[0].verdict = "probable"'
  expect_exit 'lifecycle: a verdict outside the three is invalid' 3 cmd_validate "$tmp/bad-verdict"

  local field
  for field in fingerprint fingerprint_basis verdict severity boundary entrypoint sink root_cause evidence remediation hunters verifier controls; do
    fresh "missing-$field"
    mutate "$tmp/missing-$field" findings.json "del(.findings[0].$field)"
    expect_exit "schema: a finding without $field is invalid" 3 cmd_validate "$tmp/missing-$field"
  done

  fresh empty-evidence
  mutate "$tmp/empty-evidence" findings.json '.findings[0].evidence = []'
  expect_exit 'schema: a finding needs at least one evidence item' 3 cmd_validate "$tmp/empty-evidence"

  fresh extra-property
  mutate "$tmp/extra-property" findings.json '.findings[0].note = "x"'
  expect_exit 'schema: an unknown finding property is invalid' 3 cmd_validate "$tmp/extra-property"

  fresh bad-fingerprint
  mutate "$tmp/bad-fingerprint" findings.json '.findings[0].fingerprint = "not-a-hash"'
  expect_exit 'schema: a fingerprint is 16 lowercase hex digits' 3 cmd_validate "$tmp/bad-fingerprint"

  # --- coverage inventory and units ---

  inventory_of inventory
  expect_exit 'inventory: the ledger written before hunting is a valid inventory' 0 cmd_inventory "$tmp/inventory"
  if jq -e '[.entries[] | select(.status == "queued")] | length == 3' "$tmp/inventory/coverage-ledger.json" >/dev/null; then
    pass 'inventory: every unit in scope starts queued'
  else
    fail 'inventory: queued units'
  fi

  inventory_of inventory-hunted
  mutate "$tmp/inventory-hunted" coverage-ledger.json '.entries[0].status = "covered" | .entries[0].agent = "hunter-1"'
  expect_exit 'inventory: a unit hunted before the inventory check is refused' 3 cmd_inventory "$tmp/inventory-hunted"
  expect_problem 'inventory: the problem names the unit hunted too early' 'before the first hunter opened'

  inventory_of inventory-wave
  mutate "$tmp/inventory-wave" coverage-ledger.json ".waves = [{wave: 1, hunters: [\"hunter-1\"], critic: \"critic-1\", worktree: \"$baseline\", gaps: []}]"
  expect_exit 'inventory: a wave recorded before the inventory check is refused' 3 cmd_inventory "$tmp/inventory-wave"

  inventory_of inventory-duplicate-id
  mutate "$tmp/inventory-duplicate-id" coverage-ledger.json '.entries[1].coverage_id = "C1"'
  expect_exit 'inventory: a coverage_id appears once' 3 cmd_inventory "$tmp/inventory-duplicate-id"

  inventory_of inventory-agent
  mutate "$tmp/inventory-agent" coverage-ledger.json '.entries[0].agent = "hunter-1"'
  expect_exit 'inventory: a queued unit names no agent yet' 3 cmd_inventory "$tmp/inventory-agent"
  expect_problem 'inventory: the problem names the unit that already has an agent' 'C1 already names agent hunter-1'

  inventory_of inventory-findings
  mutate "$tmp/inventory-findings" coverage-ledger.json '.entries[0].findings = ["dddddddddddddddd"]'
  expect_exit 'inventory: a queued unit lists no finding yet' 3 cmd_inventory "$tmp/inventory-findings"
  expect_problem 'inventory: the problem names the unit that already lists findings' 'C1 already lists findings'

  local unignored="$scratch/unignored/.claude/run/security-audit"
  git init -q "$scratch/unignored"
  mkdir -p "$unignored"
  inventory_of inventory-unignored
  mv "$tmp/inventory-unignored" "$unignored/"
  expect_exit 'inventory: a run directory that is not git-ignored is refused' 3 cmd_inventory "$unignored/inventory-unignored"
  expect_problem 'inventory: the problem names the run directory that is not git-ignored' 'the run directory is not git-ignored'
  printf '.claude/run/\n' >"$scratch/unignored/.gitignore"
  expect_exit 'inventory: the same run directory passes once the project ignores it' 0 cmd_inventory "$unignored/inventory-unignored"

  mkdir -p "$tmp/inventory-missing"
  expect_exit 'inventory: a missing coverage-ledger.json is refused' 3 cmd_inventory "$tmp/inventory-missing"
  expect_exit 'usage: inventory without a directory' 1 cmd_inventory

  for field in coverage_id surface boundary subsystem attack_class paths status agent findings; do
    inventory_of "unit-missing-$field"
    mutate "$tmp/unit-missing-$field" coverage-ledger.json "del(.entries[0].$field)"
    expect_exit "schema: a coverage unit without $field is invalid" 3 cmd_inventory "$tmp/unit-missing-$field"
  done

  local area
  for area in auth_authz injection_input files_ssrf queues_webhooks data_isolation ai_tools wildcard; do
    inventory_of "area-$area"
    mutate "$tmp/area-$area" coverage-ledger.json ".entries[0].attack_class = \"$area\""
    expect_exit "hunting area: $area is a valid attack class" 0 cmd_inventory "$tmp/area-$area"
  done

  inventory_of unknown-area
  mutate "$tmp/unknown-area" coverage-ledger.json '.entries[0].attack_class = "csrf"'
  expect_exit 'hunting area: an attack class outside the seven is invalid' 3 cmd_inventory "$tmp/unknown-area"

  inventory_of unknown-surface
  mutate "$tmp/unknown-surface" coverage-ledger.json '.entries[0].surface = "everything"'
  expect_exit 'schema: a surface outside the reconnaissance list is invalid' 3 cmd_inventory "$tmp/unknown-surface"

  fresh in-review-status
  mutate "$tmp/in-review-status" coverage-ledger.json '.entries[0].status = "in_review"'
  expect_exit 'schema: a status outside the six is invalid' 3 cmd_validate "$tmp/in-review-status"

  fresh queued-row
  mutate "$tmp/queued-row" coverage-ledger.json '.entries[0].status = "queued"'
  expect_exit 'cross: a finished run leaves no queued unit' 3 cmd_validate "$tmp/queued-row"

  fresh candidate-row
  mutate "$tmp/candidate-row" coverage-ledger.json '.entries[0].status = "candidate"'
  expect_exit 'cross: a finished run leaves no unverified candidate unit' 3 cmd_validate "$tmp/candidate-row"

  fresh ghost-fingerprint
  mutate "$tmp/ghost-fingerprint" coverage-ledger.json '.entries[0].findings += ["dddddddddddddddd"]'
  expect_exit 'cross: a unit lists only fingerprints findings.json holds' 3 cmd_validate "$tmp/ghost-fingerprint"

  fresh skip-no-reason
  mutate "$tmp/skip-no-reason" coverage-ledger.json 'del(.entries[1].reason)'
  expect_exit 'schema: an out_of_scope unit states why' 3 cmd_validate "$tmp/skip-no-reason"

  local status
  for status in blocked deferred; do
    fresh "$status-row"
    mutate "$tmp/$status-row" coverage-ledger.json ".entries[1].status = \"$status\" | .entries[1].reason = \"the unit could not be hunted\""
    expect_exit "coverage: a $status unit with a reason is a valid final state" 0 cmd_validate "$tmp/$status-row"
    mutate "$tmp/$status-row" coverage-ledger.json 'del(.entries[1].reason)'
    expect_exit "schema: a $status unit states why" 3 cmd_validate "$tmp/$status-row"
  done

  fresh covered-no-hunter
  mutate "$tmp/covered-no-hunter" coverage-ledger.json '.entries[3].agent = null'
  expect_exit 'schema: a covered unit names the hunter that covered it' 3 cmd_validate "$tmp/covered-no-hunter"

  # --- waves, coverage critics, and read-only parallel hunting ---

  fresh no-wave
  mutate "$tmp/no-wave" coverage-ledger.json '.waves = []'
  expect_exit 'coverage: a run whose units name hunters but records no wave is invalid' 3 cmd_validate "$tmp/no-wave"
  expect_problem 'coverage: the problem names the hunter no wave opened' 'C1 names agent hunter-1, which no wave opened'

  build_run "$tmp/quick-all-deferred" quick
  mutate "$tmp/quick-all-deferred" coverage-ledger.json '.waves = [] | .entries |= map(.findings = [] | .agent = null
    | if .status == "out_of_scope" then . else (.status = "deferred" | .attack_class = "wildcard" | .reason = "outside the quick profile") end)'
  mutate "$tmp/quick-all-deferred" findings.json '.findings = []'
  mutate "$tmp/quick-all-deferred" metadata.json '.spent = {hunting: 0, verification: 0, coverage_critic: 0} | .counts = {confirmed: 0, needs_validation: 0, rejected: 0}'
  expect_exit 'coverage: a quick run that hunts no unit validates with no wave' 0 cmd_validate "$tmp/quick-all-deferred"

  fresh reused-critic
  mutate "$tmp/reused-critic" coverage-ledger.json '.waves[1].critic = "critic-1"'
  expect_exit 'critic: every wave gets a fresh coverage critic' 3 cmd_validate "$tmp/reused-critic"
  expect_problem 'critic: the problem names the reused critic' 'every wave gets a fresh critic'

  fresh critic-unpaid
  mutate "$tmp/critic-unpaid" metadata.json '.spent.coverage_critic = 1'
  expect_exit 'critic: every wave spends its own coverage-critic pass' 3 cmd_validate "$tmp/critic-unpaid"

  fresh wave-order
  mutate "$tmp/wave-order" coverage-ledger.json '.waves[0].wave = 2 | .waves[1].wave = 1'
  expect_exit 'critic: the waves are numbered in order' 3 cmd_validate "$tmp/wave-order"

  local kind
  for kind in missing_surface missing_attack_class uncovered_entrypoint unanalysed_boundary shallow_review assumption_based_finding; do
    fresh "gap-$kind"
    mutate "$tmp/gap-$kind" coverage-ledger.json ".waves[1].gaps = [{kind: \"$kind\", detail: \"the critic reported it\", resolution: \"recorded as a stated limit\"}]"
    expect_exit "critic: a $kind gap is a report the ledger keeps" 0 cmd_validate "$tmp/gap-$kind"
  done

  fresh gap-unknown-kind
  mutate "$tmp/gap-unknown-kind" coverage-ledger.json '.waves[0].gaps[0].kind = "looks_fine"'
  expect_exit 'critic: a gap kind outside the six is invalid' 3 cmd_validate "$tmp/gap-unknown-kind"

  fresh gap-unresolved
  mutate "$tmp/gap-unresolved" coverage-ledger.json 'del(.waves[0].gaps[0].resolution)'
  expect_exit 'critic: every gap states how it was closed' 3 cmd_validate "$tmp/gap-unresolved"

  fresh gap-ghost-unit
  mutate "$tmp/gap-ghost-unit" coverage-ledger.json '.waves[0].gaps[0].coverage_id = "C9"'
  expect_exit 'critic: a gap closes only with a unit the ledger holds' 3 cmd_validate "$tmp/gap-ghost-unit"

  fresh tree-changed
  mutate "$tmp/tree-changed" coverage-ledger.json '.waves[1].worktree = "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"'
  expect_exit 'parallel: a wave that changed the working tree is invalid' 3 cmd_validate "$tmp/tree-changed"
  expect_problem 'parallel: the problem names the wave that wrote' 'the working tree changed during wave 2'

  fresh hunter-reopened
  mutate "$tmp/hunter-reopened" coverage-ledger.json '.waves[1].hunters = ["hunter-3", "hunter-1"]'
  expect_exit 'parallel: a hunter is opened in one wave only' 3 cmd_validate "$tmp/hunter-reopened"
  expect_problem 'parallel: the problem names the reopened hunter' 'a hunter is opened more than once'

  fresh duplicate-unit
  mutate "$tmp/duplicate-unit" coverage-ledger.json '.entries[1].coverage_id = "C1"'
  expect_exit 'cross: a finished run holds every coverage_id once' 3 cmd_validate "$tmp/duplicate-unit"
  expect_problem 'cross: the problem names the duplicate coverage_id' 'a coverage_id appears more than once'

  fresh hunter-twice
  mutate "$tmp/hunter-twice" coverage-ledger.json '.waves[1].hunters = ["hunter-1"] | .entries[3].agent = "hunter-1"'
  expect_exit 'parallel: a hunter is opened once' 3 cmd_validate "$tmp/hunter-twice"

  fresh hunter-two-units
  mutate "$tmp/hunter-two-units" coverage-ledger.json '.entries[3].agent = "hunter-1"'
  expect_exit 'parallel: one hunter holds one coverage unit' 3 cmd_validate "$tmp/hunter-two-units"

  fresh agent-unopened
  mutate "$tmp/agent-unopened" coverage-ledger.json '.entries[3].agent = "hunter-9"'
  expect_exit 'parallel: a unit names only a hunter a wave opened' 3 cmd_validate "$tmp/agent-unopened"

  fresh hunters-unpaid
  mutate "$tmp/hunters-unpaid" metadata.json '.spent.hunting = 2'
  expect_exit 'budget: every hunter a wave opens spends a hunting pass' 3 cmd_validate "$tmp/hunters-unpaid"

  # --- independent verification ---

  fresh confirmed-unverified
  mutate "$tmp/confirmed-unverified" findings.json '.findings[0].verifier = null'
  expect_exit 'independence: a confirmed finding names its verifier' 3 cmd_validate "$tmp/confirmed-unverified"

  fresh rejected-unverified
  mutate "$tmp/rejected-unverified" findings.json '.findings[2].verifier = null'
  expect_exit 'independence: a rejected finding names its verifier' 3 cmd_validate "$tmp/rejected-unverified"

  fresh unreached
  mutate "$tmp/unreached" findings.json '.findings[1].verifier = null | .findings[1].controls = []'
  mutate "$tmp/unreached" metadata.json '.spent.verification = 2'
  expect_exit 'independence: a candidate no verifier reached stays needs_validation' 0 cmd_validate "$tmp/unreached"

  fresh self-confirmed
  mutate "$tmp/self-confirmed" findings.json '.findings[0].verifier = "hunter-1"'
  expect_exit 'independence: a hunter cannot confirm its own finding' 3 cmd_validate "$tmp/self-confirmed"

  fresh reused-verifier
  mutate "$tmp/reused-verifier" findings.json '.findings[2].verifier = "verifier-1"'
  expect_exit 'independence: every candidate gets a new verifier' 3 cmd_validate "$tmp/reused-verifier"
  expect_problem 'independence: the problem names the reused verifier' 'every candidate gets a new verifier'

  fresh verifiers-unpaid
  mutate "$tmp/verifiers-unpaid" metadata.json '.spent.verification = 2'
  expect_exit 'budget: every verifier spends a verification pass' 3 cmd_validate "$tmp/verifiers-unpaid"

  fresh finding-unopened-hunter
  mutate "$tmp/finding-unopened-hunter" findings.json '.findings[1].hunters = ["hunter-7"]'
  expect_exit 'independence: a finding names only hunters a wave opened' 3 cmd_validate "$tmp/finding-unopened-hunter"

  fresh finding-extra-hunter
  mutate "$tmp/finding-extra-hunter" findings.json '.findings[1].hunters = ["hunter-1", "hunter-7"]'
  expect_exit 'independence: every hunter a finding names was opened by a wave' 3 cmd_validate "$tmp/finding-extra-hunter"
  expect_problem 'independence: the problem names the hunter no wave opened' 'names hunter hunter-7, which no wave opened'

  fresh unit-not-hunter
  mutate "$tmp/unit-not-hunter" findings.json '.findings[2].hunters = ["hunter-1"]'
  expect_exit 'independence: a unit lists only findings its own hunter proposed' 3 cmd_validate "$tmp/unit-not-hunter"

  # --- refutation: a control on the path never ends in confirmed ---

  local effect
  for kind in policy framework_protection middleware; do
    for effect in stops unclear; do
      fresh "confirmed-$kind-$effect"
      mutate "$tmp/confirmed-$kind-$effect" findings.json "(.findings[0].controls[] | select(.kind == \"$kind\")) |= {kind: \"$kind\", effect: \"$effect\", location: \"src/Http/Kernel.php:5\", observation: \"the control is on the path\"}"
      expect_exit "refute: a $kind with the effect $effect cannot be confirmed" 3 cmd_validate "$tmp/confirmed-$kind-$effect"
    done
  done

  for kind in middleware policy framework_protection validation sanitization configuration; do
    fresh "confirmed-unchecked-$kind"
    mutate "$tmp/confirmed-unchecked-$kind" findings.json ".findings[0].controls |= map(select(.kind != \"$kind\"))"
    expect_exit "refute: a confirmed finding records the $kind it checked" 3 cmd_validate "$tmp/confirmed-unchecked-$kind"
  done

  fresh control-no-location
  mutate "$tmp/control-no-location" findings.json 'del(.findings[2].controls[0].location)'
  expect_exit 'refute: a control the verifier found cites its location' 3 cmd_validate "$tmp/control-no-location"

  fresh control-unknown-kind
  mutate "$tmp/control-unknown-kind" findings.json '.findings[2].controls[0].kind = "firewall"'
  expect_exit 'refute: a control kind outside the six is invalid' 3 cmd_validate "$tmp/control-unknown-kind"

  # --- root-cause fingerprint and deduplication ---

  fresh basis-changed
  mutate "$tmp/basis-changed" findings.json '.findings[0].fingerprint_basis.root_cause = "CWE-78"'
  expect_exit 'dedup: a fingerprint is the hash of its fingerprint_basis' 3 cmd_validate "$tmp/basis-changed"
  expect_problem 'dedup: the problem names the fingerprint mismatch' 'is not the fingerprint of its fingerprint_basis'

  fresh duplicate-fingerprint
  mutate "$tmp/duplicate-fingerprint" findings.json '.findings[1].fingerprint_basis = .findings[0].fingerprint_basis | .findings[1].fingerprint = .findings[0].fingerprint'
  expect_exit 'dedup: two findings with the same root-cause fingerprint are one finding' 3 cmd_validate "$tmp/duplicate-fingerprint"
  expect_problem 'dedup: the problem names the duplicate fingerprint' 'a fingerprint appears more than once'

  if jq -e '[.findings[] | [.boundary, .entrypoint, .sink, .root_cause]] | unique | length == 1' "$tmp/standard/findings.json" >/dev/null \
    && jq -e '[.findings[].fingerprint] | unique | length == 3' "$tmp/standard/findings.json" >/dev/null; then
    pass 'dedup: identical prose with three root-cause bases stays three findings'
  else
    fail 'dedup: text similarity fixture'
  fi

  fresh basis-prose
  mutate "$tmp/basis-prose" findings.json '.findings[0].fingerprint_basis.root_cause = "the email is concatenated"'
  expect_exit 'dedup: a root cause in the basis is a CWE identifier, not prose' 3 cmd_validate "$tmp/basis-prose"

  expect_exit 'fingerprint: a valid basis prints its fingerprint' 0 cmd_fingerprint unauthenticated_to_authenticated 'POST /login' 'src/Auth/Login.php#LoginController::store' CWE-89
  if [[ "$(cmd_fingerprint unauthenticated_to_authenticated 'POST /login' 'src/Auth/Login.php#LoginController::store' CWE-89)" == "$fp_a" ]]; then
    pass 'fingerprint: the command prints the hash validate checks'
  else
    fail 'fingerprint: command and validator disagree'
  fi
  if [[ "$fp_a" =~ ^[a-f0-9]{16}$ && "$fp_a" != "$fp_b" ]]; then
    pass 'fingerprint: 16 hex digits, distinct per basis'
  else
    fail 'fingerprint: shape'
  fi
  expect_exit 'fingerprint: a sink without a symbol is refused' 1 cmd_fingerprint tenant_to_tenant 'GET /x' 'src/X.php' CWE-639
  expect_exit 'fingerprint: a basis value carrying the separator is refused' 1 cmd_fingerprint tenant_to_tenant 'GET /x|y' 'src/X.php#find' CWE-639
  expect_exit 'usage: fingerprint needs four values' 1 cmd_fingerprint tenant_to_tenant 'GET /x' 'src/X.php#find'

  # --- snapshot of the working tree ---

  local repo="$tmp/repo" before after
  mkdir -p "$repo"
  git -C "$repo" init -q
  printf 'run/\n' >"$repo/.gitignore"
  printf 'one\n' >"$repo/app.php.txt"
  git -C "$repo" add .gitignore app.php.txt
  git -C "$repo" -c user.name=audit -c user.email=audit@example.invalid -c commit.gpgsign=false -c core.hooksPath=/dev/null commit -q -m init
  before="$(cmd_snapshot "$repo")"
  if [[ "$before" =~ ^[a-f0-9]{64}$ && "$(cmd_snapshot "$repo")" == "$before" ]]; then
    pass 'snapshot: the same tree prints the same 64-hex value'
  else
    fail 'snapshot: stable value'
  fi
  mkdir -p "$repo/run/security-audit"
  printf '{}\n' >"$repo/run/security-audit/findings.json"
  if [[ "$(cmd_snapshot "$repo")" == "$before" ]]; then
    pass 'snapshot: writing the git-ignored run directory leaves the snapshot unchanged'
  else
    fail 'snapshot: ignored run directory'
  fi
  printf 'two\n' >>"$repo/app.php.txt"
  after="$(cmd_snapshot "$repo")"
  if [[ "$after" != "$before" ]]; then pass 'snapshot: a worker that edits a tracked file changes the snapshot'; else fail 'snapshot: tracked edit'; fi
  printf 'scratch\n' >"$repo/notes.txt"
  before="$after"
  after="$(cmd_snapshot "$repo")"
  if [[ "$after" != "$before" ]]; then pass 'snapshot: a worker that creates an untracked file changes the snapshot'; else fail 'snapshot: untracked create'; fi
  printf 'more\n' >>"$repo/notes.txt"
  before="$after"
  after="$(cmd_snapshot "$repo")"
  if [[ "$after" != "$before" ]]; then pass 'snapshot: a worker that edits an untracked file changes the snapshot'; else fail 'snapshot: untracked edit'; fi
  git -C "$repo" add app.php.txt
  before="$after"
  after="$(cmd_snapshot "$repo")"
  if [[ "$after" != "$before" ]]; then pass 'snapshot: a worker that stages an edit it already made changes the snapshot'; else fail 'snapshot: staged edit'; fi
  chmod +x "$repo/app.php.txt"
  before="$after"
  after="$(cmd_snapshot "$repo")"
  if [[ "$after" != "$before" ]]; then pass 'snapshot: a worker that makes a tracked file executable changes the snapshot'; else fail 'snapshot: mode change'; fi
  ln -s app.php.txt "$repo/link.txt"
  before="$after"
  after="$(cmd_snapshot "$repo")"
  if [[ "$after" != "$before" ]]; then pass 'snapshot: a worker that creates a symbolic link changes the snapshot'; else fail 'snapshot: symbolic link'; fi
  rm "$repo/app.php.txt"
  before="$after"
  after="$(cmd_snapshot "$repo")"
  if [[ "$after" != "$before" ]]; then pass 'snapshot: a worker that deletes a tracked file changes the snapshot'; else fail 'snapshot: tracked delete'; fi
  printf 'decoy\n' >"$repo/q"
  printf 'quoted\n' >"$repo/\"q\""
  before="$(cmd_snapshot "$repo")"
  printf 'edit\n' >>"$repo/\"q\""
  if [[ "$(cmd_snapshot "$repo")" != "$before" ]]; then pass 'snapshot: an edit to a file whose name starts with a quote changes the snapshot'; else fail 'snapshot: quoted name'; fi
  mkdir -p "$scratch/not-a-repo"
  expect_exit 'snapshot: a directory outside a git work tree is refused' 1 cmd_snapshot "$scratch/not-a-repo"

  local configured="$tmp/configured" marker="$tmp/configured-program-ran"
  mkdir -p "$configured"
  git -C "$configured" init -q
  printf '* filter=audit diff=audit\n' >"$configured/.gitattributes"
  printf 'one\n' >"$configured/app.php.txt"
  git -C "$configured" add .gitattributes app.php.txt
  git -C "$configured" -c user.name=audit -c user.email=audit@example.invalid -c commit.gpgsign=false -c core.hooksPath=/dev/null commit -q -m init
  mkdir -p "$configured/.git/audit-hooks"
  printf '#!/bin/sh\ntouch "%s"\n' "$marker" >"$configured/.git/audit-hooks/post-index-change"
  chmod +x "$configured/.git/audit-hooks/post-index-change"
  git -C "$configured" config filter.audit.clean "sh -c 'touch \"$marker\"; cat'"
  git -C "$configured" config diff.external "sh -c 'touch \"$marker\"'"
  git -C "$configured" config diff.audit.textconv "sh -c 'touch \"$marker\"; cat \"\$0\"'"
  git -C "$configured" config core.fsmonitor "sh -c 'touch \"$marker\"'"
  git -C "$configured" config core.hooksPath "$configured/.git/audit-hooks"
  printf 'two\n' >>"$configured/app.php.txt"
  printf 'new\n' >"$configured/notes.txt"
  if [[ "$(cmd_snapshot "$configured")" =~ ^[a-f0-9]{64}$ && ! -e "$marker" ]]; then
    pass 'snapshot: taking the snapshot runs no program the repository configures'
  else
    fail 'snapshot: a configured clean filter, diff program, textconv, fsmonitor, or hook ran'
  fi

  # --- artefacts ---

  fresh missing-baseline
  mutate "$tmp/missing-baseline" metadata.json 'del(.worktree_baseline)'
  expect_exit 'schema: metadata records the working-tree baseline' 3 cmd_validate "$tmp/missing-baseline"

  fresh old-ledger
  mutate "$tmp/old-ledger" coverage-ledger.json '.schema_version = 1'
  expect_exit 'schema: a ledger of schema version 1 is refused' 3 cmd_validate "$tmp/old-ledger"

  fresh missing-report
  rm "$tmp/missing-report/REPORT.md"
  expect_exit 'artefacts: a missing REPORT.md is invalid' 3 cmd_validate "$tmp/missing-report"

  fresh missing-ledger
  rm "$tmp/missing-ledger/coverage-ledger.json"
  expect_exit 'artefacts: a missing coverage-ledger.json is invalid' 3 cmd_validate "$tmp/missing-ledger"

  fresh broken-json
  printf '{ not json' >"$tmp/broken-json/findings.json"
  expect_exit 'artefacts: a findings.json that is not JSON is invalid' 3 cmd_validate "$tmp/broken-json"

  fresh two-documents
  printf '{"schema_version":2,"findings":"garbage"}\n' >>"$tmp/two-documents/findings.json"
  expect_exit 'artefacts: a findings.json holding two JSON documents is invalid' 3 cmd_validate "$tmp/two-documents"

  fresh report-heading
  grep -vxF '## Coverage' "$tmp/report-heading/REPORT.md" >"$tmp/report-heading/REPORT.new" && mv "$tmp/report-heading/REPORT.new" "$tmp/report-heading/REPORT.md"
  expect_exit 'artefacts: REPORT.md keeps every template heading' 3 cmd_validate "$tmp/report-heading"

  expect_exit 'usage: validate without a directory' 1 cmd_validate
  expect_exit 'usage: validate on a path that is not a directory' 1 cmd_validate "$tmp/nope"

  if [[ $failed -gt 0 ]]; then
    printf '%s of %s checks failed\n' "$failed" "$((checks + failed))" >&2
    exit 1
  fi
  printf 'self-test OK (%s checks)\n' "$checks"
}

main() {
  case "${1:-}" in
  --self-test)
    self_test
    ;;
  budget)
    require_tools
    shift
    cmd_budget "$@"
    ;;
  snapshot)
    require_tools
    shift
    cmd_snapshot "$@"
    ;;
  fingerprint)
    require_tools
    shift
    cmd_fingerprint "$@"
    ;;
  inventory)
    require_tools
    shift
    cmd_inventory "$@"
    ;;
  validate)
    require_tools
    shift
    cmd_validate "$@"
    ;;
  *)
    usage
    exit 1
    ;;
  esac
}

main "$@"
