#!/usr/bin/env bash
# sync-external-skills.sh — keep skills that live in public GitHub repositories in step with their upstream.
#
# Why this exists
#   AI Olympus ships skills under skills/. Some skills are maintained in other public repositories.
#   Copying them by hand loses the upstream commit, the licence, and any chance of reviewing what
#   changed. This script reads a registry of allowed sources, fetches only the files the registry
#   declares at the newest commit of the tracked ref, and records the exact commit and the SHA-256 of
#   every file in a lock file. A GitHub Actions workflow runs it and opens a pull request.
#   Nothing here merges anything.
#
# Files (relative to --root, the repository root by default)
#   config/external-skills.json       the registry; edited by hand, reviewed like code
#   config/external-skills.lock.json  the metadata; written only by this script
#   skills/<name>/                    the target; holds the declared files and UPSTREAM-LICENSE
#
# Usage
#   sync-external-skills.sh [--root <dir>] [--dry-run]
#   sync-external-skills.sh --self-test
#
#   --dry-run   report what would change, write nothing
#
# Trust
#   Upstream content is untrusted data (rules/security/general.md). It is never executed, never
#   sourced, and never interpreted: the script only copies bytes after these checks.
#   - Only registry entries with enabled=true are fetched, and only from github.com repositories
#     through the two fixed hosts api.github.com and raw.githubusercontent.com over pinned TLS.
#   - The registry paths are validated: no absolute path, no "..", no hidden segment. The target is
#     always skills/<name>, so the sync can never write into .github/ or any other tree.
#   - Files are fetched at the commit SHA the API returned, not at the moving ref, so the bytes match
#     the SHA recorded in the lock. Each file must be small, non-empty, free of NUL bytes, free of a
#     shebang, and carry an extension from a text-only allow-list.
#   - Every file of a skill is fetched and checked before any file is written.
#   - The GitHub token, when GITHUB_TOKEN is set, goes to api.github.com only, inside a 0600 curl
#     config file. It is never put in argv, never sent to the raw host, never printed.
#
# Licence
#   The licence of the upstream repository comes from the GitHub licence API. A skill whose licence is
#   missing or is not in the registry's allowed_licenses is not synced: it is reported as needing a
#   manual decision. The upstream licence file is copied to UPSTREAM-LICENSE so the copyright notice
#   travels with the files.
#
# Idempotence
#   Content is compared by SHA-256 against the lock. When no declared file and no registry setting
#   changed, nothing is written: the lock keeps its commit and its synced_at, so a run on an
#   unchanged upstream leaves the working tree clean.
#
# Exit codes
#   0  every enabled skill is in sync (or was updated)
#   1  usage error
#   2  missing required tool
#   3  at least one skill could not be synced (upstream unavailable, bad content)
#   4  the registry or the lock is invalid; nothing was fetched or written
#   5  at least one skill needs a manual licence or ownership decision (and none failed)
set -euo pipefail

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# One upstream file is a few kilobytes of text. 1 MiB per file is far above any skill file.
ATT_MAX_BYTES="${ATT_MAX_BYTES:-1048576}"
# shellcheck source=../skills/_shared/attachments.sh
source "$PACKAGE_DIR/skills/_shared/attachments.sh"

API_BASE='https://api.github.com/repos'
RAW_BASE='https://raw.githubusercontent.com'
LICENSE_COPY='UPSTREAM-LICENSE'

RE_NAME='^[a-z0-9][a-z0-9-]{0,63}$'
RE_REPO='^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}$'
RE_REF='^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$'
RE_PATH='^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$'
RE_EXT='\.(md|txt|json|ya?ml|csv)$'
RE_SPDX='^[A-Za-z0-9.+-]{1,64}$'
RE_TOKEN='^[A-Za-z0-9_.-]+$'

ROOT="$PACKAGE_DIR"
DRY_RUN=0
WORK=""
API_CFG=""
LOCK_JSON='{"skills":{}}'
ALLOWED_LICENSES='[]'
CHANGED=0
UNCHANGED=0
SKIPPED=0
MANUAL=0
ERRORS=0

usage() {
  cat >&2 <<'EOF'
Usage: sync-external-skills.sh [--root <dir>] [--dry-run]
       sync-external-skills.sh --self-test
EOF
}

# Seams: the self-test replaces these two functions and nothing else.
now_utc() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# upstream_fetch <url> <out> <api|raw> — one HTTPS GET through the shared pinned download helper.
upstream_fetch() {
  local url="$1" out="$2" kind="$3" cfg=""
  [[ "$kind" == api ]] && cfg="$API_CFG"
  att_download "$url" "$out" "$cfg"
}

# Print a message that carries only characters a workflow command cannot be built from.
clean() { printf '%s' "$1" | LC_ALL=C tr -c 'A-Za-z0-9._/:@=,() -' '?'; }

report() { # <level> <name> <message>
  local msg
  msg="$(clean "$2: $3")"
  echo "$1 $msg" >&2
  if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
    case "$1" in
    ERROR) echo "::error title=External skill sync::$msg" ;;
    MANUAL) echo "::warning title=External skill needs a manual decision::$msg" ;;
    esac
  fi
}

fail_skill() { ERRORS=$((ERRORS + 1)); report ERROR "$1" "$2"; }
manual_skill() { MANUAL=$((MANUAL + 1)); report MANUAL "$1" "$2"; }

is_rel_path() {
  local p="$1"
  [[ ${#p} -le 200 && "$p" =~ $RE_PATH && "$p" != *..* ]]
}

# has_symlink <path relative to ROOT> — true when the path or any parent below ROOT is a symlink.
has_symlink() {
  local rest="$1" cur="$ROOT" part
  while [[ -n "$rest" ]]; do
    part="${rest%%/*}"
    cur="$cur/$part"
    [[ -L "$cur" ]] && return 0
    [[ "$rest" == */* ]] && rest="${rest#*/}" || rest=""
  done
  return 1
}

# entry_problem <entry json> — prints why a registry entry is refused; prints nothing when it is valid.
entry_problem() {
  local e="$1" name f
  if ! printf '%s' "$e" | jq -e '
      type == "object"
      and ((keys | sort) == ["enabled","files","name","ref","repository","source_path","target_path","update_strategy"])
      and ([.name, .repository, .ref, .source_path, .target_path, .update_strategy] | all(type == "string"))
      and (.enabled | type == "boolean")
      and (.files | type == "array" and all(.[]; type == "string"))' >/dev/null 2>&1; then
    echo 'the entry must be an object with exactly the keys name, repository, ref, source_path, target_path, files, enabled, update_strategy'
    return 0
  fi
  name="$(printf '%s' "$e" | jq -r .name)"
  [[ "$name" =~ $RE_NAME ]] || { echo 'name must be a lowercase slug'; return 0; }
  f="$(printf '%s' "$e" | jq -r .repository)"
  [[ "$f" =~ $RE_REPO && "$f" != *..* ]] || { echo 'repository must be OWNER/REPO on github.com'; return 0; }
  f="$(printf '%s' "$e" | jq -r .ref)"
  [[ "$f" =~ $RE_REF && "$f" != *..* && "$f" != *//* && "$f" != */ && "$f" != *.lock ]] || { echo 'ref is not a safe branch or tag name'; return 0; }
  f="$(printf '%s' "$e" | jq -r .source_path)"
  is_rel_path "$f" || { echo 'source_path must be a safe relative path'; return 0; }
  f="$(printf '%s' "$e" | jq -r .target_path)"
  [[ "$f" == "skills/$name" ]] || { echo 'target_path must be skills/NAME'; return 0; }
  f="$(printf '%s' "$e" | jq -r .update_strategy)"
  [[ "$f" == pull-request ]] || { echo 'update_strategy must be pull-request, nothing merges automatically'; return 0; }
  if ! printf '%s' "$e" | jq -e --arg lic "$LICENSE_COPY" '
      (.files | length) <= 50
      and ((.files | unique | length) == (.files | length))
      and (.files | index("SKILL.md") != null)
      and (.files | index($lic) == null)
      and ([.files[] as $a | .files[] | select(startswith($a + "/"))] | length == 0)' >/dev/null 2>&1; then
    echo "files must hold SKILL.md, at most 50 unique paths, no $LICENSE_COPY, and no path that is a parent of another"
    return 0
  fi
  while IFS= read -r f; do
    is_rel_path "$f" || { echo 'a declared file is not a safe relative path'; return 0; }
    [[ "$f" =~ $RE_EXT ]] || { echo 'a declared file has an extension outside md, txt, json, yaml, yml, csv'; return 0; }
  done < <(printf '%s' "$e" | jq -r '.files[]')
}

# load_config — validate the whole registry and the lock before anything is fetched or written.
load_config() {
  local registry="$ROOT/config/external-skills.json" lock="$ROOT/config/external-skills.lock.json"
  local count i entry label problem bad=0

  if ! jq -e '
      type == "object"
      and ((keys - ["allowed_licenses","skills"]) | length == 0)
      and (.skills | type == "array")
      and ((.allowed_licenses // []) | type == "array" and all(.[]; type == "string"))
      and ([.skills[] | .name?] | (unique | length) == length)' "$registry" >/dev/null 2>&1; then
    echo "$PROG: $registry is not a valid registry (an object with a skills array, optional allowed_licenses, unique names)" >&2
    return 4
  fi
  ALLOWED_LICENSES="$(jq -c '.allowed_licenses // []' "$registry")"

  count="$(jq '.skills | length' "$registry")"
  for ((i = 0; i < count; i++)); do
    entry="$(jq -c ".skills[$i]" "$registry")"
    problem="$(entry_problem "$entry")"
    if [[ -n "$problem" ]]; then
      label="$(printf '%s' "$entry" | jq -r '.name? // empty' 2>/dev/null || true)"
      [[ "$label" =~ $RE_NAME ]] || label="skills[$i]"
      report ERROR "$label" "$problem"
      bad=1
    fi
  done

  if [[ -f "$lock" ]]; then
    if ! jq -e 'type == "object" and (.skills | type == "object")' "$lock" >/dev/null 2>&1; then
      echo "$PROG: $lock is not a valid lock file" >&2
      return 4
    fi
    LOCK_JSON="$(cat "$lock")"
  fi
  [[ "$bad" -eq 0 ]] || return 4
}

# persist_lock — write the lock in one canonical form, and only when its bytes change.
persist_lock() {
  local lock="$ROOT/config/external-skills.lock.json" new tmp
  new="$(printf '%s' "$LOCK_JSON" | jq -S .)"
  if [[ -f "$lock" && "$new" == "$(cat "$lock")" ]]; then
    return 0
  fi
  tmp="$lock.tmp.$$"
  printf '%s\n' "$new" > "$tmp"
  mv -f "$tmp" "$lock"
}

# text_problem <file> — prints why a downloaded file is refused; prints nothing when it passes.
text_problem() {
  local f="$1" size
  size="$(att_file_size "$f")"
  if [[ "$size" -eq 0 ]]; then
    echo 'is empty'
  elif [[ "$(LC_ALL=C tr -d '\000' < "$f" | wc -c | tr -d '[:space:]')" != "$size" ]]; then
    echo 'contains NUL bytes'
  elif [[ "$(head -c 2 "$f")" == '#!' ]]; then
    echo 'starts with a shebang'
  fi
}

# install_file <staged file> <target path relative to ROOT> — copy one checked file into the tree.
install_file() {
  local src="$1" rel="$2" dest tmp
  dest="$ROOT/$rel"
  mkdir -p "$(dirname "$dest")"
  has_symlink "$rel" && return 1
  tmp="$dest.tmp.$$"
  cp "$src" "$tmp"
  chmod 644 "$tmp"
  mv -f "$tmp" "$dest"
}

sync_one() {
  local entry="$1" name repo ref src target stage sha spdx lpath lsha f h reason files_obj='{}'
  local old fresh new_lock stale

  name="$(printf '%s' "$entry" | jq -r .name)"
  if [[ "$(printf '%s' "$entry" | jq -r .enabled)" != true ]]; then
    SKIPPED=$((SKIPPED + 1))
    echo "disabled   $name"
    return 0
  fi
  repo="$(printf '%s' "$entry" | jq -r .repository)"
  ref="$(printf '%s' "$entry" | jq -r .ref)"
  src="$(printf '%s' "$entry" | jq -r .source_path)"
  target="$(printf '%s' "$entry" | jq -r .target_path)"
  stage="$WORK/$name"
  mkdir -p "$stage/files"

  # 1. The upstream repository is reachable, and the newest commit of the tracked ref.
  if ! upstream_fetch "$API_BASE/$repo/commits/$ref" "$stage/commit.json" api; then
    fail_skill "$name" "upstream repository or ref is unavailable ($repo $ref)"
    return 0
  fi
  sha="$(jq -r '.sha // ""' "$stage/commit.json" 2>/dev/null || true)"
  if [[ ! "$sha" =~ ^[0-9a-f]{40}$ ]]; then
    fail_skill "$name" 'the upstream commit answer carries no commit SHA'
    return 0
  fi

  # 2. The licence allows redistribution, or a human decides.
  if ! upstream_fetch "$API_BASE/$repo/license" "$stage/license.json" api; then
    manual_skill "$name" 'no licence was detected for the upstream repository'
    return 0
  fi
  spdx="$(jq -r '.license.spdx_id // ""' "$stage/license.json" 2>/dev/null || true)"
  lpath="$(jq -r '.path // ""' "$stage/license.json" 2>/dev/null || true)"
  if [[ ! "$spdx" =~ $RE_SPDX ]] || ! printf '%s' "$ALLOWED_LICENSES" | jq -e --arg s "$spdx" 'index($s) != null' >/dev/null; then
    manual_skill "$name" "licence $spdx is unclear or not in allowed_licenses"
    return 0
  fi
  if ! is_rel_path "$lpath"; then
    manual_skill "$name" 'the upstream licence file path is not usable'
    return 0
  fi

  # 3. Fetch and check every declared file at that commit. Nothing is written yet.
  while IFS= read -r f; do
    mkdir -p "$(dirname "$stage/files/$f")"
    if ! upstream_fetch "$RAW_BASE/$repo/$sha/$src/$f" "$stage/files/$f" raw; then
      fail_skill "$name" "declared file could not be downloaded: $f"
      return 0
    fi
    reason="$(text_problem "$stage/files/$f")"
    if [[ -n "$reason" ]]; then
      fail_skill "$name" "declared file $f $reason"
      return 0
    fi
    h="$(att_sha256 "$stage/files/$f")"
    files_obj="$(printf '%s' "$files_obj" | jq -c --arg k "$f" --arg v "$h" '. + {($k): $v}')"
  done < <(printf '%s' "$entry" | jq -r '.files[]')

  if ! upstream_fetch "$RAW_BASE/$repo/$sha/$lpath" "$stage/licence-file" raw; then
    manual_skill "$name" 'the upstream licence file could not be downloaded'
    return 0
  fi
  reason="$(text_problem "$stage/licence-file")"
  if [[ -n "$reason" ]]; then
    manual_skill "$name" "the upstream licence file $reason"
    return 0
  fi
  lsha="$(att_sha256 "$stage/licence-file")"

  # 4. Compare with the lock. The commit is not part of the comparison: content decides.
  fresh="$(jq -n -c --arg repo "https://github.com/$repo" --arg ref "$ref" --arg src "$src" --arg sha "$sha" \
    --argjson files "$files_obj" --arg spdx "$spdx" --arg lpath "$lpath" --arg lsha "$lsha" \
    '{repository: $repo, ref: $ref, source_path: $src, commit: $sha, files: $files,
      license: {spdx: $spdx, path: $lpath, sha256: $lsha}}')"
  old="$(printf '%s' "$LOCK_JSON" | jq -c --arg n "$name" '.skills[$n] // null')"
  if [[ "$old" != null ]] && [[ "$(printf '%s' "$old" | jq -S -c 'del(.commit, .synced_at)')" == "$(printf '%s' "$fresh" | jq -S -c 'del(.commit)')" ]]; then
    UNCHANGED=$((UNCHANGED + 1))
    echo "unchanged  $name  $(printf '%s' "$old" | jq -r '.commit')"
    while IFS=$'\t' read -r f h; do
      if [[ ! -f "$ROOT/$target/$f" ]] || [[ "$(att_sha256 "$ROOT/$target/$f")" != "$h" ]]; then
        echo "WARN       $name  local copy of $f differs from the lock; upstream is unchanged, so it is kept"
      fi
    done < <(printf '%s' "$old" | jq -r '.files | to_entries[] | [.key, .value] | @tsv')
    return 0
  fi

  # 5. A changed skill. A first sync never takes over a path that already exists.
  if [[ "$old" == null ]] && { [[ -e "$ROOT/$target" ]] || [[ -L "$ROOT/$target" ]]; }; then
    manual_skill "$name" "$target already exists and is not managed by the registry"
    return 0
  fi
  CHANGED=$((CHANGED + 1))
  echo "updated    $name  $(printf '%s' "$old" | jq -r '.commit // "none"') -> $sha"
  [[ "$DRY_RUN" -eq 0 ]] || return 0

  has_symlink "$target" && { fail_skill "$name" "$target contains a symbolic link"; return 0; }
  while IFS= read -r f; do
    install_file "$stage/files/$f" "$target/$f" || { fail_skill "$name" "$target/$f contains a symbolic link"; return 0; }
  done < <(printf '%s' "$entry" | jq -r '.files[]')
  install_file "$stage/licence-file" "$target/$LICENSE_COPY" || { fail_skill "$name" "$target/$LICENSE_COPY contains a symbolic link"; return 0; }

  # A file the lock managed and the registry no longer declares is removed with the rest.
  while IFS= read -r stale; do
    if is_rel_path "$stale" && ! has_symlink "$target/$stale"; then
      rm -f "$ROOT/$target/$stale"
    fi
  done < <(printf '%s' "$old" | jq -r --argjson now "$files_obj" '(.files // {}) | keys[] | select(. as $k | $now | has($k) | not)')

  new_lock="$(printf '%s' "$fresh" | jq -c --arg ts "$(now_utc)" '. + {synced_at: $ts}')"
  LOCK_JSON="$(printf '%s' "$LOCK_JSON" | jq -c --arg n "$name" --argjson r "$new_lock" '.skills[$n] = $r')"
  persist_lock
}

make_api_config() {
  API_CFG="$WORK/api.curl"
  (
    umask 077
    {
      echo 'header = "Accept: application/vnd.github+json"'
      echo 'header = "X-GitHub-Api-Version: 2022-11-28"'
      if [[ "${GITHUB_TOKEN:-}" =~ $RE_TOKEN ]]; then
        printf 'header = "Authorization: Bearer %s"\n' "$GITHUB_TOKEN"
      fi
    } > "$API_CFG"
  )
}

sync_all() {
  local count i status=0
  load_config || return $?
  count="$(jq '.skills | length' "$ROOT/config/external-skills.json")"
  for ((i = 0; i < count; i++)); do
    sync_one "$(jq -c ".skills[$i]" "$ROOT/config/external-skills.json")"
  done
  echo "summary: updated=$CHANGED unchanged=$UNCHANGED disabled=$SKIPPED manual=$MANUAL errors=$ERRORS"
  if [[ "$ERRORS" -gt 0 ]]; then
    status=3
  elif [[ "$MANUAL" -gt 0 ]]; then
    status=5
  fi
  return "$status"
}

run_sync() {
  WORK="$(mktemp -d)"
  trap 'rm -rf "$WORK"' EXIT
  att_require_tools
  make_api_config
  sync_all
}

# ---------------------------------------------------------------- self-test

SELF_FAILURES=0
SELF_TMP=""
check() { # <label> <command...>
  local label="$1"
  shift
  if "$@"; then
    echo "ok    $label"
  else
    echo "FAIL  $label" >&2
    SELF_FAILURES=$((SELF_FAILURES + 1))
  fi
}

fake_key() { printf '%s' "$1" | LC_ALL=C tr -c 'A-Za-z0-9._-' '_'; }
fake_put() { printf '%s' "$2" > "$FAKE/$(fake_key "$1")"; }
fake_del() { rm -f "$FAKE/$(fake_key "$1")"; }

# fake_upstream <repo> <ref> <sha> <spdx> <SKILL.md body>
fake_upstream() {
  fake_put "$API_BASE/$1/commits/$2" "{\"sha\":\"$3\"}"
  if [[ -n "$4" ]]; then
    fake_put "$API_BASE/$1/license" "{\"path\":\"LICENSE\",\"license\":{\"spdx_id\":\"$4\"}}"
  else
    fake_del "$API_BASE/$1/license"
  fi
  fake_put "$RAW_BASE/$1/$3/skills/demo/SKILL.md" "$5"
  fake_put "$RAW_BASE/$1/$3/LICENSE" "Copyright (c) Upstream Author"
}

skill_entry() { # <name> <repo> <enabled> [extra files json]
  jq -n -c --arg n "$1" --arg r "$2" --argjson e "$3" --argjson f "${4:-[\"SKILL.md\"]}" \
    '{name: $n, repository: $r, ref: "main", source_path: "skills/demo", target_path: ("skills/" + $n),
      files: $f, enabled: $e, update_strategy: "pull-request"}'
}

write_registry() { # <entry json>...
  local joined
  joined="$(printf '%s\n' "$@" | jq -s -c '{allowed_licenses: ["MIT"], skills: .}')"
  mkdir -p "$ROOT/config" "$ROOT/skills"
  printf '%s\n' "$joined" > "$ROOT/config/external-skills.json"
}

# run_and_rc — run the sync in a subshell with errexit on, keep its exit code in $FAKE/rc and its output in $OUT.
run_and_rc() {
  set +e
  (
    set -e
    CHANGED=0 UNCHANGED=0 SKIPPED=0 MANUAL=0 ERRORS=0 LOCK_JSON='{"skills":{}}'
    run_sync
  ) > "$FAKE/out" 2>&1
  echo "$?" > "$FAKE/rc"
  set -e
  OUT="$(cat "$FAKE/out")"
}
last_rc() { cat "$FAKE/rc"; }
rc_is() { [[ "$(last_rc)" == "$1" ]]; }
out_has() { [[ "$OUT" == *"$1"* ]]; }
lock_get() { jq -r "$1" "$ROOT/config/external-skills.lock.json"; }
file_is() { [[ "$(cat "$ROOT/$1")" == "$2" ]]; }
absent() { [[ ! -e "$ROOT/$1" ]]; }

self_test() {
  local tmp sha1 sha2 sha3 before lock_before
  tmp="$(mktemp -d)"
  SELF_TMP="$tmp"
  trap 'rm -rf "$SELF_TMP"' EXIT
  FAKE="$tmp/fake"
  ROOT="$tmp/project"
  mkdir -p "$FAKE" "$ROOT"
  sha1="$(printf 'a%.0s' {1..40})"
  sha2="$(printf 'b%.0s' {1..40})"
  sha3="$(printf 'c%.0s' {1..40})"

  upstream_fetch() { # replaces the network: serves the fixture stored for the URL
    local file
    file="$FAKE/$(fake_key "$1")"
    [[ -f "$file" ]] || return 22
    cp "$file" "$2"
  }
  now_utc() { cat "$FAKE/clock"; }
  echo '2026-01-01T00:00:00Z' > "$FAKE/clock"

  # first sync
  fake_upstream o/one main "$sha1" MIT 'one v1'
  write_registry "$(skill_entry demo-one o/one true)"
  run_and_rc
  check 'first sync exits 0' rc_is 0
  check 'first sync writes the declared file' file_is skills/demo-one/SKILL.md 'one v1'
  check 'first sync copies the upstream licence beside the files' file_is skills/demo-one/UPSTREAM-LICENSE 'Copyright (c) Upstream Author'
  check 'the lock records the upstream commit SHA' test "$(lock_get '.skills["demo-one"].commit')" = "$sha1"
  check 'the lock records the repository URL, ref, source path, licence, and date' \
    test "$(lock_get '.skills["demo-one"] | [.repository, .ref, .source_path, .license.spdx, .synced_at] | join(" ")')" = 'https://github.com/o/one main skills/demo MIT 2026-01-01T00:00:00Z'
  check 'the lock records a SHA-256 for every file' test "$(lock_get '.skills["demo-one"].files["SKILL.md"] | length')" = 64

  # unchanged upstream
  echo '2027-02-02T00:00:00Z' > "$FAKE/clock"
  lock_before="$(cat "$ROOT/config/external-skills.lock.json")"
  before="$(cksum "$ROOT/skills/demo-one/SKILL.md")"
  run_and_rc
  check 'an unchanged upstream exits 0' rc_is 0
  check 'an unchanged upstream reports unchanged' out_has 'unchanged  demo-one'
  check 'an unchanged upstream leaves the lock byte for byte, so the date causes no commit' test "$(cat "$ROOT/config/external-skills.lock.json")" = "$lock_before"
  check 'an unchanged upstream leaves the skill file alone' test "$(cksum "$ROOT/skills/demo-one/SKILL.md")" = "$before"

  # upstream head moves, declared files do not
  fake_upstream o/one main "$sha2" MIT 'one v1'
  run_and_rc
  check 'a new upstream commit with the same declared content changes nothing' test "$(cat "$ROOT/config/external-skills.lock.json")" = "$lock_before"

  # upstream content changes
  fake_upstream o/one main "$sha3" MIT 'one v2'
  run_and_rc
  check 'a changed upstream file is written' file_is skills/demo-one/SKILL.md 'one v2'
  check 'a changed upstream file moves the locked commit' test "$(lock_get '.skills["demo-one"].commit')" = "$sha3"
  check 'a changed upstream file moves the date' test "$(lock_get '.skills["demo-one"].synced_at')" = '2027-02-02T00:00:00Z'

  # local edits survive an unchanged upstream
  printf 'local edit' > "$ROOT/skills/demo-one/SKILL.md"
  run_and_rc
  check 'a local edit is kept while upstream is unchanged' file_is skills/demo-one/SKILL.md 'local edit'
  check 'a local edit is reported as drift' out_has 'local copy of SKILL.md differs'

  # two independent sources, one disabled
  fake_upstream o/two main "$sha1" MIT 'two v1'
  fake_upstream o/three main "$sha1" MIT 'three v1'
  write_registry "$(skill_entry demo-one o/one true)" "$(skill_entry demo-two o/two true)" "$(skill_entry demo-three o/three false)"
  run_and_rc
  check 'several sources sync independently' file_is skills/demo-two/SKILL.md 'two v1'
  check 'a disabled skill is not fetched or written' absent skills/demo-three
  check 'a disabled skill is reported' out_has 'disabled   demo-three'
  check 'the lock holds one entry per synced source' test "$(lock_get '.skills | keys | join(",")')" = 'demo-one,demo-two'

  # one source fails, the others still sync
  fake_del "$API_BASE/o/two/commits/main"
  fake_upstream o/one main "$sha1" MIT 'one v3'
  run_and_rc
  check 'an unavailable upstream exits 3' rc_is 3
  check 'an unavailable upstream is reported' out_has 'ERROR demo-two: upstream repository or ref is unavailable'
  check 'the other source still syncs when one fails' file_is skills/demo-one/SKILL.md 'one v3'
  fake_upstream o/two main "$sha1" MIT 'two v1'

  # a removed declared file is removed with the rest
  fake_put "$RAW_BASE/o/two/$sha1/skills/demo/extra.md" 'extra'
  write_registry "$(skill_entry demo-two o/two true '["SKILL.md","extra.md"]')"
  run_and_rc
  check 'a newly declared file is synced' file_is skills/demo-two/extra.md 'extra'
  write_registry "$(skill_entry demo-two o/two true)"
  run_and_rc
  check 'a file the registry stops declaring is removed' absent skills/demo-two/extra.md

  # dry run
  fake_upstream o/two main "$sha2" MIT 'two v2'
  lock_before="$(cat "$ROOT/config/external-skills.lock.json")"
  write_registry "$(skill_entry demo-two o/two true)"
  DRY_RUN=1 run_and_rc
  check 'a dry run reports the update' out_has 'updated    demo-two'
  check 'a dry run writes no file' file_is skills/demo-two/SKILL.md 'two v1'
  check 'a dry run writes no lock' test "$(cat "$ROOT/config/external-skills.lock.json")" = "$lock_before"

  # licence
  fake_upstream o/gpl main "$sha1" 'GPL-3.0' 'gpl v1'
  write_registry "$(skill_entry demo-gpl o/gpl true)"
  run_and_rc
  check 'a licence outside allowed_licenses needs a manual decision' rc_is 5
  check 'a licence outside allowed_licenses is reported' out_has 'MANUAL demo-gpl'
  check 'a licence outside allowed_licenses writes nothing' absent skills/demo-gpl
  fake_upstream o/gpl main "$sha1" '' 'gpl v1'
  run_and_rc
  check 'a missing licence needs a manual decision' rc_is 5
  check 'a missing licence writes nothing' absent skills/demo-gpl
  fake_upstream o/gpl main "$sha1" 'NOASSERTION' 'gpl v1'
  run_and_rc
  check 'an unclear licence needs a manual decision' rc_is 5

  # ownership
  mkdir -p "$ROOT/skills/own-skill"
  echo own > "$ROOT/skills/own-skill/SKILL.md"
  fake_upstream o/own main "$sha1" MIT 'own upstream'
  write_registry "$(skill_entry own-skill o/own true)"
  run_and_rc
  check 'a path that exists and is not managed needs a manual decision' rc_is 5
  check 'an unmanaged path is never overwritten' file_is skills/own-skill/SKILL.md own

  # integrity
  fake_upstream o/bad main "$sha1" MIT '#!/bin/sh'
  write_registry "$(skill_entry demo-bad o/bad true)"
  run_and_rc
  check 'a file that starts with a shebang is refused' rc_is 3
  check 'a refused file leaves no skill directory' absent skills/demo-bad
  fake_upstream o/bad main "$sha1" MIT ''
  run_and_rc
  check 'an empty file is refused' rc_is 3
  fake_upstream o/bad main "$sha1" MIT 'text'
  printf 'a\0b' > "$FAKE/$(fake_key "$RAW_BASE/o/bad/$sha1/skills/demo/SKILL.md")"
  run_and_rc
  check 'a file with NUL bytes is refused' rc_is 3
  fake_upstream o/bad main "$sha1" MIT 'text'
  fake_put "$API_BASE/o/bad/commits/main" '{"sha":"not-a-sha"}'
  run_and_rc
  check 'an answer without a commit SHA is refused' rc_is 3
  fake_upstream o/bad main "$sha1" MIT 'text'
  write_registry "$(skill_entry demo-bad o/bad true '["SKILL.md","second.md"]')"
  run_and_rc
  check 'one failing file stops the whole skill before anything is written' absent skills/demo-bad

  # a symbolic link in the target
  mkdir -p "$tmp/outside"
  ln -s "$tmp/outside" "$ROOT/skills/demo-link"
  fake_upstream o/link main "$sha1" MIT 'link v1'
  write_registry "$(skill_entry demo-link o/link true)"
  run_and_rc
  check 'a symbolic link at the target is never followed' test -z "$(ls "$tmp/outside")"

  # registry validation: nothing is fetched and the exit code is 4
  local bad
  for bad in \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"../up","target_path":"skills/x","files":["SKILL.md"],"enabled":true,"update_strategy":"pull-request"}' \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"/abs","target_path":"skills/x","files":["SKILL.md"],"enabled":true,"update_strategy":"pull-request"}' \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"s","target_path":".github/workflows","files":["SKILL.md"],"enabled":true,"update_strategy":"pull-request"}' \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"s","target_path":"skills/y","files":["SKILL.md"],"enabled":true,"update_strategy":"pull-request"}' \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"s","target_path":"skills/x","files":["SKILL.md","../x.md"],"enabled":true,"update_strategy":"pull-request"}' \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"s","target_path":"skills/x","files":["SKILL.md","run.sh"],"enabled":true,"update_strategy":"pull-request"}' \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"s","target_path":"skills/x","files":["README.md"],"enabled":true,"update_strategy":"pull-request"}' \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"s","target_path":"skills/x","files":["SKILL.md"],"enabled":true,"update_strategy":"auto-merge"}' \
    '{"name":"x","repository":"https://evil.example/r","ref":"main","source_path":"s","target_path":"skills/x","files":["SKILL.md"],"enabled":true,"update_strategy":"pull-request"}' \
    '{"name":"x","repository":"o/r","ref":"main","source_path":"s","target_path":"skills/x","files":["SKILL.md"],"enabled":true,"update_strategy":"pull-request","hook":"run.sh"}' \
    '{"name":"x","repository":"o/r","ref":"../main","source_path":"s","target_path":"skills/x","files":["SKILL.md"],"enabled":true,"update_strategy":"pull-request"}'; do
    write_registry "$bad"
    run_and_rc
    check "an unsafe registry entry is refused: $(printf '%s' "$OUT" | sed -n 's/^ERROR //p' | head -n 1)" rc_is 4
  done
  write_registry "$(skill_entry dup o/r true)" "$(skill_entry dup o/r true)"
  run_and_rc
  check 'a duplicate name is refused' rc_is 4
  echo 'not json' > "$ROOT/config/external-skills.json"
  run_and_rc
  check 'a registry that is not JSON is refused' rc_is 4

  if [[ "$SELF_FAILURES" -gt 0 ]]; then
    echo "sync-external-skills self-test: $SELF_FAILURES failure(s)" >&2
    return 1
  fi
  echo 'sync-external-skills self-test: PASS'
}

main() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --root)
      [[ $# -ge 2 ]] || { usage; exit 1; }
      ROOT="$(cd "$2" && pwd)"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --self-test)
      self_test
      exit $?
      ;;
    *)
      usage
      exit 1
      ;;
    esac
  done
  run_sync
}

main "$@"
