#!/usr/bin/env bash
# upload-review-asset.sh — put one masked defect screenshot on the `cr-assets` branch of the base repository of a
# pull request, and verify the URL a review may link.
#
# Why this exists
#   GitHub has no API that attaches an image to a review. A review links an image from a branch that no pull request
#   diff shows. This wrapper is the one narrow write path to that branch: it calls the GitHub REST API (Contents and
#   Git Data) through `gh api` and nothing else. It runs no `git` command, no hook, no push, and no force, and it
#   writes only the branch `cr-assets` of the repository of the pull request URL, never of `origin`.
#   skills/interactive-testing/references/defect-screenshots.md owns the rules behind it.
#
# Usage
#   upload-review-asset.sh upload <PR-URL> --head-sha <SHA> --slug <source-slug> --finding <finding-id> --state head|base --sha256 <canonical-digest> <png>
#   upload-review-asset.sh verify <PR-URL> <image-URL>
#   upload-review-asset.sh --self-test
#
# upload
#   1. The file passes skills/_shared/screenshot-guard.sh `file` (a regular file, no symbolic link, directly inside
#      `.claude/run/<slug>.artifacts/` of the physical git toplevel, a plain name, the PNG signature, at most 2 MiB)
#      and loses its tEXt, iTXt, zTXt, and eXIf chunks.
#   2. The account needs push access to the base repository. A pull request from a fork has none, so nothing is written.
#   3. The branch `cr-assets` is created as an orphan branch (a root commit that holds one marker file) when it is
#      missing. An existing branch without that marker file is not ours and is never written (exit 4).
#   4. The file is written to `pr-<N>/<head SHA 12>-<finding id>-<state>.png` with one Contents API PUT. A 409 from a
#      write of another run is retried once. A path that already holds the same bytes is reused.
#   5. The wrapper verifies the result: the commit SHA is an ancestor of `cr-assets` (or its tip), and the path exists
#      in that commit. Then it prints the URL `https://github.com/<owner>/<repo>/blob/<commit SHA>/<path>?raw=true`.
#   Every write appends one `external-write` line to the run's audit file through audit-trail.sh `record`, and a failed
#   write appends a `note` line. A refusal of the write is never fatal to a review: the caller keeps the finding text only.
#
# verify
#   Exits 0 only when the URL has exactly the shape above for THIS base repository and THIS pull request, the commit is
#   an ancestor of `cr-assets`, and the path exists in it. A URL of a fork of the same network passes the shape check
#   never, and the commit check refuses a commit that `cr-assets` does not hold.
#
# Exit codes: 0 done, 1 usage or refused file or URL, 2 missing tool, 3 GitHub write or read failed (text only),
#   4 the target refuses a write (no push access, or a `cr-assets` branch that is not ours).
set -euo pipefail

PROG="upload-review-asset.sh"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUDIT_TRAIL="$HERE/../../_shared/audit-trail.sh"
GUARD="$HERE/../../_shared/screenshot-guard.sh"
# shellcheck source=../../_shared/github-target.sh
source "$HERE/../../_shared/github-target.sh"

BRANCH="cr-assets"
MARKER_PATH=".ai-olympus-cr-assets"
MARKER_TEXT="ai-olympus review screenshots: this branch holds images of code review findings and belongs to no pull request."
ROOT_MESSAGE="chore: create the cr-assets branch of the ai-olympus review screenshots"

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

require_tools() {
  local bin
  for bin in gh jq php base64 shasum; do
    command -v "$bin" >/dev/null 2>&1 || die 2 "required tool not found: $bin"
  done
}

# gh_api <outfile-prefix> <gh api arguments...> — runs one API call; stdout goes to <prefix>.out, stderr to <prefix>.err.
gh_api() {
  local prefix="$1" rc=0
  shift
  if [[ "${1:-}" == "-X" && ( "${2:-}" == "POST" || "${2:-}" == "PUT" ) ]]; then
    local current
    current="$(gh api "repos/${NWO}/pulls/${NUMBER}" --jq '.head.sha' 2>/dev/null)" || die 3 "could not confirm the review head before an asset write"
    [[ "$current" == "$UPLOAD_HEAD" ]] || die 4 "the reviewed head moved; stopped before the next asset write"
  fi
  gh api "$@" >"$prefix.out" 2>"$prefix.err" || rc=$?
  return "$rc"
}

not_found() {
  grep -q 'HTTP 404\|Not Found' "$1.err" "$1.out" 2>/dev/null
}

audit_write() {
  bash "$AUDIT_TRAIL" record --target "$1" --status "$2" --detail "$3" || true
}

# verify_asset <commit> <path> — the commit is an ancestor of the branch (or its tip) and the path exists in it.
verify_asset() {
  local commit="$1" path="$2" expected_blob="${3:-}" status
  gh_api "$TMP_ROOT/cmp" "repos/${NWO}/compare/${commit}...${BRANCH}" || return 1
  status="$(jq -r '.status // empty' "$TMP_ROOT/cmp.out" 2>/dev/null || true)"
  [[ "$status" == "ahead" || "$status" == "identical" ]] || return 1
  gh_api "$TMP_ROOT/at" "repos/${NWO}/contents/${path}?ref=${commit}" || return 1
  jq -e --arg blob "$expected_blob" '.type == "file" and ($blob == "" or .sha == $blob)' "$TMP_ROOT/at.out" >/dev/null 2>&1
}

asset_url() {
  printf 'https://github.com/%s/blob/%s/%s?raw=true' "$NWO" "$1" "$2"
}

# parse_image_url <url> — sets VERIFY_COMMIT and VERIFY_PATH when the URL has the one allowed shape for this pull request.
parse_image_url() {
  local url="$1" prefix="https://github.com/${NWO}/blob/" rest
  VERIFY_COMMIT=""
  VERIFY_PATH=""
  [[ "$url" == "$prefix"* ]] || return 1
  rest="${url#"$prefix"}"
  [[ "$rest" =~ ^([0-9a-f]{40})/(pr-${NUMBER}/[a-z0-9-]+\.png)\?raw=true$ ]] || return 1
  VERIFY_COMMIT="${BASH_REMATCH[1]}"
  VERIFY_PATH="${BASH_REMATCH[2]}"
}

resolve_pull_request() {
  [[ "$1" =~ ^https://github\.com/ ]] || die 1 "the pull request must be a github.com URL, never a bare number or the git remote"
  github_target_resolve "$1" "pull" || exit 1
  TMP_ROOT="$(mktemp -d)"
}

cmd_verify() {
  [[ $# -eq 2 ]] || die 1 "usage: upload-review-asset.sh verify <PR-URL> <image-URL>"
  require_tools
  resolve_pull_request "$1"
  parse_image_url "$2" || die 1 "the image URL does not have the shape of a screenshot of ${NWO}#${NUMBER}"
  verify_asset "$VERIFY_COMMIT" "$VERIFY_PATH" || die 3 "the commit ${VERIFY_COMMIT} is not on ${BRANCH} of ${NWO}, or the path does not exist in it"
  echo ok
}

ensure_branch() {
  local blob tree root marker_json
  if gh_api "$TMP_ROOT/ref" "repos/${NWO}/git/ref/heads/${BRANCH}"; then
    gh_api "$TMP_ROOT/marker" "repos/${NWO}/contents/${MARKER_PATH}?ref=${BRANCH}" || die 4 "the branch ${BRANCH} of ${NWO} exists without the marker file of this package, so it is not used"
    marker_json="$(jq -r '.content // empty' "$TMP_ROOT/marker.out" 2>/dev/null | tr -d '\n' | base64 -d 2>/dev/null || true)"
    [[ "$marker_json" == "$MARKER_TEXT" ]] || die 4 "the branch ${BRANCH} of ${NWO} does not carry the marker of this package, so it is not used"
    return 0
  fi
  not_found "$TMP_ROOT/ref" || die 3 "reading ${BRANCH} of ${NWO} failed: $(head -c 300 "$TMP_ROOT/ref.err")"

  # The branch does not exist: create an orphan branch with one marker file, through the Git Data API.
  gh_api "$TMP_ROOT/blob" -X POST "repos/${NWO}/git/blobs" --input - <<<"$(jq -nc --arg c "$MARKER_TEXT" '{content: $c, encoding: "utf-8"}')" \
    || die 3 "creating ${BRANCH} on ${NWO} failed at the marker blob: $(head -c 300 "$TMP_ROOT/blob.err")"
  blob="$(jq -r '.sha // empty' "$TMP_ROOT/blob.out")"
  gh_api "$TMP_ROOT/tree" -X POST "repos/${NWO}/git/trees" --input - <<<"$(jq -nc --arg b "$blob" --arg p "$MARKER_PATH" '{tree: [{path: $p, mode: "100644", type: "blob", sha: $b}]}')" \
    || die 3 "creating ${BRANCH} on ${NWO} failed at the marker tree: $(head -c 300 "$TMP_ROOT/tree.err")"
  tree="$(jq -r '.sha // empty' "$TMP_ROOT/tree.out")"
  gh_api "$TMP_ROOT/root" -X POST "repos/${NWO}/git/commits" --input - <<<"$(jq -nc --arg t "$tree" --arg m "$ROOT_MESSAGE" '{message: $m, tree: $t, parents: []}')" \
    || die 3 "creating ${BRANCH} on ${NWO} failed at the root commit: $(head -c 300 "$TMP_ROOT/root.err")"
  root="$(jq -r '.sha // empty' "$TMP_ROOT/root.out")"
  [[ "$root" =~ ^[0-9a-f]{40}$ ]] || die 3 "creating ${BRANCH} on ${NWO} returned no root commit"
  if gh_api "$TMP_ROOT/newref" -X POST "repos/${NWO}/git/refs" --input - <<<"$(jq -nc --arg r "refs/heads/${BRANCH}" --arg s "$root" '{ref: $r, sha: $s}')"; then
    audit_write "branch ${BRANCH} created on ${NWO}" 0 "orphan branch of review screenshots, root commit ${root}"
  elif gh_api "$TMP_ROOT/ref" "repos/${NWO}/git/ref/heads/${BRANCH}"; then
    # Another run created the branch in the meantime: it must carry the marker.
    gh_api "$TMP_ROOT/marker" "repos/${NWO}/contents/${MARKER_PATH}?ref=${BRANCH}" || die 4 "the concurrently created asset branch cannot be verified"
    marker_json="$(jq -r '.content // empty' "$TMP_ROOT/marker.out" | tr -d '\n' | base64 -d 2>/dev/null)" || die 4 "invalid asset branch marker"
    [[ "$marker_json" == "$MARKER_TEXT" ]] || die 4 "the concurrently created asset branch has a different marker"
  else
    audit_write "branch ${BRANCH} creation on ${NWO}" 1 "the ref was refused"
    die 3 "creating ${BRANCH} on ${NWO} was refused: $(head -c 300 "$TMP_ROOT/newref.err")"
  fi
}

cmd_upload() {
  local digest="" pr="" head="" slug="" finding="" state="" file="" safe name path b64 size local_sha remote_sha commit="" url attempt put_rc
  [[ $# -ge 1 ]] || die 1 "usage: upload-review-asset.sh upload <PR-URL> --head-sha <SHA> --slug <slug> --finding <id> --state head|base --sha256 <canonical-digest> <png>"
  pr="$1"
  shift
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --head-sha) [[ $# -ge 2 ]] || die 1 "--head-sha needs a value"; head="$2"; shift 2 ;;
      --slug) [[ $# -ge 2 ]] || die 1 "--slug needs a value"; slug="$2"; shift 2 ;;
      --sha256) [[ $# -ge 2 ]] || die 1 "--sha256 needs a value"; digest="$2"; shift 2 ;;
      --finding) [[ $# -ge 2 ]] || die 1 "--finding needs a value"; finding="$2"; shift 2 ;;
      --state) [[ $# -ge 2 ]] || die 1 "--state needs a value"; state="$2"; shift 2 ;;
      *) [[ -z "$file" ]] || die 1 "unexpected argument: $1"; file="$1"; shift ;;
    esac
  done
  [[ "$head" =~ ^[0-9a-f]{40}$ ]] || die 1 "--head-sha must be a full lowercase 40-digit SHA"
  [[ "$finding" =~ ^[rf]-[0-9a-f]{16}$ ]] || die 1 "--finding must be a finding id (r- or f- and 16 hex digits)"
  [[ "$state" == "head" || "$state" == "base" ]] || die 1 "--state must be head or base"
  [[ -n "$slug" && -n "$file" ]] || die 1 "--slug and a file are required"
  require_tools
  resolve_pull_request "$pr"
  UPLOAD_HEAD="$head"
  mkdir -p "$TMP_ROOT/safe"
  safe="$(bash "$GUARD" file --slug "$slug" --out "$TMP_ROOT/safe" "$file")" || exit 1
  [[ "$digest" =~ ^[0-9a-f]{64}$ && "$(shasum -a 256 <"$safe" | cut -d' ' -f1)" == "$digest" ]] || die 1 "the reviewed image digest does not match"
  name="${head:0:12}-${finding}-${state}.png"
  path="pr-${NUMBER}/${name}"

  gh_api "$TMP_ROOT/repo" "repos/${NWO}" || die 3 "reading ${NWO} failed: $(head -c 300 "$TMP_ROOT/repo.err")"
  jq -e '.permissions.push == true' "$TMP_ROOT/repo.out" >/dev/null 2>&1 || die 4 "the account has no push access to ${NWO}, so no screenshot is written"

  ensure_branch

  size="$(wc -c <"$safe" | tr -d ' ')"
  local_sha="$({ printf 'blob %s\0' "$size"; cat "$safe"; } | shasum | cut -d' ' -f1)"
  if gh_api "$TMP_ROOT/exists" "repos/${NWO}/contents/${path}?ref=${BRANCH}"; then
    remote_sha="$(jq -r '.sha // empty' "$TMP_ROOT/exists.out")"
    [[ "$remote_sha" == "$local_sha" ]] || die 3 "${path} already exists on ${BRANCH} with other content"
    gh_api "$TMP_ROOT/tip" "repos/${NWO}/git/ref/heads/${BRANCH}" || die 3 "reading ${BRANCH} of ${NWO} failed"
    commit="$(jq -r '.object.sha // empty' "$TMP_ROOT/tip.out")"
  else
    not_found "$TMP_ROOT/exists" || die 3 "reading ${path} on ${BRANCH} failed: $(head -c 300 "$TMP_ROOT/exists.err")"
    b64="$TMP_ROOT/content.b64"
    base64 <"$safe" | tr -d '\n' >"$b64"
    jq -nc --arg message "chore: add the review screenshot ${name} of pull request #${NUMBER}" --rawfile content "$b64" --arg branch "$BRANCH" \
      '{message: $message, content: $content, branch: $branch}' >"$TMP_ROOT/put.json"
    for attempt in 1 2; do
      put_rc=0
      gh_api "$TMP_ROOT/put" -X PUT "repos/${NWO}/contents/${path}" --input "$TMP_ROOT/put.json" || put_rc=$?
      [[ "$put_rc" -ne 0 ]] || break
      if [[ "$attempt" -eq 1 ]] && grep -q 'HTTP 409' "$TMP_ROOT/put.err" "$TMP_ROOT/put.out" 2>/dev/null; then
        sleep 1
        continue
      fi
      audit_write "cr-assets upload of ${name} to ${NWO}#${NUMBER}" "$put_rc" "the write was refused"
      die 3 "writing ${path} to ${BRANCH} of ${NWO} was refused: $(jq -r '.message // empty' "$TMP_ROOT/put.out" 2>/dev/null | head -c 200) $(head -c 200 "$TMP_ROOT/put.err")"
    done
    audit_write "cr-assets path ${path} on ${NWO}" 0 "GitHub accepted the content write; verifying pinned bytes"
    commit="$(jq -r '.commit.sha // empty' "$TMP_ROOT/put.out")"
  fi
  [[ "$commit" =~ ^[0-9a-f]{40}$ ]] || die 3 "the write of ${path} returned no commit SHA"
  verify_asset "$commit" "$path" "$local_sha" || { audit_write "cr-assets upload of ${name} to ${NWO}#${NUMBER}" 1 "the commit ${commit} is not on ${BRANCH} or the path is missing in it"; die 3 "the commit ${commit} is not on ${BRANCH} of ${NWO}, or ${path} is missing in it"; }
  url="$(asset_url "$commit" "$path")"
  audit_write "$url" 0 "review screenshot of pull request #${NUMBER} written to ${BRANCH}"
  printf '%s\n' "$url"
}

# --- self-test ------------------------------------------------------------------------------------------------

write_stub_gh() {
  local dir="$1"
  cat >"$dir/bin/gh" <<'STUB'
#!/usr/bin/env bash
S="$STUB_DIR"
printf '%s\n' "$*" >>"$S/calls.log"
[[ "${1:-}" == api ]] || exit 99
shift
method=GET
endpoint=""
input=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -X) method="$2"; shift 2 ;;
    --input) input="$2"; shift 2 ;;
    --jq) shift 2 ;;
    *) endpoint="$1"; shift ;;
  esac
done
body=""
if [[ "$input" == "-" ]]; then body="$(cat)"; elif [[ -n "$input" ]]; then body="$(cat "$input")"; fi
notfound() { echo '{"message":"Not Found"}'; echo "gh: Not Found (HTTP 404)" >&2; exit 1; }
sha() { printf '%040d' "$1"; }
case "$method $endpoint" in
  "GET repos/o/r/pulls/7") printf '%s\n' "$EXPECTED_HEAD" ;;
  "GET repos/o/r") if [[ -f "$S/nopush" ]]; then echo '{"permissions":{"push":false}}'; else echo '{"permissions":{"push":true}}'; fi ;;
  "GET repos/o/r/git/ref/heads/cr-assets") [[ -f "$S/branch" ]] || notfound; printf '{"object":{"sha":"%s"}}\n' "$(cat "$S/tip")" ;;
  "GET repos/o/r/contents/.ai-olympus-cr-assets?ref=cr-assets")
    [[ -f "$S/branch" ]] || notfound
    [[ -f "$S/marker" ]] || notfound
    printf '{"type":"file","content":"%s\\n"}\n' "$(base64 <"$S/marker" | tr -d '\n')" ;;
  "GET repos/o/r/contents/pr-7/"*"?ref=cr-assets")
    [[ -f "$S/exists_sha" ]] || notfound
    printf '{"type":"file","sha":"%s"}\n' "$(cat "$S/exists_sha")" ;;
  "GET repos/o/r/contents/pr-7/"*"?ref="*) [[ -f "$S/commit_ok" ]] || notfound; printf '{"type":"file","sha":"%s"}\n' "$EXPECTED_TEST_BLOB" ;;
  "PUT repos/o/r/contents/pr-7/"*)
    n=$(($(cat "$S/puts" 2>/dev/null || echo 0) + 1)); echo "$n" >"$S/puts"
    if [[ -f "$S/forbidden" ]]; then echo '{"message":"Repository rule violations found"}'; echo "gh: Repository rule violations found (HTTP 403)" >&2; exit 1; fi
    if [[ -f "$S/conflict" && "$n" -le "$(cat "$S/conflict")" ]]; then echo '{"message":"conflict"}'; echo "gh: conflict (HTTP 409)" >&2; exit 1; fi
    printf '%s' "$body" >"$S/put.json"
    sha 99 >"$S/tip"; touch "$S/branch" "$S/commit_ok"
    printf '{"commit":{"sha":"%s"},"content":{"path":"%s"}}\n' "$(sha 99)" "${endpoint#repos/o/r/contents/}" ;;
  "GET repos/o/r/compare/"*"...cr-assets") printf '{"status":"%s"}\n' "$(cat "$S/compare_status" 2>/dev/null || echo identical)" ;;
  "POST repos/o/r/git/blobs") printf '%s' "$body" >"$S/blob.json"; printf '{"sha":"%s"}\n' "$(sha 1)" ;;
  "POST repos/o/r/git/trees") printf '%s' "$body" >"$S/tree.json"; printf '{"sha":"%s"}\n' "$(sha 2)" ;;
  "POST repos/o/r/git/commits") printf '%s' "$body" >"$S/root.json"; printf '{"sha":"%s"}\n' "$(sha 3)" ;;
  "POST repos/o/r/git/refs")
    if [[ -f "$S/ref_race" ]]; then touch "$S/branch"; sha 3 >"$S/tip"; cp "$S/marker_text" "$S/marker"; echo '{"message":"Reference already exists"}'; echo "gh: Reference already exists (HTTP 422)" >&2; exit 1; fi
    printf '%s' "$body" >"$S/ref.json"; touch "$S/branch"; sha 3 >"$S/tip"; printf '{"ref":"refs/heads/cr-assets"}\n' ;;
  *) echo "stub gh: unexpected call: $method $endpoint" >&2; exit 98 ;;
esac
STUB
  chmod +x "$dir/bin/gh"
}

self_test() {
  local failures=0 total=0 repo rc out stub png audit finding head pr
  TMP_ROOT="$(mktemp -d)"
  local base="$TMP_ROOT/self"
  mkdir -p "$base/bin" "$base/stub"
  repo="$base/repo"
  mkdir -p "$repo/.claude/run/demo.artifacts"
  git -C "$repo" init -q
  repo="$(cd "$repo" && pwd -P)"
  png="$repo/.claude/run/demo.artifacts/step-head.png"
  # shellcheck disable=SC2016
  php -r '
    function chunk(string $t, string $d): string { return pack("N", strlen($d)) . $t . $d . pack("N", crc32($t . $d)); }
    $sig = "\x89PNG\r\n\x1a\n";
    file_put_contents($argv[1], $sig . chunk("IHDR", pack("NNCCCCC", 1, 1, 8, 2, 0, 0, 0)) . chunk("tEXt", "Comment\0secret-value") . chunk("IDAT", gzcompress("\x00\xff\x00\x00")) . chunk("IEND", ""));
  ' "$png"
  write_stub_gh "$base"
  stub="$base/stub"
  audit="$base/run.audit"
  pr="https://github.com/o/r/pull/7"
  finding="f-0123456789abcdef"
  head="0123456789abcdef0123456789abcdef01234567"
  # A git shim that records its arguments, so the test can prove that the wrapper runs no write command.
  cat >"$base/bin/git" <<SHIM
#!/usr/bin/env bash
printf '%s\n' "\$*" >>"$stub/git.log"
exec "$(command -v git)" "\$@"
SHIM
  chmod +x "$base/bin/git"

  check() {
    total=$((total + 1))
    if [[ "$2" != yes ]]; then
      echo "FAIL: $1" >&2
      failures=$((failures + 1))
    fi
  }
  reset() { find "$stub" -mindepth 1 -delete; : >"$audit"; printf '%s' "$MARKER_TEXT" >"$stub/marker_text"; }
  run() {
    (cd "$repo" && PATH="$base/bin:$PATH" STUB_DIR="$stub" AUDIT_TRAIL_FILE="$audit" AUDIT_TRAIL_ROLE=leonardo \
      bash "$HERE/upload-review-asset.sh" "$@" 2>"$base/err")
  }
  local test_digest canonical="$base/canonical.png"
  php "$HERE/../../_shared/sanitize-png.php" "$png" "$canonical" 2>/dev/null
  test_digest="$(shasum -a 256 <"$canonical" | cut -d' ' -f1)"
  export EXPECTED_HEAD="$head" EXPECTED_TEST_BLOB
  EXPECTED_TEST_BLOB="$(git hash-object "$canonical")"
  upload() { run upload "$pr" --head-sha "$head" --slug demo --finding "$finding" --state head --sha256 "$test_digest" "${1:-$png}"; }
  want_url="https://github.com/o/r/blob/$(printf '%040d' 99)/pr-7/0123456789ab-${finding}-head.png?raw=true"

  reset
  out="$(upload)" && rc=0 || rc=$?
  check 'a fresh repository gets the orphan branch and the file, and the URL is the commit-pinned one' "$([[ $rc -eq 0 && "$out" == "$want_url" ]] && echo yes || echo no)"
  check 'the root commit has no parent and the ref is cr-assets' "$(jq -e '.parents == []' "$stub/root.json" >/dev/null && jq -e '.ref == "refs/heads/cr-assets"' "$stub/ref.json" >/dev/null && echo yes || echo no)"
  check 'the file goes to cr-assets under pr-<N>/ with the sanitized bytes' \
    "$(jq -e '.branch == "cr-assets"' "$stub/put.json" >/dev/null && jq -r '.content' "$stub/put.json" | base64 -d | { ! grep -aq 'secret-value\|tEXt'; } && echo yes || echo no)"
  check 'every gh call targets the base repository of the pull request URL' "$(grep -qv 'repos/o/r' "$stub/calls.log" && echo no || echo yes)"
  check 'the wrapper runs no git write command' "$(grep -Eq '^(push|commit|checkout|fetch|switch|branch|update-ref|hash-object|mktree|commit-tree)' "$stub/git.log" 2>/dev/null && echo no || echo yes)"
  check 'the upload records the branch, accepted bytes, and verified URL' \
    "$([[ "$(grep -c '|external-write|' "$audit")" -eq 3 ]] && grep -Fq 'GitHub accepted the content write' "$audit" && grep -Fq "$want_url" "$audit" && grep -q '^leonardo|' "$audit" && echo yes || echo no)"

  reset; printf '%s' "$MARKER_TEXT" >"$stub/marker"; touch "$stub/branch"; printf '%040d' 5 >"$stub/tip"
  out="$(upload)" && rc=0 || rc=$?
  check 'an existing branch with the marker is used with one write and no branch creation' \
    "$([[ $rc -eq 0 && "$(cat "$stub/puts")" == 1 && ! -f "$stub/ref.json" ]] && echo yes || echo no)"

  reset; printf 'something else' >"$stub/marker"; touch "$stub/branch"; printf '%040d' 5 >"$stub/tip"
  out="$(upload)" && rc=0 || rc=$?
  check 'an existing branch without the marker is refused and nothing is written' "$([[ $rc -eq 4 && ! -f "$stub/puts" ]] && echo yes || echo no)"
  reset; touch "$stub/branch"; printf '%040d' 5 >"$stub/tip"
  out="$(upload)" && rc=0 || rc=$?
  check 'an existing branch with no marker file is refused' "$([[ $rc -eq 4 && ! -f "$stub/puts" ]] && echo yes || echo no)"

  reset; touch "$stub/nopush"
  out="$(upload)" && rc=0 || rc=$?
  check 'an account without push access writes nothing' "$([[ $rc -eq 4 && ! -f "$stub/puts" && ! -f "$stub/ref.json" ]] && echo yes || echo no)"

  reset; printf '%s' "$MARKER_TEXT" >"$stub/marker"; touch "$stub/branch"; printf '%040d' 5 >"$stub/tip"; echo 1 >"$stub/conflict"
  out="$(upload)" && rc=0 || rc=$?
  check 'one 409 of a concurrent write is retried once' "$([[ $rc -eq 0 && "$(cat "$stub/puts")" == 2 ]] && echo yes || echo no)"
  echo 2 >"$stub/conflict"; rm -f "$stub/puts"
  out="$(upload)" && rc=0 || rc=$?
  check 'two 409 answers end in a refusal, and the audit trail notes the failed write' "$([[ $rc -eq 3 && "$(cat "$stub/puts")" == 2 ]] && grep -q '|note|' "$audit" && echo yes || echo no)"

  reset; printf '%s' "$MARKER_TEXT" >"$stub/marker"; touch "$stub/branch"; printf '%040d' 5 >"$stub/tip"; touch "$stub/forbidden"
  out="$(upload)" && rc=0 || rc=$?
  check 'a ruleset refusal ends in exit 3 with a note line, never a success' "$([[ $rc -eq 3 && -z "$out" ]] && grep -q '|note|' "$audit" && ! grep -q '|external-write|' "$audit" && echo yes || echo no)"

  reset; printf '%s' "$MARKER_TEXT" >"$stub/marker"; touch "$stub/branch"; printf '%040d' 5 >"$stub/tip"; echo diverged >"$stub/compare_status"
  out="$(upload)" && rc=0 || rc=$?
  check 'a commit that cr-assets does not hold is refused' "$([[ $rc -eq 3 && -z "$out" ]] && echo yes || echo no)"

  reset; printf '%s' "$MARKER_TEXT" >"$stub/marker"; touch "$stub/branch" "$stub/commit_ok"; printf '%040d' 5 >"$stub/tip"
  (cd "$repo" && bash "$GUARD" file --slug demo --out "$stub" "$png" >/dev/null)
  { printf 'blob %s\0' "$(wc -c <"$stub/step-head.png" | tr -d ' ')"; cat "$stub/step-head.png"; } | shasum | cut -d' ' -f1 >"$stub/exists_sha"
  rm -f "$stub/step-head.png"
  out="$(upload)" && rc=0 || rc=$?
  check 'a path that already holds the same bytes is reused without a write' "$([[ $rc -eq 0 && ! -f "$stub/puts" && "$out" == "https://github.com/o/r/blob/$(printf '%040d' 5)/pr-7/0123456789ab-${finding}-head.png?raw=true" ]] && echo yes || echo no)"
  echo 'ffffffffffffffffffffffffffffffffffffffff' >"$stub/exists_sha"
  out="$(upload)" && rc=0 || rc=$?
  check 'a path that holds other bytes is refused' "$([[ $rc -eq 3 && ! -f "$stub/puts" ]] && echo yes || echo no)"

  reset; printf '%s' "$MARKER_TEXT" >"$stub/marker"; touch "$stub/branch"; printf '%040d' 5 >"$stub/tip"
  out="$(upload "$repo/.claude/run/demo.artifacts/../../../../x.png")" && rc=0 || rc=$?
  check 'a file outside the artifacts directory is refused before any call' "$([[ $rc -eq 1 && ! -s "$stub/calls.log" ]] && echo yes || echo no)"
  out="$(run upload 12 --head-sha "$head" --slug demo --finding "$finding" --state head "$png")" && rc=0 || rc=$?
  check 'a bare pull request number is refused, because origin is never the target' "$([[ $rc -eq 1 && ! -s "$stub/calls.log" ]] && echo yes || echo no)"
  out="$(run upload "$pr" --head-sha abc --slug demo --finding "$finding" --state head "$png")" && rc=0 || rc=$?
  check 'a short head SHA is refused' "$([[ $rc -eq 1 ]] && echo yes || echo no)"
  out="$(run upload "$pr" --head-sha "$head" --slug demo --finding 'f-../x' --state head "$png")" && rc=0 || rc=$?
  check 'a finding id that is not a finding id is refused' "$([[ $rc -eq 1 ]] && echo yes || echo no)"

  # The verify command: the one allowed URL shape, this repository, this pull request, a commit that cr-assets holds.
  reset; touch "$stub/commit_ok"
  good="https://github.com/o/r/blob/$(printf '%040d' 99)/pr-7/0123456789ab-${finding}-head.png?raw=true"
  out="$(run verify "$pr" "$good")" && rc=0 || rc=$?
  check 'verify accepts a screenshot of this pull request on a commit that cr-assets holds' "$([[ $rc -eq 0 && "$out" == ok ]] && echo yes || echo no)"
  out="$(run verify "$pr" "${good/o\/r/fork\/r}")" && rc=0 || rc=$?
  check 'verify refuses a URL of a fork of the same network' "$([[ $rc -eq 1 ]] && echo yes || echo no)"
  out="$(run verify "$pr" "${good/pr-7/pr-8}")" && rc=0 || rc=$?
  check 'verify refuses the path of another pull request' "$([[ $rc -eq 1 ]] && echo yes || echo no)"
  out="$(run verify "$pr" "${good%\?raw=true}")" && rc=0 || rc=$?
  check 'verify refuses a URL with another query' "$([[ $rc -eq 1 ]] && echo yes || echo no)"
  out="$(run verify "$pr" "${good/\/blob\//\/raw\/}")" && rc=0 || rc=$?
  check 'verify refuses another URL kind' "$([[ $rc -eq 1 ]] && echo yes || echo no)"
  echo diverged >"$stub/compare_status"
  out="$(run verify "$pr" "$good")" && rc=0 || rc=$?
  check 'verify refuses a commit that is not on cr-assets' "$([[ $rc -eq 3 ]] && echo yes || echo no)"
  echo identical >"$stub/compare_status"; rm -f "$stub/commit_ok"
  out="$(run verify "$pr" "$good")" && rc=0 || rc=$?
  check 'verify refuses a path that is missing in the commit' "$([[ $rc -eq 3 ]] && echo yes || echo no)"

  reset; touch "$stub/ref_race"
  out="$(upload)" && rc=0 || rc=$?
  check 'a branch that another run creates first is used when it carries the marker' "$([[ $rc -eq 0 ]] && echo yes || echo no)"

  if [[ "$failures" -gt 0 ]]; then
    echo "$PROG self-test: $failures of $total checks failed" >&2
    return 1
  fi
  echo "$PROG self-test: $total checks passed"
}

main() {
  case "${1:-}" in
    --self-test) self_test ;;
    upload) shift; cmd_upload "$@" ;;
    verify) shift; cmd_verify "$@" ;;
    *) die 1 "usage: upload-review-asset.sh upload|verify|--self-test" ;;
  esac
}

main "$@"
