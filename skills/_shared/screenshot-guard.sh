#!/usr/bin/env bash
# screenshot-guard.sh — the one gate a defect screenshot passes before any publisher uploads it.
#
# Why this exists
#   A screenshot of a reproduced defect goes to a public tracker and stays there. The GitHub wrapper, the JIRA wrapper,
#   and both publishers must therefore refuse the same files and the same manifest entries, so the checks exist here
#   once (skills/interactive-testing/references/defect-screenshots.md owns the rules behind them).
#
# Usage
#   screenshot-guard.sh file --slug <source-slug> --out <dir> <png>
#   screenshot-guard.sh manifest <manifest.json>
#   screenshot-guard.sh --self-test
#
# file
#   Accepts one file only when ALL hold: it is a regular file and not a symbolic link; after realpath it lies directly
#   inside `.claude/run/<slug>.artifacts/` (or `.codex/run/`) of the PHYSICAL git toplevel, and that directory is no
#   symbolic link either; its name matches `^[a-z0-9-]{1,100}\.png$`; it starts with the PNG signature; and it is
#   1 to 2097152 bytes. It then writes a copy without the tEXt, iTXt, zTXt, and eXIf chunks to <dir>/<name> through
#   sanitize-png.php and prints that path. Every refusal prints one reason on stderr and exits 1.
#
# manifest
#   Reads the screenshot manifest, a JSON array, and prints on stdout {"accepted":[...],"skipped":[{finding_id,reason}]}.
#   An entry is accepted only when it carries exactly the keys
#     finding_id, finding_type, do_not_file_publicly, state, default_branch, file, masking, crop, own_data_only,
#     not_error_page, masked_in_capture_session
#   and: finding_id is `r-` or `f-` and 16 hex digits; finding_type is one of bug, tests, refactor, performance,
#   documentation (a `security` finding never gets a screenshot, and neither does a missing type);
#   do_not_file_publicly is false; state is `head` or `base`; default_branch matches `^[A-Za-z0-9._/-]{1,100}$` for
#   `base` and is null for `head`; masking is `verified`; crop is `element` or `viewport` (never a full page);
#   own_data_only, not_error_page, and masked_in_capture_session are true. A finding carries at most one `head` and one
#   `base` entry, a `base` entry needs the `head` entry, and the manifest holds at most 20 entries. Each accepted entry
#   gets `label`: `This change` for `head` and `Default branch (<default_branch>)` for `base`, the only two labels there
#   are. A refused entry is listed in `skipped` with its reason, so the finding stays text only and the handoff says why.
#
# Exit codes: 0 done, 1 refused (file) or invalid manifest document, 2 usage error or missing tool.
set -euo pipefail

PROG="screenshot-guard.sh"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MAX_BYTES=2097152
MAX_ENTRIES=20
SELF_TMP=""

die() {
  local code="$1"
  shift
  echo "$PROG: $*" >&2
  exit "$code"
}

require_tools() {
  local bin
  for bin in jq php git od wc; do
    command -v "$bin" >/dev/null 2>&1 || die 2 "required tool not found: $bin"
  done
}

# artifacts_dir <slug> — prints the physical path of the artifacts directory, or returns 1 with the reason on stderr.
artifacts_dir() {
  local slug="$1" top run dir
  [[ "$slug" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$ ]] || { echo "$PROG: the source slug is not a plain name" >&2; return 1; }
  top="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "$PROG: not inside a git work tree" >&2; return 1; }
  top="$(cd "$top" && pwd -P)"
  for run in .claude/run .codex/run; do
    dir="$top/$run/$slug.artifacts"
    if [[ -d "$dir" && ! -L "$dir" ]]; then
      (cd "$dir" && pwd -P)
      return 0
    fi
  done
  echo "$PROG: no artifacts directory $slug.artifacts under .claude/run or .codex/run of the git toplevel" >&2
  return 1
}

cmd_file() {
  local slug="" out="" file="" dir real name size magic
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --slug) [[ $# -ge 2 ]] || die 2 "--slug needs a value"; slug="$2"; shift 2 ;;
      --out) [[ $# -ge 2 ]] || die 2 "--out needs a value"; out="$2"; shift 2 ;;
      *) [[ -z "$file" ]] || die 2 "unexpected argument"; file="$1"; shift ;;
    esac
  done
  [[ -n "$slug" && -n "$out" && -n "$file" ]] || die 2 "usage: screenshot-guard.sh file --slug <slug> --out <dir> <png>"
  require_tools
  [[ -d "$out" && ! -L "$out" ]] || die 1 "the output directory does not exist or is a symbolic link"
  [[ ! -L "$file" ]] || die 1 "the screenshot is a symbolic link"
  [[ -f "$file" ]] || die 1 "the screenshot is not a regular file"
  dir="$(artifacts_dir "$slug")" || exit 1
  real="$(cd "$(dirname -- "$file")" && pwd -P)/$(basename -- "$file")"
  [[ "$(dirname -- "$real")" == "$dir" ]] || die 1 "the screenshot is not directly inside the artifacts directory of this run"
  name="$(basename -- "$real")"
  [[ "$name" =~ ^[a-z0-9-]{1,100}\.png$ ]] || die 1 "the screenshot name must match ^[a-z0-9-]{1,100}\\.png\$"
  size="$(wc -c <"$real" | tr -d ' ')"
  [[ "$size" -ge 1 && "$size" -le "$MAX_BYTES" ]] || die 1 "the screenshot must be 1 to $MAX_BYTES bytes, it is $size"
  magic="$(head -c 8 "$real" | od -An -tx1 | tr -d ' \n')"
  [[ "$magic" == "89504e470d0a1a0a" ]] || die 1 "the screenshot does not start with the PNG signature"
  php "$HERE/sanitize-png.php" "$real" "$out/$name" 2>"$out/.sanitize.err" || { rm -f "$out/$name"; die 1 "the screenshot is not a well-formed PNG"; }
  rm -f "$out/.sanitize.err"
  printf '%s\n' "$out/$name"
}

IFS= read -r -d '' JQ_MANIFEST <<'JQ' || true
def keys_ok: (keys | sort) == (["crop","default_branch","do_not_file_publicly","file","finding_id","finding_type","masked_in_capture_session","masking","not_error_page","own_data_only","state"]);
def problem:
  if type != "object" then "the entry is not an object"
  elif (keys_ok | not) then "the entry does not carry exactly the manifest keys"
  elif (.finding_id | type != "string" or (test("^[rf]-[0-9a-f]{16}$") | not)) then "the finding id is malformed"
  elif .finding_type == "security" then "a security finding never gets a screenshot"
  elif (.finding_type | IN("bug","tests","refactor","performance","documentation") | not) then "the finding type is missing or unknown, so the screenshot is refused"
  elif .do_not_file_publicly != false then "a finding marked do_not_file_publicly never gets a screenshot"
  elif (.state | IN("head","base") | not) then "the state is neither head nor base"
  elif .state == "base" and (.default_branch | type != "string" or (test("^[A-Za-z0-9._/-]{1,100}$") | not)) then "the default branch name is not a plain name"
  elif .state == "head" and .default_branch != null then "a head screenshot carries no default branch"
  elif (.file | type != "string" or length < 1) then "the file is missing"
  elif .masking != "verified" then "masking is not verified"
  elif (.crop | IN("element","viewport") | not) then "the capture is not cropped to the failing element or the viewport"
  elif .own_data_only != true then "the cropped area is not proven to hold only data this run created"
  elif .not_error_page != true then "the page is not proven to be free of an error or debug view"
  elif .masked_in_capture_session != true then "masking did not run in the session that wrote the file"
  else null end;
if type != "array" then error("the manifest is not a JSON array")
elif length > $max then error("the manifest holds more than \($max) entries")
else
  map({e: ., p: problem}) as $checked
  | [$checked[] | select(.p == null) | .e] as $ok
  | ($ok | map(select(.state == "head") | .finding_id)) as $heads
  | ($ok | group_by([.finding_id, .state]) | map(select(length > 1) | .[0] | [.finding_id, .state])) as $dupes
  | [$ok[] | . as $e
      | if ([$e.finding_id, $e.state] | IN($dupes[])) then {skip: {finding_id: $e.finding_id, reason: "the finding has two \($e.state) screenshots, one is allowed"}}
        elif ($e.state == "base" and ($e.finding_id | IN($heads[]) | not)) then {skip: {finding_id: $e.finding_id, reason: "a base screenshot needs the head screenshot of the same finding"}}
        else {keep: ($e + {label: (if $e.state == "head" then "This change" else "Default branch (\($e.default_branch))" end)})} end] as $sorted
  | {
      accepted: [$sorted[] | select(.keep) | .keep],
      skipped: ([$checked[] | select(.p != null) | {finding_id: (if (.e | type == "object") and (.e.finding_id | type == "string") then .e.finding_id else "unknown" end), reason: .p}] + [$sorted[] | select(.skip) | .skip])
    }
end
JQ

cmd_manifest() {
  [[ $# -eq 1 ]] || die 2 "usage: screenshot-guard.sh manifest <manifest.json>"
  require_tools
  [[ -r "$1" ]] || die 1 "cannot read the manifest"
  jq -c --argjson max "$MAX_ENTRIES" "$JQ_MANIFEST" "$1" 2>/dev/null || die 1 "the manifest is not a valid screenshot manifest"
}

# --- self-test ------------------------------------------------------------------------------------------------

self_test() {
  local failures=0 total=0 repo out png rc
  SELF_TMP="$(mktemp -d)"
  tmp="$SELF_TMP"
  trap 'rm -rf "$SELF_TMP"' EXIT
  repo="$tmp/repo"
  mkdir -p "$repo/.claude/run/demo.artifacts" "$tmp/out"
  git -C "$repo" init -q
  repo="$(cd "$repo" && pwd -P)"
  png="$repo/.claude/run/demo.artifacts/step-head.png"
  # A 1x1 PNG with a tEXt chunk (Comment=secret) between IHDR and IDAT, built with php so the CRCs are right.
  # shellcheck disable=SC2016
  php -r '
    function chunk(string $t, string $d): string { return pack("N", strlen($d)) . $t . $d . pack("N", crc32($t . $d)); }
    $sig = "\x89PNG\r\n\x1a\n";
    $ihdr = chunk("IHDR", pack("NNCCCCC", 1, 1, 8, 2, 0, 0, 0));
    $idat = chunk("IDAT", gzcompress("\x00\xff\x00\x00"));
    $iend = chunk("IEND", "");
    file_put_contents($argv[1], $sig . $ihdr . chunk("tEXt", "Comment\0secret-value") . chunk("eXIf", "GPS") . $idat . $iend);
    file_put_contents($argv[2], $sig . $ihdr . $idat . $iend . "trailing");
    file_put_contents($argv[3], $sig . $ihdr . substr($idat, 0, -1) . "x" . $iend);
  ' "$png" "$tmp/trailing.png" "$tmp/badcrc.png"

  check() {
    total=$((total + 1))
    if [[ "$2" != yes ]]; then
      echo "FAIL: $1" >&2
      failures=$((failures + 1))
    fi
  }
  run_file() { (cd "$repo" && bash "$HERE/screenshot-guard.sh" file --slug demo --out "$tmp/out" "$@" 2>"$tmp/err"); }

  out="$(run_file "$png")" && rc=0 || rc=$?
  check 'a PNG in the artifacts directory is accepted' "$([[ $rc -eq 0 && "$out" == "$tmp/out/step-head.png" ]] && echo yes || echo no)"
  check 'the copy has no tEXt and no eXIf chunk' "$([[ -f "$tmp/out/step-head.png" ]] && ! grep -aq 'secret-value\|tEXt\|eXIf' "$tmp/out/step-head.png" && echo yes || echo no)"
  check 'the copy keeps the pixel data' "$(grep -aq IDAT "$tmp/out/step-head.png" && echo yes || echo no)"

  rc=0; run_file "$tmp/trailing.png" >/dev/null || rc=$?
  check 'a file outside the artifacts directory is refused' "$([[ $rc -eq 1 ]] && grep -q 'not directly inside' "$tmp/err" && echo yes || echo no)"
  ln -s "$png" "$repo/.claude/run/demo.artifacts/link-head.png"
  rc=0; run_file "$repo/.claude/run/demo.artifacts/link-head.png" >/dev/null || rc=$?
  check 'a symbolic link is refused' "$([[ $rc -eq 1 ]] && grep -q 'symbolic link' "$tmp/err" && echo yes || echo no)"
  cp "$png" "$repo/.claude/run/demo.artifacts/Bad_Name.png"
  rc=0; run_file "$repo/.claude/run/demo.artifacts/Bad_Name.png" >/dev/null || rc=$?
  check 'a file name outside the allowed pattern is refused' "$([[ $rc -eq 1 ]] && grep -q 'name must match' "$tmp/err" && echo yes || echo no)"
  printf 'not a png at all' >"$repo/.claude/run/demo.artifacts/fake.png"
  rc=0; run_file "$repo/.claude/run/demo.artifacts/fake.png" >/dev/null || rc=$?
  check 'a file without the PNG signature is refused' "$([[ $rc -eq 1 ]] && grep -q 'PNG signature' "$tmp/err" && echo yes || echo no)"
  cp "$tmp/trailing.png" "$repo/.claude/run/demo.artifacts/trailing.png"
  rc=0; run_file "$repo/.claude/run/demo.artifacts/trailing.png" >/dev/null || rc=$?
  check 'a PNG with bytes after IEND is refused' "$([[ $rc -eq 1 ]] && grep -q 'well-formed' "$tmp/err" && echo yes || echo no)"
  cp "$tmp/badcrc.png" "$repo/.claude/run/demo.artifacts/badcrc.png"
  rc=0; run_file "$repo/.claude/run/demo.artifacts/badcrc.png" >/dev/null || rc=$?
  check 'a PNG with a wrong chunk CRC is refused' "$([[ $rc -eq 1 ]] && grep -q 'well-formed' "$tmp/err" && echo yes || echo no)"
  { cat "$png"; head -c 2200000 /dev/zero; } >"$repo/.claude/run/demo.artifacts/huge.png"
  rc=0; run_file "$repo/.claude/run/demo.artifacts/huge.png" >/dev/null || rc=$?
  check 'a file over the size cap is refused' "$([[ $rc -eq 1 ]] && grep -q 'bytes' "$tmp/err" && echo yes || echo no)"
  : >"$repo/.claude/run/demo.artifacts/empty.png"
  rc=0; run_file "$repo/.claude/run/demo.artifacts/empty.png" >/dev/null || rc=$?
  check 'an empty file is refused' "$([[ $rc -eq 1 ]] && echo yes || echo no)"
  mkdir -p "$repo/elsewhere"
  cp "$png" "$repo/elsewhere/step-head.png"
  rc=0; run_file "$repo/.claude/run/demo.artifacts/../../../elsewhere/step-head.png" >/dev/null || rc=$?
  check 'a path that climbs out of the artifacts directory is refused' "$([[ $rc -eq 1 ]] && grep -q 'not directly inside' "$tmp/err" && echo yes || echo no)"
  rc=0; (cd "$repo" && bash "$HERE/screenshot-guard.sh" file --slug other --out "$tmp/out" "$png" 2>"$tmp/err") >/dev/null || rc=$?
  check 'a slug with no artifacts directory is refused' "$([[ $rc -eq 1 ]] && grep -q 'no artifacts directory' "$tmp/err" && echo yes || echo no)"
  rc=0; (cd "$repo" && bash "$HERE/screenshot-guard.sh" file --slug '../x' --out "$tmp/out" "$png" 2>"$tmp/err") >/dev/null || rc=$?
  check 'a slug that is not a plain name is refused' "$([[ $rc -eq 1 ]] && grep -q 'plain name' "$tmp/err" && echo yes || echo no)"

  local good base
  good='{"finding_id":"f-0123456789abcdef","finding_type":"bug","do_not_file_publicly":false,"state":"head","default_branch":null,"file":"a.png","masking":"verified","crop":"element","own_data_only":true,"not_error_page":true,"masked_in_capture_session":true}'
  base="$(jq -c '.state = "base" | .default_branch = "master"' <<<"$good")"
  m() { printf '%s' "$1" >"$tmp/m.json"; bash "$HERE/screenshot-guard.sh" manifest "$tmp/m.json"; }
  check 'a head entry is accepted with the label This change' "$([[ "$(m "[$good]" | jq -r '.accepted[0].label')" == "This change" ]] && echo yes || echo no)"
  check 'a regression keeps both entries, the base one labelled with the default branch' \
    "$([[ "$(m "[$good,$base]" | jq -r '[.accepted[].label] | join("|")')" == "This change|Default branch (master)" ]] && echo yes || echo no)"
  check 'a base entry without the head entry is skipped' "$(m "[$base]" | jq -e '(.accepted | length) == 0 and (.skipped[0].reason | test("needs the head"))' >/dev/null && echo yes || echo no)"
  check 'a security finding is skipped' "$(m "[$(jq -c '.finding_type = "security"' <<<"$good")]" | jq -e '(.accepted | length) == 0 and (.skipped[0].reason | test("security"))' >/dev/null && echo yes || echo no)"
  check 'a missing finding type is skipped' "$(m "[$(jq -c '.finding_type = null' <<<"$good")]" | jq -e '(.accepted | length) == 0' >/dev/null && echo yes || echo no)"
  check 'a do_not_file_publicly finding is skipped' "$(m "[$(jq -c '.do_not_file_publicly = true' <<<"$good")]" | jq -e '(.accepted | length) == 0 and (.skipped[0].reason | test("do_not_file_publicly"))' >/dev/null && echo yes || echo no)"
  check 'a full-page capture is skipped' "$(m "[$(jq -c '.crop = "fullPage"' <<<"$good")]" | jq -e '(.accepted | length) == 0 and (.skipped[0].reason | test("cropped"))' >/dev/null && echo yes || echo no)"
  check 'masking that is not verified is skipped' "$(m "[$(jq -c '.masking = "unverified"' <<<"$good")]" | jq -e '(.accepted | length) == 0' >/dev/null && echo yes || echo no)"
  check 'data the run did not create is skipped' "$(m "[$(jq -c '.own_data_only = false' <<<"$good")]" | jq -e '(.accepted | length) == 0' >/dev/null && echo yes || echo no)"
  check 'an error or debug page is skipped' "$(m "[$(jq -c '.not_error_page = false' <<<"$good")]" | jq -e '(.accepted | length) == 0' >/dev/null && echo yes || echo no)"
  check 'masking outside the capture session is skipped' "$(m "[$(jq -c '.masked_in_capture_session = false' <<<"$good")]" | jq -e '(.accepted | length) == 0' >/dev/null && echo yes || echo no)"
  check 'a default branch name with a space is skipped' "$(m "[$good,$(jq -c '.default_branch = "ma ster"' <<<"$base")]" | jq -e '(.accepted | length) == 1' >/dev/null && echo yes || echo no)"
  check 'an unknown key is skipped' "$(m "[$(jq -c '. + {extra: 1}' <<<"$good")]" | jq -e '(.accepted | length) == 0' >/dev/null && echo yes || echo no)"
  check 'two head entries for one finding are both skipped' "$(m "[$good,$good]" | jq -e '(.accepted | length) == 0' >/dev/null && echo yes || echo no)"
  rc=0; m '{"a":1}' >/dev/null 2>&1 || rc=$?
  check 'a manifest that is not an array is refused' "$([[ $rc -eq 1 ]] && echo yes || echo no)"
  rc=0; m "$(jq -nc --argjson g "$good" '[range(21) | $g]')" >/dev/null 2>&1 || rc=$?
  check 'a manifest of more than 20 entries is refused' "$([[ $rc -eq 1 ]] && echo yes || echo no)"

  if [[ "$failures" -gt 0 ]]; then
    echo "$PROG self-test: $failures of $total checks failed" >&2
    return 1
  fi
  echo "$PROG self-test: $total checks passed"
}

main() {
  case "${1:-}" in
    --self-test) self_test ;;
    file) shift; cmd_file "$@" ;;
    manifest) shift; cmd_manifest "$@" ;;
    *) die 2 "usage: screenshot-guard.sh file|manifest|--self-test" ;;
  esac
}

main "$@"
