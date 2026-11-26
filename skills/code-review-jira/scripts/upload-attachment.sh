#!/usr/bin/env bash
# upload-attachment.sh — attach one masked defect screenshot to the JIRA work item that carries the review comment.
#
# Why this exists
#   A JIRA comment shows a screenshot of a reproduced defect under its finding. The image must stay with the work item
#   after the test environment is gone, so it is stored as an attachment of the item, and the comment shows it with
#   `!<attachment name>|thumbnail!` (upsert-comment.sh --screenshots). This is the one write path for the attachment:
#   one REST call, POST /rest/api/3/issue/<KEY>/attachments. skills/interactive-testing/references/defect-screenshots.md
#   owns the rules behind it.
#
# Usage
#   upload-attachment.sh <KEY> <png> --comment-key <KEY> --slug <source-slug> [--token-file <file>]
#   upload-attachment.sh --self-test
#
#   KEY            the work item the file is attached to
#   --comment-key  the work item the review comment is published on. It must equal KEY, so an attachment never lands on
#                  another item than the comment that shows it
#   --slug         the source slug of `.claude/run/<slug>.artifacts/`, the only directory a file may come from
#
# What it checks
#   1. The key matches ^[A-Z][A-Z0-9_]+-[0-9]+$ and equals the comment key.
#   2. The file passes skills/_shared/screenshot-guard.sh `file` (a regular file, no symbolic link, directly inside the
#      artifacts directory of the physical git toplevel, a plain name, the PNG signature, at most 2 MiB) and loses its
#      tEXt, iTXt, zTXt, and eXIf chunks.
#   3. The API token goes only to the site that `acli jira auth status` reports, as a bare host name, over https, and no
#      redirect is followed: a 3xx answer is a failure, never a second request to another host. The token comes from
#      jira-rest.sh and is never printed or put in argv.
#   4. The attachment name is the file name plus a UTC time stamp (`<name>-<yyyymmddThhmmssZ lower case>.png`), so a
#      name never repeats on the item, and the answer of JIRA must carry exactly that name.
#
# Output (stdout): {"id":"<attachment id>","filename":"<name>"}. The audit trail gets one `external-write` line per upload
# (a `note` line for a failed one) through audit-trail.sh `record`.
#
# Exit codes: 0 attached, 1 usage or refused input, 2 missing tool, token, or site, 3 JIRA refused or failed.
set -euo pipefail

PROG="upload-attachment.sh"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUDIT_TRAIL="$HERE/../../_shared/audit-trail.sh"
GUARD="$HERE/../../_shared/screenshot-guard.sh"
# shellcheck source=jira-actor.sh
source "$HERE/jira-actor.sh"
# shellcheck source=jira-rest.sh
source "$HERE/jira-rest.sh"

TMP_ROOT=""
cleanup() {
  [[ -z "$TMP_ROOT" ]] || rm -rf "$TMP_ROOT"
  return 0
}
trap cleanup EXIT

die() {
  local code="$1"
  shift
  echo "$PROG: $*" >&2
  exit "$code"
}

cmd_upload() {
  local key="" file="" comment_key="" slug="" token_file="" site safe name stamp status id filename
  [[ $# -ge 1 ]] || die 1 "usage: upload-attachment.sh <KEY> <png> --comment-key <KEY> --slug <slug>"
  key="$1"
  shift
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --comment-key) [[ $# -ge 2 ]] || die 1 "--comment-key needs a value"; comment_key="$2"; shift 2 ;;
      --slug) [[ $# -ge 2 ]] || die 1 "--slug needs a value"; slug="$2"; shift 2 ;;
      --token-file) [[ $# -ge 2 ]] || die 1 "--token-file needs a value"; token_file="$2"; shift 2 ;;
      *) [[ -z "$file" ]] || die 1 "unexpected argument: $1"; file="$1"; shift ;;
    esac
  done
  [[ "$key" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ ]] || die 1 "the work item key is malformed"
  [[ "$comment_key" == "$key" ]] || die 1 "the attachment key ${key} is not the key ${comment_key:-<none>} that the comment is published on"
  [[ -n "$slug" && -n "$file" ]] || die 1 "--slug and a file are required"
  local bin
  for bin in acli curl jq php base64; do
    command -v "$bin" >/dev/null 2>&1 || die 2 "required tool not found: $bin"
  done

  TMP_ROOT="$(mktemp -d)"
  mkdir -p "$TMP_ROOT/safe"
  safe="$(bash "$GUARD" file --slug "$slug" --out "$TMP_ROOT/safe" "$file")" || exit 1

  site="$(jira_auth_status_field "$(acli jira auth status 2>/dev/null || true)" site)"
  jira_rest_site_is_plain "$site" || die 2 "no usable configured JIRA site in the acli status (a bare host name is required); the token is sent nowhere else"
  jira_rest_resolve_auth "$token_file" || exit 2

  stamp="$(date -u +%Y%m%dt%H%M%Sz)"
  name="$(basename "$safe" .png)-${stamp}.png"
  [[ "$name" =~ ^[a-z0-9-]{1,140}\.png$ ]] || die 1 "the attachment name is not a plain name"
  mv "$safe" "$TMP_ROOT/safe/$name"
  jira_rest_write_config "$TMP_ROOT/curl.cfg"

  status="$(cd "$TMP_ROOT/safe" && jira_rest_call "$TMP_ROOT/curl.cfg" "$TMP_ROOT/response.json" POST "https://${site}/rest/api/3/issue/${key}/attachments" \
    --header 'X-Atlassian-Token: no-check' --form "file=@${name};filename=${name};type=image/png")" || status="000"
  if [[ "$status" != "200" ]]; then
    bash "$AUDIT_TRAIL" record --target "jira attachment ${name} on ${key}" --status 1 --detail "the upload was refused with HTTP ${status}" || true
    die 3 "JIRA answered HTTP ${status} to the upload on ${key}; no redirect is followed and nothing else is tried"
  fi
  id="$(jq -r '.[0].id // empty' "$TMP_ROOT/response.json" 2>/dev/null || true)"
  filename="$(jq -r '.[0].filename // empty' "$TMP_ROOT/response.json" 2>/dev/null || true)"
  if [[ -z "$id" || "$filename" != "$name" ]]; then
    bash "$AUDIT_TRAIL" record --target "jira attachment ${name} on ${key}" --status 1 --detail "the answer of JIRA does not name the uploaded file" || true
    die 3 "the answer of JIRA does not carry the attachment name ${name}; the image is not used"
  fi
  bash "$AUDIT_TRAIL" record --target "jira attachment ${filename} (id ${id}) on ${key}" --status 0 --detail "review screenshot attached" || true
  jq -nc --arg id "$id" --arg filename "$filename" '{id: $id, filename: $filename}'
}

# --- self-test ------------------------------------------------------------------------------------------------

self_test() {
  local failures=0 total=0 base repo png out rc audit
  TMP_ROOT="$(mktemp -d)"
  base="$TMP_ROOT/self"
  mkdir -p "$base/bin" "$base/home"
  repo="$base/repo"
  mkdir -p "$repo/.claude/run/demo.artifacts"
  git -C "$repo" init -q
  repo="$(cd "$repo" && pwd -P)"
  png="$repo/.claude/run/demo.artifacts/step-head.png"
  # shellcheck disable=SC2016
  php -r '
    function chunk(string $t, string $d): string { return pack("N", strlen($d)) . $t . $d . pack("N", crc32($t . $d)); }
    file_put_contents($argv[1], "\x89PNG\r\n\x1a\n" . chunk("IHDR", pack("NNCCCCC", 1, 1, 8, 2, 0, 0, 0)) . chunk("tEXt", "Comment\0secret-value") . chunk("IDAT", gzcompress("\x00\xff\x00\x00")) . chunk("IEND", ""));
  ' "$png"
  audit="$base/run.audit"
  cat >"$base/bin/acli" <<'STUB'
#!/usr/bin/env bash
[[ "$*" == "jira auth status" ]] || exit 1
printf '✓ Authenticated\n  Site: %s\n  Email: tester@example.test\n' "${STUB_SITE:-acme.atlassian.net}"
STUB
  cat >"$base/bin/curl" <<'STUB'
#!/usr/bin/env bash
# Stand-in for curl: records the arguments, copies the attached file, writes the answer the test chose.
printf '%s\n' "$*" >>"$STUB_DIR/curl.log"
out=""; form=""; cfg=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --output) out="$2"; shift 2 ;;
    --form) form="$2"; shift 2 ;;
    --config) cfg="$2"; shift 2 ;;
    *) shift ;;
  esac
done
[[ -f "$cfg" ]] && grep -q '^header = "Authorization: Basic ' "$cfg" && echo cfg-ok >>"$STUB_DIR/cfg.log"
fname="${form#file=@}"; fname="${fname%%;*}"
[[ -f "$fname" ]] && cp "$fname" "$STUB_DIR/sent.png"
case "${STUB_MODE:-ok}" in
  ok) printf '[{"id":"10001","filename":"%s"}]' "$fname" >"$out"; printf '200' ;;
  renamed) printf '[{"id":"10001","filename":"other.png"}]' >"$out"; printf '200' ;;
  redirect) printf '' >"$out"; printf '302' ;;
  refused) printf '{"errorMessages":["no"]}' >"$out"; printf '403' ;;
esac
STUB
  chmod +x "$base/bin/acli" "$base/bin/curl"

  check() {
    total=$((total + 1))
    if [[ "$2" != yes ]]; then
      echo "FAIL: $1" >&2
      failures=$((failures + 1))
    fi
  }
  reset() { rm -f "$base/curl.log" "$base/cfg.log" "$base/sent.png"; : >"$audit"; }
  run() {
    (cd "$repo" && env HOME="$base/home" PATH="$base/bin:$PATH" STUB_DIR="$base" JIRA_API_EMAIL=tester@example.test JIRA_API_TOKEN=tok-never-printed-123 \
      AUDIT_TRAIL_FILE="$audit" AUDIT_TRAIL_ROLE=leonardo bash "$HERE/upload-attachment.sh" "$@" 2>"$base/err")
  }

  reset
  out="$(run ECO-7 "$png" --comment-key ECO-7 --slug demo)" && rc=0 || rc=$?
  check 'a screenshot is attached to the item and the answer names the stamped file' "$([[ $rc -eq 0 ]] && jq -e '.id == "10001" and (.filename | test("^step-head-[0-9]{8}t[0-9]{6}z\\.png$"))' <<<"$out" >/dev/null && echo yes || echo no)"
  check 'the one request is a POST to the attachments endpoint of the configured site, no redirect, https only, no-check header' \
    "$([[ "$(wc -l <"$base/curl.log" | tr -d ' ')" == 1 ]] && grep -q -- '--request POST' "$base/curl.log" && grep -q 'https://acme.atlassian.net/rest/api/3/issue/ECO-7/attachments' "$base/curl.log" && grep -q -- '--max-redirs 0' "$base/curl.log" && grep -q -- "--proto =https" "$base/curl.log" && grep -q 'X-Atlassian-Token: no-check' "$base/curl.log" && echo yes || echo no)"
  check 'the token is in the 0600 config file and never in argv, stdout, or stderr' \
    "$([[ -f "$base/cfg.log" ]] && ! grep -q 'tok-never-printed-123' "$base/curl.log" && ! grep -q 'tok-never-printed-123' "$base/err" && [[ "$out" != *tok-never-printed-123* ]] && echo yes || echo no)"
  check 'the attached bytes lost the tEXt chunk' "$(! grep -aq 'secret-value\|tEXt' "$base/sent.png" && grep -aq IDAT "$base/sent.png" && echo yes || echo no)"
  check 'the upload is one external-write audit line' "$([[ "$(grep -c '|external-write|jira attachment step-head-' "$audit")" == 1 ]] && echo yes || echo no)"

  reset
  out="$(run ECO-7 "$png" --comment-key ECO-8 --slug demo)" && rc=0 || rc=$?
  check 'a key other than the key of the comment is refused before any request' "$([[ $rc -eq 1 && ! -f "$base/curl.log" ]] && echo yes || echo no)"
  out="$(run ECO-7 "$png" --slug demo)" && rc=0 || rc=$?
  check 'a missing comment key is refused' "$([[ $rc -eq 1 && ! -f "$base/curl.log" ]] && echo yes || echo no)"
  out="$(run eco-7 "$png" --comment-key eco-7 --slug demo)" && rc=0 || rc=$?
  check 'a malformed key is refused' "$([[ $rc -eq 1 && ! -f "$base/curl.log" ]] && echo yes || echo no)"
  out="$(run ECO-7 "$repo/elsewhere.png" --comment-key ECO-7 --slug demo)" && rc=0 || rc=$?
  check 'a file outside the artifacts directory is refused before any request' "$([[ $rc -eq 1 && ! -f "$base/curl.log" ]] && echo yes || echo no)"

  reset
  out="$(STUB_SITE=other.atlassian.net run ECO-7 "$png" --comment-key ECO-7 --slug demo)" && rc=0 || rc=$?
  check 'the request goes to the site of the acli status and to no other host' "$([[ $rc -eq 0 ]] && grep -q 'https://other.atlassian.net/' "$base/curl.log" && ! grep -q 'acme' "$base/curl.log" && echo yes || echo no)"
  reset
  out="$(STUB_SITE='evil.example/path' run ECO-7 "$png" --comment-key ECO-7 --slug demo)" && rc=0 || rc=$?
  check 'a site that is not a bare host name is refused and the token is sent nowhere' "$([[ $rc -eq 2 && ! -f "$base/curl.log" ]] && echo yes || echo no)"
  reset
  out="$(JIRA_SITE=evil.example run ECO-7 "$png" --comment-key ECO-7 --slug demo)" && rc=0 || rc=$?
  check 'a JIRA_SITE of the environment does not move the request' "$([[ $rc -eq 0 ]] && ! grep -q 'evil.example' "$base/curl.log" && echo yes || echo no)"

  reset
  out="$(STUB_MODE=redirect run ECO-7 "$png" --comment-key ECO-7 --slug demo)" && rc=0 || rc=$?
  check 'a redirect is a failure and no second request is made' "$([[ $rc -eq 3 && "$(wc -l <"$base/curl.log" | tr -d ' ')" == 1 ]] && grep -q '|note|' "$audit" && echo yes || echo no)"
  reset
  out="$(STUB_MODE=refused run ECO-7 "$png" --comment-key ECO-7 --slug demo)" && rc=0 || rc=$?
  check 'a refusal by JIRA exits 3 with a note line and no external-write line' "$([[ $rc -eq 3 ]] && grep -q '|note|' "$audit" && ! grep -q '|external-write|' "$audit" && echo yes || echo no)"
  reset
  out="$(STUB_MODE=renamed run ECO-7 "$png" --comment-key ECO-7 --slug demo)" && rc=0 || rc=$?
  check 'an answer that does not name the uploaded file is refused' "$([[ $rc -eq 3 && -z "$out" ]] && echo yes || echo no)"
  reset
  out="$(env -u JIRA_API_TOKEN HOME="$base/home" PATH="$base/bin:$PATH" STUB_DIR="$base" bash -c "cd '$repo' && bash '$HERE/upload-attachment.sh' ECO-7 '$png' --comment-key ECO-7 --slug demo" 2>"$base/err")" && rc=0 || rc=$?
  check 'a missing token exits 2 and sends nothing' "$([[ $rc -eq 2 && ! -f "$base/curl.log" ]] && echo yes || echo no)"

  if [[ "$failures" -gt 0 ]]; then
    echo "$PROG self-test: $failures of $total checks failed" >&2
    return 1
  fi
  echo "$PROG self-test: $total checks passed"
}

main() {
  case "${1:-}" in
    --self-test) self_test ;;
    -h | --help | '') die 1 "usage: upload-attachment.sh <KEY> <png> --comment-key <KEY> --slug <slug>" ;;
    *) cmd_upload "$@" ;;
  esac
}

main "$@"
