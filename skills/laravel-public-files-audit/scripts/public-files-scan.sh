#!/usr/bin/env bash
# public-files-scan.sh — the deterministic scan of the laravel-public-files-audit skill. It finds the
# files of a Laravel project that a web server can serve and that must never be public: dumps,
# archives, backups, environment and configuration copies, logs, scripts in the public disk, unsafe
# symlinks and permissions, and the .gitignore and deployment steps that let such a file reach the
# web root. It reports metadata only and never opens the content of a file it reports.
#
# Why this exists
#   A file under public/ is served by the web server as it is. A dump, a .env copy, or a log placed
#   there by a deployment step or by hand is one request away from an attacker, and no code review of
#   the application code sees it. This script is the one repeatable search: the same rules and the
#   same metadata-only evidence in every run, so "never open the content" is enforced by code.
#
# Requirements
#   bash, find, stat, awk, grep, and the standard shell tools; git for the git status, the .gitignore
#   check, and --changed-from; curl for the runtime mode. The shared path classifier
#   ../../git-history-exposure-audit/scripts/history-scan.sh (its classify mode) must sit beside this
#   skill, as the installer places every skill.
#
# Usage
#   public-files-scan.sh static  --root <project> [--changed-from <base sha>]
#   public-files-scan.sh runtime --root <project> --base-url <http(s)://local-host[:port]>
#   public-files-scan.sh --self-test
#
# static (default mode of the skill)
#   Scans public/ and storage/app/public/ of the project root on disk. The scan stays inside the
#   physical project root: resolve_link follows every path one component at a time and never reads,
#   lists, or stats an entry outside it. A scan root behind a symlink is decided first:
#     - the symlink resolves to a project directory: the root is scanned at that physical directory,
#       and the symlink is a symlink-permission finding on the root path unless the directory lies in
#       public/ or storage/app/public/;
#     - the symlink leaves the project, or resolves to the project root or to a file: one
#       symlink-permission finding on the root path, and the root is not read;
#     - the symlink does not resolve: a not-verified line.
#   A deployment directory is decided the same way. Below a scan root it does not descend into a
#   symlinked directory and does not descend into a .git entry. An entry find cannot read is a
#   not-verified line. With --changed-from it prints only the findings in the diff scope, the review
#   mode of a code review. A finding is in that scope when `git diff --name-only -z <base> HEAD` lists
#   its path byte for byte, when its physical entry is the physical entry of a changed path, or when
#   the resolution of its directory follows a changed symlink (a .gitignore finding when any changed
#   path is a .gitignore file). A changed path below the target of a directory symlink under a scan
#   root is checked under the name it is served by. A changed path outside the project matches nothing.
#
# runtime (explicit request only)
#   Runs the name checks and the symlink check of the static mode on public/ and storage/app/public/
#   and sends one HEAD request per finding path, plus /.env, to the base URL. A scan root the static
#   mode would not read is a not-verified line. It accepts only a base
#   URL whose host is localhost, a name under .localhost or .test, an address in 127.0.0.0/8, or [::1],
#   with no user information, path, query, or fragment; any other target is refused with exit 4 before
#   a request. Every accepted name is pinned to the loopback address: curl gets
#   `--resolve <host>:<port>:127.0.0.1` (port 80 or 443 when the URL names none), so the system
#   resolver never decides where a request goes. A literal 127.0.0.0/8 address and [::1] connect as
#   given. Each request is `curl -q --head` with no redirect, no proxy, no credential, no cookie, and a
#   time limit. It downloads no body. A public/storage symlink that resolves to storage/app/public maps
#   a file of the public disk to /storage/<path>.
#
# Checks (the one owner of the patterns; the skill lists only the check table)
#   The name checks match the file name in any letter case, first match wins, one finding per path.
#   env-config-copy        critical  history-scan.sh classify rules env-file, private-key-file,
#                                    certificate-file, credentials-file; and *.env, *.conf, *.cnf, .git
#   dump-archive-backup    critical  history-scan.sh classify rules database-dump, backup-archive; and
#                                    *.sql, *.db, *.zip, *.tar, *.tar.*, *.tgz, *.7z, *.rar, *.old,
#                                    *.orig, *.save, *.swp, *~
#   log-debug              high      history-scan.sh classify rule log-file; and *.log.<digit>*,
#                                    *.log.gz, error_log, php_errors*, *.trace, *.prof,
#                                    cachegrind.out.*, phpinfo.php, info.php, debug.php
#   public-storage-script  high      in storage/app/public/ only: *.php, *.php<digit>, *.phtml, *.pht,
#                                    *.phar, *.phps, *.shtml, *.sh, *.cgi, *.pl, *.py, .htaccess,
#                                    .user.ini, and a regular file with an execute bit
#   symlink-permission     high      a symlink below a scan root whose target resolves outside the
#                                    scanned roots, leaves the project, or does not resolve; a scan
#                                    root or a deployment directory behind such a symlink (static
#                                    above); an entry that is world-writable
#   gitignore-deploy       medium    a probe path the project's .gitignore files do not ignore
#                                    (GITIGNORE_PROBES); a public/ path in a deployment file
#                                    (DEPLOY_FILES) whose name a name check above matches
#   http-reachable         high      (runtime) a HEAD request to a finding path answers 2xx
#   The name checks read the name only. The content of a reported file is never read, opened, hashed,
#   or copied. A deployment file is read line by line with grep -o for public/ path tokens; a line is
#   never printed.
#
# Evidence (metadata only)
#   size=<bytes> mtime=<UTC> mode=<octal> git=<tracked|ignored|untracked|none>, the git status of the
#   physical entry; target=<raw link> resolves=<outside-project|unresolved|path below the root|.>, where
#   outside-project never says whether the outside target exists; probe=<path> ignored=no;
#   line=<n> ref=<public/ token>
#   class=<check>; status=<http status> type=<content-type> length=<content-length>.
#   Every printed path is printable ASCII; any other byte and the backslash print as \xHH.
#
# Execution safety
#   Every git call goes through git_ro: no system or user configuration, no fsmonitor, no hooks, no
#   protocol. A path reaches ls-files as a :(literal) pathspec, so no name acts as a glob. The script never deletes, moves, renames, or changes the mode of a
#   file. Untrusted file names reach no eval, no sh -c, and no awk program text. A symlink of the
#   branch under review never steers a read outside the project root (static above), so the output
#   names no file, size, time, mode, existence, or line outside it.
#
# Output (stdout, one record per line)
#   public-files-scan: mode=<static|runtime> scope=<tree|diff>
#   finding<TAB>check<TAB>severity<TAB>path<TAB>evidence
#   probe<TAB>url-path<TAB>status=<code>                  (runtime: a path that is not reachable)
#   outbound-request: <host> HEAD <n> paths                (runtime)
#   not-verified: <what> - <reason and the step that settles it>
#   summary: findings=<n> status=<clean|findings|incomplete>
#
# Exit codes
#   0  a complete scan with no finding
#   1  usage error
#   2  missing required tool or the shared classifier
#   3  an incomplete scan: at least one not-verified line; the findings it has are printed
#   4  the runtime mode refused the target
#   5  a complete scan with findings

set +o xtrace +o verbose
set -u
umask 077
export LC_ALL=C

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="$SCRIPT_DIR/$PROG"
CLASSIFIER="$SCRIPT_DIR/../../git-history-exposure-audit/scripts/history-scan.sh"
FINDING_CAP=100
PROBE_CAP=200

# Laravel's own default .gitignore files ignore every probe. A probe they do not ignore is a finding.
GITIGNORE_PROBES='.env
.env.backup
.env.production
auth.json
public/storage
storage/oauth-private.key
storage/logs/laravel.log'

# The deployment files the gitignore-deploy check reads, as shell globs below the root.
DEPLOY_FILES='.github/workflows/*.yml
.github/workflows/*.yaml
.gitlab-ci.yml
bitbucket-pipelines.yml
Envoy.blade.php
deploy.php
deploy.sh
Dockerfile
Dockerfile.*
docker-compose*.yml
docker-compose*.yaml
compose.yml
compose.yaml'

usage() {
  cat >&2 <<'EOF'
usage: public-files-scan.sh static  --root <project> [--changed-from <base sha>]
       public-files-scan.sh runtime --root <project> --base-url <http(s)://local-host[:port]>
       public-files-scan.sh --self-test
EOF
  exit 1
}

die() {
  printf '%s: %s\n' "$PROG" "$2" >&2
  exit "$1"
}

need_tools() {
  local tool
  for tool in "$@"; do
    command -v "$tool" >/dev/null 2>&1 || die 2 "missing required tool: $tool"
  done
}

git_ro() {
  GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0 GIT_OPTIONAL_LOCKS=0 \
    git --no-pager -C "$ROOT" -c core.fsmonitor=false -c core.hooksPath=/dev/null \
    -c core.excludesFile=/dev/null -c protocol.allow=never "$@"
}

# Printable ASCII stays; any other byte and the backslash print as \xHH.
esc() {
  local s="$1" out='' c v i
  if [[ "$s" != *[![:print:]]* && "$s" != *\\* ]]; then
    printf '%s' "$s"
    return
  fi
  for ((i = 0; i < ${#s}; i++)); do
    c="${s:i:1}"
    if [[ "$c" == [[:print:]] && "$c" != "\\" ]]; then
      out+="$c"
    else
      printf -v v '%d' "'$c"
      ((v < 0)) && v=$((v + 256))
      out+="$(printf '\\x%02x' "$v")"
    fi
  done
  printf '%s' "$out"
}

# --- state ------------------------------------------------------------------------------------------

FINDINGS=0
INCOMPLETE=0
SCOPE=tree
SCRATCH=''
ROOT_P=''
ROOT_L=''
RESOLVED=''
PHYS_ENTRY=''
declare -a PATHS=() KINDS=() SHARED=() CLASS=() CHANGED=() CHANGED_P=() LINK_IDX=() LINK_EV=()
declare -a LINK_TRAIL=() LINK_TRAIL_RAW=() SCAN_ROOTS=() SCAN_PHYS=() ROOT_FIND=() ROOT_FEV=()
declare -a DEPLOY_REL=() DEPLOY_ABS=()

emit() {
  # emit <check> <severity> <path> <evidence>
  if [[ "$SCOPE" == diff ]] && ! in_scope "$1" "$3"; then return; fi
  printf 'finding\t%s\t%s\t%s\t%s\n' "$1" "$2" "$(esc "$3")" "$4"
  FINDINGS=$((FINDINGS + 1))
}

not_verified() {
  printf 'not-verified: %s\n' "$1"
  INCOMPLETE=1
}

# A finding is in scope when its path is a changed path byte for byte (git printed the list with -z,
# so no name is quoted), when its physical entry is the physical entry of a changed path, or when the
# resolution of its directory follows a changed symlink. CHANGED_P holds only paths inside the
# project, so the scope never reaches a path outside it.
in_scope() {
  local check="$1" path="$2" changed i link
  if [[ "$check" == gitignore-deploy && "$path" == .gitignore ]]; then
    for changed in ${CHANGED[@]+"${CHANGED[@]}"}; do
      [[ "$changed" == .gitignore || "$changed" == */.gitignore ]] && return 0
    done
    return 1
  fi
  for changed in ${CHANGED[@]+"${CHANGED[@]}"}; do
    [[ "$changed" == "$path" ]] && return 0
  done
  phys_entry "$path"
  for ((i = 0; i < ${#CHANGED_P[@]}; i++)); do
    changed="${CHANGED_P[i]}"
    [[ -n "$changed" ]] || continue
    [[ -n "$PHYS_ENTRY" && "$changed" == "$PHYS_ENTRY" ]] && return 0
    for link in ${LINK_TRAIL[@]+"${LINK_TRAIL[@]}"}; do
      [[ "$changed" == "$link" ]] && return 0
    done
  done
  return 1
}

# --- metadata ----------------------------------------------------------------------------------------

STAT_GNU=0
pick_stat() {
  if stat -c '%s' / >/dev/null 2>&1; then STAT_GNU=1; fi
}

# meta <relative path>: size=… mtime=… mode=… git=…. The git status is taken for the physical entry
# relative to the root, so a file served through a symlink reads with the status of its git path.
meta() {
  local rel="$1" raw size mtime mode when state grel="$1"
  if ((STAT_GNU)); then
    raw="$(stat -c '%s %Y %a' -- "$ROOT/$rel" 2>/dev/null)" || raw=''
  else
    raw="$(stat -f '%z %m %Lp' -- "$ROOT/$rel" 2>/dev/null)" || raw=''
  fi
  read -r size mtime mode <<<"$raw"
  if ((STAT_GNU)); then
    when="$(date -u -d "@${mtime:-0}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)" || when='-'
  else
    when="$(date -u -r "${mtime:-0}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)" || when='-'
  fi
  state=none
  if ((HAS_GIT)); then
    phys_entry "$rel" && [[ "$PHYS_ENTRY" == "$ROOT_P"/* ]] && grel="${PHYS_ENTRY#"$ROOT_P"/}"
    if git_ro ls-files --error-unmatch -- ":(literal)$grel" >/dev/null 2>&1; then
      state=tracked
    elif git_ro check-ignore -q -- "$grel" >/dev/null 2>&1; then
      state=ignored
    else
      state=untracked
    fi
  fi
  printf 'size=%s mtime=%s mode=%s git=%s' "${size:--}" "$when" "${mode:--}" "$state"
}

mode_bits() {
  if ((STAT_GNU)); then stat -c '%a' -- "$1" 2>/dev/null; else stat -f '%Lp' -- "$1" 2>/dev/null; fi
}

# resolve_link <absolute path>: the one resolver of the scan. It follows the path one component at a
# time and reads a component only while that component lies inside the physical project root ROOT_P,
# so it never lists, reads, or stats an entry outside the project. A component on the way down to
# ROOT_P is a real directory, because ROOT_P is physical, and is not read. An absolute link target
# written with the logical root (ROOT_L) is read as ROOT_P. It sets RESOLVED to the physical path, and
# records every symlink it follows in LINK_TRAIL (the physical path of the link entry) and
# LINK_TRAIL_RAW (its raw target). Returns 0 when the path resolves inside the project, 1 when it does
# not resolve (a missing entry, a file used as a directory, or more than 40 links), and 2 when it
# leaves the project; a target outside the project is never checked for existence.
resolve_link() {
  local rest="${1#/}" cur='' comp next target hops=0
  RESOLVED=''
  LINK_TRAIL=()
  LINK_TRAIL_RAW=()
  while [[ -n "$rest" ]]; do
    if [[ "$rest" == */* ]]; then
      comp="${rest%%/*}"
      rest="${rest#*/}"
    else
      comp="$rest"
      rest=''
    fi
    case "$comp" in
    '' | .) continue ;;
    ..) cur="${cur%/*}"; continue ;;
    esac
    next="$cur/$comp"
    if [[ "$next" == "$ROOT_P" || "$next" == "$ROOT_P"/* ]]; then
      if [[ -L "$next" ]]; then
        ((hops++ < 40)) || return 1
        target="$(readlink -- "$next")" || return 1
        LINK_TRAIL+=("$next")
        LINK_TRAIL_RAW+=("$target")
        if [[ "$target" == /* ]]; then
          [[ "$target" == "$ROOT_L" || "$target" == "$ROOT_L"/* ]] && target="$ROOT_P${target#"$ROOT_L"}"
          cur=''
        fi
        rest="${target#/}${rest:+/$rest}"
        continue
      fi
      [[ -z "$rest" || -d "$next" ]] || return 1
      cur="$next"
    elif [[ "$ROOT_P" == "$next"/* ]]; then
      cur="$next"
    else
      return 2
    fi
  done
  [[ "$cur" == "$ROOT_P" || "$cur" == "$ROOT_P"/* ]] || return 2
  [[ -e "$cur" ]] || return 1
  RESOLVED="$cur"
}

# phys_entry <relative path>: sets PHYS_ENTRY to the physical path of the entry itself; its directory
# is resolved, its last component is not followed. Returns the code of resolve_link for the directory.
phys_entry() {
  local rel="$1" dir=''
  PHYS_ENTRY=''
  [[ "$rel" == */* ]] && dir="/${rel%/*}"
  resolve_link "$ROOT_P$dir" || return
  PHYS_ENTRY="$RESOLVED/${rel##*/}"
}

# A physical path relative to the project root, "." for the root itself.
proj_rel() {
  if [[ "$1" == "$ROOT_P" ]]; then printf '.'; else printf '%s' "${1#"$ROOT_P"/}"; fi
}

# The evidence of the last symlink resolve_link followed: its raw target and where the path ends.
# trail_ev <resolve_link code>; call it only when LINK_TRAIL is not empty.
trail_ev() {
  local n=${#LINK_TRAIL_RAW[@]} shown=outside-project
  [[ "$1" == 0 ]] && shown="$(proj_rel "$RESOLVED")"
  printf 'target=%s resolves=%s' "$(esc "${LINK_TRAIL_RAW[n - 1]}")" "$(esc "$shown")"
}

# --- name checks -------------------------------------------------------------------------------------

# The shared classifier reads one path per line, so a newline inside a name is replaced for it only.
classify_shared() {
  local i
  SHARED=()
  for ((i = 0; i < ${#PATHS[@]}; i++)); do SHARED[i]=''; done
  ((${#PATHS[@]})) || return 0
  local line rule out
  out="$(for ((i = 0; i < ${#PATHS[@]}; i++)); do printf '%s\n' "${PATHS[i]//$'\n'/?}"; done | bash "$CLASSIFIER" classify)" ||
    not_verified 'the shared sensitive-name table - the classifier failed, so only the patterns of this script applied; run the scan again'
  while IFS=$'\t' read -r line rule; do
    [[ "$line" =~ ^[0-9]+$ ]] && SHARED[line - 1]="$rule"
  done <<<"$out"
}

# name_check <relative path> <kind f|d|l> <shared rule> <in public disk 0|1> sets NAME_CHECK to the
# check id, or to nothing. It sets a variable instead of printing, so no subshell runs per path.
name_check() {
  local rel="$1" kind="$2" shared="$3" disk="$4" base="${1##*/}" bits
  shopt -s nocasematch
  local result=''
  if [[ "$base" == .git ]]; then
    result=env-config-copy
  elif [[ "$kind" == d ]]; then
    result=''
  else
    case "$shared" in
    env-file | private-key-file | certificate-file | credentials-file) result=env-config-copy ;;
    database-dump | backup-archive) result=dump-archive-backup ;;
    log-file) result=log-debug ;;
    esac
    if [[ -z "$result" ]]; then
      case "$base" in
      *.env | *.conf | *.cnf) result=env-config-copy ;;
      *.sql | *.db | *.zip | *.tar | *.tar.* | *.tgz | *.7z | *.rar | *.old | *.orig | *.save | *.swp | *~)
        result=dump-archive-backup ;;
      *.log.[0-9]* | *.log.gz | error_log | php_errors* | *.trace | *.prof | cachegrind.out.* | phpinfo.php | info.php | debug.php)
        result=log-debug ;;
      esac
    fi
    if [[ -z "$result" && "$disk" == 1 ]]; then
      case "$base" in
      *.php | *.php[0-9] | *.phtml | *.pht | *.phar | *.phps | *.shtml | *.sh | *.cgi | *.pl | *.py | .htaccess | .user.ini)
        result=public-storage-script ;;
      *)
        if [[ "$kind" == f ]]; then
          bits="$(mode_bits "$ROOT/$rel")"
          [[ "$bits" =~ ^[0-7]{3,4}$ ]] && ((8#${bits: -3} & 0111)) && result=public-storage-script
        fi
        ;;
      esac
    fi
  fi
  shopt -u nocasematch
  NAME_CHECK="$result"
}

severity_of() {
  case "$1" in
  env-config-copy | dump-archive-backup) printf critical ;;
  gitignore-deploy) printf medium ;;
  *) printf high ;;
  esac
}

# A find run that printed an error read only part of the tree: a not-verified line, never a clean one.
find_errors() {
  local n
  [[ -s "$SCRATCH/find.err" ]] || return 0
  n="$(awk 'END { print NR }' "$SCRATCH/find.err")"
  not_verified "$1/ - find could not read $n entries of the $2; give the scan read access to every entry and run the scan again"
}

# Decide each scan root with resolve_link. A root with no symlink on its way is scanned. A root whose
# symlink resolves to a directory of the project is scanned at that physical directory; it is a
# finding unless the directory lies in public/ or storage/app/public/. A root whose symlink leaves the
# project, or resolves to the project root or to a file, is a finding and is never read. SCAN_ROOTS
# and SCAN_PHYS hold the roots to scan; ROOT_FIND and ROOT_FEV hold the root findings.
root_checks() {
  local dir rc
  SCAN_ROOTS=()
  SCAN_PHYS=()
  ROOT_FIND=()
  ROOT_FEV=()
  for dir in public storage/app/public; do
    resolve_link "$ROOT_P/$dir"
    rc=$?
    if ((${#LINK_TRAIL[@]} == 0)); then
      if ((rc == 0)) && [[ -d "$RESOLVED" ]]; then
        SCAN_ROOTS+=("$dir")
        SCAN_PHYS+=("$RESOLVED")
      fi
      continue
    fi
    if ((rc == 1)); then
      not_verified "$dir/ - the symlink does not resolve to a directory; restore its target and run the scan again"
      continue
    fi
    if ((rc == 0)) && [[ -d "$RESOLVED" && "$RESOLVED" != "$ROOT_P" ]]; then
      SCAN_ROOTS+=("$dir")
      SCAN_PHYS+=("$RESOLVED")
      [[ "$RESOLVED" == "$ROOT_P/public" || "$RESOLVED" == "$ROOT_P"/public/* ||
        "$RESOLVED" == "$ROOT_P/storage/app/public" || "$RESOLVED" == "$ROOT_P"/storage/app/public/* ]] && continue
    fi
    ROOT_FIND+=("$dir")
    ROOT_FEV+=("$(trail_ev "$rc")")
  done
}

# A physical path at or below a scanned root.
in_scan_roots() {
  local phys
  for phys in ${SCAN_PHYS[@]+"${SCAN_PHYS[@]}"}; do
    [[ "$1" == "$phys" || "$1" == "$phys"/* ]] && return 0
  done
  return 1
}

# add_path <relative path> <physical path>: one entry of PATHS and KINDS.
add_path() {
  local kind=f
  if [[ -L "$2" ]]; then kind=l; elif [[ -d "$2" ]]; then kind=d; fi
  PATHS+=("$1")
  KINDS+=("$kind")
}

# Collect every entry below the scanned roots into PATHS, named below the root path (public/…), and
# KINDS. find walks the physical directory of each root and does not follow a symlink below it.
collect() {
  local i dir phys p
  PATHS=()
  KINDS=()
  for ((i = 0; i < ${#SCAN_ROOTS[@]}; i++)); do
    dir="${SCAN_ROOTS[i]}"
    phys="${SCAN_PHYS[i]}"
    while IFS= read -r -d '' p; do
      add_path "$dir${p#"$phys"}" "$p"
    done < <(find "$phys" -mindepth 1 \( -name .git -print0 -prune \) -o -print0 2>"$SCRATCH/find.err")
    find_errors "$dir" 'name checks'
  done
  [[ "$SCOPE" == diff ]] && collect_changed_below_links
  return 0
}

# Diff scope: a changed path whose physical entry lies below the target of a directory symlink under
# a scan root is served through that symlink, so it joins PATHS under the served name. The target is
# inside the project, because resolve_link returned 0, and outside the scanned roots, which find
# already walked.
collect_changed_below_links() {
  local i j n="${#PATHS[@]}" target chg
  for ((i = 0; i < n; i++)); do
    [[ "${KINDS[i]}" == l ]] || continue
    resolve_link "$ROOT_P/${PATHS[i]}" || continue
    target="$RESOLVED"
    [[ -d "$target" ]] || continue
    in_scan_roots "$target" && continue
    for ((j = 0; j < ${#CHANGED_P[@]}; j++)); do
      chg="${CHANGED_P[j]}"
      [[ "$chg" == "$target"/* ]] || continue
      [[ -L "$chg" || -e "$chg" ]] || continue
      add_path "${PATHS[i]}/${chg#"$target"/}" "$chg"
    done
  done
}

in_disk() {
  [[ "$1" == storage/app/public/* ]] && return 0
  [[ "$1" == public/storage/* && ! -L "$ROOT/public/storage" ]]
}

run_name_checks() {
  local i disk
  CLASS=()
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    disk=0
    in_disk "${PATHS[i]}" && disk=1
    name_check "${PATHS[i]}" "${KINDS[i]}" "${SHARED[i]}" "$disk"
    CLASS[i]="$NAME_CHECK"
  done
}

# --- static mode -------------------------------------------------------------------------------------

# The symlinks below the scan roots whose target leaves the scanned roots, leaves the project, or does
# not resolve: LINK_IDX holds the index into PATHS, LINK_EV the evidence. The static mode reports them
# and the runtime mode probes them.
link_checks() {
  local i rc shown
  LINK_IDX=()
  LINK_EV=()
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    [[ "${KINDS[i]}" == l ]] || continue
    resolve_link "$ROOT_P/${PATHS[i]}"
    rc=$?
    if ((rc == 0)); then
      in_scan_roots "$RESOLVED" && continue
      shown="$(esc "$(proj_rel "$RESOLVED")")"
    elif ((rc == 2)); then
      shown=outside-project
    else
      shown=unresolved
    fi
    LINK_IDX+=("$i")
    LINK_EV+=("target=$(esc "$(readlink -- "$ROOT_P/${PATHS[i]}")") resolves=$shown")
  done
}

static_checks() {
  local i n rel phys abs ww probe
  for ((i = 0; i < ${#ROOT_FIND[@]}; i++)); do
    emit symlink-permission high "${ROOT_FIND[i]}" "${ROOT_FEV[i]}"
  done
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    [[ -n "${CLASS[i]}" ]] || continue
    emit "${CLASS[i]}" "$(severity_of "${CLASS[i]}")" "${PATHS[i]}" "$(meta "${PATHS[i]}")"
  done

  link_checks
  n=${#LINK_IDX[@]}
  for ((i = 0; i < n && i < FINDING_CAP; i++)); do
    emit symlink-permission high "${PATHS[LINK_IDX[i]]}" "${LINK_EV[i]}"
  done

  local writable=0
  for ((i = 0; i < ${#SCAN_ROOTS[@]}; i++)); do
    rel="${SCAN_ROOTS[i]}"
    phys="${SCAN_PHYS[i]}"
    while IFS= read -r -d '' abs; do
      writable=$((writable + 1))
      ((writable > FINDING_CAP)) && continue
      ww="$rel${abs#"$phys"}"
      emit symlink-permission high "$ww" "world-writable $(meta "$ww")"
    done < <(find "$phys" \( -name .git -prune \) -o \( -perm -0002 ! -type l -print0 \) 2>"$SCRATCH/find.err")
    find_errors "$rel" 'world-writable check'
  done
  if ((n > FINDING_CAP || writable > FINDING_CAP)); then
    not_verified "symlink-permission - more than $FINDING_CAP findings of one kind; the first $FINDING_CAP are printed; fix them and run the scan again"
  fi

  if ((HAS_GIT)); then
    while IFS= read -r probe; do
      git_ro check-ignore -q --no-index -- "$probe" >/dev/null 2>&1 && continue
      emit gitignore-deploy medium .gitignore "probe=$probe ignored=no"
    done <<<"$GITIGNORE_PROBES"
  else
    not_verified 'gitignore coverage and git status - the root is not a git work tree; run the scan in a checkout of the project'
  fi

  deploy_checks
}

# The deployment files that exist: DEPLOY_REL holds the name below the root, DEPLOY_ABS the physical
# path. Each deployment directory is resolved with resolve_link before it is listed. A directory whose
# symlink leaves the project is a symlink-permission finding and is never listed or read; one that
# does not resolve is a not-verified line. A symlinked deployment file is not followed.
deploy_files() {
  local pattern dir base file phys rc seen=$'\n'
  DEPLOY_REL=()
  DEPLOY_ABS=()
  while IFS= read -r pattern; do
    dir=''
    base="$pattern"
    phys="$ROOT_P"
    if [[ "$pattern" == */* ]]; then
      dir="${pattern%/*}"
      base="${pattern##*/}"
      resolve_link "$ROOT_P/$dir"
      rc=$?
      if ((rc != 0)); then
        if ((${#LINK_TRAIL[@]})) && [[ "$seen" != *$'\n'"$dir"$'\n'* ]]; then
          seen+="$dir"$'\n'
          if ((rc == 2)); then
            emit symlink-permission high "$dir" "$(trail_ev "$rc")"
          else
            not_verified "$dir/ - the symlink does not resolve to a directory; restore its target and run the scan again"
          fi
        fi
        continue
      fi
      phys="$RESOLVED"
    fi
    shopt -s nullglob
    # shellcheck disable=SC2231  # the unquoted $base is the glob of the pattern
    for file in "$phys"/$base; do
      [[ ! -L "$file" && -f "$file" ]] || continue
      DEPLOY_REL+=("${dir:+$dir/}${file##*/}")
      DEPLOY_ABS+=("$file")
    done
    shopt -u nullglob
  done <<<"$DEPLOY_FILES"
}

deploy_checks() {
  local file line token check i k
  local -a files=() lines=() tokens=()
  deploy_files
  for ((k = 0; k < ${#DEPLOY_REL[@]}; k++)); do
    file="${DEPLOY_REL[k]}"
    if [[ ! -r "${DEPLOY_ABS[k]}" ]]; then
      not_verified "$(esc "$file") - the deployment file is not readable; give the scan read access and run the scan again"
      continue
    fi
    while IFS=: read -r line token; do
      [[ "$line" =~ ^[0-9]+$ ]] || continue
      files+=("$file")
      lines+=("$line")
      tokens+=("$token")
    done < <(grep -I -n -o -E 'public/[A-Za-z0-9._~*/-]+' -- "${DEPLOY_ABS[k]}" 2>/dev/null)
  done
  ((${#tokens[@]})) || return 0
  local -a saved_paths=(${PATHS[@]+"${PATHS[@]}"})
  PATHS=("${tokens[@]}")
  classify_shared
  for ((i = 0; i < ${#tokens[@]}; i++)); do
    name_check "${tokens[i]%/}" f "${SHARED[i]}" 0
    check="$NAME_CHECK"
    [[ -n "$check" ]] || continue
    emit gitignore-deploy medium "${files[i]}" "line=${lines[i]} ref=$(esc "${tokens[i]}") class=$check"
  done
  PATHS=(${saved_paths[@]+"${saved_paths[@]}"})
}

# --- runtime mode ------------------------------------------------------------------------------------

# A base URL whose host is shown to be local or a reserved test name, or nothing.
check_target() {
  local url="$1" host
  [[ "$url" =~ ^(http|https)://([A-Za-z0-9.-]+|\[::1\])(:[0-9]{1,5})?/?$ ]] || return 1
  host="$(printf '%s' "${BASH_REMATCH[2]}" | tr '[:upper:]' '[:lower:]')"
  case "$host" in
  localhost | '[::1]') return 0 ;;
  esac
  if [[ "$host" =~ ^127\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]]; then
    ((BASH_REMATCH[1] < 256 && BASH_REMATCH[2] < 256 && BASH_REMATCH[3] < 256))
    return
  fi
  [[ "$host" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+(localhost|test)$ ]]
}

urlenc() {
  local s="$1" out='' c v i
  for ((i = 0; i < ${#s}; i++)); do
    c="${s:i:1}"
    case "$c" in
    [A-Za-z0-9._~/-]) out+="$c" ;;
    *)
      printf -v v '%d' "'$c"
      ((v < 0)) && v=$((v + 256))
      out+="$(printf '%%%02X' "$v")"
      ;;
    esac
  done
  printf '%s' "$out"
}

runtime_checks() {
  local base="${1%/}" host port link disk i rel path url hdr code ctype clen reach=0 sent=0
  host="${base#*://}"
  host="${host%%:*}"
  [[ "$host" == '['* ]] && host='[::1]'
  host="$(printf '%s' "$host" | tr '[:upper:]' '[:lower:]')"
  # A name is pinned to the loopback address, so the system resolver never decides the target.
  local -a pin=()
  if [[ "$host" != '[::1]' && ! "$host" =~ ^127\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then
    if [[ "$base" =~ :([0-9]{1,5})$ ]]; then port="$((10#${BASH_REMATCH[1]}))"; elif [[ "$base" == https:* ]]; then port=443; else port=80; fi
    pin=(--resolve "$host:$port:127.0.0.1")
  fi
  # A scan root that root_checks did not scan has no candidates: the runtime result is incomplete.
  local pub=''
  disk=''
  for ((i = 0; i < ${#SCAN_ROOTS[@]}; i++)); do
    [[ "${SCAN_ROOTS[i]}" == public ]] && pub="${SCAN_PHYS[i]}"
    [[ "${SCAN_ROOTS[i]}" == storage/app/public ]] && disk="${SCAN_PHYS[i]}"
  done
  for ((i = 0; i < ${#ROOT_FIND[@]}; i++)); do
    [[ "${ROOT_FIND[i]}" == public && -n "$pub" ]] && continue
    [[ "${ROOT_FIND[i]}" == storage/app/public && -n "$disk" ]] && continue
    not_verified "${ROOT_FIND[i]}/ - the scan root is a symlink (${ROOT_FEV[i]}) that the scan does not read; run the static mode for the finding"
  done
  link=0
  if [[ -n "$pub" && -n "$disk" && -L "$pub/storage" ]] && resolve_link "$pub/storage" && [[ "$RESOLVED" == "$disk" ]]; then link=1; fi

  # The candidates: every name-check finding, then every symlink the static symlink check flags.
  local -a cand=() urls=(/.env)
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    [[ -n "${CLASS[i]}" ]] && cand+=("$i")
  done
  link_checks
  for i in ${LINK_IDX[@]+"${LINK_IDX[@]}"}; do
    [[ -n "${CLASS[i]}" ]] || cand+=("$i")
  done
  for i in ${cand[@]+"${cand[@]}"}; do
    rel="${PATHS[i]}"
    case "$rel" in
    public/*) path="/${rel#public/}" ;;
    storage/app/public/*) ((link)) || continue; path="/storage/${rel#storage/app/public/}" ;;
    *) continue ;;
    esac
    [[ "${rel##*/}" == .git && "${KINDS[i]}" == d ]] && path="$path/HEAD"
    [[ "$path" == /.env ]] || urls+=("$path")
  done

  hdr="$SCRATCH/headers"
  for path in "${urls[@]}"; do
    if ((sent >= PROBE_CAP)); then
      not_verified "http-reachable - more than $PROBE_CAP paths; the first $PROBE_CAP were checked; fix the static findings and run the scan again"
      break
    fi
    sent=$((sent + 1))
    url="$base$(urlenc "$path")"
    : >"$hdr"
    code="$(curl -q --silent --globoff --head --max-redirs 0 --proto '=http,https' --noproxy '*' ${pin[@]+"${pin[@]}"} \
      --connect-timeout 5 --max-time 10 --user-agent public-files-scan -o /dev/null -D "$hdr" \
      -w '%{http_code}' -- "$url" 2>/dev/null)" || code="${code:-000}"
    [[ "$code" =~ ^[0-9]{3}$ ]] || code=000
    ctype="$(tr -d '\r' <"$hdr" | awk 'tolower($1) == "content-type:" { v = $2 } END { print v }' | tr -cd 'A-Za-z0-9!#$&^_.+/-')"
    clen="$(tr -d '\r' <"$hdr" | awk 'tolower($1) == "content-length:" { v = $2 } END { print v }' | tr -cd '0-9')"
    case "$code" in
    2??)
      emit http-reachable high "$path" "status=$code type=${ctype:--} length=${clen:--}"
      reach=$((reach + 1))
      ;;
    401 | 403 | 404 | 410) printf 'probe\t%s\tstatus=%s\n' "$(esc "$path")" "$code" ;;
    3??) not_verified "$(esc "$path") - status $code is a redirect and the scan follows none; check the redirect target on the same host" ;;
    *) not_verified "$(esc "$path") - status $code; the request failed or the answer is unknown; start the local instance and run the scan again" ;;
    esac
  done
  printf 'outbound-request: %s HEAD %s paths\n' "$host" "$sent"
}

# --- main --------------------------------------------------------------------------------------------

finish() {
  local status=clean code=0
  if ((FINDINGS)); then status=findings; code=5; fi
  if ((INCOMPLETE)); then status=incomplete; code=3; fi
  printf 'summary: findings=%s status=%s\n' "$FINDINGS" "$status"
  exit "$code"
}

main() {
  [[ $# -ge 1 ]] || usage
  local mode="$1" root='' base='' url=''
  shift
  case "$mode" in
  --self-test) self_test "$@" ;;
  static | runtime) ;;
  *) usage ;;
  esac
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --root) [[ $# -ge 2 ]] || usage; root="$2"; shift 2 ;;
    --changed-from) [[ "$mode" == static && $# -ge 2 ]] || usage; base="$2"; shift 2 ;;
    --base-url) [[ "$mode" == runtime && $# -ge 2 ]] || usage; url="$2"; shift 2 ;;
    *) usage ;;
    esac
  done
  [[ -n "$root" ]] || usage
  [[ "$mode" == static || -n "$url" ]] || usage
  [[ -z "$base" || "$base" =~ ^[0-9a-f]{7,64}$ ]] || die 1 '--changed-from takes a commit object id'
  need_tools find stat awk grep sed tr
  [[ -f "$CLASSIFIER" ]] || die 2 "missing the shared classifier: $CLASSIFIER"
  if [[ "$mode" == runtime ]]; then
    check_target "$url" || die 4 'refused: the base URL is not shown to be a local or test environment (localhost, *.localhost, *.test, 127.0.0.0/8, [::1]; no user information, path, query, or fragment)'
    need_tools curl
  fi
  ROOT_P="$(cd -P -- "$root" 2>/dev/null && pwd -P)" || die 1 "the root is not a directory: $root"
  ROOT_L="$(cd -- "$root" 2>/dev/null && pwd -L)" || ROOT_L="$ROOT_P"
  ROOT="$ROOT_P"
  SCRATCH="$(mktemp -d)" || die 2 'cannot create a temporary directory'
  trap 'rm -rf -- "$SCRATCH"' EXIT
  HAS_GIT=0
  if command -v git >/dev/null 2>&1 && [[ "$(git_ro rev-parse --is-inside-work-tree 2>/dev/null)" == true ]]; then HAS_GIT=1; fi
  pick_stat

  if [[ -n "$base" ]]; then
    ((HAS_GIT)) || die 1 '--changed-from needs a git work tree'
    local changed
    git_ro diff --name-only -z --no-renames "$base" HEAD -- >"$SCRATCH/changed" 2>/dev/null || die 3 'the base commit is not in this checkout'
    # The physical entry of each changed path. A path whose directory is gone (a deletion) keeps its
    # name below ROOT_P; a path whose directory passes a symlink that leaves the project or does not
    # resolve is empty, so it matches nothing and the scope never reaches outside the project.
    local rc
    while IFS= read -r -d '' changed; do
      CHANGED+=("$changed")
      phys_entry "$changed"
      rc=$?
      if ((rc == 0)); then
        CHANGED_P+=("$PHYS_ENTRY")
      elif ((rc == 1 && ${#LINK_TRAIL[@]} == 0)); then
        CHANGED_P+=("$ROOT_P/$changed")
      else
        CHANGED_P+=('')
      fi
    done <"$SCRATCH/changed"
    SCOPE='diff'
  fi

  printf 'public-files-scan: mode=%s scope=%s\n' "$mode" "$SCOPE"
  if [[ ! -L "$ROOT/public" && ! -d "$ROOT/public" ]]; then
    not_verified 'public/ - the root has no public/ directory; run the scan on the root of a Laravel project'
    finish
  fi
  root_checks
  collect
  classify_shared
  run_name_checks
  if [[ "$mode" == static ]]; then static_checks; else runtime_checks "$url"; fi
  finish
}

# ---------------------------------------------------------------------------------------------------
# Self-test. Every fixture project is built at run time in its own temporary directory.
# ---------------------------------------------------------------------------------------------------

ST_PASS=0
ST_FAIL=0

st_ok() {
  if [[ "$1" == 0 ]]; then
    printf 'ok    %s\n' "$2"
    ST_PASS=$((ST_PASS + 1))
  else
    printf 'not ok %s\n' "$2"
    ST_FAIL=$((ST_FAIL + 1))
  fi
}

st_git() {
  git -c user.name='Dev Person' -c user.email='dev@example.test' -c init.defaultBranch=master \
    -c commit.gpgsign=false -c core.autocrlf=false "$@"
}

st_run() {
  local name="$1"
  shift
  (PATH="$ST_TMP/bin:$PATH" "$BASH" "$SCRIPT_PATH" "$@" >"$ST_TMP/out.$name" 2>"$ST_TMP/err.$name")
  ST_RC=$?
  ST_OUT="$(<"$ST_TMP/out.$name")"
}

# st_line <needle>...: one output line carries every needle.
# shellcheck disable=SC2329  # invoked through st_check and st_check_not
st_line() {
  local line needle hit
  local IFS=$'\n'
  for line in $ST_OUT; do
    hit=1
    for needle in "$@"; do [[ "$line" == *"$needle"* ]] || hit=0; done
    ((hit)) && return 0
  done
  return 1
}

st_check() {
  local label="$1"
  shift
  if "$@"; then st_ok 0 "$label"; else st_ok 1 "$label"; fi
}

st_check_not() {
  local label="$1"
  shift
  if "$@"; then st_ok 1 "$label"; else st_ok 0 "$label"; fi
}

# The fixture: a Laravel layout with one file per check, clean files beside them, and a secret marker.
st_fixture() {
  local p="$1"
  mkdir -p "$p/public/build" "$p/public/uploads" "$p/storage/app/public" "$p/storage/logs" "$p/.github/workflows"
  printf '<?php\n' >"$p/public/index.php"
  printf 'x\n' >"$p/public/build/app.js"
  printf 'APP_KEY=\n' >"$p/public/.env.example"
  printf 'png\n' >"$p/storage/app/public/avatar.png"
  printf 'SECRET=%s\n' "$ST_MARKER" >"$p/.env"
  printf 'SECRET=%s\n' "$ST_MARKER" >"$p/public/.env"
  printf '%s\n' "$ST_MARKER" >"$p/public/backup.sql"
  printf '%s\n' "$ST_MARKER" >"$p/public/site.TAR.gz"
  printf '%s\n' "$ST_MARKER" >"$p/public/laravel.log"
  printf '<?php\n' >"$p/public/phpinfo.php"
  printf '<?php\n' >"$p/storage/app/public/shell.php"
  printf 'x\n' >"$p/storage/app/public/.htaccess"
  printf 'x\n' >"$p/public/\$(touch marker).sql"
  printf 'x\n' >"$p/public/tab"$'\t'"name.zip"
  chmod 000 "$p/.env" "$p/public/.env" "$p/public/backup.sql" "$p/public/site.TAR.gz" "$p/public/laravel.log"
  chmod 777 "$p/public/uploads"
  ln -s ../storage/app/public "$p/public/storage"
  ln -s ../.env "$p/public/leak"
  ln -s ../../shared/storage/backup.sql "$p/public/latest"
  ln -s loop "$p/public/loop"
  printf '/public/storage\n.env.backup\n.env.production\n/storage/*.key\nauth.json\n' >"$p/.gitignore"
  printf '*\n!.gitignore\n' >"$p/storage/logs/.gitignore"
  printf 'cp public/build/app.js /tmp/x\nmysqldump app > public/dump.sql\n' >"$p/deploy.sh"
  printf 'steps:\n  - run: php artisan optimize\n' >"$p/.github/workflows/deploy.yml"
}

st_static_cases() {
  local p="$ST_TMP/project" before after
  st_fixture "$p"
  (cd "$p" && git init -q && git add .gitignore storage/logs/.gitignore deploy.sh public/index.php &&
    st_git commit -q -m base) >/dev/null 2>&1
  before="$(cd "$p" && ls -laR public storage .env .gitignore deploy.sh .github 2>/dev/null)"
  st_run static static --root "$p"
  after="$(cd "$p" && ls -laR public storage .env .gitignore deploy.sh .github 2>/dev/null)"

  st_check 'static: findings exit 5' test "$ST_RC" = 5
  st_check 'static: a dump in public/ is a critical dump-archive-backup finding' st_line $'finding\tdump-archive-backup\tcritical\tpublic/backup.sql\tsize='
  st_check 'static: an archive name matches in any letter case' st_line $'dump-archive-backup\tcritical\tpublic/site.TAR.gz'
  st_check 'static: a .env copy in public/ is a critical env-config-copy finding' st_line $'finding\tenv-config-copy\tcritical\tpublic/.env\t'
  st_check 'static: a log in public/ is a high log-debug finding' st_line $'finding\tlog-debug\thigh\tpublic/laravel.log\t'
  st_check 'static: a phpinfo script is a log-debug finding' st_line $'log-debug\thigh\tpublic/phpinfo.php'
  st_check 'static: a PHP file in the public disk is a public-storage-script finding' st_line $'finding\tpublic-storage-script\thigh\tstorage/app/public/shell.php'
  st_check 'static: an .htaccess in the public disk is a public-storage-script finding' st_line $'public-storage-script\thigh\tstorage/app/public/.htaccess'
  st_check 'static: a symlink that leaves the public paths is a symlink-permission finding' st_line $'finding\tsymlink-permission\thigh\tpublic/leak\ttarget=../.env resolves=.env'
  st_check 'static: a symlink that leaves the project is outside-project, never checked for existence' \
    st_line $'finding\tsymlink-permission\thigh\tpublic/latest\ttarget=../../shared/storage/backup.sql resolves=outside-project'
  st_check 'static: a symlink loop is a symlink-permission finding' st_line $'symlink-permission\thigh\tpublic/loop\ttarget=loop resolves=unresolved'
  st_check 'static: a world-writable directory is a symlink-permission finding' st_line $'symlink-permission\thigh\tpublic/uploads\tworld-writable'
  st_check 'static: a probe the .gitignore does not ignore is a medium gitignore-deploy finding' st_line $'finding\tgitignore-deploy\tmedium\t.gitignore\tprobe=.env ignored=no'
  st_check_not 'static: a probe the .gitignore ignores is no finding' st_line 'probe=.env.backup'
  st_check_not 'static: a probe a nested .gitignore ignores is no finding' st_line 'probe=storage/logs/laravel.log'
  st_check 'static: a deployment step that writes a dump to public/ is a gitignore-deploy finding' st_line $'gitignore-deploy\tmedium\tdeploy.sh\tline=2 ref=public/dump.sql class=dump-archive-backup'
  st_check_not 'static: a deployment line with a plain asset is no finding' st_line 'ref=public/build/app.js'
  st_check 'static: the evidence carries the git status' st_line 'public/backup.sql' 'git=untracked'
  st_check_not 'static: clean files are no finding' st_line 'public/index.php'
  st_check_not 'static: .env.example is no finding' st_line '.env.example'
  st_check_not 'static: the public disk link is no finding' st_line $'\tpublic/storage\t'
  st_check_not 'static: a clean public disk file is no finding' st_line 'avatar.png'
  st_check 'static: a non-printable byte in a name prints as \x09' st_line 'public/tab\x09name.zip'
  st_check_not 'static: the content of a reported file is never printed' st_line "$ST_MARKER"
  st_check_not 'static: a hostile file name runs nothing' test -e "$p/marker"
  st_check 'static: the scan changes no file, mode, or time' test "$before" = "$after"
  st_check 'static: the summary counts the findings' st_line 'summary: findings=' 'status=findings'

  local base
  base="$(cd "$p" && git rev-parse HEAD)"
  printf 'x\n' >"$p/public/new-dump.sql"
  local quoted=$'z\xc3\xa1.sql'
  printf 'x\n' >"$p/public/$quoted"
  printf 'x\n' >"$p/public/q\"x\\y.zip"
  printf 'x\n' >"$p/public/t"$'\t'"b.sql"
  printf 'cp db.sql public/fresh.sql\n' >>"$p/deploy.sh"
  (cd "$p" && git add -- public/new-dump.sql "public/$quoted" 'public/q"x\y.zip' "public/t"$'\t'"b.sql" deploy.sh &&
    st_git commit -q -m change) >/dev/null 2>&1
  st_run diff static --root "$p" --changed-from "$base"
  st_check 'diff: a dump the diff adds is reported' st_line $'dump-archive-backup\tcritical\tpublic/new-dump.sql\t' 'git=tracked'
  st_check 'diff: a non-ASCII name that git quotes is reported' st_line $'dump-archive-backup\tcritical\tpublic/z\\xc3\\xa1.sql\t'
  st_check 'diff: a name with a quote and a backslash is reported' st_line $'dump-archive-backup\tcritical\tpublic/q"x\\x5cy.zip\t'
  st_check 'diff: a name with a tab is reported' st_line $'dump-archive-backup\tcritical\tpublic/t\\x09b.sql\t'
  st_check 'diff: a changed deployment file is reported' st_line $'deploy.sh\tline=3 ref=public/fresh.sql'
  st_check_not 'diff: a dump the diff does not touch is not reported' st_line 'public/backup.sql'
  st_check_not 'diff: a .gitignore finding is not reported when no .gitignore changed' st_line 'probe=.env'
  st_check 'diff: the header names the diff scope' st_line 'mode=static scope=diff'
  st_run nodiff static --root "$p" --changed-from "$(cd "$p" && git rev-parse HEAD)"
  st_check 'diff: an empty diff completes clean, exit 0' test "$ST_RC" = 0

  # An empty public/ and a deployment file that writes a dump there: the scan completes under bash 3.2.
  mkdir -p "$ST_TMP/empty/public"
  printf 'mysqldump app > public/dump.sql\n' >"$ST_TMP/empty/deploy.sh"
  (cd "$ST_TMP/empty" && git init -q) >/dev/null 2>&1
  st_run empty static --root "$ST_TMP/empty"
  st_check 'static: an empty public/ completes with the deployment finding, exit 5' test "$ST_RC" = 5
  st_check 'static: an empty public/ still reports the deployment step' st_line $'gitignore-deploy\tmedium\tdeploy.sh\tline=1 ref=public/dump.sql'
  st_check 'static: an empty public/ prints the summary line' st_line 'summary: findings=' 'status=findings'
  st_curl_stub
  st_run empty-runtime runtime --root "$ST_TMP/empty" --base-url http://localhost
  st_check 'runtime: an empty public/ probes /.env only and completes clean, exit 0' test "$ST_RC" = 0 -a "$(grep -c '^argv' "$ST_TMP/curl.log")" = 1
  : >"$ST_TMP/curl.log"

  # public/ is a symlink to the real web root; the public disk is a symlink that does not resolve.
  local l="$ST_TMP/linked"
  mkdir -p "$l/web/private" "$l/storage/app"
  printf '%s\n' "$ST_MARKER" >"$l/web/backup.sql"
  printf 'SECRET=%s\n' "$ST_MARKER" >"$l/web/.env"
  ln -s web "$l/public"
  ln -s ../../missing "$l/storage/app/public"
  printf 'cp x public/a.sql\n' >"$l/deploy.sh"
  (cd "$l" && git init -q) >/dev/null 2>&1
  chmod 000 "$l/web/private" "$l/deploy.sh"
  st_run linked static --root "$l"
  st_check 'static: a dump below a symlinked public/ is reported' st_line $'finding\tdump-archive-backup\tcritical\tpublic/backup.sql\t'
  st_check 'static: an .env copy below a symlinked public/ is reported' st_line $'finding\tenv-config-copy\tcritical\tpublic/.env\t'
  st_check 'static: a scan root link that does not resolve is a not-verified line' st_line 'not-verified: storage/app/public/ - the symlink does not resolve'
  st_check 'static: a scan with a not-verified line exits 3' test "$ST_RC" = 3
  if [[ "$(id -u)" != 0 ]]; then
    st_check 'static: an entry find cannot read is a not-verified line' st_line 'not-verified: public/ - find could not read'
    st_check 'static: a deployment file the scan cannot read is a not-verified line' st_line 'not-verified: deploy.sh - the deployment file is not readable'
  fi
  st_check_not 'static: the content below a symlinked public/ is never printed' st_line "$ST_MARKER"
  chmod 700 "$l/web/private" "$l/deploy.sh"

  # storage/app/public is a symlink to a shared directory that holds a script.
  local d="$ST_TMP/linkeddisk"
  mkdir -p "$d/public" "$d/shared/disk" "$d/storage/app"
  printf '<?php\n' >"$d/shared/disk/shell.php"
  ln -s ../../shared/disk "$d/storage/app/public"
  (cd "$d" && git init -q) >/dev/null 2>&1
  st_run linkeddisk static --root "$d"
  st_check 'static: a script below a symlinked public disk is reported' st_line $'finding\tpublic-storage-script\thigh\tstorage/app/public/shell.php\t'

  mkdir -p "$ST_TMP/nogit/public"
  st_run nogit static --root "$ST_TMP/nogit"
  st_check 'static: a root that is not a git work tree is an incomplete scan, exit 3' test "$ST_RC" = 3
  st_check 'static: the missing git check is a not-verified line' st_line 'not-verified: gitignore coverage'
  mkdir -p "$ST_TMP/nopublic"
  st_run nopublic static --root "$ST_TMP/nopublic"
  st_check 'static: a root without public/ exits 3' test "$ST_RC" = 3
  st_run usage static
  st_check 'usage: a missing --root exits 1' test "$ST_RC" = 1
  st_run badsha static --root "$p" --changed-from 'HEAD;x'
  st_check 'usage: a --changed-from that is not an object id exits 1' test "$ST_RC" = 1
}

st_commit() {
  (cd "$1" && { [[ -d .git ]] || git init -q; } && git add -A && st_git commit -q -m "$2") >/dev/null 2>&1
}

# The scan stays inside the physical project root: a symlink of a scan root, of a directory below
# it, or of a deployment directory never makes it read, list, or stat an entry outside the project,
# and the diff scope compares physical paths with the git paths.
st_confine_cases() {
  local o="$ST_TMP/outside" r base spec raw want n=0
  mkdir -p "$o/victim" "$o/workflows" "$o/app/public"
  printf '%s\n' "$ST_MARKER" >"$o/hostonly-dump.sql"
  printf 'k\n' >"$o/victim/id_rsa"
  printf '<?php\n' >"$o/app/public/hostonly.php"
  printf 'run: cp x public/hostonly-secret.sql\n' >"$o/workflows/ci.yml"

  # public -> web in the base; the change adds a dump and an .env copy to web/.
  r="$ST_TMP/n1a"
  mkdir -p "$r/web"
  printf '<?php\n' >"$r/web/index.php"
  ln -s web "$r/public"
  st_commit "$r" base
  base="$(git -C "$r" rev-parse HEAD)"
  printf 'x\n' >"$r/web/backup.sql"
  printf 'x\n' >"$r/web/.env"
  st_commit "$r" change
  st_run n1a static --root "$r" --changed-from "$base"
  st_check 'confine: a dump added behind a symlinked public/ is in the diff scope, exit 5' test "$ST_RC" = 5
  st_check 'confine: that dump carries the git status of its git path' st_line $'dump-archive-backup\tcritical\tpublic/backup.sql\t' 'git=tracked'
  st_check 'confine: an .env copy added behind a symlinked public/ is in the diff scope' st_line $'env-config-copy\tcritical\tpublic/.env\t'
  st_check_not 'confine: the unchanged root link is not in the diff scope' st_line $'\tpublic\ttarget='
  st_run n1a-tree static --root "$r"
  st_check 'confine: the tree mode names the git status of the tracked target' st_line $'\tpublic/backup.sql\t' 'git=tracked'
  st_check 'confine: a root link to a project directory is a symlink-permission finding' \
    st_line $'symlink-permission\thigh\tpublic\ttarget=web resolves=web'

  # The change replaces public/ with public -> web and adds web/db.sql.
  r="$ST_TMP/n1b"
  mkdir -p "$r/public" "$r/web"
  printf '<?php\n' >"$r/public/index.php"
  printf '<?php\n' >"$r/web/index.php"
  st_commit "$r" base
  base="$(git -C "$r" rev-parse HEAD)"
  (cd "$r" && git rm -q -r public) >/dev/null 2>&1
  ln -s web "$r/public"
  printf 'x\n' >"$r/web/db.sql"
  st_commit "$r" change
  st_run n1b static --root "$r" --changed-from "$base"
  st_check 'confine: a dump behind a root link the diff adds is reported, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'dump-archive-backup\tcritical\tpublic/db.sql\t' "$ST_TMP/out.n1b")"
  st_check 'confine: the root link the diff adds is a symlink-permission finding' st_line $'symlink-permission\thigh\tpublic\ttarget=web resolves=web'

  # public/files -> ../web/files in the base; the change adds web/files/dump.sql.
  r="$ST_TMP/n1c"
  mkdir -p "$r/public" "$r/web/files"
  printf '<?php\n' >"$r/public/index.php"
  printf 'x\n' >"$r/web/files/keep.txt"
  ln -s ../web/files "$r/public/files"
  st_commit "$r" base
  base="$(git -C "$r" rev-parse HEAD)"
  printf 'x\n' >"$r/web/files/dump.sql"
  st_commit "$r" change
  st_run n1c static --root "$r" --changed-from "$base"
  st_check 'confine: a dump added behind a directory symlink below public/ is reported, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'dump-archive-backup\tcritical\tpublic/files/dump.sql\t' "$ST_TMP/out.n1c" | grep -F 'git=tracked')"

  # The change points public, storage, and .github at a directory outside the project.
  r="$ST_TMP/s5"
  mkdir -p "$r/public/victim" "$r/.github/workflows" "$r/storage/app/public"
  printf 'k\n' >"$r/public/victim/id_rsa"
  printf 'steps: []\n' >"$r/.github/workflows/deploy.yml"
  printf 'png\n' >"$r/storage/app/public/a.png"
  st_commit "$r" base
  base="$(git -C "$r" rev-parse HEAD)"
  (cd "$r" && git rm -q -r public .github storage) >/dev/null 2>&1
  ln -s "$o" "$r/public"
  ln -s "$o" "$r/.github"
  ln -s "$o" "$r/storage"
  st_commit "$r" change
  for spec in diff tree; do
    if [[ "$spec" == diff ]]; then st_run s5 static --root "$r" --changed-from "$base"; else st_run s5 static --root "$r"; fi
    st_check "confine $spec: a scan root outside the project is one finding on the root, exit 5" \
      test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tpublic\ttarget='"$o"' resolves=outside-project' "$ST_TMP/out.s5")"
    st_check "confine $spec: a scan root behind a parent symlink outside the project is one finding" \
      st_line $'symlink-permission\thigh\tstorage/app/public\ttarget='"$o"' resolves=outside-project'
    st_check "confine $spec: a deployment directory outside the project is one finding" \
      st_line $'symlink-permission\thigh\t.github/workflows\ttarget='"$o"' resolves=outside-project'
    st_check_not "confine $spec: no line names an entry outside the project" st_line 'hostonly'
    st_check_not "confine $spec: no line carries metadata of a file outside the project" st_line 'id_rsa'
    st_check_not "confine $spec: the scan of an outside root is never incomplete" st_line 'not-verified:'
  done
  st_curl_stub
  : >"$ST_TMP/curl.log"
  st_run s5-runtime runtime --root "$r" --base-url http://localhost
  st_check 'confine runtime: a scan root outside the project is a not-verified line, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: public/ - the scan root is a symlink' "$ST_TMP/out.s5-runtime")"
  st_check_not 'confine runtime: no request names an entry outside the project' grep -q -F hostonly "$ST_TMP/curl.log"

  # A scan root link to the project root, an ancestor, a chain that leaves, and a relative way out.
  for spec in ".|.|." "..|..|outside-project" "hop|$o|outside-project" "../outside|../outside|outside-project"; do
    n=$((n + 1))
    r="$ST_TMP/variant$n"
    raw="${spec#*|}"
    want="${raw#*|}"
    raw="${raw%%|*}"
    mkdir -p "$r"
    printf 'x\n' >"$r/backup.sql"
    ln -s "${spec%%|*}" "$r/public"
    [[ "${spec%%|*}" == hop ]] && ln -s "$o" "$r/hop"
    st_commit "$r" base
    st_run "variant$n" static --root "$r"
    st_check "confine: public -> ${spec%%|*} is one finding with resolves=$want, exit 5" \
      test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tpublic\ttarget='"$raw resolves=$want" "$ST_TMP/out.variant$n")"
    st_check_not "confine: public -> ${spec%%|*} lists nothing below the root" st_line $'\tpublic/'
  done

  # An absolute target that names the project and climbs out of it, and a deeper deployment link.
  r="$ST_TMP/escape"
  mkdir -p "$r/.github"
  ln -s "$r/../outside" "$r/public"
  ln -s "$o/workflows" "$r/.github/workflows"
  st_commit "$r" base
  st_run escape static --root "$r"
  st_check 'confine: public -> <project>/../outside is outside-project' st_line $'\tpublic\ttarget=' 'resolves=outside-project'
  st_check 'confine: .github/workflows -> an outside directory is outside-project' st_line $'\t.github/workflows\ttarget=' 'resolves=outside-project'
  st_check_not 'confine: neither link makes the scan read outside the project' st_line 'hostonly'

  # A symlink below public/ that leaves the project reads the same whether its target exists or not.
  r="$ST_TMP/oracle"
  mkdir -p "$r/public"
  ln -s "$o/hostonly-dump.sql" "$r/public/a"
  ln -s "$o/absent" "$r/public/b"
  st_commit "$r" base
  st_run oracle static --root "$r"
  st_check 'confine: a link to an existing outside file is outside-project' st_line $'\tpublic/a\ttarget=' 'resolves=outside-project'
  st_check 'confine: a link to a missing outside file is outside-project too' st_line $'\tpublic/b\ttarget=' 'resolves=outside-project'

  # The change re-points the public disk at database/ and the web root at the project root.
  r="$ST_TMP/s4a"
  mkdir -p "$r/public" "$r/storage/app/public" "$r/database"
  printf '<?php\n' >"$r/public/index.php"
  printf 'png\n' >"$r/storage/app/public/a.png"
  printf 'x\n' >"$r/database/backup.sql"
  st_commit "$r" base
  base="$(git -C "$r" rev-parse HEAD)"
  (cd "$r" && git rm -q -r storage/app/public) >/dev/null 2>&1
  mkdir -p "$r/storage/app"
  ln -s ../../database "$r/storage/app/public"
  st_commit "$r" change
  st_run s4a static --root "$r" --changed-from "$base"
  st_check 'confine: storage/app/public -> ../../database in the diff is a finding on the root, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tstorage/app/public\ttarget=../../database resolves=database' "$ST_TMP/out.s4a")"
  st_check 'confine: the dump the re-pointed public disk serves is in the diff scope' st_line $'dump-archive-backup\tcritical\tstorage/app/public/backup.sql\t'
  r="$ST_TMP/s4b"
  mkdir -p "$r/public"
  printf '<?php\n' >"$r/public/index.php"
  printf 'x\n' >"$r/backup.sql"
  st_commit "$r" base
  base="$(git -C "$r" rev-parse HEAD)"
  (cd "$r" && git rm -q -r public) >/dev/null 2>&1
  ln -s . "$r/public"
  st_commit "$r" change
  st_run s4b static --root "$r" --changed-from "$base"
  st_check 'confine: public -> . in the diff is a finding on the root, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tpublic\ttarget=. resolves=.' "$ST_TMP/out.s4b")"

  # Guards: an absolute link written with the logical root, and a public disk inside public/.
  r="$ST_TMP/absreal"
  mkdir -p "$r/public" "$r/storage/app/public"
  ln -s absreal "$ST_TMP/absalias"
  ln -s "$ST_TMP/absalias/storage/app/public" "$r/public/storage"
  st_commit "$r" base
  st_run absalias static --root "$ST_TMP/absalias"
  st_check_not 'confine: an absolute link through the logical root reads as inside the project' st_line $'\tpublic/storage\t'
  r="$ST_TMP/inpub"
  mkdir -p "$r/public/uploads" "$r/storage/app"
  ln -s ../../public/uploads "$r/storage/app/public"
  st_commit "$r" base
  st_run inpub static --root "$r"
  st_check_not 'confine: a public disk link into public/ is no finding' st_line $'\tstorage/app/public\ttarget='
}

# A curl stub: logs its argv, writes headers, and answers 200 for backup.sql, 302 for laravel.log, else 404.
st_curl_stub() {
  mkdir -p "$ST_TMP/bin"
  cat >"$ST_TMP/bin/curl" <<EOF
#!/bin/sh
{ printf 'argv'; for a in "\$@"; do printf ' [%s]' "\$a"; done; printf '\n'; } >>'$ST_TMP/curl.log'
hdr=''
prev=''
for a in "\$@"; do
  [ "\$prev" = '-D' ] && hdr="\$a"
  prev="\$a"
  url="\$a"
done
case "\$url" in
  */backup.sql) code=200 ;;
  */laravel.log) code=302 ;;
  *) code=404 ;;
esac
[ -n "\$hdr" ] && printf 'HTTP/1.1 %s X\r\nContent-Type: application/sql\r\nContent-Length: 12\r\n\r\n' "\$code" >"\$hdr"
printf '%s' "\$code"
EOF
  chmod +x "$ST_TMP/bin/curl"
}

st_runtime_cases() {
  local p="$ST_TMP/project" target private="10.0.0"
  st_curl_stub
  # The private address is assembled at run time, so no tracked line carries an address literal.
  for target in https://example.com "http://$private.1" http://user@localhost http://localhost/app \
    'http://localhost?x=1' http://127.0.0.1.example.com http://example.test.evil.com; do
    : >"$ST_TMP/curl.log"
    st_run refused runtime --root "$p" --base-url "$target"
    st_check "runtime: $target is refused with exit 4 before any request" test "$ST_RC" = 4 -a ! -s "$ST_TMP/curl.log"
  done

  : >"$ST_TMP/curl.log"
  st_run runtime runtime --root "$p" --base-url http://app.test:8080/
  local argv
  argv="$(<"$ST_TMP/curl.log")"
  st_check 'runtime: a redirect leaves the scan incomplete, exit 3' test "$ST_RC" = 3
  st_check 'runtime: a 2xx answer is a high http-reachable finding with status, type, and length' \
    st_line $'finding\thttp-reachable\thigh\t/backup.sql\tstatus=200 type=application/sql length=12'
  st_check 'runtime: a 404 answer is a probe line, not a finding' st_line $'probe\t/.env\tstatus=404'
  st_check 'runtime: a redirect is not followed and is a not-verified line' st_line 'not-verified: /laravel.log - status 302'
  st_check 'runtime: a file of the public disk maps to /storage/' st_line $'/storage/shell.php\tstatus=404'
  st_check 'runtime: a symlink the static check flags is probed' st_line $'probe\t/leak\tstatus=404'
  st_check 'runtime: a symlink that leaves the project is probed' st_line $'probe\t/latest\tstatus=404'
  st_check 'runtime: every request to a name is pinned to 127.0.0.1 on the URL port' \
    test -z "$(printf '%s\n' "$argv" | grep -v -F '[--resolve] [app.test:8080:127.0.0.1]')"
  st_check 'runtime: a name with a non-URL byte is percent-encoded' test -n "$(grep -F '/tab%09name.zip' "$ST_TMP/curl.log")"
  st_check 'runtime: every request is a curl -q HEAD with no redirect, no proxy, and no credential' \
    test -z "$(printf '%s\n' "$argv" | grep -v '^argv \[-q\] .*\[--head\] \[--max-redirs\] \[0\] .*\[--noproxy\] \[\*\]')"
  st_check_not 'runtime: no request follows a redirect or sends a credential' \
    test -n "$(printf '%s\n' "$argv" | grep -E '\[(-L|--location|-u|--user|-n|--netrc|-b|--cookie)\]')"
  st_check 'runtime: the outbound request line names the host and the count' st_line 'outbound-request: app.test HEAD'
  st_check_not 'runtime: the content of a reported file is never printed' st_line "$ST_MARKER"

  : >"$ST_TMP/curl.log"
  st_run loopback runtime --root "$p" --base-url 'http://[::1]'
  st_check 'runtime: [::1] is accepted' test -s "$ST_TMP/curl.log"
  st_check_not 'runtime: [::1] connects as given, with no pin' grep -q -F -- '--resolve' "$ST_TMP/curl.log"

  : >"$ST_TMP/curl.log"
  st_run literal runtime --root "$p" --base-url http://127.0.0.2:8000
  st_check_not 'runtime: a 127.0.0.0/8 address connects as given, with no pin' grep -q -F -- '--resolve' "$ST_TMP/curl.log"

  : >"$ST_TMP/curl.log"
  st_run default-https runtime --root "$p" --base-url https://App.localhost
  st_check 'runtime: a name with no port is pinned to 127.0.0.1 on the scheme port' \
    test -z "$(grep -v -F '[--resolve] [app.localhost:443:127.0.0.1]' "$ST_TMP/curl.log")"

  # Another letter case, a port with a leading zero, and a name that starts like a literal address.
  local url want
  for url in 'http://APP.TEST:8080|app.test:8080' 'http://app.test:08080|app.test:8080' 'http://127.0.0.1.test|127.0.0.1.test:80'; do
    want="${url#*|}"
    : >"$ST_TMP/curl.log"
    st_run pin runtime --root "$p" --base-url "${url%%|*}"
    st_check "runtime: ${url%%|*} is pinned as $want:127.0.0.1" \
      test -s "$ST_TMP/curl.log" -a -z "$(grep -v -F "[--resolve] [$want:127.0.0.1]" "$ST_TMP/curl.log")"
  done

  : >"$ST_TMP/curl.log"
  st_run default-http runtime --root "$p" --base-url http://localhost
  st_check 'runtime: localhost is pinned to 127.0.0.1 on port 80' \
    test -z "$(grep -v -F '[--resolve] [localhost:80:127.0.0.1]' "$ST_TMP/curl.log")"
}

self_test() {
  local tmp
  tmp="$(mktemp -d)" || exit 1
  ST_TMP="$(cd -P "$tmp" && pwd -P)"
  # shellcheck disable=SC2064  # expand the path now: the trap must remove this run's directory
  trap "chmod -R u+rwx '$ST_TMP' 2>/dev/null; rm -rf '$ST_TMP'" EXIT
  export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
  need_tools git find stat awk grep sed tr
  ST_MARKER="marker$RANDOM$RANDOM$RANDOM"

  st_static_cases
  st_runtime_cases
  st_confine_cases

  printf '%s: self-test %s passed, %s failed (%s checks)\n' "$PROG" "$ST_PASS" "$ST_FAIL" "$((ST_PASS + ST_FAIL))"
  [[ $ST_FAIL -eq 0 ]]
  exit
}

main "$@"
