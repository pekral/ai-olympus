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
#   check, and the submodule check; curl for the runtime mode. The shared path classifier
#   ../../git-history-exposure-audit/scripts/history-scan.sh (its classify mode) must sit beside this
#   skill, as the installer places every skill.
#
# Usage
#   public-files-scan.sh static  --root <project> [--changed-from <base sha>]
#   public-files-scan.sh runtime --root <project> --base-url <http(s)://local-host[:port]>
#   public-files-scan.sh --self-test
#
# static (default mode of the skill)
#   Scans public/, storage/app/public/, and every directory or file that config/filesystems.php serves,
#   of the project root on disk. The scan stays inside the physical project root: resolve_link follows
#   every path one component at a time and never reads, lists, or stats an entry outside it. A scan root
#   behind a symlink is decided first:
#     - the symlink resolves to a project directory: the root is scanned at that physical directory,
#       and the symlink is a symlink-permission finding on the root path unless the directory lies in
#       public/ or storage/app/public/;
#     - the symlink leaves the project, or resolves to the project root or to a file: one
#       symlink-permission finding on the root path, and the root is not read;
#     - the symlink does not resolve: a not-verified line.
#   A deployment directory is decided the same way. Below a scan root it does not descend into a
#   symlinked directory and does not descend into a .git entry. An entry find cannot read is a
#   not-verified line.
#   The directories and files that config/filesystems.php serves are scan roots too, in every run. `php
#   artisan storage:link` links each entry of its `links` array into public/, and the framework serves
#   every local disk that sets `serve` to true and `visibility` to 'public'. The scan reads the file as
#   data (CONFIG_AWK; it runs no PHP and reads no environment value) and scans each target as it scans
#   storage/app/public/, with the same checks, named by its path below the project root. A target is
#   read only as a helper call with one literal argument (storage_path, base_path, public_path,
#   app_path, resource_path, database_path, config_path, lang_path, bootstrap_path). A `..` component
#   stays in the path and resolves at the physical target of a symlink on the way, as the kernel does.
#   The cases that follow:
#     - the target is a directory: its files are scanned; a regular file: the file itself is checked by
#       its name, with the checks of the public disk;
#     - the target lies at or below a root already scanned: nothing more is read, and no path is
#       reported twice;
#     - the target is a directory above a root already scanned: it is scanned, and the paths of the
#       root already scanned are reported once;
#     - the target is the project root, or leaves it: one symlink-permission finding on
#       config/filesystems.php that names the line, and nothing is read;
#     - the target is a symlink: it is decided as a scan root above;
#     - the target does not exist: nothing is served and no line is printed;
#     - a disk is a served root only when it is a local disk with serve true and visibility 'public'; a
#       disk with no serve, serve false or null, another driver, or no visibility or 'private' serves
#       no root. A serve, driver, visibility, or root that is not a literal, a disk that is not an
#       array literal, a disk with a key that is not a string literal, and a served disk with no root:
#       a not-verified line that names the config line and never the entry.
#   Every form the scan does not read with certainty is a not-verified line, never a clean result:
#     - an entry that is not a helper call with one literal argument (a variable, an env() or config()
#       call, a concatenation, an interpolated string or one with an escape), and a `links` or `disks`
#       key whose value is not an array literal;
#     - a config that is not `<?php`, then only `declare(...);` directives and class `use` statements,
#       then `return [ ... ];` with string-literal keys, or that holds a heredoc, a nowdoc, a backtick,
#       an end tag, an attribute, a NUL byte, a carriage return that no line feed follows, an
#       unterminated string or comment, or text before the open tag;
#     - a statement before the return that changes how a name inside the array resolves: a `namespace`
#       statement (PHP then calls a function of that namespace before the global helper), and a `use`
#       statement that holds `function` or `const` anywhere (plain, aliased, or inside a group: it
#       rebinds a helper name or a constant to a function or a constant that the scan does not read).
#       A `declare` that is not `declare(<directive>);`, and a `use` that holds anything but names, are
#       not read either. A plain or aliased class `use` changes no function or constant name, so it is read;
#     - a config that is a symlink, or whose directory is a symlink, is never opened, because a symlink
#       can lead to a file the scan must never read (an .env file): a not-verified line and no read;
#     - a config that is not readable or exceeds 1 MiB.
#   Other ways to move a served directory are not read statically, so a PHP file of app/, bootstrap/,
#   config/, routes/, or public/index.php that calls usePublicPath, use<App|Base|Bootstrap|Config|
#   Database|Lang|Storage>Path, or names path.public, path.base, path.lang, path.config, path.storage,
#   path.database, path.resources, path.bootstrap, or filesystems.links is a not-verified line; so is a
#   symlink below those four directories, a symlinked public/index.php, and a file grep cannot read.
#   --changed-from <base> is the review mode of a code review. The review reports every finding of the
#   full static scan, never only the findings of the diff, so a finding that already exists in the
#   project blocks the pull request too: a fail-safe. The base serves only the submodule check below.
#   A git submodule (a gitlink, mode 160000) is a not-verified line that names its path, so the scan
#   never exits 0 over one: every submodule the change adds, removes, or updates, read with
#   `git diff --raw -z --no-renames --ignore-submodules=none <base> HEAD`, a comparison of two trees
#   that never runs git inside a submodule checkout and that no submodule.<name>.ignore setting
#   changes; and, in every mode, a submodule of the index that is not checked out and lies
#   at or below a scan root, or at or above public/, storage/app/public/, or a deployment directory.
#
# runtime (explicit request only)
#   Runs the name checks and the symlink check of the static mode on public/ and storage/app/public/
#   and sends one HEAD request per finding path, plus /.env, to the base URL. A scan root the static
#   mode would not read is a not-verified line. A path that config/filesystems.php serves is not
#   probed: it is a not-verified line, and so is each static finding of that file. It accepts only a base
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
#   public-storage-script  high      in storage/app/public/ and in each directory or file a links entry
#                                    or a served disk serves: *.php, *.php<digit>, *.phtml, *.pht,
#                                    *.phar, *.phps, *.shtml, *.sh, *.cgi, *.pl, *.py, .htaccess,
#                                    .user.ini, and a regular file with an execute bit
#   symlink-permission     high      a symlink below a scan root whose target resolves outside the
#                                    scanned roots, leaves the project, or does not resolve; a scan
#                                    root or a deployment directory behind such a symlink (static
#                                    above); an entry that is world-writable
#   gitignore-deploy       medium    a probe path the project's .gitignore files do not ignore
#                                    (GITIGNORE_PROBES); a path below public/, storage/app/public/, or
#                                    a served path of the config, in a deployment file (DEPLOY_FILES),
#                                    whose name a name check above matches; a step that names such a
#                                    path and goes on with a form the scan cannot read (not-verified)
#   http-reachable         high      (runtime) a HEAD request to a finding path answers 2xx
#   The name checks read the name only. The content of a reported file is never read, opened, hashed,
#   or copied. A deployment file is read line by line with awk for served path tokens (DEPLOY_AWK); a
#   line is never printed.
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
#   names no file, size, time, mode, existence, or line outside it. read_link reads a symlink target
#   byte for byte, a trailing newline included, so the scan follows the entry the kernel follows, and
#   every stat reads the physical entry that the scan resolved inside the project, never a path that
#   passes a symlink.
#
# Output (stdout, one record per line)
#   public-files-scan: mode=<static|runtime>
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
SCRATCH=''
ROOT_P=''
ROOT_L=''
RESOLVED=''
LINK_TARGET=''
PHYS_ENTRY=''
PROJ_REL=''
PUB_P=''
DISK_P=''
declare -a PATHS=() PATHS_P=() KINDS=() SHARED=() CLASS=() LINK_IDX=() LINK_EV=()
declare -a LINK_TRAIL=() LINK_TRAIL_RAW=() SCAN_ROOTS=() SCAN_PHYS=() ROOT_FIND=() ROOT_FEV=()
declare -a DEPLOY_REL=() DEPLOY_ABS=() CFG_FIND=() CFG_FEV=() DISK_EXTRA=() SERVED_PREFIX=()
EXTRA_FROM=0

emit() {
  # emit <check> <severity> <path> <evidence>
  printf 'finding\t%s\t%s\t%s\t%s\n' "$1" "$2" "$(esc "$3")" "$4"
  FINDINGS=$((FINDINGS + 1))
}

not_verified() {
  printf 'not-verified: %s\n' "$1"
  INCOMPLETE=1
}

# --- metadata ----------------------------------------------------------------------------------------

STAT_GNU=0
pick_stat() {
  if stat -c '%s' / >/dev/null 2>&1; then STAT_GNU=1; fi
}

# meta <physical path>: size=… mtime=… mode=… git=…. The path is the physical entry inside the
# project that the scan already resolved (find below a resolved root), so stat follows no symlink of
# the branch under review. The git status is taken for that entry relative to the root, so a file
# served through a symlink reads with the status of its git path.
meta() {
  local phys="$1" raw size mtime mode when state grel
  grel="${phys#"$ROOT_P"/}"
  if ((STAT_GNU)); then
    raw="$(stat -c '%s %Y %a' -- "$phys" 2>/dev/null)" || raw=''
  else
    raw="$(stat -f '%z %m %Lp' -- "$phys" 2>/dev/null)" || raw=''
  fi
  read -r size mtime mode <<<"$raw"
  if ((STAT_GNU)); then
    when="$(date -u -d "@${mtime:-0}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)" || when='-'
  else
    when="$(date -u -r "${mtime:-0}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)" || when='-'
  fi
  state=none
  if ((HAS_GIT)); then
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

# mode_bits <physical path>: the permission bits of an entry the scan already resolved.
mode_bits() {
  if ((STAT_GNU)); then stat -c '%a' -- "$1" 2>/dev/null; else stat -f '%Lp' -- "$1" 2>/dev/null; fi
}

# read_link <path>: sets LINK_TARGET to the raw target of a symlink, byte for byte, so the scan
# follows the same entry the kernel follows. readlink -n prints no delimiter of its own (the macOS
# readlink adds none after a target that already ends in a newline), and a command substitution drops
# every trailing newline, so the x keeps them.
read_link() {
  LINK_TARGET="$(readlink -n -- "$1" && printf x)" || return 1
  LINK_TARGET="${LINK_TARGET%x}"
}

# resolve_link <absolute path>: the one resolver of the scan. It follows the path one component at a
# time and reads a component only while that component lies inside the physical project root ROOT_P,
# so it never lists, reads, or stats an entry outside the project. A component on the way down to
# ROOT_P is a real directory, because ROOT_P is physical, and is not read. An absolute link target
# written with the logical root (ROOT_L) is read as ROOT_P. It sets RESOLVED to the physical path, and
# records every symlink it follows in LINK_TRAIL (the physical path of the link entry) and
# LINK_TRAIL_RAW (its raw target, read byte for byte by read_link). Returns 0 when the path resolves
# inside the project, 1 when it does not resolve (a missing entry, a file used as a directory, or more
# than 40 links), and 2 when it leaves the project; a target outside the project is never checked for
# existence.
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
        read_link "$next" || return 1
        target="$LINK_TARGET"
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

# proj_rel <physical path> sets PROJ_REL to the path relative to the project root, "." for the root
# itself. It sets a variable, because a command substitution would drop a trailing newline of a name.
proj_rel() {
  if [[ "$1" == "$ROOT_P" ]]; then PROJ_REL=.; else PROJ_REL="${1#"$ROOT_P"/}"; fi
}

# The evidence of the last symlink resolve_link followed: its raw target and where the path ends.
# trail_ev <resolve_link code>; call it only when LINK_TRAIL is not empty.
trail_ev() {
  local n=${#LINK_TRAIL_RAW[@]} shown=outside-project
  if [[ "$1" == 0 ]]; then
    proj_rel "$RESOLVED"
    shown="$PROJ_REL"
  fi
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

# name_check <relative path> <kind f|d|l> <shared rule> <in public disk 0|1> <physical path> sets
# NAME_CHECK to the check id, or to nothing. It sets a variable instead of printing, so no subshell
# runs per path. Only the execute-bit check reads the physical path, for a path in the public disk.
name_check() {
  local kind="$2" shared="$3" disk="$4" phys="$5" base="${1##*/}" bits
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
          bits="$(mode_bits "$phys")"
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
# and SCAN_PHYS hold the roots to scan; ROOT_FIND and ROOT_FEV hold the root findings. PUB_P and
# DISK_P hold the physical directory of public/ and storage/app/public/ when they are scanned. The
# directories that config/filesystems.php links follow as extra roots (config_roots).
root_checks() {
  local dir
  SCAN_ROOTS=()
  SCAN_PHYS=()
  ROOT_FIND=()
  ROOT_FEV=()
  CFG_FIND=()
  CFG_FEV=()
  DISK_EXTRA=()
  SERVED_PREFIX=()
  PUB_P=''
  DISK_P=''
  for dir in public storage/app/public; do
    serve_prefix "$dir"
    decide_root "$dir"
  done
  EXTRA_FROM=${#SCAN_ROOTS[@]}
  config_roots
  served_path_overrides
}

# decide_root <path> [<name>]: the decision of one scan root, as root_checks states it. The root is read
# at the physical target of the path and is named by <name>, the path by default. An extra root, one
# that a config entry serves, may also be a regular file, and a root that lies at or below a root
# already scanned adds nothing.
decide_root() {
  local dir="$1" name="${2:-$1}" rc extra=1
  case "$name" in public | storage/app/public) extra=0 ;; esac
  resolve_link "$ROOT_P/$dir"
  rc=$?
  if ((${#LINK_TRAIL[@]} == 0)); then
    if ((rc == 0)); then
      if [[ -d "$RESOLVED" ]] || { ((extra)) && [[ -f "$RESOLVED" ]]; }; then
        in_scan_roots "$RESOLVED" || add_root "$name"
      elif ((extra)); then
        not_verified "$(esc "$name") - the target is neither a regular file nor a directory, so the scan does not read it; check it by hand and run the scan again"
      fi
    fi
    return 0
  fi
  if ((rc == 1)); then
    not_verified "$(esc "$name")/ - the symlink does not resolve to a directory; restore its target and run the scan again"
    return 0
  fi
  if ((rc == 0)) && [[ "$RESOLVED" != "$ROOT_P" ]]; then
    if [[ -d "$RESOLVED" ]] || { ((extra)) && [[ -f "$RESOLVED" ]]; }; then
      in_scan_roots "$RESOLVED" && return 0
      add_root "$name"
      [[ "$RESOLVED" == "$ROOT_P/public" || "$RESOLVED" == "$ROOT_P"/public/* ||
        "$RESOLVED" == "$ROOT_P/storage/app/public" || "$RESOLVED" == "$ROOT_P"/storage/app/public/* ]] && return 0
    fi
  fi
  ROOT_FIND+=("$name")
  ROOT_FEV+=("$(trail_ev "$rc")")
}

# add_root <root name>: scan the root at RESOLVED, its physical path. A root other than public/ and
# storage/app/public/ is a directory or file that a config entry serves, and it gets the checks of the
# public disk.
add_root() {
  SCAN_ROOTS+=("$1")
  SCAN_PHYS+=("$RESOLVED")
  case "$1" in
  public) PUB_P="$RESOLVED" ;;
  storage/app/public) DISK_P="$RESOLVED" ;;
  *) DISK_EXTRA+=("$1") ;;
  esac
}

# --- the directories that the configuration serves --------------------------------------------------

# `php artisan storage:link` creates a symlink in public/ for every entry of the `links` array of
# config/filesystems.php, and the framework serves every local disk that sets `serve` and a public
# visibility, so each such directory or file is served whichever path the change touches. CONFIG_AWK
# reads the file as data and never runs it. It models no more PHP than it needs, and every form it does
# not model is a not-verified record, never a clean one: the file must be `<?php`, then only
# `declare(...);` directives and class `use` statements, then `return [ ... ];` as its last statement,
# and the keys of that array must be string literals. A `namespace` statement and a `use` statement
# that holds `function` or `const` rebind a name inside the array, so they are not modelled. A
# heredoc, a nowdoc, a backtick, an end tag, an attribute, a carriage return that no line feed follows,
# an unterminated string or comment, and text before the open tag are not modelled either. It prints one record per entry, fields separated by a tab:
#   <line> OK <tag> <path below the project root, "." for the root, ".." components kept> <lexical path>
#   <line> NV <tag>               the entry is a form that the scan does not read
# The tag is `links`, `disk`, or `file` (the whole file). The path keeps every `..` component, so
# resolve_link resolves it at the physical target of a symlink on the way, as the kernel does. The
# lexical path is the same path with the `..` components removed, empty when it leaves the root, and
# names the directory in the deployment check. A record never carries the text of an entry, so a value
# that comes from env() is never echoed, and the scan reads no environment value.
# shellcheck disable=SC2016  # the awk program is single-quoted on purpose: its $ is awk text, not shell
CONFIG_AWK='
function bad_at(lno) { if (!badl) badl = lno }
function push(type, val, lno, esc) { n++; tt[n] = type; tv[n] = val; tl[n] = lno; te[n] = esc }
function lex(s, lno,    i, c, d, len, w, nx) {
  len = length(s); i = 1
  while (i <= len) {
    c = substr(s, i, 1); d = substr(s, i, 2)
    if (inblk) { if (d == "*/") { inblk = 0; i += 2 } else i++; continue }
    if (instr) {
      if (c == "\\") {
        nx = substr(s, i + 1, 1)
        if (!(q == sq && (nx == "\\" || nx == sq))) esc = 1
        buf = buf nx; i += 2; continue
      }
      if (c == q) { push(dyn ? "D" : "S", buf, sl, esc); instr = 0; i++; continue }
      if (q == "\"" && c == "$") dyn = 1
      buf = buf c; i++; continue
    }
    if (c == "\r") { if (i < len) bad_at(lno); i++; continue }
    if (c == " " || c == "\t") { i++; continue }
    if (!started) {
      started = 1
      if (tolower(substr(s, i, 5)) == "<?php") { i += 5; continue }
      bad_at(lno)
    }
    if (d == "//" || (c == "#" && substr(s, i + 1, 1) != "[")) { if (index(substr(s, i), "?>")) bad_at(lno); return }
    if (d == "/*") { inblk = 1; i += 2; continue }
    if (d == "?>" || d == "<?" || substr(s, i, 3) == "<<<" || c == "`" || c == "#") { bad_at(lno); i++; continue }
    if (c == sq || c == "\"") { instr = 1; q = c; buf = ""; dyn = 0; esc = 0; sl = lno; i++; continue }
    if (d == "=>") { push("A", "=>", lno, 0); i += 2; continue }
    if (c ~ /[A-Za-z_\\]/) {
      w = ""
      while (i <= len && substr(s, i, 1) ~ /[A-Za-z0-9_\\]/) { w = w substr(s, i, 1); i++ }
      push("I", w, lno, 0); continue
    }
    push("P", c, lno, 0); i++
  }
  if (instr) buf = buf "\n"
}
function helper(name) {
  sub(/^\\/, "", name)
  name = tolower(name)
  if (name == "storage_path") return "storage"
  if (name == "base_path") return "/"
  if (name == "public_path") return "public"
  if (name == "app_path") return "app"
  if (name == "resource_path") return "resources"
  if (name == "database_path") return "database"
  if (name == "config_path") return "config"
  if (name == "lang_path") return "lang"
  if (name == "bootstrap_path") return "bootstrap"
  return ""
}
function isopen(k) { return (tt[k] == "P" && tv[k] ~ /^[\[\(\{]$/) }
function isclose(k) { return (tt[k] == "P" && tv[k] ~ /^[\]\)\}]$/) }
function nv(a, tag) { print tl[a] "\tNV\t" tag }
function single(a, b, type) { return (a == b && tt[a] == type && !te[a]) }
function keyed(a, b,    k, depth) {
  depth = 0
  for (k = a; k <= b; k++) {
    if (isopen(k)) depth++
    else if (isclose(k)) depth--
    else if (tt[k] == "A" && depth == 0) return k
  }
  return 0
}
function arr(a, b,    k, depth, want) {
  if (tt[a] == "P" && tv[a] == "[") { AO = a; want = "]" }
  else if (tt[a] == "I" && tolower(tv[a]) == "array" && tt[a + 1] == "P" && tv[a + 1] == "(") { AO = a + 1; want = ")" }
  else return 0
  depth = 0
  for (k = AO + 1; k <= b; k++) {
    if (isopen(k)) depth++
    else if (isclose(k)) { if (depth == 0) return (tv[k] == want && k == b) ? k : 0; depth-- }
  }
  return 0
}
function elements(lvl, o, c,    k, depth, start, cnt) {
  cnt = 0; depth = 0; start = o + 1
  for (k = o + 1; k < c; k++) {
    if (isopen(k)) depth++
    else if (isclose(k)) depth--
    else if (tt[k] == "P" && tv[k] == "," && depth == 0) {
      if (k > start) { cnt++; EA[lvl, cnt] = start; EB[lvl, cnt] = k - 1 }
      start = k + 1
    }
  }
  if (c > start) { cnt++; EA[lvl, cnt] = start; EB[lvl, cnt] = c - 1 }
  EN[lvl] = cnt
}
function target(a, b, tag,    base, arg, np, parts, j, ns, stack, raw, norm, under) {
  if (!(tt[a] == "I" && (base = helper(tv[a])) != "" && tt[a + 1] == "P" && tv[a + 1] == "(")) { nv(a, tag); return }
  if (tt[a + 2] == "S" && !te[a + 2] && tt[a + 3] == "P" && tv[a + 3] == ")" && a + 3 == b) arg = tv[a + 2]
  else if (tt[a + 2] == "P" && tv[a + 2] == ")" && a + 2 == b) arg = ""
  else { nv(a, tag); return }
  if (arg ~ /[^ -~]/ || index(arg, "\\")) { nv(a, tag); return }
  np = split((base == "/" ? "" : base) "/" arg, parts, "/")
  ns = 0; under = 0; raw = ""
  for (j = 1; j <= np; j++) {
    if (parts[j] == "" || parts[j] == ".") continue
    raw = raw (raw == "" ? "" : "/") parts[j]
    if (parts[j] == "..") { if (ns == 0) under = 1; else ns--; continue }
    stack[++ns] = parts[j]
  }
  norm = ""
  for (j = 1; j <= ns; j++) norm = norm (j > 1 ? "/" : "") stack[j]
  print tl[a] "\tOK\t" tag "\t" (raw == "" ? "." : raw) "\t" (under ? "" : (norm == "" ? "." : norm))
}
function links_value(a, b, lno,    c, e, a2, b2, m) {
  c = arr(a, b)
  if (!c) { print lno "\tNV\tlinks"; return }
  elements(2, AO, c)
  for (e = 1; e <= EN[2]; e++) {
    a2 = EA[2, e]; b2 = EB[2, e]
    m = keyed(a2, b2)
    target(m ? m + 1 : a2, b2, "links")
  }
}
function disk(a, b, ka,    c, f, a3, b3, m3, kk, bad, sv, dr, vi, rt, sva, svb, dra, drb, via, vib, rta, rtb, w) {
  c = arr(a, b)
  if (!c) { nv(ka, "disk"); return }
  elements(3, AO, c)
  bad = 0; sv = 0; dr = 0; vi = 0; rt = 0
  for (f = 1; f <= EN[3]; f++) {
    a3 = EA[3, f]; b3 = EB[3, f]
    m3 = keyed(a3, b3)
    if (!(tt[a3] == "S" && !te[a3] && m3 == a3 + 1 && m3 < b3)) { bad = 1; continue }
    kk = tv[a3]
    if (kk == "serve") { sv = 1; sva = m3 + 1; svb = b3 }
    else if (kk == "driver") { dr = 1; dra = m3 + 1; drb = b3 }
    else if (kk == "visibility") { vi = 1; via = m3 + 1; vib = b3 }
    else if (kk == "root") { rt = 1; rta = m3 + 1; rtb = b3 }
  }
  if (bad) { nv(ka, "disk"); return }
  if (!sv) return
  if (!single(sva, svb, "I")) { nv(ka, "disk"); return }
  w = tolower(tv[sva])
  if (w == "false" || w == "null") return
  if (w != "true") { nv(ka, "disk"); return }
  if (!dr || !single(dra, drb, "S")) { nv(ka, "disk"); return }
  if (tv[dra] != "local") return
  if (!vi) return
  if (!single(via, vib, "S")) { nv(ka, "disk"); return }
  if (tv[via] != "public") return
  if (!rt) { nv(ka, "disk"); return }
  target(rta, rtb, "disk")
}
function disks_value(a, b, lno,    c, e, a2, b2, m) {
  c = arr(a, b)
  if (!c) { print lno "\tNV\tdisk"; return }
  elements(2, AO, c)
  for (e = 1; e <= EN[2]; e++) {
    a2 = EA[2, e]; b2 = EB[2, e]
    m = keyed(a2, b2)
    if (!(tt[a2] == "S" && !te[a2] && m == a2 + 1 && m < b2)) { nv(a2, "disk"); continue }
    disk(m + 1, b2, a2)
  }
}
{ lex($0, NR) }
END {
  if (instr || inblk) bad_at(NR)
  if (badl) { print badl "\tNV\tfile"; exit }
  i = 1
  while (i <= n && !(tt[i] == "I" && tolower(tv[i]) == "return")) {
    w = (tt[i] == "I") ? tolower(tv[i]) : ""
    if (w == "declare") {
      j = i + 1
      if (!(tt[j] == "P" && tv[j] == "(")) { print tl[i] "\tNV\tfile"; exit }
      for (j++; j <= n && !(tt[j] == "P" && tv[j] == ")"); j++)
        if (isopen(j) || isclose(j)) { print tl[j] "\tNV\tfile"; exit }
      if (!(j < n && tt[j + 1] == "P" && tv[j + 1] == ";")) { print tl[i] "\tNV\tfile"; exit }
      i = j + 2
    } else if (w == "use") {
      for (j = i + 1; j <= n && !(tt[j] == "P" && tv[j] == ";"); j++) {
        if (tt[j] == "I" && (tolower(tv[j]) == "function" || tolower(tv[j]) == "const")) { print tl[j] "\tNV\tfile"; exit }
        if (!(tt[j] == "I" || (tt[j] == "P" && tv[j] ~ /^[,{}]$/))) { print tl[j] "\tNV\tfile"; exit }
      }
      if (j > n) { print tl[i] "\tNV\tfile"; exit }
      i = j + 1
    } else { print tl[i] "\tNV\tfile"; exit }
  }
  lastl = n ? tl[n] : 1
  if (i > n) { print lastl "\tNV\tfile"; exit }
  r = i
  c = 0
  if (tt[n] == "P" && tv[n] == ";") c = arr(r + 1, n - 1)
  if (!c) { print tl[r] "\tNV\tfile"; exit }
  elements(1, AO, c)
  for (e = 1; e <= EN[1]; e++) {
    a1 = EA[1, e]; b1 = EB[1, e]
    m = keyed(a1, b1)
    if (!(tt[a1] == "S" && !te[a1] && m == a1 + 1 && m < b1)) { print tl[a1] "\tNV\tfile"; exit }
    if (tv[a1] == "links") links_value(m + 1, b1, tl[a1])
    else if (tv[a1] == "disks") disks_value(m + 1, b1, tl[a1])
  }
}
'

# config_nv <line> <tag>: the not-verified line of a record the awk program did not read to a path.
config_nv() {
  local line="$1" cfg=config/filesystems.php
  case "$2" in
  file)
    not_verified "$cfg - line $line holds PHP that the scan does not read (code before the returned array, a namespace statement, a use function or use const statement, an unterminated string or comment, a heredoc, a backtick, an end tag, an attribute, a computed key, a spread, a NUL or stray carriage return byte, or text before the open tag), so it does not know which directories the file serves; write the file as one plain returned array or check the directories by hand, and run the scan again"
    ;;
  disk)
    not_verified "$cfg - the disks entry at line $line is not a local disk whose serve, visibility, and root are literals, so the scan does not know whether it serves a directory; write the entry with literals or check the directory by hand, and run the scan again"
    ;;
  *)
    not_verified "$cfg - the links entry at line $line is not a helper call with one literal path, so the scan does not know which directory it serves; write the entry as storage_path('<path>') or check the directory by hand, and run the scan again"
    ;;
  esac
}

# config_roots: read config/filesystems.php as data, and make each directory or file that it serves a
# scan root, scanned as storage/app/public is. A config that is missing sets no links, and the public
# disk of the default is a root already. The file is never followed through a symlink: a symlink could
# lead to a file the scan must never open, such as an .env file, so a symlink on the way to the file, or
# the file itself, is a not-verified line and nothing is read.
config_roots() {
  local cfg=config/filesystems.php file size plain line kind tag raw norm rc
  phys_entry "$cfg"
  rc=$?
  if ((${#LINK_TRAIL[@]})); then
    not_verified "$cfg - a directory on the way is a symlink, so the scan reads nothing from it; check the links and the disks of the project by hand and run the scan again"
    return 0
  fi
  ((rc == 0)) || return 0
  file="$PHYS_ENTRY"
  if [[ -L "$file" ]]; then
    not_verified "$cfg - the file is a symlink, so the scan reads nothing from it (a symlink can lead to a file the scan must never open); check the links and the disks of the project by hand and run the scan again"
    return 0
  fi
  [[ -e "$file" ]] || return 0
  if [[ ! -f "$file" || ! -r "$file" ]]; then
    not_verified "$cfg - the file is not a readable regular file; give the scan read access and run the scan again"
    return 0
  fi
  size="$(wc -c <"$file" 2>/dev/null | tr -d ' ')"
  if [[ ! "$size" =~ ^[0-9]+$ ]] || ((size > 1048576)); then
    not_verified "$cfg - the file is larger than 1 MiB, so the scan does not read it; check the links and the disks of the project by hand and run the scan again"
    return 0
  fi
  plain="$(tr -d '\000' <"$file" 2>/dev/null | wc -c | tr -d ' ')"
  if [[ "$plain" != "$size" ]]; then
    not_verified "$cfg - the file holds a NUL byte, so the scan does not read it; check the links and the disks of the project by hand and run the scan again"
    return 0
  fi
  awk -v "sq='" "$CONFIG_AWK" "$file" >"$SCRATCH/config" 2>/dev/null ||
    { not_verified "$cfg - the links and disks entries could not be read; run the scan again"; return 0; }
  while IFS=$'\t' read -r line kind tag raw norm; do
    [[ "$line" =~ ^[0-9]+$ ]] || continue
    if [[ "$kind" == OK ]]; then config_target "$line" "$tag" "$raw" "$norm"; else config_nv "$line" "$tag"; fi
  done <"$SCRATCH/config"
  return 0
}

# config_target <line> <tag> <path> <lexical path>: the decision of one served path of the config. The
# path resolves at its physical target: a path that leaves the project, and one that is the project root,
# are a symlink-permission finding on the config line and are never read. Any other path is a scan root,
# named by its physical path below the project when it holds a .. component.
config_target() {
  local line="$1" tag="$2" raw="$3" norm="$4" label=links name rc
  [[ "$tag" == disk ]] && label='disk root'
  [[ -n "$norm" && "$norm" != . ]] && serve_prefix "$norm"
  resolve_link "$ROOT_P/$raw"
  rc=$?
  if ((rc == 0)) && [[ "$RESOLVED" == "$ROOT_P" ]]; then
    CFG_FIND+=(config/filesystems.php)
    CFG_FEV+=("line=$line $label target=. resolves=.")
    return 0
  fi
  if ((rc == 2 && ${#LINK_TRAIL[@]} == 0)); then
    CFG_FIND+=(config/filesystems.php)
    CFG_FEV+=("line=$line $label target=outside-project resolves=outside-project")
    return 0
  fi
  name="$raw"
  if ((rc == 0)) && [[ "/$raw/" == */../* ]]; then
    proj_rel "$RESOLVED"
    name="$PROJ_REL"
  fi
  serve_prefix "$name"
  decide_root "$raw" "$name"
}

# serve_prefix <path>: a path below the root that the web server serves, kept for the deployment check
# whether or not the checkout holds it.
serve_prefix() {
  local known
  for known in ${SERVED_PREFIX[@]+"${SERVED_PREFIX[@]}"}; do
    [[ "$known" == "$1" ]] && return 0
  done
  SERVED_PREFIX+=("$1")
}

# Another way to serve a directory that no static read settles: a custom framework path, a container
# binding for one, or code that sets the links. The scan reads the PHP files of app/, bootstrap/,
# config/, routes/, and public/index.php with grep, and prints the match only. Each file that names one
# is a not-verified line. A symlink below those directories, a directory that is a symlink leaving the
# project, a symlinked public/index.php, and a file grep cannot read are not-verified lines too.
OVERRIDE_PUBLIC='usePublicPath|path\.public'
OVERRIDE_LINKS='filesystems\.links'
OVERRIDE_PATH='use(App|Base|Bootstrap|Config|Database|Lang|Storage)Path|path\.(base|lang|config|storage|database|resources|bootstrap)'

served_path_overrides() {
  local dir rc base p
  for dir in app bootstrap config routes; do
    resolve_link "$ROOT_P/$dir"
    rc=$?
    if ((rc != 0)); then
      ((${#LINK_TRAIL[@]})) &&
        not_verified "$dir/ - the directory is a symlink that does not resolve inside the project, so the scan does not read it; check it by hand and run the scan again"
      continue
    fi
    base="$RESOLVED"
    while IFS= read -r -d '' p; do
      not_verified "$(esc "${p#"$ROOT_P"/}") - the entry is a symlink, so the scan does not read it; check where it points and run the scan again"
    done < <(find "$base" -type l -print0 2>"$SCRATCH/find.err")
    find_errors "$dir" 'symlink check'
    grep -r -a -i -o -H -E --include='*.php' --exclude='.env*' -e "$OVERRIDE_PUBLIC|$OVERRIDE_LINKS|$OVERRIDE_PATH" -- "$base" \
      >"$SCRATCH/override" 2>"$SCRATCH/grep.err"
    override_report "$dir"
  done
  # A public/ that does not resolve inside the project is a finding of its own, so only the file is decided here.
  phys_entry public/index.php
  rc=$?
  if ((rc == 0)) && [[ -L "$PHYS_ENTRY" ]]; then
    not_verified "public/index.php - the entry is a symlink, so the scan does not read it; check where it points and run the scan again"
  elif ((rc == 0)) && [[ -e "$PHYS_ENTRY" ]]; then
    grep -a -i -o -H -E -e "$OVERRIDE_PUBLIC|$OVERRIDE_LINKS|$OVERRIDE_PATH" -- "$PHYS_ENTRY" >"$SCRATCH/override" 2>"$SCRATCH/grep.err"
    override_report public/index.php
  fi
}

# override_report <what>: one not-verified line for each file and each kind of match that grep printed
# to override as "<path>:<match>", and one when grep could not read an entry.
override_report() {
  local rec hit file kind seen=$'\n'
  if [[ -s "$SCRATCH/grep.err" ]]; then
    not_verified "$1 - grep could not read $(awk 'END { print NR }' "$SCRATCH/grep.err") entries of the framework-path check; give the scan read access to every entry and run the scan again"
  fi
  while IFS= read -r rec; do
    hit="${rec##*:}"
    file="${rec%:*}"
    shopt -s nocasematch
    case "$hit" in
    usePublicPath | path.public) kind=public ;;
    filesystems.links) kind=links ;;
    *) kind=path ;;
    esac
    shopt -u nocasematch
    [[ "$seen" == *$'\n'"$file:$kind"$'\n'* ]] && continue
    seen+="$file:$kind"$'\n'
    file="$(esc "${file#"$ROOT_P"/}")"
    case "$kind" in
    public) not_verified "$file - the file sets the public path, so the scan cannot tell which directory the web server serves; check it by hand and run the scan again" ;;
    links) not_verified "$file - the file sets the filesystems links, so the scan cannot tell which directories they serve; check them by hand and run the scan again" ;;
    *) not_verified "$file - the file sets a framework path, so the scan cannot tell which directory the helpers it reads point at; check it by hand and run the scan again" ;;
    esac
  done <"$SCRATCH/override"
}

# A physical path at or below a scanned root.
in_scan_roots() {
  local phys
  for phys in ${SCAN_PHYS[@]+"${SCAN_PHYS[@]}"}; do
    [[ "$1" == "$phys" || "$1" == "$phys"/* ]] && return 0
  done
  return 1
}

# add_path <relative path> <physical path>: one entry of PATHS, PATHS_P, and KINDS. The physical path
# is the entry inside the project that every later stat reads; its last component is not followed.
add_path() {
  local kind=f
  if [[ -L "$2" ]]; then kind=l; elif [[ -d "$2" ]]; then kind=d; fi
  PATHS+=("$1")
  PATHS_P+=("$2")
  KINDS+=("$kind")
}

# Collect every entry below the scanned roots into PATHS, named below the root path (public/…), and
# KINDS. find walks the physical directory of each root and does not follow a symlink below it.
collect() {
  local i dir phys p
  PATHS=()
  PATHS_P=()
  KINDS=()
  for ((i = 0; i < ${#SCAN_ROOTS[@]}; i++)); do
    dir="${SCAN_ROOTS[i]}"
    phys="${SCAN_PHYS[i]}"
    if [[ ! -d "$phys" ]]; then
      add_path "$dir" "$phys"
      continue
    fi
    while IFS= read -r -d '' p; do
      ((i >= EXTRA_FROM)) && under_earlier "$i" "$p" && continue
      add_path "$dir${p#"$phys"}" "$p"
    done < <(find "$phys" -mindepth 1 \( -name .git -print0 -prune \) -o -print0 2>"$SCRATCH/find.err")
    find_errors "$dir" 'name checks'
  done
  return 0
}

# under_earlier <root index> <physical path>: a physical path at or below a root that precedes the
# root, so an entry that two roots serve is scanned and reported once.
under_earlier() {
  local j
  for ((j = 0; j < $1; j++)); do
    [[ "$2" == "${SCAN_PHYS[j]}" || "$2" == "${SCAN_PHYS[j]}"/* ]] && return 0
  done
  return 1
}

# A path of the public disk: below storage/app/public/, or below a public/storage that is a real
# directory of the physical public/ root.
in_disk() {
  local extra
  [[ "$1" == storage/app/public/* ]] && return 0
  for extra in ${DISK_EXTRA[@]+"${DISK_EXTRA[@]}"}; do
    [[ "$1" == "$extra" || "$1" == "$extra"/* ]] && return 0
  done
  [[ "$1" == public/storage/* && -n "$PUB_P" && ! -L "$PUB_P/storage" ]]
}

run_name_checks() {
  local i disk
  CLASS=()
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    disk=0
    in_disk "${PATHS[i]}" && disk=1
    name_check "${PATHS[i]}" "${KINDS[i]}" "${SHARED[i]}" "$disk" "${PATHS_P[i]}"
    CLASS[i]="$NAME_CHECK"
  done
}

# --- submodules --------------------------------------------------------------------------------------

# A git submodule is a gitlink entry (mode 160000). git keeps its content in another repository, and a
# review worktree never checks it out, so the scan cannot read what a submodule serves. Each case below
# is a not-verified line that names the path, so the scan never exits 0 over a submodule:
#   - with --changed-from <base>, every gitlink the change adds, removes, or moves to another commit,
#     read with --ignore-submodules=none, so a submodule.<name>.ignore setting never hides one;
#   - every other gitlink of the index that gitlink_hidden reports.
submodule_checks() {
  local base="$1" hdr path known
  local -a reported=()
  ((HAS_GIT)) || return 0
  if [[ -n "$base" ]]; then
    while IFS= read -r -d '' hdr && IFS= read -r -d '' path; do
      [[ "${hdr:1:6}" == 160000 || "${hdr:8:6}" == 160000 ]] || continue
      reported+=("$path")
      not_verified "$(esc "$path") - the change adds, removes, or updates a git submodule, which the scan does not read; review the files of the submodule commit and run the scan again"
    done <"$SCRATCH/changed"
  fi
  if ! git_ro ls-files -s -z >"$SCRATCH/index" 2>/dev/null; then
    not_verified 'git submodules - git ls-files failed; run the scan in a readable checkout of the project'
    return 0
  fi
  # Each record is "<mode> <object id> <stage>", a tab, the path, NUL.
  while IFS= read -r -d '' hdr; do
    [[ "$hdr" == 160000\ * ]] || continue
    path="${hdr#*$'\t'}"
    for known in ${reported[@]+"${reported[@]}"}; do
      [[ "$known" == "$path" ]] && continue 2
    done
    gitlink_hidden "$path" || continue
    not_verified "$(esc "$path") - a git submodule that is not checked out, so the scan cannot read the files it serves; check out the submodule and run the scan again"
  done <"$SCRATCH/index"
}

# gitlink_hidden <relative path>: 0 when the gitlink holds no checkout (no .git entry) and the scan
# needs what it holds: its physical entry lies at or below a scan root, or the path is public,
# storage/app/public, a deployment directory, or a directory above one of them. A symlink at the path
# is read by the symlink check instead.
gitlink_hidden() {
  local path="$1" dir pattern dirs=$'public\nstorage/app/public'
  if phys_entry "$path"; then
    [[ -L "$PHYS_ENTRY" || -L "$PHYS_ENTRY/.git" || -e "$PHYS_ENTRY/.git" ]] && return 1
    in_scan_roots "$PHYS_ENTRY" && return 0
  fi
  while IFS= read -r pattern; do
    [[ "$pattern" == */* ]] && dirs+=$'\n'"${pattern%/*}"
  done <<<"$DEPLOY_FILES"
  while IFS= read -r dir; do
    [[ "$path" == "$dir" || "$path" == "$dir"/* || "$dir" == "$path"/* ]] && return 0
  done <<<"$dirs"
  return 1
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
    # The physical entry is the first symlink of its own resolution, so LINK_TRAIL_RAW[0] is its target.
    resolve_link "${PATHS_P[i]}"
    rc=$?
    if ((rc == 0)); then
      in_scan_roots "$RESOLVED" && continue
      proj_rel "$RESOLVED"
      shown="$(esc "$PROJ_REL")"
    elif ((rc == 2)); then
      shown=outside-project
    else
      shown=unresolved
    fi
    LINK_IDX+=("$i")
    LINK_EV+=("target=$(esc "${LINK_TRAIL_RAW[0]-}") resolves=$shown")
  done
}

static_checks() {
  local i n rel phys abs ww probe
  for ((i = 0; i < ${#ROOT_FIND[@]}; i++)); do
    emit symlink-permission high "${ROOT_FIND[i]}" "${ROOT_FEV[i]}"
  done
  for ((i = 0; i < ${#CFG_FIND[@]}; i++)); do
    emit symlink-permission high "${CFG_FIND[i]}" "${CFG_FEV[i]}"
  done
  for ((i = 0; i < ${#PATHS[@]}; i++)); do
    [[ -n "${CLASS[i]}" ]] || continue
    emit "${CLASS[i]}" "$(severity_of "${CLASS[i]}")" "${PATHS[i]}" "$(meta "${PATHS_P[i]}")"
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
      ((i >= EXTRA_FROM)) && under_earlier "$i" "$abs" && continue
      writable=$((writable + 1))
      ((writable > FINDING_CAP)) && continue
      ww="$rel${abs#"$phys"}"
      emit symlink-permission high "$ww" "world-writable $(meta "$abs")"
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
      if [[ -L "$file" ]]; then
        not_verified "$(esc "${dir:+$dir/}${file##*/}") - the deployment file is a symlink, so the scan does not read it; check it by hand and run the scan again"
        continue
      fi
      [[ -f "$file" ]] || continue
      DEPLOY_REL+=("${dir:+$dir/}${file##*/}")
      DEPLOY_ABS+=("$file")
    done
    shopt -u nullglob
  done <<<"$DEPLOY_FILES"
}

# DEPLOY_AWK reads a deployment file with the served paths in the file it gets first. It prints no line
# of the file, only records, fields separated by a tab:
#   <line> T <token>     a run of path characters that holds a served path followed by a slash, from
#                        that path on; public/ matches anywhere in the run, any other served path only
#                        at the start of the run or after a slash
#   <line> NV            the run goes on with a character that a path cannot be read from (a variable,
#                        a command substitution, a brace list, an escape), so the scan does not know
#                        which file the step writes
#   <line> CD            the line changes into a served path (cd, pushd, chdir, WORKDIR, a working
#                        directory setting), so the paths that follow are relative to it
# shellcheck disable=SC2016  # the awk program is single-quoted on purpose: its $ is awk text, not shell
DEPLOY_AWK='
FNR == NR { if ($0 != "") pfx[++np] = $0; next }
{
  s = $0; len = length(s); i = 1
  while (i <= len) {
    if (substr(s, i, 1) !~ /[A-Za-z0-9._~*\/-]/) { i++; continue }
    j = i
    while (j <= len && substr(s, j, 1) ~ /[A-Za-z0-9._~*\/-]/) j++
    run = substr(s, i, j - i); nxt = substr(s, j, 1); i = j
    pos = index(run, "public/")
    if (!pos) {
      for (k = 1; k <= np; k++) {
        p = pfx[k] "/"; off = 0
        while ((q = index(substr(run, off + 1), p)) > 0) {
          q += off
          if (q == 1 || substr(run, q - 1, 1) == "/") { if (!pos || q < pos) pos = q; break }
          off = q
        }
      }
    }
    if (!pos) continue
    print FNR "\tT\t" substr(run, pos)
    if (nxt != "" && index(" \t\r\"" sq ";&|)<>:,]", nxt) == 0) print FNR "\tNV"
  }
  rest = s
  while (match(rest, /(^|[^A-Za-z0-9_-])(cd|pushd|chdir|WORKDIR|working-directory|working_dir)[ \t=:]+/)) {
    rest = substr(rest, RSTART + RLENGTH)
    after = rest
    while (substr(after, 1, 1) == "\"" || substr(after, 1, 1) == sq) after = substr(after, 2)
    if (substr(after, 1, 2) == "./") after = substr(after, 3)
    j = 1
    while (j <= length(after) && substr(after, j, 1) ~ /[A-Za-z0-9._~*\/-]/) j++
    run = substr(after, 1, j - 1)
    for (k = 1; k <= np; k++) if (run == pfx[k] || index(run, pfx[k] "/") == 1) { print FNR "\tCD"; break }
  }
}
'

# The paths a deployment step names below a served path: public/, storage/app/public/, and every path a
# config entry serves, whether or not the checkout holds it. A token whose file name a name check matches
# is a finding; a step that names a served path and goes on with a form the scan cannot read is a
# not-verified line.
deploy_checks() {
  local file line kind token check i k p size plain
  local -a files=() lines=() tokens=()
  deploy_files
  ((${#DEPLOY_REL[@]})) || return 0
  printf '%s\n' "${SERVED_PREFIX[@]}" >"$SCRATCH/prefixes"
  for p in "${SERVED_PREFIX[@]}"; do
    if [[ "$p" == *[!A-Za-z0-9._~*/-]* ]]; then
      not_verified "$(esc "$p")/ - a served directory whose name holds a character that a deployment path cannot be matched against; check the deployment steps by hand and run the scan again"
    fi
  done
  for ((k = 0; k < ${#DEPLOY_REL[@]}; k++)); do
    file="${DEPLOY_REL[k]}"
    if [[ ! -r "${DEPLOY_ABS[k]}" ]]; then
      not_verified "$(esc "$file") - the deployment file is not readable; give the scan read access and run the scan again"
      continue
    fi
    size="$(wc -c <"${DEPLOY_ABS[k]}" 2>/dev/null | tr -d ' ')"
    plain="$(tr -d '\000' <"${DEPLOY_ABS[k]}" 2>/dev/null | wc -c | tr -d ' ')"
    if [[ ! "$size" =~ ^[0-9]+$ || "$plain" != "$size" ]]; then
      not_verified "$(esc "$file") - the deployment file holds a NUL byte or cannot be measured, so the scan does not read it; check it by hand and run the scan again"
      continue
    fi
    awk -v "sq='" "$DEPLOY_AWK" "$SCRATCH/prefixes" "${DEPLOY_ABS[k]}" >"$SCRATCH/deploy" 2>/dev/null ||
      { not_verified "$(esc "$file") - the deployment file could not be read; run the scan again"; continue; }
    while IFS=$'\t' read -r line kind token; do
      [[ "$line" =~ ^[0-9]+$ ]] || continue
      if [[ "$kind" == CD ]]; then
        not_verified "$(esc "$file") - line $line changes into a served directory, so the paths that follow it are relative to a directory the scan does not match; write the full path of each file the step writes or check the step by hand, and run the scan again"
        continue
      fi
      if [[ "$kind" == NV ]]; then
        not_verified "$(esc "$file") - line $line names a served directory and goes on with a path the scan cannot read (a variable, a command substitution, a brace list, or an escape), so it does not know which file the step writes; write the path out or check the step by hand, and run the scan again"
        continue
      fi
      files+=("$file")
      lines+=("$line")
      tokens+=("$token")
    done <"$SCRATCH/deploy"
  done
  ((${#tokens[@]})) || return 0
  local -a saved_paths=(${PATHS[@]+"${PATHS[@]}"})
  PATHS=("${tokens[@]}")
  classify_shared
  for ((i = 0; i < ${#tokens[@]}; i++)); do
    name_check "${tokens[i]%/}" f "${SHARED[i]}" 0 ''
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
  local pub="$PUB_P"
  disk="$DISK_P"
  for ((i = 0; i < ${#ROOT_FIND[@]}; i++)); do
    [[ "${ROOT_FIND[i]}" == public && -n "$pub" ]] && continue
    [[ "${ROOT_FIND[i]}" == storage/app/public && -n "$disk" ]] && continue
    not_verified "${ROOT_FIND[i]}/ - the scan root is a symlink (${ROOT_FEV[i]}) that the scan does not read; run the static mode for the finding"
  done
  for ((i = 0; i < ${#CFG_FIND[@]}; i++)); do
    not_verified "${CFG_FIND[i]} - a links entry (${CFG_FEV[i]}) that the scan does not read; run the static mode for the finding"
  done
  for rel in ${DISK_EXTRA[@]+"${DISK_EXTRA[@]}"}; do
    not_verified "$(esc "$rel") - a path that config/filesystems.php serves, which the runtime mode does not probe; run the static mode for its files"
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
    git_ro diff --raw -z --no-renames --ignore-submodules=none "$base" HEAD -- >"$SCRATCH/changed" 2>/dev/null ||
      die 3 'the base commit is not in this checkout'
  fi

  printf 'public-files-scan: mode=%s\n' "$mode"
  if [[ ! -L "$ROOT/public" && ! -d "$ROOT/public" ]]; then
    not_verified 'public/ - the root has no public/ directory; run the scan on the root of a Laravel project'
    finish
  fi
  root_checks
  submodule_checks "$base"
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
  st_check 'review: a dump the diff adds is reported' st_line $'dump-archive-backup\tcritical\tpublic/new-dump.sql\t' 'git=tracked'
  st_check 'review: a non-ASCII name that git quotes is reported' st_line $'dump-archive-backup\tcritical\tpublic/z\\xc3\\xa1.sql\t'
  st_check 'review: a name with a quote and a backslash is reported' st_line $'dump-archive-backup\tcritical\tpublic/q"x\\x5cy.zip\t'
  st_check 'review: a name with a tab is reported' st_line $'dump-archive-backup\tcritical\tpublic/t\\x09b.sql\t'
  st_check 'review: a changed deployment file is reported' st_line $'deploy.sh\tline=3 ref=public/fresh.sql'
  st_check 'review: a dump the diff does not touch is reported too' st_line $'dump-archive-backup\tcritical\tpublic/backup.sql\t'
  st_check 'review: a .gitignore finding is reported when no .gitignore changed' st_line $'gitignore-deploy\tmedium\t.gitignore\tprobe=.env ignored=no'
  st_check 'review: the header names the mode and no diff scope' test "$(head -n 1 "$ST_TMP/out.diff")" = 'public-files-scan: mode=static'
  st_run nodiff static --root "$p" --changed-from "$(cd "$p" && git rev-parse HEAD)"
  st_check 'review: an empty diff reports every finding of the scan, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'dump-archive-backup\tcritical\tpublic/backup.sql\t' "$ST_TMP/out.nodiff")"

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
# and the review mode reports what the full scan finds behind a symlink.
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
  st_check 'confine: a dump added behind a symlinked public/ is reported, exit 5' test "$ST_RC" = 5
  st_check 'confine: that dump carries the git status of its git path' st_line $'dump-archive-backup\tcritical\tpublic/backup.sql\t' 'git=tracked'
  st_check 'confine: an .env copy added behind a symlinked public/ is reported' st_line $'env-config-copy\tcritical\tpublic/.env\t'
  st_check 'confine: an unchanged root link that serves a changed file is reported' \
    st_line $'symlink-permission\thigh\tpublic\ttarget=web resolves=web'
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
  st_check 'confine: the directory symlink below public/ that serves an added dump is reported, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tpublic/files\ttarget=../web/files resolves=web/files' "$ST_TMP/out.n1c")"

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
  st_check 'confine: the dump the re-pointed public disk serves is reported' st_line $'dump-archive-backup\tcritical\tstorage/app/public/backup.sql\t'
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

# st_diff_case <name> <label> <needle>: run the review mode from ST_BASE and expect the finding, exit 5.
st_diff_case() {
  st_run "$1" static --root "$ST_TMP/$1" --changed-from "$ST_BASE"
  st_check "chain: $2, exit 5" test "$ST_RC" = 5 -a -n "$(grep -F -- "$3" "$ST_TMP/out.$1")"
}

# st_base <dir>: commit the base of a chain case and keep its object id in ST_BASE.
st_base() {
  st_commit "$1" base
  ST_BASE="$(git -C "$1" rev-parse HEAD)"
}

# The review mode reports every finding of the full static scan, whatever path the change touches: a
# link of a chain, the end of a resolution, or a path a chain of directory links serves. A symlink
# target that ends in a newline is read byte for byte, and every stat reads the physical entry.
st_chain_cases() {
  local r o2 i out1

  # public -> hop -> realpub; the change re-points the middle link hop at the project root, then at config.
  for i in .:. config:config; do
    r="$ST_TMP/chain-${i##*:}"
    mkdir -p "$r/realpub" "$r/config"
    printf '<?php\n' >"$r/realpub/index.php"
    printf '<?php\n' >"$r/config/app.php"
    ln -s hop "$r/public"
    ln -s realpub "$r/hop"
    st_base "$r"
    rm "$r/hop"
    ln -s "${i%%:*}" "$r/hop"
    st_commit "$r" change
    st_diff_case "chain-${i##*:}" "a changed middle link hop -> ${i%%:*} keeps the root finding" \
      $'symlink-permission\thigh\tpublic\ttarget='"${i%%:*} resolves=${i##*:}"
  done

  # public/a -> ../hop2 -> web; the change re-points hop2 out of the project.
  r="$ST_TMP/chain-hop2"
  mkdir -p "$r/public" "$r/web"
  printf '<?php\n' >"$r/public/index.php"
  printf 'x\n' >"$r/web/a.txt"
  ln -s ../hop2 "$r/public/a"
  ln -s web "$r/hop2"
  st_base "$r"
  rm "$r/hop2"
  ln -s ../out "$r/hop2"
  st_commit "$r" change
  st_diff_case chain-hop2 'a changed second link of a link below public/ keeps the link finding' \
    $'symlink-permission\thigh\tpublic/a\ttarget=../hop2 resolves=outside-project'

  # public/latest -> ../database/backup.sql does not resolve; the change adds its target.
  r="$ST_TMP/chain-target"
  mkdir -p "$r/public" "$r/database"
  printf 'x\n' >"$r/database/keep.txt"
  ln -s ../database/backup.sql "$r/public/latest"
  st_base "$r"
  printf 'x\n' >"$r/database/backup.sql"
  st_commit "$r" change
  st_diff_case chain-target 'a file link whose target the change adds is reported' \
    $'symlink-permission\thigh\tpublic/latest\ttarget=../database/backup.sql resolves=database/backup.sql'

  # The same link resolves in the base; the change deletes its target.
  r="$ST_TMP/chain-gone"
  mkdir -p "$r/public" "$r/database"
  printf 'x\n' >"$r/database/keep.txt"
  printf 'x\n' >"$r/database/backup.sql"
  ln -s ../database/backup.sql "$r/public/latest"
  st_base "$r"
  rm "$r/database/backup.sql"
  st_commit "$r" change
  st_diff_case chain-gone 'a file link whose target the change deletes is reported' \
    $'symlink-permission\thigh\tpublic/latest\ttarget=../database/backup.sql resolves=unresolved'

  # public/a -> ../web, web/b -> ../other; the change adds other/dump.sql, served as public/a/b/dump.sql.
  r="$ST_TMP/chain-two"
  mkdir -p "$r/public" "$r/web" "$r/other"
  printf 'x\n' >"$r/other/keep.txt"
  ln -s ../web "$r/public/a"
  ln -s ../other "$r/web/b"
  st_base "$r"
  printf 'x\n' >"$r/other/dump.sql"
  st_commit "$r" change
  st_diff_case chain-two 'the link that serves a dump through a second directory link is reported' \
    $'symlink-permission\thigh\tpublic/a\ttarget=../web resolves=web'

  # public -> dist/public; the change replaces dist/public with a link to the project root.
  r="$ST_TMP/chain-dist"
  mkdir -p "$r/dist/public"
  printf '<?php\n' >"$r/dist/public/index.php"
  ln -s dist/public "$r/public"
  st_base "$r"
  rm -rf "$r/dist/public"
  ln -s .. "$r/dist/public"
  printf 'x\n' >"$r/backup.sql"
  st_commit "$r" change
  st_diff_case chain-dist 'a root link whose target the change replaces with a link is reported' \
    $'symlink-permission\thigh\tpublic\ttarget=.. resolves=.'

  # public/media -> ../media; the change replaces media with a link out of the project.
  r="$ST_TMP/chain-media"
  mkdir -p "$r/public" "$r/media"
  printf 'png\n' >"$r/media/a.png"
  ln -s ../media "$r/public/media"
  st_base "$r"
  rm -rf "${r:?}/media"
  ln -s .. "$r/media"
  st_commit "$r" change
  st_diff_case chain-media 'a link whose target directory the change replaces with a link is reported' \
    $'symlink-permission\thigh\tpublic/media\ttarget=../media resolves=outside-project'

  # .github/workflows -> ../hop -> ci; the change re-points hop out of the project.
  r="$ST_TMP/chain-deploy"
  mkdir -p "$r/public" "$r/.github" "$r/ci"
  printf '<?php\n' >"$r/public/index.php"
  printf 'steps: []\n' >"$r/ci/deploy.yml"
  ln -s ../hop "$r/.github/workflows"
  ln -s ci "$r/hop"
  st_base "$r"
  rm "$r/hop"
  ln -s ../out "$r/hop"
  st_commit "$r" change
  st_diff_case chain-deploy 'a changed link in the chain of a deployment directory keeps its finding' \
    $'symlink-permission\thigh\t.github/workflows\ttarget=../out resolves=outside-project'

  # public/x.sql -> ../hopf -> notes.txt; the change re-points hopf at a dump.
  r="$ST_TMP/chain-file"
  mkdir -p "$r/public"
  printf 'x\n' >"$r/notes.txt"
  printf 'x\n' >"$r/db.sql"
  ln -s ../hopf "$r/public/x.sql"
  ln -s notes.txt "$r/hopf"
  st_base "$r"
  rm "$r/hopf"
  ln -s db.sql "$r/hopf"
  st_commit "$r" change
  st_diff_case chain-file 'a changed link in the chain of a file link keeps its name finding' $'dump-archive-backup\tcritical\tpublic/x.sql\t'
  st_check 'chain: the same change keeps the link finding of that file link' st_line $'symlink-permission\thigh\tpublic/x.sql\ttarget=../hopf resolves=db.sql'

  # M2: the change touches no public path and no path that serves the unchanged root link public -> web.
  r="$ST_TMP/chain-quiet"
  mkdir -p "$r/web" "$r/docs"
  printf '<?php\n' >"$r/web/index.php"
  ln -s web "$r/public"
  st_base "$r"
  printf 'x\n' >"$r/docs/notes.txt"
  st_commit "$r" change
  st_diff_case chain-quiet 'a change outside every public path still reports the existing root link' \
    $'symlink-permission\thigh\tpublic\ttarget=web resolves=web'

  # M1 Q1 to Q4: a link through d1/.. resolves only once d1 is a directory; the change adds d1/keep.txt
  # (Q2: turns the file d1 into a directory) and changes no symlink.
  for i in q1 q2 q3 q4; do
    r="$ST_TMP/chain-$i"
    mkdir -p "$r/public" "$r/web" "$r/secrets"
    printf '<?php\n' >"$r/public/index.php"
    printf 'x\n' >"$r/db.sql"
    printf 'x\n' >"$r/secrets/dump.sql"
    printf 'x\n' >"$r/web/readme.txt"
    case "$i" in
    q1 | q2) ln -s ../d1/../db.sql "$r/public/x.sql" ;;
    q3) ln -s ../web "$r/public/a" && ln -s ../d1/../db.sql "$r/web/f.sql" ;;
    q4) ln -s ../d1/../secrets "$r/public/a" ;;
    esac
    [[ "$i" == q2 ]] && printf 'f\n' >"$r/d1"
    st_base "$r"
    rm -f "$r/d1"
    mkdir -p "$r/d1"
    printf 'k\n' >"$r/d1/keep.txt"
    st_commit "$r" change
    case "$i" in
    q1 | q2) st_diff_case "chain-$i" "M1 ${i}: a dump link made live through d1/.. is reported" $'dump-archive-backup\tcritical\tpublic/x.sql\t'
      st_check "chain: M1 ${i}: the same link is a symlink-permission finding" \
        st_line $'symlink-permission\thigh\tpublic/x.sql\ttarget=../d1/../db.sql resolves=db.sql' ;;
    q3) st_diff_case "chain-$i" 'M1 q3: the start link of a nested link made live through d1/.. is reported' \
      $'symlink-permission\thigh\tpublic/a\ttarget=../web resolves=web' ;;
    q4) st_diff_case "chain-$i" 'M1 q4: a directory link made live through d1/.. is reported' \
      $'symlink-permission\thigh\tpublic/a\ttarget=../d1/../secrets resolves=secrets' ;;
    esac
  done

  # A link target that ends in a newline: web<LF> and dir<LF> lead out of the project.
  o2="$ST_TMP/outside-nl"
  mkdir -p "$o2"
  printf '%12346s\n' x >"$o2/id_rsa"
  printf 'x\n' >"$o2/present.sql"
  r="$ST_TMP/nl-root"
  mkdir -p "$r/web"
  printf '<?php\n' >"$r/web/index.php"
  st_base "$r"
  printf 'k\n' >"$r/web/id_rsa"
  printf 'x\n' >"$r/web/present.sql"
  printf 'x\n' >"$r/web/absent.sql"
  ln -s "$o2" "$r/web"$'\n'
  ln -s web$'\n' "$r/public"
  st_commit "$r" change
  for i in diff tree; do
    if [[ "$i" == diff ]]; then st_run nl-root static --root "$r" --changed-from "$ST_BASE"; else st_run nl-root static --root "$r"; fi
    st_check "newline $i: public -> web<LF> -> outside is one outside-project finding, exit 5" \
      test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tpublic\ttarget='"$o2"' resolves=outside-project' "$ST_TMP/out.nl-root")"
    st_check_not "newline $i: no line carries metadata of a file outside the project" st_line 'size=12347'
    st_check_not "newline $i: no file below the outside root is listed" st_line $'\tpublic/'
  done

  # A project directory web<LF> and no web: the evidence names the entry the kernel follows.
  r="$ST_TMP/nl-inside"
  mkdir -p "$r/web"$'\n'
  printf 'x\n' >"$r/web"$'\n'/dump.sql
  ln -s web$'\n' "$r/public"
  st_commit "$r" base
  st_run nl-inside static --root "$r"
  st_check 'newline: public -> web<LF> inside the project names the raw target and its end, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tpublic\ttarget=web\\x0a resolves=web\\x0a' "$ST_TMP/out.nl-inside")"
  st_check 'newline: the dump below web<LF> is reported under public/' st_line $'dump-archive-backup\tcritical\tpublic/dump.sql\t' 'git=tracked'

  r="$ST_TMP/nl-deploy"
  mkdir -p "$r/public" "$r/.github" "$r/ci"
  printf '<?php\n' >"$r/public/index.php"
  printf 'run: cp x public/a.sql\n' >"$r/ci/deploy.yml"
  ln -s "$o2" "$r/ci"$'\n'
  ln -s ../ci$'\n' "$r/.github/workflows"
  st_commit "$r" base
  st_run nl-deploy static --root "$r"
  st_check 'newline: a deployment directory -> ../ci<LF> -> outside is an outside-project finding' \
    st_line $'symlink-permission\thigh\t.github/workflows\ttarget='"$o2"' resolves=outside-project'
  st_check_not 'newline: the deployment file of the look-alike directory is not read' st_line 'ref=public/a.sql'

  r="$ST_TMP/nl-link"
  mkdir -p "$r/public" "$r/dir"
  printf '<?php\n' >"$r/public/index.php"
  st_base "$r"
  printf 'k\n' >"$r/dir/id_rsa"
  printf 'x\n' >"$r/dir/present.sql"
  ln -s "$o2" "$r/dir"$'\n'
  ln -s ../dir$'\n' "$r/public/lnk"
  st_commit "$r" change
  st_run nl-link static --root "$r" --changed-from "$ST_BASE"
  out1="$ST_OUT"
  st_check 'newline: public/lnk -> ../dir<LF> -> outside is an outside-project finding with the raw target, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tpublic/lnk\ttarget=../dir\\x0a resolves=outside-project' "$ST_TMP/out.nl-link")"
  st_check_not 'newline: no line carries metadata of a file outside the project' st_line 'size=12347'
  st_check_not 'newline: no file served through the outside link is listed' st_line $'\tpublic/lnk/'
  rm "$o2/present.sql"
  st_run nl-link static --root "$r" --changed-from "$ST_BASE"
  st_check 'newline: the output is the same whether the outside file exists or not' test "$out1" = "$ST_OUT"
}

# st_served <name> <start link> <target dir>: a project whose unchanged scan-root link public/<start
# link> -> ../<target dir> serves a project directory; the case builds the rest and calls st_base.
st_served() {
  r="$ST_TMP/$1"
  mkdir -p "$r/public" "$r/$3"
  printf '<?php\n' >"$r/public/index.php"
  ln -s "../$3" "$r/public/$2"
}

# A link below a served directory: whatever the change re-points, adds, or edits below it, the review
# reports the scan-root link that serves the directory (leonardo P1 to P6, splinter a to e).
st_served_cases() {
  local r i link target chg name

  # public/a -> ../web, web/b -> ../hop, hop -> data; the change re-points hop to config, the project
  # root, then out.
  for i in config:p1 .:p2 ../out:p3; do
    st_served "served-${i##*:}" a web
    mkdir -p "$r/data" "$r/config"
    printf 'x\n' >"$r/data/a.txt"
    printf '<?php\n' >"$r/config/app.php"
    ln -s ../hop "$r/web/b"
    ln -s data "$r/hop"
    st_base "$r"
    rm "$r/hop"
    ln -s "${i%%:*}" "$r/hop"
    st_commit "$r" change
    st_diff_case "served-${i##*:}" "a changed middle link hop -> ${i%%:*} of a nested link reports the scan" \
      $'symlink-permission\thigh\tpublic/a\ttarget=../web resolves=web'
  done

  # P5: web/f.txt -> ../hopf -> notes.txt; the change re-points hopf at a dump.
  st_served served-p5 a web
  printf 'x\n' >"$r/notes.txt"
  printf 'x\n' >"$r/db.sql"
  ln -s ../hopf "$r/web/f.txt"
  ln -s notes.txt "$r/hopf"
  st_base "$r"
  rm "$r/hopf"
  ln -s db.sql "$r/hopf"
  st_commit "$r" change
  st_diff_case served-p5 'a re-pointed link of a nested file-link chain reports the scan' \
    $'symlink-permission\thigh\tpublic/a\ttarget=../web resolves=web'

  # (a) and (c): media/robots.txt -> ../hopf -> db/seed; the change re-points hopf at .env, then outside.
  for i in .env:a "$ST_TMP/outside-served/seed":c; do
    st_served "served-${i##*:}" media media
    mkdir -p "$r/db"
    printf 'x\n' >"$r/db/seed"
    ln -s ../hopf "$r/media/robots.txt"
    ln -s db/seed "$r/hopf"
    st_base "$r"
    rm "$r/hopf"
    ln -s "${i%%:*}" "$r/hopf"
    st_commit "$r" change
    st_diff_case "served-${i##*:}" "a nested robots.txt link re-pointed through hopf (${i##*:}) reports the scan" \
      $'symlink-permission\thigh\tpublic/media\ttarget=../media resolves=media'
  done

  # (b): media/sub -> ../hopd -> d1; the change re-points hopd at d2, which already holds a dump.
  st_served served-b media media
  mkdir -p "$r/d1" "$r/d2"
  printf 'x\n' >"$r/d1/a.txt"
  printf 'x\n' >"$r/d2/dump.sql"
  ln -s ../hopd "$r/media/sub"
  ln -s d1 "$r/hopd"
  st_base "$r"
  rm "$r/hopd"
  ln -s d2 "$r/hopd"
  st_commit "$r" change
  st_diff_case served-b 'a nested directory link re-pointed at a directory with a dump reports the scan' \
    $'symlink-permission\thigh\tpublic/media\ttarget=../media resolves=media'

  # P6: web/m -> ../media; the change replaces media with a link to the project root.
  st_served served-p6 a web
  mkdir -p "$r/media"
  printf 'png\n' >"$r/media/a.png"
  ln -s ../media "$r/web/m"
  st_base "$r"
  rm -rf "${r:?}/media"
  ln -s . "$r/media"
  st_commit "$r" change
  st_diff_case served-p6 'a nested target directory replaced by a link to the project root reports the scan' \
    $'symlink-permission\thigh\tpublic/a\ttarget=../web resolves=web'

  # P4 and (d): a dangling nested file link whose target the change adds; (e): its target changes.
  for i in other/real.sql:p4 db/dump:d db/seed:e; do
    st_served "served-${i##*:}" media media
    mkdir -p "$r/other" "$r/db"
    printf 'x\n' >"$r/other/keep.txt"
    printf 'x\n' >"$r/db/keep.txt"
    [[ "${i##*:}" == e ]] && printf 'x\n' >"$r/${i%%:*}"
    ln -s "../${i%%:*}" "$r/media/x.sql"
    st_base "$r"
    printf 'y\n' >"$r/${i%%:*}"
    st_commit "$r" change
    st_diff_case "served-${i##*:}" "a nested file link whose target ${i%%:*} the change adds or edits reports the scan" \
      $'symlink-permission\thigh\tpublic/media\ttarget=../media resolves=media'
  done

  # Attacks: a two-link nested file chain whose end the change edits; a nested link whose missing
  # directory the change adds; a link three directories below the served target.
  for i in x.sql:../hopf:db/seed:two media/x.sql:../newdir/f.sql:newdir/f.sql:miss \
    deep/er/x.sql:../../../db/dump:db/dump:deep; do
    IFS=: read -r link target chg name <<<"$i"
    st_served "served-$name" media media
    mkdir -p "$r/db" "$r/media/deep/er"
    printf 'x\n' >"$r/db/seed"
    ln -s db/seed "$r/hopf"
    ln -s "$target" "$r/media/${link#media/}"
    st_base "$r"
    mkdir -p "$r/${chg%/*}"
    printf 'y\n' >"$r/$chg"
    st_commit "$r" change
    st_diff_case "served-$name" "a nested file link served through ${target} reports the scan when ${chg} changes" \
      $'symlink-permission\thigh\tpublic/media\ttarget=../media resolves=media'
  done

  # Attack: the change adds a symlink whose name ends in a newline; the raw diff record still reads it.
  st_served served-nl media media
  mkdir -p "$r/docs"
  printf 'x\n' >"$r/docs/notes.txt"
  st_base "$r"
  ln -s notes.txt "$r/docs/l"$'\n'
  st_commit "$r" change
  st_diff_case served-nl 'an added symlink whose name ends in a newline reports the scan' \
    $'symlink-permission\thigh\tpublic/media\ttarget=../media resolves=media'

  # M2: the change touches no symlink, no public path, and no path that serves the nested link.
  st_served served-quiet media media
  mkdir -p "$r/db" "$r/docs"
  printf 'x\n' >"$r/db/seed"
  ln -s ../db/seed "$r/media/x.sql"
  st_base "$r"
  printf 'x\n' >"$r/docs/notes.txt"
  st_commit "$r" change
  st_diff_case served-quiet 'a change to no symlink and no serving path still reports the existing link' \
    $'symlink-permission\thigh\tpublic/media\ttarget=../media resolves=media'
}

# st_gitlink <dir> <path> <object id>: record a git submodule (a gitlink) in the index of the fixture,
# with the empty directory that a checkout without the submodule leaves.
st_gitlink() {
  mkdir -p "$1/$2"
  git -C "$1" update-index --add --cacheinfo "160000,$3,$2"
}

# st_clean <name>: a Laravel layout in r with no finding; its .gitignore ignores every probe.
st_clean() {
  r="$ST_TMP/$1"
  mkdir -p "$r/public" "$r/storage/app/public"
  printf '<?php\n' >"$r/public/index.php"
  printf '%s\n' "$GITIGNORE_PROBES" >"$r/.gitignore"
  (cd "$r" && git init -q) >/dev/null 2>&1
}

# S6: a git submodule is never read, so it is a not-verified line and the scan never exits 0 over it.
st_submodule_cases() {
  local r

  # The change adds a submodule under public/.
  st_clean sub-add
  st_base "$r"
  st_gitlink "$r" public/vendor 3333333333333333333333333333333333333333
  st_commit "$r" change
  st_run sub-add static --root "$r" --changed-from "$ST_BASE"
  st_check 'submodule: a submodule the change adds under public/ is a not-verified line, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: public/vendor - the change adds, removes, or updates a git submodule' "$ST_TMP/out.sub-add")"
  st_check 'submodule: that submodule is named once' test "$(grep -c -F 'public/vendor' "$ST_TMP/out.sub-add")" = 1
  st_run sub-add-tree static --root "$r"
  st_check 'submodule: the static mode reports a submodule under public/ that is not checked out, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: public/vendor - a git submodule that is not checked out' "$ST_TMP/out.sub-add-tree")"
  printf 'gitdir: elsewhere\n' >"$r/public/vendor/.git"
  st_run sub-add-tree static --root "$r"
  st_check 'submodule: a submodule under public/ that is checked out is read, so its .git is a finding, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'env-config-copy\tcritical\tpublic/vendor/.git\t' "$ST_TMP/out.sub-add-tree")"

  # The change moves a submodule outside the scan roots to another commit, and the .gitmodules of the
  # branch sets ignore = all for it.
  st_clean sub-bump
  st_gitlink "$r" modules/theme 1111111111111111111111111111111111111111
  st_base "$r"
  git -C "$r" update-index --cacheinfo 160000,2222222222222222222222222222222222222222,modules/theme
  printf '[submodule "theme"]\n\tpath = modules/theme\n\turl = ../theme\n\tignore = all\n' >"$r/.gitmodules"
  st_commit "$r" change
  st_run sub-bump static --root "$r" --changed-from "$ST_BASE"
  st_check 'submodule: a submodule bump that .gitmodules marks ignore = all is a not-verified line, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: modules/theme - the change adds, removes, or updates a git submodule' "$ST_TMP/out.sub-bump")"
  st_run sub-bump-tree static --root "$r"
  st_check 'submodule: an unchanged submodule outside every scan root and deployment directory is no line, exit 0' test "$ST_RC" = 0

  # A submodule above a scan root that is not checked out hides the public disk.
  st_clean sub-above
  rm -rf "${r:?}/storage"
  st_gitlink "$r" storage 4444444444444444444444444444444444444444
  st_commit "$r" base
  st_run sub-above static --root "$r"
  st_check 'submodule: a submodule above storage/app/public that is not checked out is a not-verified line, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: storage - a git submodule that is not checked out' "$ST_TMP/out.sub-above")"
}

# st_links <name> <links entries>: a clean project whose config/filesystems.php declares the links.
st_links() {
  st_clean "$1"
  mkdir -p "$r/config"
  printf "<?php\n\nreturn [\n    'disks' => [],\n    'links' => [\n%s\n    ],\n];\n" "$2" >"$r/config/filesystems.php"
}

# S7: a directory that config/filesystems.php links is served by `php artisan storage:link`, so the
# scan reads every links entry in every run, whatever the change touches, and scans each target it can
# read as it scans storage/app/public. An entry it cannot read is a not-verified line.
st_links_cases() {
  local r o="$ST_TMP/links-outside" i
  mkdir -p "$o"
  printf '%s\n' "$ST_MARKER" >"$o/hostonly-dump.sql"

  # The reproduction: the config is unchanged, and the change adds a dump to the linked directory.
  st_links links-dl "        public_path('downloads') => storage_path('app/downloads'),"
  mkdir -p "$r/storage/app/downloads"
  printf 'x\n' >"$r/storage/app/downloads/readme.txt"
  st_base "$r"
  printf '%s\n' "$ST_MARKER" >"$r/storage/app/downloads/db-backup.sql"
  st_commit "$r" change
  st_run links-dl static --root "$r" --changed-from "$ST_BASE"
  st_check 'links: a dump added to a linked directory of an unchanged config is a critical finding, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'finding\tdump-archive-backup\tcritical\tstorage/app/downloads/db-backup.sql\t' "$ST_TMP/out.links-dl")"
  st_check_not 'links: a clean file of a linked directory is no finding' st_line 'readme.txt'
  st_run links-dl-tree static --root "$r"
  st_check 'links: the static mode reports the same dump, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'dump-archive-backup\tcritical\tstorage/app/downloads/db-backup.sql\t' "$ST_TMP/out.links-dl-tree")"
  st_check_not 'links: the content of the dump is never printed' st_line "$ST_MARKER"

  # The same checks as storage/app/public: a script and an execute bit.
  printf '<?php\n' >"$r/storage/app/downloads/shell.php"
  printf 'x\n' >"$r/storage/app/downloads/run"
  chmod 755 "$r/storage/app/downloads/run"
  st_run links-dl-script static --root "$r"
  st_check 'links: a PHP file of a linked directory is a public-storage-script finding' \
    st_line $'finding\tpublic-storage-script\thigh\tstorage/app/downloads/shell.php\t'
  st_check 'links: an execute bit in a linked directory is a public-storage-script finding' \
    st_line $'public-storage-script\thigh\tstorage/app/downloads/run\t'

  # A clean linked directory leaves the scan clean.
  st_links links-clean "        public_path('downloads') => storage_path('app/downloads'),"
  mkdir -p "$r/storage/app/downloads"
  printf 'x\n' >"$r/storage/app/downloads/readme.txt"
  st_commit "$r" base
  st_run links-clean static --root "$r"
  st_check 'links: a clean linked directory is no finding, exit 0' test "$ST_RC" = 0

  # Every helper, the long array syntax, a double-quoted literal, a leading backslash, comments, and a
  # trailing comma are read; a commented entry is not.
  st_links links-forms "        // public_path('old') => storage_path('app/old'),
        /* public_path('older') => storage_path('app/older'), */
        public_path('a') => \\storage_path(\"app/a\"),
        public_path('b') => base_path('files/b'),
        public_path('c') => app_path('c'),
        public_path('d') => resource_path('d'),
        public_path('e') => database_path('e'),
        public_path('f') =>
            storage_path('app/f'),"
  mkdir -p "$r/storage/app/old" "$r/storage/app/older" "$r/storage/app/a" "$r/files/b" "$r/app/c" "$r/resources/d" "$r/database/e" "$r/storage/app/f"
  for i in storage/app/old storage/app/older storage/app/a files/b app/c resources/d database/e storage/app/f; do
    printf 'x\n' >"$r/$i/dump.sql"
  done
  st_commit "$r" base
  st_run links-forms static --root "$r"
  for i in storage/app/a files/b app/c resources/d database/e storage/app/f; do
    st_check "links: the entry that reads $i is scanned" st_line $'dump-archive-backup\tcritical\t'"$i/dump.sql"
  done
  st_check_not 'links: an entry in a line comment is not scanned' st_line 'storage/app/old/'
  st_check_not 'links: an entry in a block comment is not scanned' st_line 'storage/app/older/'
  st_check 'links: every readable entry leaves a complete scan, exit 5' test "$ST_RC" = 5

  st_clean links-array
  mkdir -p "$r/config" "$r/storage/app/dl"
  printf "<?php\nreturn array('links' => array(public_path('dl') => storage_path('app/dl')));\n" >"$r/config/filesystems.php"
  printf 'x\n' >"$r/storage/app/dl/a.sql"
  st_commit "$r" base
  st_run links-array static --root "$r"
  st_check 'links: the array() syntax is read' st_line $'dump-archive-backup\tcritical\tstorage/app/dl/a.sql\t'

  # A target that does not exist serves nothing.
  st_links links-missing "        public_path('dl') => storage_path('app/missing'),"
  st_commit "$r" base
  st_run links-missing static --root "$r"
  st_check 'links: a target that does not exist is no line, exit 0' test "$ST_RC" = 0

  # A target the scan already scans is read once.
  st_links links-dup "        public_path('storage') => storage_path('app/public'),
        public_path('again') => storage_path('app/public/sub'),
        public_path('up') => storage_path('app'),"
  mkdir -p "$r/storage/app/public/sub"
  printf '<?php\n' >"$r/storage/app/public/sub/shell.php"
  printf '<?php\n' >"$r/storage/app/public/shell.php"
  printf '<?php\n' >"$r/storage/app/other.php"
  st_commit "$r" base
  st_run links-dup static --root "$r"
  st_check 'links: a file of the public disk is reported once however many entries serve it' \
    test "$(grep -c -F $'\tstorage/app/public/shell.php\t' "$ST_TMP/out.links-dup")" = 1
  st_check 'links: a file below a directory above the public disk is reported' st_line $'public-storage-script\thigh\tstorage/app/other.php\t'

  # An entry the scan cannot read statically is a not-verified line, exit 3, and no environment value is read.
  local want
  # shellcheck disable=SC2016  # the entries are PHP text, never expanded
  for i in "env('DL_DIR')" "env('DL_DIR', storage_path('app/dl'))" '$dir' "config('app.dl')" "storage_path('app') . '/dl'" \
    "storage_path(\$name)" "storage_path(\"app/\$name\")" "'storage/app/dl'" "base_path(env('DL_DIR'))"; do
    st_links links-nv "        public_path('ok') => storage_path('app/ok'),
        public_path('dl') => $i,"
    mkdir -p "$r/storage/app/ok"
    printf '%s\n' "$ST_MARKER" >"$r/storage/app/ok/dump.sql"
    st_commit "$r" base
    st_run links-nv static --root "$r"
    st_check "links: the entry $i is a not-verified line on its own line, exit 3" \
      test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: config/filesystems.php - the links entry at line 7' "$ST_TMP/out.links-nv")"
    st_check "links: the entry $i leaves the readable entry scanned" st_line $'dump-archive-backup\tcritical\tstorage/app/ok/dump.sql\t'
    st_check_not "links: the entry $i is never echoed into the output" \
      test -n "$(grep -F -e DL_DIR -e '$name' -e '$dir' -e app.dl "$ST_TMP/out.links-nv")"
  done
  st_clean links-var
  mkdir -p "$r/config"
  printf "<?php\nreturn ['links' => \$links];\n" >"$r/config/filesystems.php"
  st_commit "$r" base
  st_run links-var static --root "$r"
  st_check 'links: a links key that is not an array literal is a not-verified line, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: config/filesystems.php - the links entry at line 2' "$ST_TMP/out.links-var")"

  # A target that is the project root, or leaves the project, is a finding on the config line and is never read.
  st_links links-root "        public_path('all') => base_path(),"
  st_commit "$r" base
  st_run links-root static --root "$r"
  st_check 'links: a target that is the project root is a symlink-permission finding on the config, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tconfig/filesystems.php\tline=6 links target=. ' "$ST_TMP/out.links-root")"
  st_links links-out "        public_path('up') => base_path('../links-outside'),"
  st_commit "$r" base
  st_run links-out static --root "$r"
  st_check 'links: a target that leaves the project is a finding that never says whether it exists, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tconfig/filesystems.php\tline=6 links target=outside-project' "$ST_TMP/out.links-out")"
  st_check_not 'links: nothing outside the project is read for a target that leaves it' st_line 'hostonly-dump'

  # A target that is a symlink is decided like a scan root.
  st_links links-sym "        public_path('dl') => storage_path('app/dl'),"
  mkdir -p "$r/storage/app"
  ln -s "$o" "$r/storage/app/dl"
  st_commit "$r" base
  st_run links-sym static --root "$r"
  st_check 'links: a target symlink that leaves the project is a finding on the target, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tstorage/app/dl\ttarget=' "$ST_TMP/out.links-sym")" -a -n "$(grep -F 'resolves=outside-project' "$ST_TMP/out.links-sym")"
  st_check_not 'links: nothing behind a target symlink that leaves the project is read' st_line 'hostonly-dump'

  # Other declarations of a served directory that the scan cannot read are not-verified lines.
  st_clean links-public-path
  mkdir -p "$r/bootstrap" "$r/app/Providers"
  printf "<?php\nreturn Application::configure()->create()->usePublicPath(base_path('web'));\n" >"$r/bootstrap/app.php"
  printf "<?php\nconfig(['filesystems.links' => []]);\n" >"$r/app/Providers/AppServiceProvider.php"
  st_commit "$r" base
  st_run links-public-path static --root "$r"
  st_check 'links: a custom public path in bootstrap/app.php is a not-verified line, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: bootstrap/app.php - the file sets the public path' "$ST_TMP/out.links-public-path")"
  st_check 'links: a provider that sets filesystems.links is a not-verified line' \
    st_line 'not-verified: app/Providers/AppServiceProvider.php - the file sets the filesystems links'

  # A config that is a symlink leaving the project is not read.
  st_clean links-cfg-sym
  mkdir -p "$r/config"
  printf "<?php\nreturn ['links' => [public_path('x') => storage_path('app/x')]];\n" >"$o/filesystems.php"
  ln -s "$o/filesystems.php" "$r/config/filesystems.php"
  st_commit "$r" base
  st_run links-cfg-sym static --root "$r"
  st_check 'links: a config symlink that leaves the project is a not-verified line, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: config/filesystems.php - ' "$ST_TMP/out.links-cfg-sym")"

  # The runtime mode does not probe a linked directory and says so.
  st_curl_stub
  st_links links-rt "        public_path('dl') => storage_path('app/dl'),"
  mkdir -p "$r/storage/app/dl"
  st_commit "$r" base
  st_run links-rt runtime --root "$r" --base-url http://localhost
  st_check 'links: the runtime mode reports a linked directory it does not probe, exit 3' \
    test "$ST_RC" = 3 -a -n "$(grep -F 'not-verified: storage/app/dl - a path that config/filesystems.php serves' "$ST_TMP/out.links-rt")"
}

# st_serve <name> <config text>: a clean project that holds a committed dump in storage/app/dl and the
# given config/filesystems.php, scanned in static mode into out.<name>. The config text is written as is.
st_serve() {
  st_clean "$1"
  mkdir -p "$r/config" "$r/storage/app/dl"
  printf '%s\n' "$ST_MARKER" >"$r/storage/app/dl/db.sql"
  printf '%s' "$2" >"$r/config/filesystems.php"
  st_commit "$r" base
  st_run "$1" static --root "$r"
}

# st_nv <label> <run name> <needle>: the scan is incomplete (exit 3) and one not-verified line carries the needle.
st_nv() {
  st_check "$1" test "$ST_RC" = 3 -a -n "$(grep -F "not-verified: $3" "$ST_TMP/out.$2")"
}

# st_rebind <name> <statements> [<links target>]: a project whose config/filesystems.php holds the statements on
# line 2, before a return that links public/dl to the target (storage_path('app/dl') when none is given). The
# directory storage/app/dl does not exist, and database/dumps holds a dump: it is the directory that PHP serves
# when the statements rebind the helper, so the scan reads the project clean unless it reports the statements.
st_rebind() {
  st_clean "$1"
  mkdir -p "$r/config" "$r/database/dumps"
  printf '%s\n' "$ST_MARKER" >"$r/database/dumps/db.sql"
  printf "<?php\n%s\nreturn ['links' => [public_path('dl') => %s]];\n" "$2" "${3:-storage_path('app/dl')}" >"$r/config/filesystems.php"
  st_commit "$r" base
  st_run "$1" static --root "$r"
}

# Round 7: the scan reads project-controlled input (the PHP of config/filesystems.php, the deployment
# files, the files that move a framework path), so a form it cannot read with certainty is a
# not-verified line, never a clean result. The cases that follow fail on the code of round 7.
st_failclosed_cases() {
  local r o="$ST_TMP/fc-outside" L i spec disk
  L="public_path('dl') => storage_path('app/dl')"
  mkdir -p "$o"
  printf '%s\n' "$ST_MARKER" >"$o/hostonly-dump.sql"

  # S8: a symlinked config/filesystems.php is never opened, so no .env* file is read through it.
  st_clean cfg-env
  mkdir -p "$r/config"
  printf "APP_KEY=\nA=1\nB=2\nLINKS='links' => [public_path('x') => storage_path('app/FIXTURE_SECRET_9f3')]\n" >"$r/.env"
  mkdir -p "$r/storage/app/FIXTURE_SECRET_9f3"
  printf 'x\n' >"$r/storage/app/FIXTURE_SECRET_9f3/d.sql"
  ln -s ../.env "$r/config/filesystems.php"
  st_commit "$r" base
  st_run cfg-env static --root "$r"
  st_nv 's8: a config symlink to .env is a not-verified line, exit 3' cfg-env 'config/filesystems.php - the file is a symlink'
  st_check_not 's8: no text of .env reaches the output' st_line FIXTURE_SECRET
  st_clean cfg-real
  mkdir -p "$r/config"
  printf "<?php\nreturn ['links' => []];\n" >"$r/config/real.php"
  ln -s real.php "$r/config/filesystems.php"
  st_commit "$r" base
  st_run cfg-real static --root "$r"
  st_nv 's8: a config symlink to a file of the project is a not-verified line too' cfg-real 'config/filesystems.php - the file is a symlink'
  st_clean cfg-dir
  mkdir -p "$r/cfg"
  printf "<?php\nreturn ['links' => []];\n" >"$r/cfg/filesystems.php"
  ln -s cfg "$r/config"
  st_commit "$r" base
  st_run cfg-dir static --root "$r"
  st_nv 's8: a symlinked config directory is a not-verified line' cfg-dir 'config/filesystems.php - '

  # S9: a PHP form the lexer does not model is a not-verified line.
  st_serve fc-assign "<?php
\$c = [];
\$c['links'] = [$L];
return \$c;
"
  st_nv 's9: links assigned to a variable before the return is a not-verified line' fc-assign 'config/filesystems.php - '
  st_serve fc-tag "<?php // x ?><?php return ['links' => [$L]];
"
  st_nv 's9: an end tag inside a line comment is a not-verified line' fc-tag 'config/filesystems.php - '
  st_serve fc-escape "<?php return ['links' => [public_path('dl') => storage_path(\"app/\\x64l\")]];
"
  st_nv 's9: an escape in a double-quoted path is a not-verified line' fc-escape 'config/filesystems.php - '
  st_serve fc-heredoc "<?php
return ['note' => <<<EOT
it's
EOT, 'links' => [$L]];
"
  st_nv 's9: a heredoc is a not-verified line' fc-heredoc 'config/filesystems.php - '
  st_serve fc-nowdoc "<?php return ['note' => <<<'EOT'
x
EOT, 'links' => [$L]];
"
  st_nv 's9: a nowdoc is a not-verified line' fc-nowdoc 'config/filesystems.php - '
  st_serve fc-backtick "<?php return ['note' => \`ls\`, 'links' => [$L]];
"
  st_nv 's9: a backtick is a not-verified line' fc-backtick 'config/filesystems.php - '
  st_serve fc-key "<?php return ['li' . 'nks' => [$L]];
"
  st_nv 's9: a computed key is a not-verified line' fc-key 'config/filesystems.php - '
  st_serve fc-keyesc "<?php return [\"lin\\x6bs\" => [$L]];
"
  st_nv 's9: a key with an escape is a not-verified line' fc-keyesc 'config/filesystems.php - '
  st_serve fc-union "<?php return ['a' => 1] + ['links' => [$L]];
"
  st_nv 's9: an operator after the returned array is a not-verified line' fc-union 'config/filesystems.php - '
  st_serve fc-merge "<?php return array_merge(['a' => 1], ['links' => [$L]]);
"
  st_nv 's9: a returned call is a not-verified line' fc-merge 'config/filesystems.php - '
  st_serve fc-spread "<?php \$base = [];
return [...\$base, 'links' => [$L]];
"
  st_nv 's9: code before the return and a spread are a not-verified line' fc-spread 'config/filesystems.php - '
  st_serve fc-after "<?php return ['links' => [$L]]; echo 1;
"
  st_nv 's9: code after the returned array is a not-verified line' fc-after 'config/filesystems.php - '
  st_serve fc-close "<?php return ['links' => [$L]];
?>
"
  st_nv 's9: a closing tag is a not-verified line' fc-close 'config/filesystems.php - '
  st_serve fc-pre "x
<?php return ['links' => [$L]];
"
  st_nv 's9: text before the open tag is a not-verified line' fc-pre 'config/filesystems.php - '
  st_serve fc-cr "$(printf '<?php\rreturn [%s];\r' "'links' => [$L]")"
  st_nv 's9: a carriage return that no line feed follows is a not-verified line' fc-cr 'config/filesystems.php - '
  st_serve fc-open "<?php return ['links' => [$L], 'x' => 'abc];
"
  st_nv 's9: an unterminated string is a not-verified line' fc-open 'config/filesystems.php - '
  st_serve fc-comment "<?php return ['links' => [$L]]; /* open
"
  st_nv 's9: an unterminated comment is a not-verified line' fc-comment 'config/filesystems.php - '
  st_serve fc-nul "<?php return ['links' => [$L]];
"
  printf '<?php return [%s];\n\0\n' "'links' => [$L]" >"$r/config/filesystems.php"
  st_commit "$r" base
  st_run fc-nul static --root "$r"
  st_nv 's9: a NUL byte is a not-verified line' fc-nul 'config/filesystems.php - '
  st_serve fc-attr "<?php #[Attr]
return ['links' => [$L]];
"
  st_nv 's9: an attribute is a not-verified line' fc-attr 'config/filesystems.php - '

  # Forms the scan reads: the stock file, a preface of use and declare, and every comment form.
  st_clean fc-stock
  mkdir -p "$r/config"
  printf '%s' "<?php

declare(strict_types=1);

use Illuminate\\Support\\Str;

/*
| Laravel's stock file: it's a comment with an apostrophe and a ?> inside a block.
*/
return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        // it's a line comment
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
        ],
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],
    ],
    # a hash comment
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
" >"$r/config/filesystems.php"
  st_commit "$r" base
  st_run fc-stock static --root "$r"
  st_check 'stock: a stock Laravel filesystems file is read with no line, exit 0' test "$ST_RC" = 0

  # S13: a statement before the return that changes how a name inside the array resolves. PHP then
  # calls another function than the global helper, so storage:link serves a directory the scan does
  # not read (here database/dumps, behind storage_path('app/dl')). The scan reads no other file, so
  # the statement is a not-verified line that names its line, never a clean result.
  st_rebind s13-ns 'namespace App\Support;'
  st_nv 's13: a namespace statement is a not-verified line on its line, exit 3' s13-ns 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-nsb 'namespace App\Support { }'
  st_nv 's13: a bracketed namespace statement is a not-verified line, exit 3' s13-nsb 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-alias 'use function base_path as storage_path;'
  st_nv 's13: a use function alias of a global helper is a not-verified line, exit 3' s13-alias 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-fn 'use function App\Support\dumps_dir as storage_path;'
  st_nv 's13: a use function alias of a namespaced function is a not-verified line, exit 3' s13-fn 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-fnplain 'use function App\Support\storage_path;'
  st_nv 's13: a use function import with no alias is a not-verified line, exit 3' s13-fnplain 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-group 'use App\Support\{function dumps_dir as storage_path};'
  st_nv 's13: a group use with a function member is a not-verified line, exit 3' s13-group 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-groupmix 'use App\Support\{Foo, Bar as Baz, function dumps_dir as storage_path};'
  st_nv 's13: a group use that mixes classes and a function member is a not-verified line, exit 3' s13-groupmix 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-const 'use const App\Support\DUMPS as PUBLIC_DIR;'
  st_nv 's13: a use const statement is a not-verified line, exit 3' s13-const 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-constgroup 'use App\Support\{const DUMPS};'
  st_nv 's13: a group use with a const member is a not-verified line, exit 3' s13-constgroup 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-case 'USE FUNCTION App\Support\dumps_dir AS storage_path;'
  st_nv 's13: the keywords are read in any letter case, exit 3' s13-case 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-multi "use Illuminate\\Support\\Str;
declare(strict_types=1);
use function App\\Support\\dumps_dir as storage_path;"
  st_nv 's13: the statement is named by its own line after clean statements, exit 3' s13-multi 'config/filesystems.php - line 4 holds PHP'
  st_rebind s13-decl 'declare(ticks=1) storage_path_hook();'
  st_nv 's13: a declare statement that carries a statement is a not-verified line, exit 3' s13-decl 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-declb 'declare(ticks=1) { }'
  st_nv 's13: a declare block is a not-verified line, exit 3' s13-declb 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-declc 'declare(ticks=count([1]));'
  st_nv 's13: a declare directive that is not a literal is a not-verified line, exit 3' s13-declc 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-usecall "use Foo\\Bar ('x');"
  st_nv 's13: a use statement that holds anything but names is a not-verified line, exit 3' s13-usecall 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-useopen 'use Foo\Bar'
  st_nv 's13: a use statement with no semicolon is a not-verified line, exit 3' s13-useopen 'config/filesystems.php - '
  st_rebind s13-nsfn '' "namespace\\storage_path('app/dl')"
  st_nv 's13: a namespace-relative helper name in an entry is a not-verified line, exit 3' s13-nsfn 'config/filesystems.php - '
  # Attacks on the fix, kept as rows: a comment between the keywords, a group spread over lines, and a
  # namespace after a clean statement are read as the same statement.
  st_rebind s13-cmt 'use /* x */ function /* y */ App\Support\dumps_dir as storage_path;'
  st_nv 's13: a comment between the use keywords is a not-verified line, exit 3' s13-cmt 'config/filesystems.php - line 2 holds PHP'
  st_rebind s13-mline 'use App\Support\{
    Foo,
    function dumps_dir as storage_path,
};'
  st_nv 's13: a group use spread over lines names the line of its function member, exit 3' s13-mline 'config/filesystems.php - line 4 holds PHP'
  st_rebind s13-nsafter 'declare(strict_types=1);
namespace App\Support;'
  st_nv 's13: a namespace after a declare directive is a not-verified line, exit 3' s13-nsafter 'config/filesystems.php - line 3 holds PHP'
  # The forms the scan still reads: a declare directive and a class import, aliased or not, change no
  # function or constant name, so the entry reads as the global helper and the scan reports nothing.
  st_rebind s13-ok 'declare(strict_types=1);
use Illuminate\Support\Str;
use App\Support\Foo as storage_path;
use App\Support\{Bar, Baz as Qux};'
  st_check 's13: declare and class use statements stay accepted, exit 0' test "$ST_RC" = 0

  # M1: a .. component after a symlink component resolves at the physical target, as the kernel does.
  st_clean m1
  mkdir -p "$r/config" "$r/database/dumps/sub" "$r/database/dumps/secret" "$r/storage/app"
  printf '%s\n' "$ST_MARKER" >"$r/database/dumps/secret/db.sql"
  ln -s ../../database/dumps/sub "$r/storage/app/lnk"
  printf "<?php\nreturn ['links' => [public_path('dl') => storage_path('app/lnk/../secret')]];\n" >"$r/config/filesystems.php"
  st_commit "$r" base
  st_run m1 static --root "$r"
  st_check 'm1: a target behind a symlink and a .. component reports the dump it serves, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'finding\tdump-archive-backup\tcritical\tdatabase/dumps/secret/db.sql\t' "$ST_TMP/out.m1")"
  st_links m1-plain "        public_path('dl') => storage_path('app/a/../b'),"
  mkdir -p "$r/storage/app/a" "$r/storage/app/b"
  printf 'x\n' >"$r/storage/app/b/db.sql"
  st_commit "$r" base
  st_run m1-plain static --root "$r"
  st_check 'm1: a .. component without a symlink is scanned at its directory, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'dump-archive-backup\tcritical\tstorage/app/b/db.sql\t' "$ST_TMP/out.m1-plain")"
  st_links m1-out "        public_path('dl') => storage_path('app/out/..'),"
  ln -s "$o" "$r/storage/app/out"
  st_commit "$r" base
  st_run m1-out static --root "$r"
  st_check 'm1: a .. component after a symlink that leaves the project is never exit 0' test "$ST_RC" != 0
  st_check_not 'm1: nothing outside the project is read for it' st_line 'hostonly-dump'

  # M2: a target that is a regular file is served and checked by its name.
  st_links m2 "        public_path('backup.sql') => storage_path('app/nightly.sql'),"
  printf '%s\n' "$ST_MARKER" >"$r/storage/app/nightly.sql"
  st_commit "$r" base
  st_run m2 static --root "$r"
  st_check 'm2: a linked file named like a dump is a critical finding, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'finding\tdump-archive-backup\tcritical\tstorage/app/nightly.sql\t' "$ST_TMP/out.m2")"
  st_links m2-clean "        public_path('r.txt') => storage_path('app/readme.txt'),"
  printf 'x\n' >"$r/storage/app/readme.txt"
  st_commit "$r" base
  st_run m2-clean static --root "$r"
  st_check 'm2: a linked file with a clean name is no finding, exit 0' test "$ST_RC" = 0
  st_links m2-script "        public_path('s.php') => storage_path('app/run.php'),"
  printf '<?php\n' >"$r/storage/app/run.php"
  st_commit "$r" base
  st_run m2-script static --root "$r"
  st_check 'm2: a linked PHP file is a public-storage-script finding' st_line $'public-storage-script\thigh\tstorage/app/run.php\t'
  st_links m2-sym "        public_path('b.sql') => storage_path('app/b.sql'),"
  printf '%s\n' "$ST_MARKER" >"$r/storage/real.sql"
  ln -s ../real.sql "$r/storage/app/b.sql"
  st_commit "$r" base
  st_run m2-sym static --root "$r"
  st_check 'm2: a linked symlink to a file is a symlink finding and its file is checked by name' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tstorage/app/b.sql\t' "$ST_TMP/out.m2-sym")" \
    -a -n "$(grep -F $'dump-archive-backup\tcritical\tstorage/app/b.sql\t' "$ST_TMP/out.m2-sym")"

  # M3 and S10: a deployment step that writes into a served directory is a gitignore-deploy finding.
  st_links m3 "        public_path('downloads') => storage_path('app/downloads'),"
  mkdir -p "$r/storage/app/downloads"
  printf 'x\n' >"$r/storage/app/downloads/readme.txt"
  printf 'cp a b\nmysqldump app > storage/app/downloads/nightly.sql\n' >"$r/deploy.sh"
  st_commit "$r" base
  st_run m3 static --root "$r"
  st_check 'm3: a deployment step that writes a dump into a linked directory is a gitignore-deploy finding, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'gitignore-deploy\tmedium\tdeploy.sh\tline=2 ref=storage/app/downloads/nightly.sql class=dump-archive-backup' "$ST_TMP/out.m3")"
  st_links m3-missing "        public_path('downloads') => storage_path('app/downloads'),"
  printf 'mysqldump app > storage/app/downloads/nightly.sql\n' >"$r/deploy.sh"
  st_commit "$r" base
  st_run m3-missing static --root "$r"
  st_check 'm3: the same step is a finding when the linked directory is not in the checkout' \
    st_line $'gitignore-deploy\tmedium\tdeploy.sh\tline=1 ref=storage/app/downloads/nightly.sql'
  st_links m3-clean "        public_path('downloads') => storage_path('app/downloads'),"
  printf 'cp a mystorage/app/downloads/nightly.sql\ncp a storage/app/downloads/ok.txt\ncp -r a public/ \n' >"$r/deploy.sh"
  st_commit "$r" base
  st_run m3-clean static --root "$r"
  st_check 'm3: another directory name and a clean file name are no finding, exit 0' test "$ST_RC" = 0
  # shellcheck disable=SC2016  # the deployment paths are shell text, never expanded
  for i in 'public/$NAME.sql' 'storage/app/downloads/{a,b}.sql' 'public/`date`.sql' 'storage/app/downloads/a\ b.sql' 'public/${DIR}/x'; do
    st_links m3-dyn "        public_path('downloads') => storage_path('app/downloads'),"
    printf 'echo x > %s\n' "$i" >"$r/deploy.sh"
    st_commit "$r" base
    st_run m3-dyn static --root "$r"
    st_nv "m3: the deployment path $i is a not-verified line, exit 3" m3-dyn 'deploy.sh - line 1 names a served directory'
  done
  for i in 'cd public && mysqldump app > db.sql' 'cd ./storage/app/downloads' 'WORKDIR "public/sub"' 'pushd public/../x'; do
    st_links m3-cd "        public_path('downloads') => storage_path('app/downloads'),"
    printf '%s\n' "$i" >"$r/deploy.sh"
    st_commit "$r" base
    st_run m3-cd static --root "$r"
    st_nv "m3: the deployment line $i is a not-verified line, exit 3" m3-cd 'deploy.sh - line 1 changes into a served directory'
  done
  st_links m3-cd-ok "        public_path('downloads') => storage_path('app/downloads'),"
  printf 'cd build && cp a b\ncd mypublic\n' >"$r/deploy.sh"
  st_commit "$r" base
  st_run m3-cd-ok static --root "$r"
  st_check 'm3: a cd into another directory is no line, exit 0' test "$ST_RC" = 0
  st_clean m3-link
  printf 'echo x > public/a.sql\n' >"$r/real-deploy.sh"
  ln -s real-deploy.sh "$r/Dockerfile"
  st_commit "$r" base
  st_run m3-link static --root "$r"
  st_nv 'm3: a deployment file that is a symlink is a not-verified line' m3-link 'Dockerfile - the deployment file is a symlink'

  # S11: a local disk with serve and a public visibility is served, so its root is scanned.
  st_serve fc-disk "<?php return ['disks' => ['backups' => ['driver' => 'local', 'root' => storage_path('app/dl'), 'serve' => true, 'visibility' => 'public']]];
"
  st_check 's11: a served public local disk reports the dump in its root, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'finding\tdump-archive-backup\tcritical\tstorage/app/dl/db.sql\t' "$ST_TMP/out.fc-disk")"
  for disk in "no visibility (private)|'driver' => 'local', 'root' => storage_path('app/dl'), 'serve' => true" \
    "a private visibility|'driver' => 'local', 'root' => storage_path('app/dl'), 'serve' => true, 'visibility' => 'private'" \
    "serve false|'driver' => 'local', 'root' => storage_path('app/dl'), 'serve' => false, 'visibility' => 'public'" \
    "no serve|'driver' => 'local', 'root' => storage_path('app/dl'), 'visibility' => 'public'" \
    "an s3 driver|'driver' => 's3', 'root' => storage_path('app/dl'), 'serve' => true, 'visibility' => 'public'"; do
    st_serve fc-disk-no "<?php return ['disks' => ['backups' => [${disk#*|}]]];
"
    st_check "s11: a disk with ${disk%%|*} serves no root, exit 0" test "$ST_RC" = 0
  done
  for disk in "a serve from env()|'driver' => 'local', 'root' => storage_path('app/dl'), 'serve' => env('SERVE', true), 'visibility' => 'public'" \
    "a visibility from env()|'driver' => 'local', 'root' => storage_path('app/dl'), 'serve' => true, 'visibility' => env('VIS')" \
    "a driver from env()|'driver' => env('D', 'local'), 'root' => storage_path('app/dl'), 'serve' => true, 'visibility' => 'public'" \
    "a root from env()|'driver' => 'local', 'root' => env('ROOT'), 'serve' => true, 'visibility' => 'public'" \
    "no root|'driver' => 'local', 'serve' => true, 'visibility' => 'public'" \
    "a spread|...\$common, 'driver' => 'local'"; do
    st_serve fc-disk-nv "<?php return ['disks' => ['backups' => [${disk#*|}]]];
"
    st_nv "s11: a disk with ${disk%%|*} is a not-verified line, exit 3" fc-disk-nv 'config/filesystems.php - the disks entry at line 1'
  done
  st_serve fc-disk-var "<?php return ['disks' => \$disks];
"
  st_nv 's11: a disks key that is no array literal is a not-verified line' fc-disk-var 'config/filesystems.php - the disks entry at line 1'
  st_serve fc-disk-elem "<?php return ['disks' => ['backups' => \$one]];
"
  st_nv 's11: a disk that is no array literal is a not-verified line' fc-disk-elem 'config/filesystems.php - the disks entry at line 1'
  st_serve fc-disk-root "<?php return ['disks' => ['all' => ['driver' => 'local', 'root' => base_path(), 'serve' => true, 'visibility' => 'public']]];
"
  st_check 's11: a served disk whose root is the project root is a finding on the config line, exit 5' \
    test "$ST_RC" = 5 -a -n "$(grep -F $'symlink-permission\thigh\tconfig/filesystems.php\tline=1 disk root target=. ' "$ST_TMP/out.fc-disk-root")"

  # S12 and the served-path family: a binding or a call that moves a framework path is not read.
  for spec in "bootstrap/app.php|\$app->bind('path.public', fn () => base_path('public_html'));|sets the public path" \
    "app/Support/Boot.php|\$app->usePublicPath(base_path('web'));|sets the public path" \
    "bootstrap/app.php|\$app->useStoragePath(base_path('data'));|sets a framework path" \
    "app/Providers/P.php|\$this->app->instance('path.storage', '/x');|sets a framework path" \
    "routes/web.php|app()->useBasePath('/x');|sets a framework path" \
    "public/index.php|\$app->usePublicPath('/x');|sets the public path"; do
    st_clean fc-path
    mkdir -p "$r/$(dirname "${spec%%|*}")"
    printf '<?php\n%s\n' "$(printf '%s' "$spec" | cut -d'|' -f2)" >"$r/${spec%%|*}"
    st_commit "$r" base
    st_run fc-path static --root "$r"
    st_nv "s12: ${spec%%|*} with $(printf '%s' "$spec" | cut -d'|' -f2) is a not-verified line, exit 3" fc-path "${spec%%|*} - the file ${spec##*|}"
  done
  st_clean fc-sympath
  mkdir -p "$r/bootstrap" "$r/app/Providers" "$r/app/Link"
  printf '<?php\n' >"$o/app.php"
  ln -s "$o/app.php" "$r/bootstrap/app.php"
  ln -s "$o" "$r/app/Link/x"
  st_commit "$r" base
  st_run fc-sympath static --root "$r"
  st_nv 's12: a symlinked bootstrap/app.php is a not-verified line' fc-sympath 'bootstrap/app.php - the entry is a symlink'
  st_nv 's12: a symlink below app/ is a not-verified line' fc-sympath 'app/Link/x - the entry is a symlink'
  st_check_not 's12: nothing outside the project is read behind them' st_line 'hostonly-dump'
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
  st_chain_cases
  st_served_cases
  st_submodule_cases
  st_links_cases
  st_failclosed_cases

  printf '%s: self-test %s passed, %s failed (%s checks)\n' "$PROG" "$ST_PASS" "$ST_FAIL" "$((ST_PASS + ST_FAIL))"
  [[ $ST_FAIL -eq 0 ]]
  exit
}

main "$@"
