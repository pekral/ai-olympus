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
#   Scans public/ and storage/app/public/ of the project root on disk. It does not descend into a
#   symlinked directory and does not descend into a .git entry. With --changed-from it prints only the
#   findings on a path that `git diff --name-only <base> HEAD` lists (a .gitignore finding when any
#   changed path is a .gitignore file); this is the review mode of a code review.
#
# runtime (explicit request only)
#   Runs the name checks of the static mode on public/ and storage/app/public/ and sends one HEAD
#   request per finding path, plus /.env, to the base URL. It accepts only a base URL whose host is
#   localhost, a name under .localhost or .test, an address in 127.0.0.0/8, or [::1], with no user
#   information, path, query, or fragment; any other target is refused with exit 4 before a request.
#   Each request is `curl -q --head` with no redirect, no proxy, no credential, no cookie, and a time
#   limit. It downloads no body. A public/storage symlink that resolves to storage/app/public maps a
#   file of the public disk to /storage/<path>.
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
#   symlink-permission     high      a symlink whose target resolves outside public/ and
#                                    storage/app/public/; an entry that is world-writable
#   gitignore-deploy       medium    a probe path the project's .gitignore files do not ignore
#                                    (GITIGNORE_PROBES); a public/ path in a deployment file
#                                    (DEPLOY_FILES) whose name a name check above matches
#   http-reachable         high      (runtime) a HEAD request to a finding path answers 2xx
#   The name checks read the name only. The content of a reported file is never read, opened, hashed,
#   or copied. A deployment file is read line by line with grep -o for public/ path tokens; a line is
#   never printed.
#
# Evidence (metadata only)
#   size=<bytes> mtime=<UTC> mode=<octal> git=<tracked|ignored|untracked|none>; target=<raw link>
#   resolves=<outside-project|path>; probe=<path> ignored=no; line=<n> ref=<public/ token>
#   class=<check>; status=<http status> type=<content-type> length=<content-length>.
#   Every printed path is printable ASCII; any other byte and the backslash print as \xHH.
#
# Execution safety
#   Every git call goes through git_ro: no system or user configuration, no fsmonitor, no hooks, no
#   protocol. A path reaches ls-files as a :(literal) pathspec, so no name acts as a glob. The script never deletes, moves, renames, or changes the mode of a
#   file. Untrusted file names reach no eval, no sh -c, and no awk program text.
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
CHANGED=''
SCOPE=tree
declare -a PATHS=() KINDS=() SHARED=() CLASS=()

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

in_scope() {
  local check="$1" path="$2"
  if [[ "$check" == gitignore-deploy && "$path" == .gitignore ]]; then
    [[ "$CHANGED" == *$'\n.gitignore\n'* || "$CHANGED" == *'/.gitignore'$'\n'* ]]
    return
  fi
  [[ "$CHANGED" == *$'\n'"$path"$'\n'* ]]
}

# --- metadata ----------------------------------------------------------------------------------------

STAT_GNU=0
pick_stat() {
  if stat -c '%s' / >/dev/null 2>&1; then STAT_GNU=1; fi
}

# meta <relative path>: size=… mtime=… mode=… git=…
meta() {
  local rel="$1" raw size mtime mode when state
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
    if git_ro ls-files --error-unmatch -- ":(literal)$rel" >/dev/null 2>&1; then
      state=tracked
    elif git_ro check-ignore -q -- "$rel" >/dev/null 2>&1; then
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

# The physical path a symlink chain ends at, or a failure when the chain is broken or too long.
resolve_link() {
  local path="$1" dir target n=0
  while [[ -L "$path" ]]; do
    ((n++ < 40)) || return 1
    dir="$(cd -P -- "$(dirname -- "$path")" 2>/dev/null && pwd -P)" || return 1
    target="$(readlink -- "$path")" || return 1
    [[ "$target" == /* ]] || target="$dir/$target"
    path="$target"
  done
  [[ -e "$path" ]] || return 1
  if [[ -d "$path" ]]; then
    (cd -P -- "$path" 2>/dev/null && pwd -P)
    return
  fi
  dir="$(cd -P -- "$(dirname -- "$path")" 2>/dev/null && pwd -P)" || return 1
  printf '%s/%s\n' "$dir" "${path##*/}"
}

# --- name checks -------------------------------------------------------------------------------------

# The shared classifier reads one path per line, so a newline inside a name is replaced for it only.
classify_shared() {
  local i
  SHARED=()
  for ((i = 0; i < ${#PATHS[@]}; i++)); do SHARED[i]=''; done
  ((${#PATHS[@]})) || return 0
  local line rule
  while IFS=$'\t' read -r line rule; do
    [[ "$line" =~ ^[0-9]+$ ]] && SHARED[line - 1]="$rule"
  done < <(for ((i = 0; i < ${#PATHS[@]}; i++)); do printf '%s\n' "${PATHS[i]//$'\n'/?}"; done | bash "$CLASSIFIER" classify)
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

# Collect every entry below the scan roots into PATHS (relative to the root) and KINDS.
collect() {
  local dir rel p kind
  PATHS=()
  KINDS=()
  for dir in public storage/app/public; do
    [[ -d "$ROOT/$dir" && ! -L "$ROOT/$dir" ]] || continue
    while IFS= read -r -d '' p; do
      rel="${p#"$ROOT"/}"
      if [[ -L "$p" ]]; then kind=l; elif [[ -d "$p" ]]; then kind=d; else kind=f; fi
      PATHS+=("$rel")
      KINDS+=("$kind")
    done < <(find "$ROOT/$dir" -mindepth 1 \( -name .git -print0 -prune \) -o -print0 2>/dev/null)
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

static_checks() {
  local i n=0 rel abs resolved shown pub disk probe
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    [[ -n "${CLASS[i]}" ]] || continue
    emit "${CLASS[i]}" "$(severity_of "${CLASS[i]}")" "${PATHS[i]}" "$(meta "${PATHS[i]}")"
  done

  pub="$(cd -P -- "$ROOT/public" 2>/dev/null && pwd -P)"
  disk="$(cd -P -- "$ROOT/storage/app/public" 2>/dev/null && pwd -P)" || disk=''
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    [[ "${KINDS[i]}" == l ]] || continue
    abs="$ROOT/${PATHS[i]}"
    resolved="$(resolve_link "$abs")" || continue
    if [[ "$resolved" == "$pub" || "$resolved" == "$pub"/* ]]; then continue; fi
    if [[ -n "$disk" && ("$resolved" == "$disk" || "$resolved" == "$disk"/*) ]]; then continue; fi
    if [[ "$resolved" == "$ROOT_P"/* ]]; then shown="${resolved#"$ROOT_P"/}"; else shown=outside-project; fi
    n=$((n + 1))
    if ((n > FINDING_CAP)); then continue; fi
    emit symlink-permission high "${PATHS[i]}" "target=$(esc "$(readlink -- "$abs")") resolves=$(esc "$shown")"
  done

  local writable=0
  while IFS= read -r -d '' abs; do
    writable=$((writable + 1))
    ((writable > FINDING_CAP)) && continue
    rel="${abs#"$ROOT"/}"
    emit symlink-permission high "$rel" "world-writable $(meta "$rel")"
  done < <(for rel in public storage/app/public; do
    [[ -d "$ROOT/$rel" && ! -L "$ROOT/$rel" ]] || continue
    find "$ROOT/$rel" \( -name .git -prune \) -o \( -perm -0002 ! -type l -print0 \) 2>/dev/null
  done)
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

# The deployment files that exist, NUL-separated, relative to the root. A symlink is not followed.
deploy_files() {
  (
    cd "$ROOT" || exit 0
    shopt -s nullglob
    local IFS=$'\n' file
    # shellcheck disable=SC2086  # the unquoted expansion is the glob expansion of each pattern
    for file in $DEPLOY_FILES; do
      [[ -f "$file" && ! -L "$file" ]] && printf '%s\0' "$file"
    done
  )
}

deploy_checks() {
  local file line token check i
  local -a files=() lines=() tokens=()
  while IFS= read -r -d '' file; do
    while IFS=: read -r line token; do
      [[ "$line" =~ ^[0-9]+$ ]] || continue
      files+=("$file")
      lines+=("$line")
      tokens+=("$token")
    done < <(grep -I -n -o -E 'public/[A-Za-z0-9._~*/-]+' -- "$ROOT/$file" 2>/dev/null)
  done < <(deploy_files)
  ((${#tokens[@]})) || return 0
  local -a saved_paths=("${PATHS[@]}")
  PATHS=("${tokens[@]}")
  classify_shared
  for ((i = 0; i < ${#tokens[@]}; i++)); do
    name_check "${tokens[i]%/}" f "${SHARED[i]}" 0
    check="$NAME_CHECK"
    [[ -n "$check" ]] || continue
    emit gitignore-deploy medium "${files[i]}" "line=${lines[i]} ref=$(esc "${tokens[i]}") class=$check"
  done
  PATHS=("${saved_paths[@]}")
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
  local base="${1%/}" host link disk i rel path url hdr code ctype clen reach=0 sent=0
  host="${base#*://}"
  host="${host%%:*}"
  [[ "$host" == '['* ]] && host='[::1]'
  link=0
  disk="$(cd -P -- "$ROOT/storage/app/public" 2>/dev/null && pwd -P)" || disk=''
  if [[ -L "$ROOT/public/storage" && -n "$disk" && "$(resolve_link "$ROOT/public/storage")" == "$disk" ]]; then link=1; fi

  local -a urls=(/.env)
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    [[ -n "${CLASS[i]}" ]] || continue
    rel="${PATHS[i]}"
    case "$rel" in
    public/*) path="/${rel#public/}" ;;
    storage/app/public/*) ((link)) || continue; path="/storage/${rel#storage/app/public/}" ;;
    *) continue ;;
    esac
    [[ "${rel##*/}" == .git && "${KINDS[i]}" == d ]] && path="$path/HEAD"
    [[ "$path" == /.env ]] || urls+=("$path")
  done

  hdr="$(mktemp)" || die 2 'cannot create a temporary file'
  trap 'rm -f "$hdr"' EXIT
  for path in "${urls[@]}"; do
    if ((sent >= PROBE_CAP)); then
      not_verified "http-reachable - more than $PROBE_CAP paths; the first $PROBE_CAP were checked; fix the static findings and run the scan again"
      break
    fi
    sent=$((sent + 1))
    url="$base$(urlenc "$path")"
    : >"$hdr"
    code="$(curl -q --silent --globoff --head --max-redirs 0 --proto '=http,https' --noproxy '*' \
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
  rm -f "$hdr"
  trap - EXIT
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
  ROOT="$ROOT_P"
  HAS_GIT=0
  if command -v git >/dev/null 2>&1 && [[ "$(git_ro rev-parse --is-inside-work-tree 2>/dev/null)" == true ]]; then HAS_GIT=1; fi
  pick_stat

  if [[ -n "$base" ]]; then
    ((HAS_GIT)) || die 1 '--changed-from needs a git work tree'
    local changed
    changed="$(git_ro diff --name-only --no-renames "$base" HEAD -- 2>/dev/null)" || die 3 'the base commit is not in this checkout'
    CHANGED=$'\n'"$changed"$'\n'
    SCOPE='diff'
  fi

  printf 'public-files-scan: mode=%s scope=%s\n' "$mode" "$SCOPE"
  if [[ ! -d "$ROOT/public" ]]; then
    not_verified 'public/ - the root has no public/ directory; run the scan on the root of a Laravel project'
    finish
  fi
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
  printf 'cp db.sql public/fresh.sql\n' >>"$p/deploy.sh"
  (cd "$p" && git add public/new-dump.sql deploy.sh && st_git commit -q -m change) >/dev/null 2>&1
  st_run diff static --root "$p" --changed-from "$base"
  st_check 'diff: a dump the diff adds is reported' st_line $'dump-archive-backup\tcritical\tpublic/new-dump.sql\t' 'git=tracked'
  st_check 'diff: a changed deployment file is reported' st_line $'deploy.sh\tline=3 ref=public/fresh.sql'
  st_check_not 'diff: a dump the diff does not touch is not reported' st_line 'public/backup.sql'
  st_check_not 'diff: a .gitignore finding is not reported when no .gitignore changed' st_line 'probe=.env'
  st_check 'diff: the header names the diff scope' st_line 'mode=static scope=diff'

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

  printf '%s: self-test %s passed, %s failed (%s checks)\n' "$PROG" "$ST_PASS" "$ST_FAIL" "$((ST_PASS + ST_FAIL))"
  [[ $ST_FAIL -eq 0 ]]
  exit
}

main "$@"
