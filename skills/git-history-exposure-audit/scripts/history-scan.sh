#!/usr/bin/env bash
# history-scan.sh — the deterministic scan of the git-history-exposure-audit skill. It reads a git
# history and the GitHub texts of a repository and prints redacted findings: what an attacker could
# still collect from them. It never prints a found credential, and it attacks nothing.
#
# Why this exists
#   A clean default branch proves nothing about the history. A value that a later commit deleted
#   stays in the earlier commit, in every tag that contains it, in the pull-request refs, and in a
#   commit message that names it. A manual search picks its own patterns and its own surfaces, and
#   nothing proves which surface it skipped. This script is the one repeatable search: the same
#   rules, the same surfaces, and the same redaction in every run.
#
# Usage
#   history-scan.sh range --base <sha> --head <sha>
#   history-scan.sh text  < <load-issue.sh JSON of a pull request>
#   history-scan.sh full  --repo <owner>/<repo> [--terms-file <file>] [--run-root <dir>]
#   history-scan.sh --self-test
#
# range (review mode, git surfaces)
#   Scans every commit in `^<base> <head>` of the current checkout: the lines each commit added, the
#   paths each commit added, and the commit messages. A merge commit is read as a combined diff, so a
#   line it takes from one parent is not read again and a line its own conflict resolution adds is.
#   A commit the base reaches is not scanned, so a value merged in from the default branch is no
#   finding. Classes: credential, sensitive-file, infrastructure. It makes no network request and
#   writes no file. A base or head that is not in the checkout is a failed scan (exit 3), never a
#   fetch.
#
# text (review mode, pull-request texts)
#   Reads the JSON that skills/code-review-github/scripts/load-issue.sh prints for a pull request on
#   standard input and scans its title, body, comments, review comments, and reviews with the same
#   rules. Classes: credential, infrastructure. It makes no network request and writes no file.
#
# full (full audit)
#   Refuses the target unless `gh api --method GET repos/<owner>/<repo>` returns
#   `.permissions.push == true`; a fork also needs push on its `parent` and `source`. A failed call,
#   a missing field, `null`, or `false` refuses (exit 4) and the message says which. Only
#   `<owner>/<repo>` on github.com is accepted; `gh` never receives `--hostname`, and GH_HOST is
#   pinned to github.com. It then makes one hardened mirror clone under the run root, scans every ref
#   of it (branches, tags, pull-request refs, notes), and reads the GitHub surfaces below. Classes:
#   all five. Each finding states its first commit, whether the default-branch head carries it, and
#   the tags that carry it. The run directory is removed on exit.
#   --run-root is `.claude/run/history-exposure` (default) or `.codex/run/history-exposure`.
#   --terms-file names a file with one user-supplied term per line (named-term class).
#
# Detection classes and rules (the one owner; the skill lists only the class table)
#   credential       private-key-block, aws-access-key-id, github-token, gitlab-token, slack-token,
#                    stripe-live-key, google-api-key, npm-token, anthropic-api-key, openai-api-key,
#                    url-password (the password of a URL's user info), connection-string-password
#                    (Password= / Pwd= on a line that also names Server=, Host=, or Data Source=)
#   sensitive-file   a path a commit added that matches SENSITIVE_TABLE below: env-file (.env*,
#                    except exactly .env.example), private-key-file, certificate-file,
#                    credentials-file, database-dump, backup-archive, log-file. Matched on the path
#                    alone, in any letter case, and a path renamed to such a name counts as added
#                    (--no-renames). The content of such a path is never read.
#   infrastructure   private-ipv4, internal-hostname, error-tracker-url, storage-bucket,
#                    deploy-host-url. The matched value is never printed, in any mode.
#   identity         (full only) commit-email-domain and personal-name of every author, committer,
#                    and tagger, read raw with --no-mailmap; email-address in any text.
#   named-term       (full only) every term of --terms-file, matched case-insensitively as a fixed
#                    string (awk index()), never as a pattern.
#   Every pattern is a POSIX ERE without back-references, built with explicit repetition, so no
#   pattern backtracks. A line longer than LINE_CAP bytes is cut at the cap and counted.
#   Skipped and counted (`skipped-placeholder:`): a template value (`<…>`, `${…}`, `$NAME`, `%…%`,
#   `{{…}}`), a run of one character, a value that carries a documentation marker word (example,
#   placeholder, changeme, dummy, sample, redacted, your, acme), a value that is only a
#   generic word (tok, token, pass, passwd, password, pwd, secret, user, test, xxx), and a URL password whose host is a
#   documentation host. Never an infrastructure finding: a documentation host (example.com, .org,
#   .net, *.example, *.test, *.invalid, localhost), a documentation address range (not private), and
#   an address in CIDR notation.
#
# Suppression (review modes only)
#   The one suppression path is the default-branch manifest key `history-exposure.allow`, a list of
#   {rule, path} objects with literal paths as this script prints them, read through
#   skills/_shared/read-manifest.sh. Each suppressed occurrence is counted on `suppressed:`. Nothing
#   on the branch — an inline marker, a PR text, a commit message, a manifest change — suppresses a
#   finding. The full audit suppresses nothing.
#
# Execution safety
#   Every git call goes through git_ro: `git --no-pager --no-lazy-fetch --no-replace-objects` with
#   GIT_CONFIG_NOSYSTEM=1 and GIT_CONFIG_GLOBAL=/dev/null, so no system or user configuration selects
#   a program, plus core.fsmonitor=false, core.hooksPath=/dev/null, and protocol.allow=never. Every
#   content read passes --no-ext-diff --no-textconv --no-show-signature --ignore-submodules=all
#   --no-renames, identity reads pass --no-mailmap. The scan reads commits, trees, and blobs by object
#   id only: no index, no work tree, no checkout, so no smudge filter, LFS, or fsmonitor runs. No ref
#   name is passed as an argument: refs travel as object ids that git printed. Untrusted text reaches
#   the matcher only on standard input, never inside $(...), eval, sh -c, or an awk program text.
#
# Redaction
#   A credential or infrastructure value exists only in awk memory and in the pipe to the hashing
#   step. It is never an argument of a process, never in a file, never in an exported variable. Every
#   stage that reads content sends its stderr to /dev/null; a failure is reported by its exit status.
#   The script turns xtrace and verbose off first, so an inherited SHELLOPTS=xtrace traces nothing.
#   Fingerprint: the first 12 hex digits of SHA-256 over `salt LF class LF value`, where the salt is
#   32 random bytes from /dev/urandom, hex-encoded, held in one unexported shell variable, never
#   printed, written, or passed as an argument, and gone with the process. Two findings of one value
#   in one run carry one fingerprint; two runs never match. A sensitive-file fingerprint is taken over
#   the blob id. Identity and named-term findings print the matched term instead. A path that matches
#   a credential rule is printed with the match replaced by `<redacted:rule-id>`. Every printed path,
#   ref, term, e-mail, and URL is printable ASCII; any other byte is printed as \xHH.
#
# Output (stdout, one record per line)
#   history-scan: mode=<mode> ...                     the header
#   finding<TAB>class<TAB>rule<TAB>surface<TAB>commit<TAB>fingerprint-or-term<TAB>head=<yes|no|->
#     <TAB>tags=<names|->...<TAB>occurrences=<n><TAB>location
#   outbound-request: github.com <what>               one line per network read (full only)
#   suppressed: <n> / skipped-placeholder: <n>        counters
#   not-verified: <surface> - <reason and the step that settles it>
#   summary: findings=<n> status=<clean|findings|failed>
#
# Exit codes
#   0  a complete scan with no finding
#   1  usage error, a refused terms file, or a refused run directory
#   2  missing required tool
#   3  a failed or incomplete scan; the findings it has are printed
#   4  the full audit refused the target
#   5  a complete scan with findings

set +o xtrace +o verbose
set -u
umask 077
export LC_ALL=C

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="$SCRIPT_DIR/$PROG"
LINE_CAP=4096
RUN_DIR=''

# One rule and one basename glob per line. The pathspecs that keep these paths out of the content
# read and the pattern that classifies a path are both derived from this table, so they cannot drift.
SENSITIVE_TABLE='env-file .env*
private-key-file *.pem
private-key-file *.key
private-key-file *.p12
private-key-file *.pfx
private-key-file *.jks
private-key-file *.keystore
private-key-file *.ppk
private-key-file id_rsa
private-key-file id_dsa
private-key-file id_ecdsa
private-key-file id_ed25519
certificate-file *.crt
certificate-file *.cer
credentials-file auth.json
credentials-file .npmrc
credentials-file .netrc
credentials-file _netrc
credentials-file .pgpass
credentials-file .htpasswd
credentials-file .git-credentials
database-dump *.sql.gz
database-dump *.sql.bz2
database-dump *.sql.xz
database-dump *.sql.zip
database-dump *.dump
database-dump *dump*.sql
database-dump *.sqlite
database-dump *.sqlite3
backup-archive *.bak
backup-archive *.backup
backup-archive *backup*.zip
backup-archive *backup*.tar
backup-archive *backup*.tgz
backup-archive *backup*.gz
backup-archive *backup*.7z
log-file *.log'

usage() {
  cat >&2 <<'EOF'
usage: history-scan.sh range --base <sha> --head <sha>
       history-scan.sh text  < <load-issue.sh JSON of a pull request>
       history-scan.sh full  --repo <owner>/<repo> [--terms-file <file>] [--run-root <dir>]
       history-scan.sh --self-test
EOF
  exit 1
}

die() {
  printf '%s: %s\n' "$PROG" "$2" >&2
  exit "$1"
}

# The one git function. Every git call of this script goes through it.
git_ro() {
  GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0 GIT_OPTIONAL_LOCKS=0 \
    git --no-pager --no-lazy-fetch --no-replace-objects \
    -c core.fsmonitor=false -c core.hooksPath=/dev/null -c core.quotePath=true -c protocol.allow=never "$@"
}

DIFF_OPTS=(--no-ext-diff --no-textconv --no-show-signature --ignore-submodules=all --no-renames
  --no-color --no-relative --src-prefix=a/ --dst-prefix=b/ --no-abbrev)
LOG_OPTS=(--stdin --topo-order --reverse --no-mailmap --no-decorate --no-notes --root)
META_FMT='%x01C%x09%H%n%x01I%x09author%x09%an%x09%ae%n%x01I%x09committer%x09%cn%x09%ce%n%w(0,2,2)%B%w(0,0,0)'

need_tools() {
  local tool
  for tool in "$@"; do
    command -v "$tool" >/dev/null 2>&1 || die 2 "missing required tool: $tool"
  done
}

pick_hash() {
  if command -v shasum >/dev/null 2>&1; then
    HASH=(shasum -a 256)
  elif command -v sha256sum >/dev/null 2>&1; then
    HASH=(sha256sum)
  elif command -v openssl >/dev/null 2>&1; then
    HASH=(openssl dgst -sha256 -r)
  else
    die 2 'missing required tool: shasum, sha256sum, or openssl'
  fi
  SALT="$(od -An -tx1 -N32 /dev/urandom 2>/dev/null | tr -d ' \n')"
  [[ "$SALT" =~ ^[0-9a-f]{64}$ ]] || die 2 'cannot read 32 random bytes from /dev/urandom'
}

glob_to_ere() {
  local glob="$1" out='' index char
  for ((index = 0; index < ${#glob}; index++)); do
    char="${glob:index:1}"
    case "$char" in
    '*') out+='[^/]*' ;;
    '.') out+='[.]' ;;
    *) out+="$char" ;;
    esac
  done
  printf '(^|/)%s$' "$out"
}

build_sensitive() {
  local line rule glob IFS=$'\n'
  SENS_PATHSPECS=()
  SENS_ERE=''
  for line in $SENSITIVE_TABLE; do
    rule="${line%% *}"
    glob="${line#* }"
    SENS_PATHSPECS+=(":(exclude,icase,glob)**/$glob")
    SENS_ERE+="$rule $(glob_to_ere "$glob");"
  done
}

# Shared awk functions: the sensitive-path classifier and the printable-ASCII escape.
# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
AWK_LIB='
function init_lib(   i, n, sp) {
  for (i = 1; i < 256; i++) ORD[sprintf("%c", i)] = i
  n = split(sens, SE, ";")
  NSENS = 0
  for (i = 1; i <= n; i++) {
    if (SE[i] == "") continue
    sp = index(SE[i], " ")
    NSENS++
    SR[NSENS] = substr(SE[i], 1, sp - 1)
    SX[NSENS] = substr(SE[i], sp + 1)
  }
}
function sens_rule(path,   q, b, l, i, s) {
  q = path
  if (substr(q, 1, 1) == "\"" && substr(q, length(q), 1) == "\"") q = substr(q, 2, length(q) - 2)
  b = q
  while ((s = index(b, "/")) > 0) b = substr(b, s + 1)
  l = tolower(q)
  for (i = 1; i <= NSENS; i++) {
    if (l ~ SX[i]) {
      if (SR[i] == "env-file" && b == ".env.example") continue
      return SR[i]
    }
  }
  return ""
}
function esc(s,   out, i, c, o) {
  out = ""
  for (i = 1; i <= length(s); i++) {
    c = substr(s, i, 1)
    o = ORD[c]
    if (o >= 32 && o <= 126) out = out c
    else out = out sprintf("\\x%02x", o)
  }
  return out
}
function rest(n,   s, i, p) {
  s = $0
  for (i = 0; i < n; i++) {
    p = index(s, "\t")
    if (p == 0) return ""
    s = substr(s, p + 1)
  }
  return s
}
'

# git log --raw/--format output of the meta pass -> O, I, M, P records.
# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
AWK_META='
BEGIN { FS = "\t"; MC = "\001C\t"; MI = "\001I\t" }
index($0, MC) == 1 { c = $2; print "O\t" c; next }
index($0, MI) == 1 { if (c != "") print "I\t" c "\t" substr($0, 4); next }
substr($0, 1, 2) == "  " { if (c != "") print "M\t" c "\t" substr($0, 3); next }
substr($0, 1, 1) == ":" {
  p = index($0, "\t")
  if (p == 0 || c == "") next
  n = split(substr($0, 1, p - 1), f, " ")
  if (f[n] ~ /^A+$/) print "P\t" c "\t" f[n - 1] "\t" substr($0, p + 1)
  next
}
'

# git log -p --cc output of a content pass -> L and N records.
# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
AWK_CONTENT='
BEGIN { FS = "\t"; MC = "\001C\t"; state = 0 }
index($0, MC) == 1 {
  c = $2
  np = split($3, pp, " ")
  if (np < 1) np = 1
  plus = ""
  for (i = 0; i < np; i++) plus = plus "+"
  state = 0
  next
}
substr($0, 1, 5) == "diff " { state = 1; path = ""; next }
state == 1 && substr($0, 1, 4) == "+++ " {
  x = substr($0, 5)
  if (x == "/dev/null") path = ""
  else if (substr(x, 1, 2) == "b/") path = substr(x, 3)
  else if (substr(x, 1, 3) == "\"b/") path = "\"" substr(x, 4)
  else path = x
  next
}
state == 1 && substr($0, 1, 13) == "Binary files " { print "N\tbinary\t1"; next }
state >= 1 && substr($0, 1, 2) == "@@" { state = 2; next }
state == 2 {
  if (substr($0, 1, np) == plus && path != "" && c != "") print "L\t" c "\t" path "\t" substr($0, np + 1)
  next
}
'

# git cat-file tag output, each line prefixed with one space, behind an \001X marker -> I and X records.
# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
AWK_TAGS='
BEGIN { FS = "\t"; MX = "\001X\t" }
index($0, MX) == 1 { oid = $2; ref = rest(2); hdr = 1; print "O\t" oid; next }
oid == "" { next }
hdr && $0 == " " { hdr = 0; next }
hdr && substr($0, 1, 8) == " tagger " {
  s = substr($0, 9)
  lt = index(s, " <")
  gt = index(s, ">")
  if (lt > 0 && gt > lt) print "I\t" oid "\ttagger\t" substr(s, 1, lt - 1) "\t" substr(s, lt + 2, gt - lt - 2)
  next
}
hdr { next }
{ print "X\t" oid "\t" ref "\t" substr($0, 2) }
'

# ls-tree and numstat sections per root -> R records, and the text blobs to read into the blob list.
# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
AWK_TREE='
BEGIN { FS = "\t"; MR = "\001R\t"; init_lib() }
function flush(   i) {
  for (i = 1; i <= ne; i++) {
    print "R\t" label "\t" EO[i] "\t" EP[i]
    if (ET[i] != "blob") continue
    if (EP[i] in BIN) { if (!(EO[i] in SEENBIN)) { SEENBIN[EO[i]] = 1; print "N\tbinary\t1" } continue }
    if (sens_rule(EP[i]) != "") continue
    print EO[i] > blobs
  }
  ne = 0
  split("", BIN)
}
substr($0, 1, 2) == "E\t" { print; next }
index($0, MR) == 1 { flush(); label = $2; section = 1; next }
$0 == "\001N" { section = 2; next }
section == 1 {
  p = index($0, "\t")
  if (p == 0) next
  split(substr($0, 1, p - 1), f, " ")
  ne++
  ET[ne] = f[2]
  EO[ne] = f[3]
  EP[ne] = substr($0, p + 1)
  next
}
section == 2 && $1 == "-" && $2 == "-" { BIN[rest(2)] = 1; next }
END { flush() }
'

# git cat-file --batch output -> B records. Sizes are counted in bytes (LC_ALL=C).
# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
AWK_BLOB='
BEGIN { need = -1 }
need < 0 {
  if ($0 == "") next
  n = split($0, h, " ")
  if (h[2] == "missing" || n < 3) { print "N\tmissing\t1"; next }
  oid = h[1]
  need = h[3] + 0
  if (need == 0) need = -1
  next
}
{
  need -= length($0) + 1
  print "B\t" oid "\t" $0
  if (need <= 0) need = -1
}
'

# The matcher: detection rules, aggregation, presence, and the sorted finding records.
# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
AWK_MATCH='
function rep(s, n,   r, i) { r = ""; for (i = 0; i < n; i++) r = r s; return r }
function addc(rule, pre, re, bnd, plen) { NC++; CR[NC] = rule; CPRE[NC] = pre; CRE[NC] = re; CB[NC] = bnd; CPL[NC] = plen }
function addi(rule, pre, re, bnd) { NI++; IR[NI] = rule; IPRE[NI] = pre; IRE[NI] = re; IB[NI] = bnd }
function haspre(text, pre,   n, i, P) {
  if (pre == "") return 1
  n = split(pre, P, "|")
  for (i = 1; i <= n; i++) if (index(text, P[i]) > 0) return 1
  return 0
}
function find_all(text, re, bnd,   s, off, st, ln, b, a, n) {
  n = 0
  s = text
  off = 0
  while (match(s, re)) {
    st = off + RSTART
    ln = RLENGTH
    if (ln <= 0) break
    b = (st > 1) ? substr(text, st - 1, 1) : ""
    a = substr(text, st + ln, 1)
    if ((b == "" || b !~ bnd) && (a == "" || a !~ bnd)) {
      n++
      MS[n] = st
      ML[n] = ln
      off = st + ln - 1
    } else off = st
    s = substr(text, off + 1)
  }
  return n
}
function onerun(v,   c, i) {
  if (v == "") return 1
  c = substr(v, 1, 1)
  for (i = 2; i <= length(v); i++) if (substr(v, i, 1) != c) return 0
  return 1
}
function placeholder(v, plen,   l) {
  l = tolower(v)
  if (v ~ /^[<$%{]/ || index(v, "${") || index(v, "{{") || (v ~ /^%.*%$/)) return 1
  if (index(l, "example") || index(l, "placeholder") || index(l, "changeme") || index(l, "dummy") || index(l, "sample") || index(l, "redacted") || index(l, "your") || index(l, "acme")) return 1
  if (l ~ /^(tok|token|pass|passwd|password|pwd|secret|user|test|xxx+)$/) return 1
  if (onerun(substr(v, plen + 1))) return 1
  return 0
}
function doc_host(h) {
  h = tolower(h)
  sub(/:[0-9]+$/, "", h)
  if (h == "localhost" || h == "localhost.localdomain") return 1
  if (h ~ /(^|[.])example[.](com|org|net)$/) return 1
  if (h ~ /[.](example|test|invalid|localhost)$/) return 1
  return 0
}
function skip(surf) { if (surf != "tree") PLACEHOLDER++ }
function emit(cls, rule, val, surf, c, loc, blob, hv,   k, pk, o) {
  if (!ON[cls]) return
  if (surf == "tree") {
    pk = cls SUBSEP rule SUBSEP val
    if (!((pk SUBSEP blob) in PB)) { PB[pk SUBSEP blob] = 1; PBL[pk] = PBL[pk] " " blob }
    return
  }
  if ((rule SUBSEP loc) in ALLOW) { SUPPRESSED++; return }
  k = cls SUBSEP rule SUBSEP surf SUBSEP val
  o = (c in OIDX) ? OIDX[c] : 1000000000 + NR
  if (!(k in CNT)) { NK++; KEYS[NK] = k; KC[k] = cls; KR[k] = rule; KS[k] = surf; KV[k] = val; FO[k] = o + 1 }
  if (o < FO[k]) { FO[k] = o; FC[k] = c; FL[k] = loc; HV[k] = (hv != "") ? hv : val }
  CNT[k]++
}
function pend_flush() {
  if (!PEND) return
  emit("credential", "private-key-block", "pem:" PSID, PSURF, PC, PLOC, PBLOB, "")
  PEND = 0
}
function scan_cred(text, surf, c, loc, blob,   i, n, j, v, l, p, q, u, host, pass) {
  for (i = 1; i <= NC; i++) {
    if (!haspre(text, CPRE[i])) continue
    n = find_all(text, CRE[i], CB[i])
    for (j = 1; j <= n; j++) {
      v = substr(text, MS[j], ML[j])
      if (placeholder(v, CPL[i])) { skip(surf); continue }
      emit("credential", CR[i], v, surf, c, loc, blob, "")
    }
  }
  if (index(text, "PRIVATE KEY-----") && match(text, RE_PEM)) {
    if (surf == "line" || surf == "tree") {
      pend_flush()
      PEND = 1; PSID = SID; PSURF = surf; PC = c; PLOC = loc; PBLOB = blob
    } else emit("credential", "private-key-block", substr(text, RSTART, RLENGTH), surf, c, loc, blob, "")
  }
  if (index(text, "://") && index(text, "@")) {
    n = find_all(text, RE_URLPW, "[A-Za-z0-9+.-]")
    for (j = 1; j <= n; j++) {
      v = substr(text, MS[j], ML[j])
      u = substr(v, index(v, "://") + 3)
      p = index(u, "@")
      q = index(u, ":")
      host = substr(u, p + 1)
      pass = substr(u, q + 1, p - q - 1)
      if (doc_host(host) || placeholder(pass, 0)) { skip(surf); continue }
      emit("credential", "url-password", pass, surf, c, loc, blob, "")
    }
  }
  l = tolower(text)
  if ((index(l, "password=") || index(l, "pwd=")) && (index(l, "server=") || index(l, "host=") || index(l, "data source="))) {
    n = find_all(l, "(password|pwd)=[^;\"'"'"' \t]+", "[a-z0-9_]")
    for (j = 1; j <= n; j++) {
      v = substr(text, MS[j], ML[j])
      v = substr(v, index(v, "=") + 1)
      if (placeholder(v, 0)) { skip(surf); continue }
      emit("credential", "connection-string-password", v, surf, c, loc, blob, "")
    }
  }
}
function scan_infra(text, surf, c, loc, blob,   l, n, j, v, i, o, ok, a2, host, lab) {
  l = tolower(text)
  if (index(l, "10.") || index(l, "172.") || index(l, "192.168.")) {
    n = find_all(l, RE_IP, "[0-9]")
    for (j = 1; j <= n; j++) {
      if (MS[j] > 1 && substr(l, MS[j] - 1, 1) == ".") continue
      a2 = substr(l, MS[j] + ML[j], 2)
      if (a2 ~ /^[.][0-9]/ || a2 ~ /^\/[0-9]/) continue
      v = substr(l, MS[j], ML[j])
      split(v, o, ".")
      ok = 1
      for (i = 1; i <= 4; i++) if (o[i] + 0 > 255) ok = 0
      if (ok) emit("infrastructure", "private-ipv4", v, surf, c, loc, blob, "")
    }
  }
  if (haspre(l, ".internal|.intranet|.corp|.lan|.local")) {
    n = find_all(l, RE_HOSTINT, "[a-z0-9.-]")
    for (j = 1; j <= n; j++) {
      v = substr(l, MS[j], ML[j])
      if (doc_host(v)) continue
      if (placeholder(v, 0)) { skip(surf); continue }
      emit("infrastructure", "internal-hostname", v, surf, c, loc, blob, "")
    }
  }
  for (i = 1; i <= NI; i++) {
    if (!haspre(l, IPRE[i])) continue
    n = find_all(l, IRE[i], IB[i])
    for (j = 1; j <= n; j++) {
      v = substr(l, MS[j], ML[j])
      if (IR[i] == "deploy-host-url") {
        host = substr(v, index(v, "://") + 3)
        if (index(host, "@")) host = substr(host, index(host, "@") + 1)
        if (doc_host(host) || host ~ /^(github[.]com|gitlab[.]com|bitbucket[.]org)$/) continue
      }
      if (IR[i] == "error-tracker-url") {
        lab = v
        sub(/[.].*/, "", lab)
        if (lab ~ /^(www|docs|status|ingest)$/) continue
      }
      if (placeholder(v, 0)) { skip(surf); continue }
      emit("infrastructure", IR[i], v, surf, c, loc, blob, "")
    }
  }
}
function scan_email(text, surf, c, loc, blob,   l, n, j, v, at, dom) {
  if (!index(text, "@")) return
  l = tolower(text)
  n = find_all(l, RE_EMAIL, "[a-z0-9._%+-]")
  for (j = 1; j <= n; j++) {
    v = substr(l, MS[j], ML[j])
    at = index(v, "@")
    dom = substr(v, at + 1)
    if (substr(v, 1, at - 1) == "git" || ignored_domain(dom) || dom ~ /[.](png|jpg|jpeg|gif|svg|webp)$/) continue
    emit("identity", "email-address", v, surf, c, loc, blob, "")
  }
}
function ignored_domain(d) { return (d ~ /(^|[.])noreply[.]github[.]com$/ || doc_host(d)) }
function scan_terms(text, surf, c, loc, blob,   l, i) {
  if (!NT) return
  l = tolower(text)
  for (i = 1; i <= NT; i++) if (index(l, LT[i])) emit("named-term", "user-term", TERM[i], surf, c, loc, blob, "")
}
function scan_text(text, surf, c, loc, blob) {
  if (text == "") return
  if (ON["credential"]) scan_cred(text, surf, c, loc, blob)
  if (ON["infrastructure"]) scan_infra(text, surf, c, loc, blob)
  if (ON["identity"] && surf != "path") scan_email(text, surf, c, loc, blob)
  scan_terms(text, surf, c, loc, blob)
}
function cap(s) {
  if (length(s) > CAPN) { CUT++; return substr(s, 1, CAPN) }
  return s
}
function redact(s,   i, n, j, out) {
  for (i = 1; i <= NC; i++) {
    if (!haspre(s, CPRE[i])) continue
    n = find_all(s, CRE[i], CB[i])
    out = ""
    for (j = n; j >= 1; j--) s = substr(s, 1, MS[j] - 1) "<redacted:" CR[i] ">" substr(s, MS[j] + ML[j])
  }
  return s
}
function record_root(label, blob, path,   r) {
  if (!((blob SUBSEP label) in RB)) { RB[blob SUBSEP label] = 1; ROOTS[blob] = ROOTS[blob] " " label }
  r = sens_rule(path)
  if (r != "") emit("sensitive-file", r, path, "tree", "", "", blob, "")
}
function presence(k,   pk, nb, B, i, nr, RL, j, head, tags, seen) {
  pk = KC[k] SUBSEP KR[k] SUBSEP KV[k]
  head = "no"
  tags = ""
  split("", seen)
  nb = split(PBL[pk], B, " ")
  for (i = 1; i <= nb; i++) {
    nr = split(ROOTS[B[i]], RL, " ")
    for (j = 1; j <= nr; j++) {
      if (RL[j] == "head") head = "yes"
      else if (substr(RL[j], 1, 4) == "tag:" && !(RL[j] in seen)) { seen[RL[j]] = 1; tags = tags (tags == "" ? "" : ",") esc(substr(RL[j], 5)) }
    }
  }
  PH = head
  PT = (tags == "") ? "-" : tags
}
BEGIN {
  FS = "\t"
  init_lib()
  n = split(classes, CLS, ",")
  for (i = 1; i <= n; i++) ON[CLS[i]] = 1
  RANK["credential"] = 1; RANK["sensitive-file"] = 2; RANK["infrastructure"] = 3; RANK["identity"] = 4; RANK["named-term"] = 5
  CAPN = cap_bytes + 0
  AN = "[A-Za-z0-9]"
  TK = "[A-Za-z0-9_-]"
  addc("aws-access-key-id", "AKIA|ASIA", "(AKIA|ASIA)" rep("[A-Z0-9]", 16), "[A-Za-z0-9]", 4)
  addc("github-token", "ghp_|gho_|ghu_|ghs_|ghr_", "gh[pousr]_" rep(AN, 36), "[A-Za-z0-9_]", 4)
  addc("github-token", "github_pat_", "github_pat_" rep("[A-Za-z0-9_]", 22) "[A-Za-z0-9_]*", "[A-Za-z0-9_]", 11)
  addc("gitlab-token", "glpat-", "glpat-" rep(TK, 20), TK, 6)
  addc("slack-token", "xox", "xox[abprs]-" rep("[A-Za-z0-9-]", 10) "[A-Za-z0-9-]*", "[A-Za-z0-9-]", 5)
  addc("stripe-live-key", "_live_", "[rs]k_live_" rep(AN, 16) AN "*", "[A-Za-z0-9_]", 8)
  addc("google-api-key", "AIza", "AIza" rep(TK, 35), TK, 4)
  addc("npm-token", "npm_", "npm_" rep(AN, 36), "[A-Za-z0-9_]", 4)
  addc("anthropic-api-key", "sk-ant-", "sk-ant-" rep(TK, 20) TK "*", TK, 7)
  addc("openai-api-key", "sk-proj-", "sk-proj-" rep(TK, 20) TK "*", TK, 8)
  RE_PEM = "-----BEGIN [A-Z]* ?[A-Z]* ?PRIVATE KEY-----"
  RE_URLPW = "[A-Za-z][A-Za-z0-9+.-]*://[^/:@ \t\"'"'"'<>]*:[^/@ \t\"'"'"']+@[A-Za-z0-9._-]+"
  OCT = "[0-9][0-9]?[0-9]?"
  RE_IP = "(10[.]" OCT "[.]" OCT "|172[.](1[6-9]|2[0-9]|3[01])[.]" OCT "|192[.]168[.]" OCT ")[.]" OCT
  LBL = "[a-z0-9]([a-z0-9-]*[a-z0-9])?"
  RE_HOSTINT = LBL "([.]" LBL ")*[.](internal|intranet|corp|lan|localdomain|local)"
  RE_EMAIL = "[a-z0-9._%+-]+@" LBL "([.]" LBL ")*[.][a-z][a-z]+"
  addi("error-tracker-url", "atlassian.net", "[a-z0-9-]+[.]atlassian[.]net", "[a-z0-9.-]")
  addi("error-tracker-url", "app.bugsnag.com/", "app[.]bugsnag[.]com/[a-z0-9_-]+", "[a-z0-9_-]")
  addi("error-tracker-url", "sentry.io", "[a-z0-9-]+[.]sentry[.]io", "[a-z0-9.-]")
  addi("error-tracker-url", "sentry.io/organizations/", "sentry[.]io/organizations/[a-z0-9_-]+", "[a-z0-9_-]")
  addi("error-tracker-url", "linear.app/", "linear[.]app/[a-z0-9_-]+", "[a-z0-9_-]")
  addi("storage-bucket", "s3://", "s3://[a-z0-9.-]+", "[a-z0-9_-]")
  addi("storage-bucket", "gs://", "gs://[a-z0-9._-]+", "[a-z0-9_-]")
  addi("storage-bucket", "amazonaws.com", "[a-z0-9.-]+[.]s3[.a-z0-9-]*[.]amazonaws[.]com", "[a-z0-9.-]")
  addi("storage-bucket", "storage.googleapis.com/", "storage[.]googleapis[.]com/[a-z0-9._-]+", "[a-z0-9_-]")
  addi("storage-bucket", "blob.core.windows.net", "[a-z0-9-]+[.]blob[.]core[.]windows[.]net", "[a-z0-9.-]")
  addi("deploy-host-url", "ssh://|sftp://|rsync://", "(ssh|sftp|rsync)://([^ /@]+@)?[a-z0-9.-]+", "[a-z0-9_-]")
}
{
  t = $1
  if (t != "L" && t != "B") pend_flush()
  if (t == "O") { if (!($2 in OIDX)) OIDX[$2] = ++NO; next }
  if (t == "T") { v = rest(1); if (v != "") { NT++; TERM[NT] = v; LT[NT] = tolower(v) } next }
  if (t == "W") { ALLOW[$2 SUBSEP rest(2)] = 1; next }
  if (t == "E" || t == "Q") { print; next }
  if (t == "N") { COUNTER[$2] += $3; next }
  if (t == "R") { record_root($2, $3, rest(3)); next }
  if (t == "B" || t == "L") {
    if (t == "B") { SID = "B" $2; surf = "tree"; c = ""; loc = ""; blob = $2; text = cap(rest(2)) }
    else { SID = "L" $2 SUBSEP $3; surf = "line"; c = $2; loc = $3; blob = ""; text = cap(rest(3)) }
    if (PEND && PSID != SID) pend_flush()
    if (PEND && PSID == SID) { emit("credential", "private-key-block", text, PSURF, PC, PLOC, PBLOB, ""); PEND = 0 }
    scan_text(text, surf, c, loc, blob)
    next
  }
  if (t == "M") { scan_text(cap(rest(2)), "message", $2, "-", ""); next }
  if (t == "X") { scan_text(cap(rest(3)), "tag", $2, $3, ""); next }
  if (t == "G") { scan_text(cap(rest(2)), "github", "-", $2, ""); next }
  if (t == "P") {
    v = rest(3)
    r = sens_rule(v)
    if (r != "") emit("sensitive-file", r, v, "path", $2, v, "", $3)
    if (ON["credential"]) scan_cred(v, "path", $2, v, "")
    scan_terms(v, "path", $2, v, "")
    next
  }
  if (t == "I") {
    email = $NF
    name = rest(3)
    name = substr(name, 1, length(name) - length(email) - 1)
    if (ON["identity"]) {
      d = tolower(email)
      sub(/^[^@]*@/, "", d)
      if (index(email, "@") && d != "" && !ignored_domain(d)) emit("identity", "commit-email-domain", d, "identity", $2, $3, "", "")
      if (name != "" && name !~ /\[bot\]$/) emit("identity", "personal-name", name, "identity", $2, $3, "", "")
    }
    scan_terms(name, "identity", $2, $3, "")
    scan_terms(email, "identity", $2, $3, "")
    next
  }
}
END {
  pend_flush()
  for (i = 1; i <= NK; i++) { K = KEYS[i]; SK[i] = RANK[KC[K]] * 10000000000 + FO[K] }
  for (gap = int(NK / 2); gap > 0; gap = int(gap / 2))
    for (i = gap + 1; i <= NK; i++) {
      tk = KEYS[i]; ts = SK[i]
      for (j = i; j > gap && SK[j - gap] > ts; j -= gap) { KEYS[j] = KEYS[j - gap]; SK[j] = SK[j - gap] }
      KEYS[j] = tk; SK[j] = ts
    }
  for (i = 1; i <= NK; i++) {
    K = KEYS[i]
    s = KS[K]
    if (presence_on && (s == "line" || s == "path")) { presence(K); head = PH; tags = PT }
    else if (presence_on && (s == "message" || s == "identity")) { head = "reach"; tags = "reach" }
    else if (s == "tag") { head = "-"; tags = esc(FL[K]); sub(/^refs\/tags\//, "", tags) }
    else { head = "-"; tags = "-" }
    loc = (FL[K] == "" ? "-" : esc(redact(FL[K])))
    if (KC[K] == "identity" || KC[K] == "named-term") kind = "t\t" esc(KV[K])
    else kind = "h\t" HV[K]
    print "F\t" KC[K] "\t" KR[K] "\t" s "\t" (FC[K] == "" ? "-" : FC[K]) "\t" head "\t" tags "\t" CNT[K] "\t" loc "\t" kind
  }
  print "C\tsuppressed\t" (SUPPRESSED + 0)
  print "C\tskipped-placeholder\t" (PLACEHOLDER + 0)
  print "C\tlines-cut\t" (CUT + 0)
  print "C\tbinary\t" (COUNTER["binary"] + 0)
  print "C\tmissing\t" (COUNTER["missing"] + 0)
  print "Z"
}
'

# Reads the matcher records, hashes each credential, sensitive-file, and infrastructure value into its
# fingerprint, and prints the report. The value is read from the pipe and handed to the hash tool on
# its standard input through the printf builtin, so it never becomes an argument or a file.
emit_report() {
  local rec rest cls rule surf commit head tags count loc kind value out fp
  local findings=0 failed=0 complete=0 name
  while IFS= read -r rec; do
    case "$rec" in
    F$'\t'*)
      rest="${rec#F$'\t'}"
      cls="${rest%%$'\t'*}"; rest="${rest#*$'\t'}"
      rule="${rest%%$'\t'*}"; rest="${rest#*$'\t'}"
      surf="${rest%%$'\t'*}"; rest="${rest#*$'\t'}"
      commit="${rest%%$'\t'*}"; rest="${rest#*$'\t'}"
      head="${rest%%$'\t'*}"; rest="${rest#*$'\t'}"
      tags="${rest%%$'\t'*}"; rest="${rest#*$'\t'}"
      count="${rest%%$'\t'*}"; rest="${rest#*$'\t'}"
      loc="${rest%%$'\t'*}"; rest="${rest#*$'\t'}"
      kind="${rest%%$'\t'*}"; value="${rest#*$'\t'}"
      if [[ "$kind" == h ]]; then
        out="$(printf '%s\n%s\n%s' "$SALT" "$cls" "$value" | "${HASH[@]}" 2>/dev/null)"
        fp="${out:0:12}"
        [[ "$fp" =~ ^[0-9a-f]{12}$ ]] || { fp='unhashed'; failed=1; }
      else
        fp="$value"
      fi
      value=''
      if [[ "$head" == reach ]]; then
        head="$(reach_head "$commit")"
        tags="$(reach_tags "$commit")"
      fi
      findings=$((findings + 1))
      printf 'finding\t%s\t%s\t%s\t%s\t%s\thead=%s\ttags=%s\toccurrences=%s\t%s\n' \
        "$cls" "$rule" "$surf" "$commit" "$fp" "$head" "$tags" "$count" "$loc"
      ;;
    Q$'\t'*) printf 'outbound-request: %s\n' "${rec#Q$'\t'}" ;;
    E$'\t'*)
      failed=1
      rest="${rec#E$'\t'}"
      printf 'not-verified: %s - %s\n' "${rest%%$'\t'*}" "${rest#*$'\t'}"
      ;;
    C$'\t'*)
      rest="${rec#C$'\t'}"
      name="${rest%%$'\t'*}"
      count="${rest#*$'\t'}"
      case "$name" in
      suppressed) printf 'suppressed: %s (default-branch allow-list history-exposure.allow)\n' "$count" ;;
      skipped-placeholder) printf 'skipped-placeholder: %s\n' "$count" ;;
      lines-cut) [[ "$count" == 0 ]] || printf 'not-verified: %s lines cut at %s bytes - the part after the cap was not scanned; a reviewer reads those lines in the diff\n' "$count" "$LINE_CAP" ;;
      binary) [[ "$count" == 0 ]] || printf 'not-verified: %s binary blobs - their content was not scanned; the owner checks each binary file the history carries\n' "$count" ;;
      missing)
        if [[ "$count" != 0 ]]; then
          failed=1
          printf 'not-verified: %s blobs missing from the clone - run the full audit again\n' "$count"
        fi
        ;;
      esac
      ;;
    Z) complete=1 ;;
    esac
  done
  if [[ $complete -ne 1 ]]; then
    failed=1
    printf 'not-verified: matcher - the scan stopped before it finished; run it again\n'
  fi
  if [[ $failed -eq 1 ]]; then
    printf 'summary: findings=%s status=failed\n' "$findings"
    return 3
  fi
  if [[ $findings -gt 0 ]]; then
    printf 'summary: findings=%s status=findings\n' "$findings"
    return 5
  fi
  printf 'summary: findings=0 status=clean\n'
  return 0
}

# A commit message or an identity stays in every ref whose history holds its commit.
reach_head() {
  if [[ "$1" =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]] && git_ro merge-base --is-ancestor "$1" HEAD >/dev/null 2>&1; then
    printf 'yes'
  else
    printf 'no'
  fi
}

reach_tags() {
  local list
  [[ "$1" =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]] || { printf -- '-'; return; }
  list="$(git_ro for-each-ref --contains "$1" --format='%(refname)' refs/tags 2>/dev/null |
    awk -v sens='' "$AWK_LIB"'BEGIN { init_lib() } { sub(/^refs\/tags\//, ""); out = out (out == "" ? "" : ",") esc($0) } END { print out }' 2>/dev/null)"
  printf '%s' "${list:--}"
}

# The default-branch allow-list as W records. Read through read-manifest.sh, which reads composer.json
# from the default branch only; the branch under review never supplies it.
allow_records() {
  local manifest="$SCRIPT_DIR/../../_shared/read-manifest.sh"
  [[ -f "$manifest" ]] || return 0
  bash "$manifest" 2>/dev/null | jq -r '
    (.["history-exposure"].allow // [])
    | if type == "array" then .[] else empty end
    | select(type == "object" and (.rule | type) == "string" and (.path | type) == "string")
    | select((.rule | test("^[a-z0-9-]+$")) and (.path | length) > 0 and (.path | explode | all(. >= 32 and . != 127)))
    | "W\t\(.rule)\t\(.path)"' 2>/dev/null
}

is_sha() {
  [[ "$1" =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]]
}

# The git surfaces of one rev-list input (object ids on standard input of the caller): the meta pass
# (messages, identities, added paths), the content pass with every sensitive path excluded, and the
# content pass over .env.example. Failures become E records.
produce_git() {
  local revs="$1" status
  printf '%s' "$revs" | git_ro log "${LOG_OPTS[@]}" --format="$META_FMT" --raw --cc "${DIFF_OPTS[@]}" 2>/dev/null |
    awk "$AWK_META" 2>/dev/null
  status=("${PIPESTATUS[@]}")
  [[ "${status[1]}" == 0 && "${status[2]}" == 0 ]] || printf 'E\tcommit messages and added paths\tgit log failed; run the scan again\n'

  printf '%s' "$revs" | git_ro log "${LOG_OPTS[@]}" --format='%x01C%x09%H%x09%P' -p --cc --unified=0 "${DIFF_OPTS[@]}" \
    -- . "${SENS_PATHSPECS[@]}" 2>/dev/null | awk "$AWK_CONTENT" 2>/dev/null
  status=("${PIPESTATUS[@]}")
  [[ "${status[1]}" == 0 && "${status[2]}" == 0 ]] || printf 'E\tadded lines\tgit log failed; run the scan again\n'

  printf '%s' "$revs" | git_ro log "${LOG_OPTS[@]}" --format='%x01C%x09%H%x09%P' -p --cc --unified=0 "${DIFF_OPTS[@]}" \
    -- ':(glob)**/.env.example' 2>/dev/null | awk "$AWK_CONTENT" 2>/dev/null
  status=("${PIPESTATUS[@]}")
  [[ "${status[1]}" == 0 && "${status[2]}" == 0 ]] || printf 'E\tadded lines of .env.example\tgit log failed; run the scan again\n'
}

mode_range() {
  local base='' head='' top status
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --base) [[ $# -ge 2 ]] || usage; base="$2"; shift 2 ;;
    --head) [[ $# -ge 2 ]] || usage; head="$2"; shift 2 ;;
    *) usage ;;
    esac
  done
  if ! is_sha "$base" || ! is_sha "$head"; then
    die 1 '--base and --head must be full commit object ids'
  fi
  need_tools git awk jq od tr
  pick_hash
  build_sensitive
  top="$(git_ro rev-parse --show-toplevel 2>/dev/null)" || die 1 'not inside a git checkout'
  cd "$top" || die 1 'cannot enter the checkout'

  printf 'history-scan: mode=range base=%s head=%s engine=history-scan.sh\n' "$base" "$head"
  if ! git_ro cat-file -e "$base^{commit}" 2>/dev/null || ! git_ro cat-file -e "$head^{commit}" 2>/dev/null; then
    printf 'not-verified: commit range - the base or the head commit is not in this checkout; fetch the pull request head and base, then run the scan again\n'
    printf 'summary: findings=0 status=failed\n'
    exit 3
  fi

  {
    allow_records
    produce_git "$head"$'\n'"^$base"$'\n'
  } | awk -v classes='credential,sensitive-file,infrastructure' -v sens="$SENS_ERE" -v cap_bytes="$LINE_CAP" \
    -v presence_on=0 "$AWK_LIB$AWK_MATCH" 2>/dev/null | emit_report
  status=("${PIPESTATUS[@]}")
  exit "${status[2]}"
}

# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
JQ_TEXT='
def clean: tostring | explode | map(if (. < 32 and . != 9 and . != 10) or . == 127 then 32 else . end) | implode;
def lines($u): clean | split("\n")[] | "G\t\($u)\t\(.)";
def url($fallback): ((.url // $fallback) | clean | gsub("[\t\n]"; " "));
. as $p | ($p | url("-")) as $u
| (($p.title // ""), ($p.body // "") | lines($u)),
  (($p.comments // [])[] | url($u) as $cu | (.body // "") | lines($cu)),
  (($p.reviewComments // [])[] | url($u) as $cu | (.body // "") | lines($cu)),
  (($p.reviews // [])[] | url($u) as $cu | (.body // "") | lines($cu)),
  (if $p.reviewCommentsComplete == false then "E\tpull-request review comments\tthe loader could not read them; run the loader and the scan again" else empty end)'

mode_text() {
  local status
  [[ $# -eq 0 ]] || usage
  need_tools awk jq od tr
  pick_hash
  build_sensitive
  printf 'history-scan: mode=text engine=history-scan.sh\n'
  {
    if git_ro rev-parse --show-toplevel >/dev/null 2>&1; then
      allow_records
    fi
    jq -r "$JQ_TEXT" 2>/dev/null ||
      printf 'E\tpull-request texts\tthe input is not the loader JSON of a pull request; run the loader and the scan again\n'
  } | awk -v classes='credential,infrastructure' -v sens="$SENS_ERE" -v cap_bytes="$LINE_CAP" \
    -v presence_on=0 "$AWK_LIB$AWK_MATCH" 2>/dev/null | emit_report
  status=("${PIPESTATUS[@]}")
  exit "${status[2]}"
}

# ---------------------------------------------------------------------------------------------------
# Full audit
# ---------------------------------------------------------------------------------------------------

# shellcheck disable=SC2016  # an awk or jq program: its $ belongs to the program, not to the shell
GH_JQ_LIB='
def clean: tostring | explode | map(if (. < 32 and . != 9 and . != 10) or . == 127 then 32 else . end) | implode;
def lines($u): clean | split("\n")[] | "G\t\($u)\t\(.)";
def url: ((.html_url // .url // "-") | clean | gsub("[\t\n]"; " "));'

valid_repo() {
  [[ "$1" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$ ]] && [[ "${1#*/}" != . && "${1#*/}" != .. ]]
}

# Every GitHub read: GET only, host pinned to github.com, never --hostname, stderr discarded.
gh_get() {
  GH_HOST=github.com GH_PROMPT_DISABLED=1 gh api --method GET "$@" 2>/dev/null
}

refuse() {
  printf '%s: refused: %s\n' "$PROG" "$1" >&2
  exit 4
}

# Push permission on one repository, or a refusal that names the reason.
check_push() {
  local repo="$1" json verdict
  printf 'outbound-request: github.com GET repos/%s\n' "$repo"
  json="$(gh_get "repos/$repo")" || refuse "$repo: the GitHub API call failed, so push permission is not proven"
  verdict="$(printf '%s' "$json" | jq -r 'if (.permissions | type) != "object" then "missing"
    elif .permissions.push == true then "true" elif .permissions.push == null then "null" else "false" end' 2>/dev/null)"
  case "$verdict" in
  true) ;;
  missing) refuse "$repo: the API returned no permissions field, so push permission is not proven" ;;
  null) refuse "$repo: permissions.push is null, so push permission is not proven" ;;
  *) refuse "$repo: the authenticated account cannot push to it; the audit is for the owner's own repositories" ;;
  esac
  REPO_JSON="$json"
}

cleanup_run() {
  [[ -n "$RUN_DIR" && -d "$RUN_DIR" ]] && rm -rf -- "$RUN_DIR"
  RUN_DIR=''
}

# The run directory: the git-ignored run root of the current checkout, created without `mkdir -p`,
# checked physically against the top level, and refused when git does not ignore it. Each start
# removes sibling run directories whose holder process is confirmed dead; an inconclusive probe
# leaves the directory alone (gate_lock_is_stale in skills/_shared/gate-record.sh).
make_run_dir() {
  local root="$1" top phys path part sibling IFS
  top="$(git_ro rev-parse --show-toplevel 2>/dev/null)" || die 1 'the full audit runs inside a git checkout'
  phys="$(cd "$top" && pwd -P)" || die 1 'cannot resolve the checkout'
  path="$phys"
  IFS=/
  for part in $root; do
    path="$path/$part"
    [[ -d "$path" ]] || mkdir "$path" 2>/dev/null || die 1 "cannot create the run root $root"
  done
  unset IFS
  [[ "$(cd "$path" && pwd -P)" == "$phys/$root" ]] || die 1 "the run root $root passes through a symbolic link"
  git_ro -C "$phys" check-ignore -q --no-index "$root/probe" 2>/dev/null || die 1 "git does not ignore the run root $root"
  for sibling in "$path"/run.*; do
    [[ -d "$sibling" && ! -L "$sibling" ]] || continue
    gate_lock_is_stale "$sibling" && rm -rf -- "$sibling"
  done
  RUN_DIR="$(mktemp -d "$path/run.XXXXXXXX")" || die 1 'cannot create the run directory'
  gate_holder_text >"$RUN_DIR/holder"
}

# The tree of the default-branch head and of every tag: R records for every entry, and the text blobs
# that are neither binary nor a sensitive path into the blob list. Then the blobs as B records.
produce_trees() {
  local empty line oid ptype target ref
  empty="$(git_ro hash-object -t tree --stdin </dev/null 2>/dev/null)"
  : >"$RUN_DIR/blobs"
  {
    if [[ -n "$HEAD_OID" ]]; then
      printf '\001R\thead\n'
      git_ro ls-tree -r --full-tree "$HEAD_OID" 2>/dev/null || printf 'E\tdefault-branch tree\tgit ls-tree failed; run the audit again\n'
      printf '\001N\n'
      git_ro diff-tree -r --numstat "${DIFF_OPTS[@]}" "$empty" "$HEAD_OID" 2>/dev/null
    fi
    git_ro for-each-ref --format='%(objectname)%09%(objecttype)%09%(*objectname)%09%(*objecttype)%09%(refname)' refs/tags 2>/dev/null |
      while IFS= read -r line; do
        oid="${line%%$'\t'*}"; line="${line#*$'\t'}"
        ptype="${line%%$'\t'*}"; line="${line#*$'\t'}"
        target="${line%%$'\t'*}"; line="${line#*$'\t'}"
        [[ "$ptype" == commit ]] && target="$oid"
        [[ "${line%%$'\t'*}" == commit || "$ptype" == commit ]] || continue
        ref="${line#*$'\t'}"
        is_sha "$target" || continue
        printf '\001R\ttag:%s\n' "${ref#refs/tags/}"
        git_ro ls-tree -r --full-tree "$target" 2>/dev/null || printf 'E\ttag trees\tgit ls-tree failed; run the audit again\n'
        printf '\001N\n'
        git_ro diff-tree -r --numstat "${DIFF_OPTS[@]}" "$empty" "$target" 2>/dev/null
      done
  } | awk -v sens="$SENS_ERE" -v blobs="$RUN_DIR/blobs" "$AWK_LIB$AWK_TREE" 2>/dev/null
  sort -u "$RUN_DIR/blobs" | git_ro cat-file --batch 2>/dev/null | awk "$AWK_BLOB" 2>/dev/null
  status=("${PIPESTATUS[@]}")
  [[ "${status[1]}" == 0 && "${status[2]}" == 0 ]] || printf 'E\tdefault-branch and tag trees\tgit cat-file failed; run the audit again\n'
  rm -f -- "$RUN_DIR/blobs"
}

produce_tags() {
  local line oid ref
  git_ro for-each-ref --format='%(objectname)%09%(objecttype)%09%(refname)' 2>/dev/null |
    while IFS= read -r line; do
      oid="${line%%$'\t'*}"; line="${line#*$'\t'}"
      [[ "${line%%$'\t'*}" == tag ]] || continue
      ref="${line#*$'\t'}"
      is_sha "$oid" || continue
      printf '\001X\t%s\t%s\n' "$oid" "$ref"
      git_ro cat-file tag "$oid" 2>/dev/null | sed 's/^/ /'
    done | awk "$AWK_LIB$AWK_TAGS" 2>/dev/null
}

# One paginated GitHub surface: the endpoint, a Q record, and the jq fields that become G records.
github_surface() {
  local name="$1" endpoint="$2" fields="$3" status
  printf 'Q\tgithub.com GET %s\n' "$endpoint"
  gh_get --paginate "$endpoint" | jq -r "$GH_JQ_LIB"' .[] | url as $u | ('"$fields"') | lines($u)' 2>/dev/null
  status=("${PIPESTATUS[@]}")
  [[ "${status[0]}" == 0 && "${status[1]}" == 0 ]] ||
    printf 'E\t%s\tthe GitHub read failed, so the surface is unread; run the audit again\n' "$name"
}

rate_left() {
  local out
  out="$(gh_get rate_limit | jq -r '"\(.resources.core.remaining) \(.resources.core.reset)"' 2>/dev/null)" || return 1
  RATE_REMAINING="${out%% *}"
  RATE_RESET="${out##* }"
  [[ "$RATE_REMAINING" =~ ^[0-9]+$ && "$RATE_RESET" =~ ^[0-9]+$ ]]
}

reset_time() {
  date -u -r "$1" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || printf 'epoch %s' "$1"
}

produce_github() {
  local repo="$1" numbers count number status
  printf 'Q\tgithub.com GET rate_limit\n'
  if ! rate_left; then
    printf 'E\tGitHub surfaces\tthe rate limit could not be read; run the audit again\n'
    return
  fi
  if [[ "$RATE_REMAINING" -lt 10 ]]; then
    printf 'E\tGitHub surfaces\tthe rate limit leaves %s requests, which cannot cover the reads; run the audit again after %s\n' \
      "$RATE_REMAINING" "$(reset_time "$RATE_RESET")"
    return
  fi
  printf '%s' "$REPO_JSON" | jq -r "$GH_JQ_LIB"' url as $u | ((.description // ""), (.homepage // ""), ((.topics // [])[])) | lines($u)' 2>/dev/null
  github_surface 'issue and pull-request titles and bodies' "repos/$repo/issues?state=all&per_page=100" '(.title // ""), (.body // "")'
  github_surface 'issue and pull-request comments' "repos/$repo/issues/comments?per_page=100" '(.body // "")'
  github_surface 'review comments' "repos/$repo/pulls/comments?per_page=100" '(.body // "")'
  github_surface 'commit comments' "repos/$repo/comments?per_page=100" '(.body // "")'
  github_surface 'releases' "repos/$repo/releases?per_page=100" '(.name // ""), (.body // ""), ((.assets // [])[] | (.name // ""))'

  printf 'Q\tgithub.com GET repos/%s/pulls?state=all\n' "$repo"
  numbers="$(gh_get --paginate "repos/$repo/pulls?state=all&per_page=100" | jq -r '.[].number | select(type == "number")' 2>/dev/null)"
  status=("${PIPESTATUS[@]}")
  if [[ "${status[0]}" != 0 || "${status[1]}" != 0 ]]; then
    printf 'E\treviews\tthe pull-request list could not be read, so reviews are unread; run the audit again\n'
    numbers=''
  fi
  count=0
  for number in $numbers; do count=$((count + 1)); done
  printf 'Q\tgithub.com GET rate_limit\n'
  if ! rate_left || [[ "$RATE_REMAINING" -lt $((count + 5)) ]]; then
    printf 'E\treviews\tthe rate limit cannot cover %s pull-request review reads; run the audit again after %s\n' \
      "$count" "$(reset_time "${RATE_RESET:-0}")"
  else
    for number in $numbers; do
      [[ "$number" =~ ^[0-9]+$ ]] || continue
      github_surface "reviews of pull request $number" "repos/$repo/pulls/$number/reviews?per_page=100" '(.body // "")'
    done
  fi

  printf 'Q\tgithub.com POST graphql (discussions query)\n'
  GH_HOST=github.com GH_PROMPT_DISABLED=1 gh api graphql --paginate -f owner="${repo%%/*}" -f name="${repo#*/}" \
    -F query=@"$SCRIPT_DIR/../queries/discussions.graphql" 2>/dev/null |
    jq -r "$GH_JQ_LIB"' .data.repository.discussions.nodes[]?
      | (url as $u | ((.title // ""), (.body // "")) | lines($u)),
        ((.comments.nodes // [])[] | (url as $u | (.body // "") | lines($u)),
          ((.replies.nodes // [])[] | url as $u | (.body // "") | lines($u)))' 2>/dev/null
  status=("${PIPESTATUS[@]}")
  [[ "${status[0]}" == 0 && "${status[1]}" == 0 ]] ||
    printf 'E\tdiscussions\tthe GitHub GraphQL read failed, so discussions are unread; run the audit again\n'
}

print_always_not_verified() {
  printf 'not-verified: edit history of edited GitHub texts - the API returns the current revision only; a repository admin reviews the edit history of each edited issue, pull request, and comment in the GitHub web interface\n'
  printf 'not-verified: deleted GitHub texts - the API does not return them; GitHub Support settles what a deleted text still holds\n'
  printf 'not-verified: commits no ref reaches - GitHub keeps them readable by SHA; GitHub Support removes cached views after a history rewrite\n'
  printf 'not-verified: private forks, the wiki, gists, Actions logs and artifacts, and packages - the scan does not read them; the owner reviews each in the GitHub web interface\n'
  printf 'not-verified: discussion comments beyond the first 50 per discussion and replies beyond the first 50 per comment - the query reads one page of them; the owner reviews longer threads in the GitHub web interface\n'
}

mode_full() {
  local repo='' terms='' root='.claude/run/history-exposure' parent source status default security revs
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --repo) [[ $# -ge 2 ]] || usage; repo="$2"; shift 2 ;;
    --terms-file) [[ $# -ge 2 ]] || usage; terms="$2"; shift 2 ;;
    --run-root) [[ $# -ge 2 ]] || usage; root="$2"; shift 2 ;;
    *) usage ;;
    esac
  done
  valid_repo "$repo" || refuse 'the target must be <owner>/<repo> on github.com'
  case "$root" in
  .claude/run/history-exposure | .codex/run/history-exposure) ;;
  *) die 1 'the run root must be .claude/run/history-exposure or .codex/run/history-exposure' ;;
  esac
  if [[ -n "$terms" ]]; then
    [[ -f "$terms" && ! -L "$terms" && -r "$terms" ]] || die 1 'the terms file must be a readable regular file'
    [[ "$(LC_ALL=C tr -cd '\000-\011\013-\037\177' <"$terms" | wc -c | tr -d ' ')" == 0 ]] ||
      die 1 'a term carries a NUL or a control character; the terms file is refused'
  fi
  need_tools git gh jq awk sed sort od tr ps date mktemp
  pick_hash
  build_sensitive
  # shellcheck source=../../_shared/gate-record.sh
  . "$SCRIPT_DIR/../../_shared/gate-record.sh"
  set +e +o pipefail

  check_push "$repo"
  if [[ "$(printf '%s' "$REPO_JSON" | jq -r '.fork == true' 2>/dev/null)" == true ]]; then
    local repo_json="$REPO_JSON"
    parent="$(printf '%s' "$repo_json" | jq -r '.parent.full_name // empty' 2>/dev/null)"
    source="$(printf '%s' "$repo_json" | jq -r '.source.full_name // empty' 2>/dev/null)"
    if ! valid_repo "$parent" || ! valid_repo "$source"; then
      refuse "$repo is a fork and the API names no valid parent and source"
    fi
    check_push "$parent"
    check_push "$source"
    REPO_JSON="$repo_json"
  fi

  make_run_dir "$root"
  trap cleanup_run EXIT
  trap 'cleanup_run; exit 130' INT
  trap 'cleanup_run; exit 143' TERM
  trap 'cleanup_run; exit 129' HUP

  printf 'outbound-request: github.com clone %s\n' "$repo"
  if ! git_ro -c protocol.https.allow=always -c credential.helper= -c 'credential.helper=!gh auth git-credential' \
    clone --mirror --template= --quiet \
    --config core.hooksPath=/dev/null --config core.fsmonitor=false --config protocol.allow=never \
    "https://github.com/$repo.git" "$RUN_DIR/mirror.git" >/dev/null 2>&1; then
    printf 'not-verified: git history - the mirror clone failed; check the network and the gh login, then run the audit again\n'
    printf 'summary: findings=0 status=failed\n'
    exit 3
  fi
  cd "$RUN_DIR/mirror.git" || die 3 'cannot enter the mirror'

  HEAD_OID="$(git_ro rev-parse --verify --quiet 'HEAD^{commit}' 2>/dev/null)"
  default="$(git_ro symbolic-ref --quiet HEAD 2>/dev/null | awk -v sens='' "$AWK_LIB"'BEGIN { init_lib() } { sub(/^refs\/heads\//, ""); print esc($0) }')"
  printf 'history-scan: mode=full repository=%s default-branch=%s engine=history-scan.sh\n' "$repo" "${default:--}"
  printf '%s' "$REPO_JSON" | jq -r '"repository: archived=\(.archived == true) fork=\(.fork == true) visibility=\(.visibility // (if .private == true then "private" else "public" end))"' 2>/dev/null
  security="$(printf '%s' "$REPO_JSON" | jq -r 'if (.security_and_analysis | type) == "object" then
      "secret-scanning: \(.security_and_analysis.secret_scanning.status // "unknown") push-protection: \(.security_and_analysis.secret_scanning_push_protection.status // "unknown")"
    else "" end' 2>/dev/null)"
  if [[ -n "$security" ]]; then
    printf '%s\n' "$security"
  else
    printf 'not-verified: secret scanning and push protection status - the API returned no security_and_analysis block for this account; a repository admin reading the repository'"'"'s code security settings settles it\n'
  fi
  print_always_not_verified

  revs="$(git_ro for-each-ref --format='%(objectname) %(objecttype) %(*objecttype)' 2>/dev/null |
    awk '$2 == "commit" || $3 == "commit" { print $1 }' | sort -u)"
  {
    if [[ -n "$terms" ]]; then
      awk 'length($0) > 0 { print "T\t" $0 }' <"$terms"
    fi
    produce_trees
    [[ -n "$revs" ]] && produce_git "$revs"$'\n'
    produce_tags
    produce_github "$repo"
  } | awk -v classes="credential,sensitive-file,infrastructure,identity,named-term" -v sens="$SENS_ERE" \
    -v cap_bytes="$LINE_CAP" -v presence_on=1 "$AWK_LIB$AWK_MATCH" 2>/dev/null | emit_report
  status=("${PIPESTATUS[@]}")
  exit "${status[2]}"
}

main() {
  [[ $# -ge 1 ]] || usage
  case "$1" in
  range) shift; mode_range "$@" ;;
  text) shift; mode_text "$@" ;;
  full) shift; mode_full "$@" ;;
  --self-test) shift; self_test "$@" ;;
  *) usage ;;
  esac
}

# ---------------------------------------------------------------------------------------------------
# Self-test. Every repository and every planted value is built at run time: no tracked file carries a
# secret-shaped string. Every program the script starts runs behind an argument recorder.
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

# st_check <label> <command...>: the command succeeds. st_check_not: it fails.
st_check() {
  local label="$1"
  shift
  if "$@"; then st_ok 0 "$label"; else st_ok 1 "$label"; fi
}

st_is() { [[ "$1" == "$2" ]]; }

st_check_not() {
  local label="$1"
  shift
  if "$@"; then st_ok 1 "$label"; else st_ok 0 "$label"; fi
}

st_rnd() {
  LC_ALL=C tr -dc "$2" </dev/urandom 2>/dev/null | head -c "$1"
}

st_git() {
  git -c user.name='Dev Person' -c user.email="dev@$ST_DOMAIN" -c init.defaultBranch=master -c commit.gpgsign=false \
    -c tag.gpgsign=false -c core.autocrlf=false "$@"
}

st_commit() {
  st_git add -A >/dev/null 2>&1
  printf '%s\n' "$1" | st_git commit -q --allow-empty -F - >/dev/null 2>&1
}

# The planted values: assembled from fragments and random characters.
st_values() {
  ST_AWS_DELETED="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_KEEP="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_ENV="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_RANGE="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_BASE="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_MERGE="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_HOSTILE="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_HOSTILE2="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_PATH="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_ISSUE="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_AWS_TEXT="AK""IA$(st_rnd 16 'A-Z0-9')"
  ST_GH_TOKEN="gh""p_$(st_rnd 36 'A-Za-z0-9')"
  ST_GH_EXAMPLE="gh""p_$(st_rnd 36 'A-Za-z0-9')"
  ST_PASSWORD="Pw$(st_rnd 14 'A-Za-z0-9')"
  ST_IP="10.$((RANDOM % 200 + 20)).$((RANDOM % 200 + 20)).$((RANDOM % 200 + 20))"
  ST_IP_ALLOWED="10.$((RANDOM % 200 + 20)).$((RANDOM % 200 + 20)).$((RANDOM % 200 + 20))"
  ST_HOST="build$(st_rnd 6 'a-z').intranet"
  ST_DOMAIN="mail$(st_rnd 8 'a-z').org"
  ST_TERM="Proj$(st_rnd 8 'a-z')"
  ST_VALUES=("$ST_AWS_DELETED" "$ST_AWS_KEEP" "$ST_AWS_ENV" "$ST_AWS_RANGE" "$ST_AWS_BASE" "$ST_AWS_MERGE"
    "$ST_AWS_HOSTILE" "$ST_AWS_HOSTILE2" "$ST_AWS_PATH" "$ST_AWS_ISSUE" "$ST_AWS_TEXT" "$ST_GH_TOKEN" "$ST_GH_EXAMPLE" "$ST_PASSWORD"
    "$ST_IP" "$ST_IP_ALLOWED" "$ST_HOST")
}

# The argument recorder: one wrapper per program, logging its argv (never its standard input).
# shellcheck disable=SC2016  # the wrappers are shell text written to files: their $ belongs to them
st_recorder() {
  local bin="$1" log="$2" prog real
  mkdir -p "$bin"
  for prog in git jq awk od tr shasum sha256sum openssl sed sort ps date mktemp mkdir rm cat cut head wc dirname \
    basename find grep env bash ls; do
    real="$(command -v "$prog" 2>/dev/null)" || continue
    [[ "$prog" == git ]] && continue
    printf '#!/bin/sh\n{ printf %%s %s; for a in "$@"; do printf "\\037%%s" "$a"; done; printf "\\n"; } >>%s\nexec %s "$@"\n' \
      "'$prog'" "'$log'" "'$real'" >"$bin/$prog"
  done
  for prog in curl wget nc ssh scp; do
    printf '#!/bin/sh\n{ printf %%s %s; for a in "$@"; do printf "\\037%%s" "$a"; done; printf "\\n"; } >>%s\nexit 1\n' \
      "'$prog'" "'$log'" >"$bin/$prog"
  done
  real="$(command -v git)"
  {
    printf '#!/bin/sh\n{ printf %%s git; for a in "$@"; do printf "\\037%%s" "$a"; done; printf "\\n"; } >>%s\n' "'$log'"
    printf 'case " $* " in *" clone "*) for a in "$@"; do dest="$a"; done\n'
    printf '  exec env GIT_CONFIG_GLOBAL=/dev/null %s clone --mirror --quiet --template= "$(%s %s)" "$dest" ;;\nesac\n' \
      "'$real'" "'$(command -v cat)'" "'$ST_TMP/fixture-path'"
    printf 'exec %s "$@"\n' "'$real'"
  } >"$bin/git"
  # gh: a stub that serves one JSON fixture per endpoint and never touches the network.
  {
    printf '#!/bin/sh\n{ printf %%s gh; for a in "$@"; do printf "\\037%%s" "$a"; done; printf "\\n"; } >>%s\n' "'$log'"
    printf 'ep=""\nfor a in "$@"; do case "$a" in repos/*|rate_limit|graphql) ep="$a" ;; esac; done\n'
    printf 'key=$(printf %%s "$ep" | %s %s %s)\n' "'$(command -v tr)'" "'/?&=.'" "'_____'"
    printf '[ -f %s/"$key".fail ] && exit 1\n[ -f %s/"$key".json ] || exit 1\nexec %s %s/"$key".json\n' \
      "'$ST_TMP/gh'" "'$ST_TMP/gh'" "'$(command -v cat)'" "'$ST_TMP/gh'"
  } >"$bin/gh"
  chmod +x "$bin"/*
}

st_gh_fixture() {
  local key
  key="$(printf '%s' "$1" | tr '/?&=.' '_____')"
  printf '%s' "$2" >"$ST_TMP/gh/$key.json"
}

# scan <name> <dir> <stdin> <args...>: runs the script behind the recorder with a private TMPDIR.
st_scan() {
  local name="$1" dir="$2" input="$3"
  shift 3
  : >"$ST_TMP/argv.log"
  (cd "$dir" && PATH="$ST_TMP/bin:$PATH" TMPDIR="$ST_TMP/tmpdir" "$BASH" "$SCRIPT_PATH" "$@" \
    <"$input" >"$ST_TMP/out/$name" 2>"$ST_TMP/err/$name")
  ST_RC=$?
  cp "$ST_TMP/argv.log" "$ST_TMP/argv/$name"
  ST_OUT="$(<"$ST_TMP/out/$name")"
  ST_ARGV="$(<"$ST_TMP/argv/$name")"
}

st_has() { [[ "$ST_OUT" == *"$1"* ]]; }
st_line() {
  # st_line <needle>...: one finding line carries every needle
  local line needle hit
  local IFS=$'\n'
  for line in $ST_OUT; do
    hit=1
    for needle in "$@"; do [[ "$line" == *"$needle"* ]] || { hit=0; break; }; done
    [[ $hit -eq 1 ]] && return 0
  done
  return 1
}
st_field() {
  # st_field <column> <needle>...: the column of the first finding line that carries every needle
  local column="$1" line needle hit
  shift
  local IFS=$'\n'
  for line in $ST_OUT; do
    hit=1
    for needle in "$@"; do [[ "$line" == *"$needle"* ]] || { hit=0; break; }; done
    if [[ $hit -eq 1 ]]; then
      printf '%s' "$line" | cut -f "$column"
      return 0
    fi
  done
  return 1
}

# No planted value in stdout, stderr, any recorded argument list, or any file left under TMPDIR.
st_no_leak() {
  local name="$1" value file text leak=0
  for value in "${ST_VALUES[@]}"; do
    [[ "$(<"$ST_TMP/out/$name")" == *"$value"* ]] && leak=1
    [[ "$(<"$ST_TMP/err/$name")" == *"$value"* ]] && leak=1
    [[ "$(<"$ST_TMP/argv/$name")" == *"$value"* ]] && leak=1
  done
  st_ok "$leak" "$name: no planted value on stdout, on stderr, or in a recorded argument list"
  leak=0
  while IFS= read -r file; do
    text="$(<"$file")"
    for value in "${ST_VALUES[@]}"; do [[ "$text" == *"$value"* ]] && leak=1; done
  done < <(find "$ST_TMP/tmpdir" -type f 2>/dev/null)
  [[ -z "$(find "$ST_TMP/tmpdir" -mindepth 1 2>/dev/null)" ]] || leak=1
  st_ok "$leak" "$name: no file is left under TMPDIR"
}

st_ascii() {
  [[ "$(LC_ALL=C tr -d '\t\n -~' <"$ST_TMP/out/$1" | wc -c | tr -d ' ')" == 0 ]]
}

st_build_main() {
  local repo="$1" side tagged
  mkdir -p "$repo" && cd "$repo" || return 1
  st_git init -q
  printf 'hello\n' >README
  st_commit 'start'
  printf 'key = "%s"\n' "$ST_AWS_DELETED" >config.php
  st_commit 'add the configuration'
  st_git rm -q config.php
  st_commit 'remove the configuration'
  side="$(git rev-parse HEAD)"
  printf 'token %s\n' "$ST_GH_TOKEN" >notes.txt
  st_commit "rotate $ST_GH_TOKEN"
  printf 'KEY=%s\n' "$ST_AWS_ENV" >.env
  st_commit 'add env'
  st_git rm -q .env
  st_commit 'drop env'
  printf 'Mapped Name <mapped@other-%s.net> <dev@%s>\n' "$(st_rnd 5 'a-z')" "$ST_DOMAIN" >.mailmap
  printf 'a.*(b+)+$ is literal\naaab\n' >terms.txt
  st_commit "work for $ST_TERM"
  printf 'GITHUB_TOKEN=%s\n' "$ST_GH_EXAMPLE" >.env.example
  printf 'aws = %s\n' "$ST_AWS_KEEP" >keep.txt
  st_commit 'keep a value'
  st_git checkout -q -b side "$side"
  printf 'dsn = "https://deploy:%s@db-%s.net/app"\n' "$ST_PASSWORD" "$(st_rnd 6 'a-z')" >side.txt
  st_commit 'side work'
  # shellcheck disable=SC2016  # a literal hostile branch name, never expanded
  st_git checkout -q -b 'b$(touch${IFS}marker)' "$side"
  printf 'nothing\n' >hostile.txt
  st_commit 'hostile branch'
  st_git checkout -q --detach "$side"
  printf 'server %s\n' "$ST_IP" >ip.txt
  st_commit 'release work'
  tagged="$(git rev-parse HEAD)"
  st_git tag -a rel-1 -m 'release one' "$tagged"
  st_git checkout -q master
}

st_build_range() {
  local repo="$1"
  mkdir -p "$repo" && cd "$repo" || return 1
  st_git init -q
  printf 'a\nb\n' >f.txt
  st_commit 'start'
  ST_ROOT="$(git rev-parse HEAD)"
  printf '{"extra":{"ai-olympus":{"history-exposure":{"allow":[{"rule":"private-ipv4","path":"net.txt"}]}}}}\n' >composer.json
  printf 'base %s\n' "$ST_AWS_BASE" >base.txt
  st_commit 'base work'
  ST_BASE="$(git rev-parse HEAD)"
  st_git update-ref refs/remotes/origin/master "$ST_BASE"
  st_git checkout -q -b pr "$ST_ROOT"
  printf '* diff=evil filter=evil\n' >.gitattributes
  printf 'aws = %s\n' "$ST_AWS_RANGE" >added.txt
  printf 'name = x\n' >settings.ini
  st_commit 'add a value'
  st_git rm -q added.txt
  st_commit 'remove the value'
  st_git mv settings.ini .env
  st_commit 'rename to env'
  st_git merge -q --no-edit "$ST_BASE" >/dev/null 2>&1
  printf '{"extra":{"ai-olympus":{"history-exposure":{"allow":[{"rule":"aws-access-key-id","path":"added.txt"}]}}}}\n' >composer.json
  st_commit 'allow-list on the branch'
  st_git checkout -q -b y
  printf 'a\nY\n' >f.txt
  st_commit 'y side'
  st_git checkout -q pr
  printf 'a\nP\n' >f.txt
  st_commit 'p side'
  st_git merge -q --no-edit y >/dev/null 2>&1
  printf 'a\nresolved %s\n' "$ST_AWS_MERGE" >f.txt
  st_commit 'merge y'
  printf 'k = %s\n' "$ST_AWS_HOSTILE" >'--output=marker'
  # shellcheck disable=SC2016  # a literal hostile path, never expanded
  printf 'k = %s\n' "$ST_AWS_HOSTILE2" >'$(touch marker).txt'
  printf 'x\n' >"$ST_AWS_PATH.txt"
  printf 'net %s\n' "$ST_IP_ALLOWED" >net.txt
  printf 'host %s\n' "$ST_HOST" >"$(printf 'caf\303\251.txt')"
  # shellcheck disable=SC2016  # a literal template value, never expanded
  printf 'dsn = "postgres://app:${DB_PASS}@db.%s.net/x"\nt = gh%s\ncidr = 10.0.0.0/8\nweb = https://user:%s@example.com/\n' \
    "$(st_rnd 5 'a-z')" "p_$(printf 'x%.0s' $(seq 1 36))" "$ST_PASSWORD" >placeholders.txt
  printf '\000\001\002binary\n' >blob.bin
  printf '%*s\n' 5000 '' | tr ' ' 'a' >long.txt
  st_commit 'hostile and edge cases'
  ST_HEAD="$(git rev-parse HEAD)"
  ST_MERGE="$(git rev-parse HEAD~1)"
  git config diff.evil.textconv "touch $ST_TMP/marker-textconv; cat"
  git config diff.evil.command "touch $ST_TMP/marker-extdiff"
  git config filter.evil.smudge "touch $ST_TMP/marker-smudge; cat"
  git config core.pager "touch $ST_TMP/marker-pager; cat"
  git config core.fsmonitor "$ST_TMP/marker-fsmonitor-hook"
  printf '#!/bin/sh\ntouch %s/marker-fsmonitor\n' "$ST_TMP" >"$ST_TMP/marker-fsmonitor-hook"
  chmod +x "$ST_TMP/marker-fsmonitor-hook"
}

st_range_cases() {
  local repo="$ST_TMP/range" status_before fp
  st_build_range "$repo" || { st_ok 1 'build the range fixture'; return; }
  status_before="$(cd "$repo" && git -c core.fsmonitor=false status --porcelain --ignored 2>/dev/null | sort)"

  st_scan range "$repo" /dev/null range --base "$ST_BASE" --head "$ST_HEAD"
  st_res=0
  [[ $ST_RC == 5 ]] || st_res=1
  st_ok "$st_res" 'range: a complete scan with findings exits 5'
  st_check 'range: a value one commit adds and the next removes is reported' st_line $'credential\taws-access-key-id\tline' 'added.txt'
  st_check 'range: an allow-list entry the branch adds to its own manifest suppresses nothing' st_line $'aws-access-key-id\tline' 'added.txt'
  st_check 'range: a value only in a merge commit'"'"'s conflict resolution is reported with the merge commit' st_line $'credential\taws-access-key-id\tline' "$ST_MERGE" 'f.txt'
  st_check_not 'range: a value merged in from the default branch is no finding' st_line 'base.txt'
  st_check 'range: a file renamed to .env is an added sensitive path' st_line $'sensitive-file\tenv-file\tpath' $'\t.env'
  st_check 'range: a path named --output=marker is reported as a path' st_line $'\t--output=marker'
  # shellcheck disable=SC2016  # a literal hostile path, never expanded
  st_check 'range: a path carrying $(touch marker) is reported as a path' st_line '$(touch marker).txt'
  st_res=0
  [[ ! -e "$repo/marker" && ! -e "$ST_TMP/marker" && ! -e "$repo/--output=marker.out" ]] || st_res=1
  st_ok "$st_res" 'range: a hostile path runs nothing and writes no marker'
  st_res=0
  [[ ! -e "$ST_TMP/marker-textconv" && ! -e "$ST_TMP/marker-extdiff" && ! -e "$ST_TMP/marker-smudge" && ! -e "$ST_TMP/marker-pager" && ! -e "$ST_TMP/marker-fsmonitor" ]] || st_res=1
  st_ok "$st_res" 'range: no textconv, external diff, smudge, pager, or fsmonitor program runs'
  st_check 'range: a path that matches a credential rule is printed redacted' st_line $'\t<redacted:aws-access-key-id>.txt'
  st_check 'range: a non-ASCII path is printed escaped' st_line $'infrastructure\tinternal-hostname' '"caf\303\251.txt"'
  st_check 'range: every printed byte is printable ASCII' st_ascii range
  st_check 'range: the default-branch allow-list suppresses one counted finding' st_has 'suppressed: 1 '
  st_check_not 'range: the suppressed finding is not printed' st_line 'net.txt'
  st_check_not 'range: template values, one-character runs, CIDR, and documentation hosts are no finding' st_line 'placeholders.txt'
  st_res=0
  [[ "$ST_OUT" =~ skipped-placeholder:\ ([0-9]+) && "${BASH_REMATCH[1]}" -ge 3 ]] || st_res=1
  st_ok "$st_res" 'range: skipped placeholders are counted'
  st_check 'range: a binary blob is listed under not-verified' st_has 'binary blobs'
  st_check 'range: a line over the cap is cut and counted under not-verified' st_has '1 lines cut at 4096 bytes'
  st_res=0
  [[ "$ST_ARGV" != *$'\037clone\037'* && "$ST_ARGV" != *$'\037fetch\037'* && "$ST_ARGV" != *$'\037ls-remote'* && "$ST_ARGV" != *$'\n'gh* && "$ST_ARGV" != gh* && "$ST_ARGV" != *curl* ]] || st_res=1
  st_ok "$st_res" 'range: no clone, fetch, ls-remote, gh, or curl call'
  st_res=0
  [[ "$ST_ARGV" == *$'\037--no-pager\037--no-lazy-fetch\037--no-replace-objects'* ]] || st_res=1
  st_ok "$st_res" 'range: git runs with --no-pager --no-lazy-fetch --no-replace-objects'
  st_res=0
  [[ "$(cd "$repo" && git -c core.fsmonitor=false status --porcelain --ignored 2>/dev/null | sort)" == "$status_before" && ! -e "$repo/.claude" ]] || st_res=1
  st_ok "$st_res" 'range: review mode creates no file in the checkout'
  st_no_leak range
  fp="$(st_field 6 $'aws-access-key-id\tline' 'added.txt')"

  st_scan range-again "$repo" /dev/null range --base "$ST_BASE" --head "$ST_HEAD"
  st_res=0
  [[ -n "$fp" && "$(st_field 6 $'aws-access-key-id\tline' 'added.txt')" != "$fp" ]] || st_res=1
  st_ok "$st_res" 'range: one value found in two runs carries two different fingerprints'
  st_res=0
  [[ "$fp" != "$(printf '%s' "$ST_AWS_RANGE" | "${HASH[@]}" | cut -c1-12)" ]] || st_res=1
  st_ok "$st_res" 'range: a fingerprint differs from the unsalted SHA-256 prefix of its value'

  st_scan range-xtrace "$repo" /dev/null range --base "$ST_BASE" --head "$ST_HEAD"
  (cd "$repo" && env SHELLOPTS=xtrace PATH="$ST_TMP/bin:$PATH" TMPDIR="$ST_TMP/tmpdir" "$BASH" "$SCRIPT_PATH" range \
    --base "$ST_BASE" --head "$ST_HEAD" >"$ST_TMP/out/range-xtrace" 2>"$ST_TMP/err/range-xtrace")
  st_no_leak range-xtrace

  st_scan range-missing "$repo" /dev/null range --base "$ST_BASE" --head "$(printf '%040d' 0 | tr 0 e)"
  st_res=0
  [[ $ST_RC == 3 ]] || st_res=1
  st_ok "$st_res" 'range: a missing object exits 3, never 0'

  mkdir -p "$ST_TMP/no-tools"
  (cd "$repo" && PATH="$ST_TMP/no-tools" "$BASH" "$SCRIPT_PATH" range --base "$ST_BASE" --head "$ST_HEAD" >/dev/null 2>&1)
  st_check 'range: a missing tool exits 2' st_is "$?" 2

  st_scan range-usage "$repo" /dev/null range --base HEAD --head HEAD
  st_res=0
  [[ $ST_RC == 1 ]] || st_res=1
  st_ok "$st_res" 'range: a ref name instead of an object id is a usage error'
}

st_text_cases() {
  local json="$ST_TMP/pr.json"
  jq -n --arg body "key $ST_AWS_TEXT" --arg comment "see $ST_HOST" '{url: "https://github.com/o/r/pull/9", title: "Fix", body: $body,
    comments: [{url: "https://github.com/o/r/pull/9#issuecomment-1", body: $comment}], reviewComments: [], reviews: [], reviewCommentsComplete: true}' >"$json"
  st_scan text "$ST_TMP" "$json" text
  st_res=0
  [[ $ST_RC == 5 ]] || st_res=1
  st_ok "$st_res" 'text: a pull-request text with findings exits 5'
  st_check 'text: a credential in the body is reported with the pull-request URL' st_line $'credential\taws-access-key-id\tgithub' 'https://github.com/o/r/pull/9'
  st_check 'text: an internal host in a comment is reported with the comment URL' st_line $'infrastructure\tinternal-hostname\tgithub' '#issuecomment-1'
  st_res=0
  [[ "$ST_ARGV" != *$'\n'gh* && "$ST_ARGV" != gh* && "$ST_ARGV" != *curl* ]] || st_res=1
  st_ok "$st_res" 'text: no gh or curl call'
  st_no_leak text
}

st_full_fixtures() {
  rm -rf "$ST_TMP/gh"
  mkdir -p "$ST_TMP/gh"
  local repo_json='{"full_name":"o/r","html_url":"https://github.com/o/r","permissions":{"push":true},"fork":false,"archived":false,"private":false,"visibility":"public","description":"demo","topics":["demo"],"homepage":"","security_and_analysis":{"secret_scanning":{"status":"enabled"},"secret_scanning_push_protection":{"status":"disabled"}}}'
  st_gh_fixture 'repos/o/r' "$repo_json"
  st_gh_fixture 'repos/o/clean' "${repo_json//o\/r/o\/clean}"
  st_gh_fixture 'repos/o/plain' '{"full_name":"o/plain","html_url":"https://github.com/o/plain","permissions":{"push":true},"fork":false,"private":false}'
  st_gh_fixture 'repos/o/nopush' '{"full_name":"o/nopush","permissions":{"push":false}}'
  st_gh_fixture 'repos/o/noperm' '{"full_name":"o/noperm"}'
  st_gh_fixture 'repos/o/nullpush' '{"full_name":"o/nullpush","permissions":{"push":null}}'
  st_gh_fixture 'repos/o/fork' '{"full_name":"o/fork","permissions":{"push":true},"fork":true,"parent":{"full_name":"up/r"},"source":{"full_name":"up/r"}}'
  st_gh_fixture 'repos/up/r' '{"full_name":"up/r","permissions":{"push":false}}'
  st_gh_fixture 'rate_limit' '{"resources":{"core":{"remaining":5000,"reset":1700000000}}}'
  local name
  for name in o/r o/clean o/plain; do
    st_gh_fixture "repos/$name/issues?state=all&per_page=100" '[]'
    st_gh_fixture "repos/$name/issues/comments?per_page=100" '[]'
    st_gh_fixture "repos/$name/pulls/comments?per_page=100" '[]'
    st_gh_fixture "repos/$name/comments?per_page=100" '[]'
    st_gh_fixture "repos/$name/releases?per_page=100" '[]'
    st_gh_fixture "repos/$name/pulls?state=all&per_page=100" '[]'
  done
  st_gh_fixture 'repos/o/r/issues?state=all&per_page=100' \
    "$(jq -cn --arg b "leaked $ST_AWS_ISSUE" '[{html_url: "https://github.com/o/r/issues/1", title: "Bug", body: $b}]')"
  st_gh_fixture 'repos/o/r/pulls?state=all&per_page=100' '[{"number":2}]'
  st_gh_fixture 'repos/o/r/pulls/2/reviews?per_page=100' '[{"html_url":"https://github.com/o/r/pull/2#pullrequestreview-1","body":"fine"}]'
  st_gh_fixture 'graphql' '{"data":{"repository":{"hasDiscussionsEnabled":true,"discussions":{"nodes":[]}}}}'
}

st_home() {
  local home="$1"
  mkdir -p "$home"
  (cd "$home" && git init -q && printf '.claude/run/\n' >.gitignore)
}

st_full_cases() {
  local main="$ST_TMP/main" clean="$ST_TMP/clean" home="$ST_TMP/home" bare="$ST_TMP/bare" terms="$ST_TMP/terms.txt"
  local dead fp1 fp2 line st_env_read
  st_build_main "$main" || { st_ok 1 'build the full-audit fixture'; return; }
  mkdir -p "$clean" && (cd "$clean" && git init -q && printf 'plain\n' >README && git add README &&
    git -c user.name='dependabot[bot]' -c user.email='1+dependabot[bot]@users.noreply.github.com' commit -q -m start)
  st_full_fixtures
  st_home "$home"
  printf '%s\n%s\n' "$ST_TERM" 'a.*(b+)+$' >"$terms"
  mkdir -p "$home/.claude/run/history-exposure/run.dead" "$home/.claude/run/history-exposure/run.live"
  sh -c ':' &
  dead=$!
  wait "$dead"
  printf 'PID=%s\nSTARTED=2020-01-01T00:00:00Z\nSTARTED_EPOCH=1577836800\n' "$dead" >"$home/.claude/run/history-exposure/run.dead/holder"
  printf 'PID=%s\n' "$$" >"$home/.claude/run/history-exposure/run.live/holder"

  printf '%s' "$main" >"$ST_TMP/fixture-path"
  st_scan full "$home" /dev/null full --repo o/r --terms-file "$terms"
  st_res=0
  [[ $ST_RC == 5 ]] || st_res=1
  st_ok "$st_res" 'full: a complete audit with findings exits 5'
  st_check 'full: a value in a file a later commit deleted is found, and the head no longer carries it' st_line $'credential\taws-access-key-id\tline' 'head=no' 'config.php'
  st_check 'full: a value the default-branch head still carries says head=yes' st_line $'credential\taws-access-key-id\tline' 'head=yes' 'keep.txt'
  st_check 'full: a value only on a non-default branch is found' st_line $'credential\turl-password\tline' 'side.txt'
  st_check 'full: a value only in a tagged commit names the tag' st_line $'infrastructure\tprivate-ipv4\tline' 'head=no' 'tags=rel-1' 'ip.txt'
  fp1="$(st_field 6 $'github-token\tmessage')"
  fp2="$(st_field 6 $'github-token\tline' 'notes.txt')"
  st_res=0
  [[ -n "$fp1" && "$fp1" == "$fp2" ]] || st_res=1
  st_ok "$st_res" 'full: a value in a commit message is found, and one value found twice carries one fingerprint'
  st_check 'full: a .env path added and removed is a sensitive-file finding' st_line $'sensitive-file\tenv-file\tpath' $'\t.env'
  st_env_read=0
  while IFS= read -r line; do
    [[ "$line" == finding$'\t'credential$'\t'* && "$line" == *$'\t.env' ]] && st_env_read=1
  done <<<"$ST_OUT"
  st_ok "$st_env_read" 'full: the content of a .env blob is never read'
  st_check 'full: the content of .env.example is scanned' st_line $'credential\tgithub-token\tline' '.env.example'
  st_check 'full: a commit e-mail domain is an identity finding that prints the domain' st_line $'identity\tcommit-email-domain' "$ST_DOMAIN"
  st_check_not 'full: a committed .mailmap changes no reported identity' st_line $'commit-email-domain' 'other-'
  st_check 'full: a user-supplied term is found' st_line $'named-term\tuser-term\tmessage' "$ST_TERM"
  st_check 'full: a term that looks like a pattern matches only its literal text' st_line $'named-term\tuser-term\tline' 'a.*(b+)+$' 'occurrences=1'
  st_check 'full: a credential in an issue body is found' st_line $'credential\taws-access-key-id\tgithub' 'https://github.com/o/r/issues/1'
  st_check 'full: the secret-scanning and push-protection status is reported' st_has 'secret-scanning: enabled push-protection: disabled'
  st_check 'full: the surfaces the scan cannot read are listed under not-verified' st_has 'not-verified: edit history of edited GitHub texts'
  st_res=0
  { st_has 'outbound-request: github.com clone o/r' && st_has 'outbound-request: github.com GET repos/o/r/issues?state=all&per_page=100'; } || st_res=1
  st_ok "$st_res" 'full: every network read prints an outbound-request line'
  st_res=0
  [[ "$ST_ARGV" == *$'\037-c\037protocol.https.allow=always\037-c\037credential.helper=\037-c\037credential.helper=!gh auth git-credential\037clone\037--mirror\037--template=\037--quiet\037--config\037core.hooksPath=/dev/null\037--config\037core.fsmonitor=false\037--config\037protocol.allow=never\037https://github.com/o/r.git'* ]] || st_res=1
  st_ok "$st_res" 'full: the clone is a hardened mirror clone of a URL the script composed'
  st_res=0
  [[ "$ST_ARGV" == *$'\037-c\037protocol.allow=never\037-c\037protocol.https.allow=always'* && "$ST_ARGV" != *--filter* ]] || st_res=1
  st_ok "$st_res" 'full: the clone allows https only and passes no --filter'
  st_res=0
  [[ "$ST_ARGV" != *--hostname* && "$ST_ARGV" != *secret-scanning/alerts* ]] || st_res=1
  st_ok "$st_res" 'full: gh never receives --hostname and the secret-scanning alerts are never read'
  st_res=0
  [[ ! -e "$home/marker" && ! -e "$ST_TMP/marker" && ! -e "$ST_TMP/main/marker" ]] || st_res=1
  # shellcheck disable=SC2016  # a literal hostile branch name, never expanded
  st_ok "$st_res" 'full: a branch named with $(touch marker) runs nothing'
  st_res=0
  [[ -z "$(find "$home/.claude/run/history-exposure" -maxdepth 1 -name 'run.*' ! -name run.live 2>/dev/null)" && -d "$home/.claude/run/history-exposure/run.live" ]] || st_res=1
  st_ok "$st_res" 'full: the run directory is removed, a dead sibling is swept, and an inconclusive one is kept'
  st_check 'full: every printed byte is printable ASCII' st_ascii full
  st_no_leak full

  st_scan full-twice "$home" /dev/null full --repo o/r --terms-file "$terms"
  st_res=0
  [[ -n "$fp1" && "$(st_field 6 $'github-token\tmessage')" != "$fp1" ]] || st_res=1
  st_ok "$st_res" 'full: two runs give one value two fingerprints'

  printf '%s' "$clean" >"$ST_TMP/fixture-path"
  st_scan full-clean "$home" /dev/null full --repo o/clean
  st_res=0
  [[ $ST_RC == 0 && "$ST_OUT" == *'status=clean'* ]] || st_res=1
  st_ok "$st_res" 'full: a clean repository exits 0'

  st_scan full-plain "$home" /dev/null full --repo o/plain
  st_check 'full: an absent security_and_analysis block puts the status under not-verified' \
    st_has 'not-verified: secret scanning and push protection status - the API returned no security_and_analysis block'

  : >"$ST_TMP/gh/repos_o_clean_releases_per_page_100.fail"
  st_scan full-failed "$home" /dev/null full --repo o/clean
  st_res=0
  [[ $ST_RC == 3 && "$ST_OUT" == *'not-verified: releases'* ]] || st_res=1
  st_ok "$st_res" 'full: a failed GitHub surface is unread and exits 3'
  rm -f "$ST_TMP/gh/repos_o_clean_releases_per_page_100.fail"

  st_gh_fixture 'rate_limit' '{"resources":{"core":{"remaining":3,"reset":1700000000}}}'
  st_scan full-rate "$home" /dev/null full --repo o/clean
  st_res=0
  [[ $ST_RC == 3 && "$ST_OUT" == *'rate limit leaves 3 requests'*'2023-11-14T22:13:20Z'* ]] || st_res=1
  st_ok "$st_res" 'full: a rate limit that cannot cover the reads exits 3 and names the reset time'
  st_gh_fixture 'rate_limit' '{"resources":{"core":{"remaining":5000,"reset":1700000000}}}'

  local target reason
  for target in 'o/nopush:cannot push' 'o/noperm:no permissions field' 'o/nullpush:permissions.push is null' \
    'o/gone:the GitHub API call failed' 'o/fork:up/r: the authenticated account cannot push' 'o/r/x:<owner>/<repo> on github.com' \
    'github.example/o:<owner>/<repo> on github.com'; do
    reason="${target#*:}"
    st_scan full-refused "$home" /dev/null full --repo "${target%%:*}"
    st_res=0
    [[ $ST_RC == 4 && "$(<"$ST_TMP/err/full-refused")" == *"$reason"* ]] || st_res=1
    st_ok "$st_res" "full: ${target%%:*} is refused with the reason ($reason)"
  done
  st_res=0
  [[ "$ST_ARGV" != *clone* ]] || st_res=1
  st_ok "$st_res" 'full: a refused target is never cloned'

  printf 'ok\n%s\n' "$(printf 'bad\001term')" >"$ST_TMP/bad-terms.txt"
  st_scan full-terms "$home" /dev/null full --repo o/r --terms-file "$ST_TMP/bad-terms.txt"
  st_res=0
  [[ $ST_RC == 1 ]] || st_res=1
  st_ok "$st_res" 'full: a term with a control character is refused with exit 1'

  mkdir -p "$bare" && (cd "$bare" && git init -q)
  st_scan full-unignored "$bare" /dev/null full --repo o/clean
  st_res=0
  [[ $ST_RC == 1 && -z "$(find "$bare/.claude/run/history-exposure" -name 'run.*' 2>/dev/null)" ]] || st_res=1
  st_ok "$st_res" 'full: a run root git does not ignore is refused'
}

# The package's own tracked tree must give no finding, so the package never blocks its own reviews and
# no tracked file carries a string the rules match. Runs only inside the package checkout.
st_package_case() {
  local pkg dest root head
  pkg="$(cd "$SCRIPT_DIR/../../.." && pwd -P)"
  [[ -f "$pkg/composer.json" ]] && [[ "$(jq -r '.name // ""' "$pkg/composer.json" 2>/dev/null)" == pekral/ai-olympus ]] &&
    git -C "$pkg" rev-parse --is-inside-work-tree >/dev/null 2>&1 || return 0
  dest="$ST_TMP/package"
  mkdir -p "$dest"
  (cd "$pkg" && git ls-files -z | tar --null -T - -cf - 2>/dev/null) | (cd "$dest" && tar -xf - 2>/dev/null)
  (cd "$dest" && git init -q && st_git commit -q --allow-empty -m root && st_git add -A >/dev/null 2>&1 && st_git commit -q -m tree) || {
    st_ok 1 'package: build a repository from the tracked tree'
    return
  }
  root="$(cd "$dest" && git rev-parse HEAD~1)"
  head="$(cd "$dest" && git rev-parse HEAD)"
  st_scan package "$dest" /dev/null range --base "$root" --head "$head"
  st_res=0
  [[ $ST_RC == 0 && "$ST_OUT" == *'status=clean'* ]] || st_res=1
  st_ok "$st_res" 'package: the tracked tree of this package gives no finding'
}

self_test() {
  local tmp
  tmp="$(mktemp -d)" || exit 1
  ST_TMP="$(cd "$tmp" && pwd -P)"
  trap 'rm -rf "$ST_TMP"' EXIT
  export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
  mkdir -p "$ST_TMP/out" "$ST_TMP/err" "$ST_TMP/argv" "$ST_TMP/tmpdir" "$ST_TMP/gh"
  need_tools git jq awk od tr
  pick_hash
  st_values
  st_recorder "$ST_TMP/bin" "$ST_TMP/argv.log"

  st_range_cases
  st_text_cases
  st_full_cases
  st_package_case

  printf '%s: self-test %s passed, %s failed (%s checks)\n' "$PROG" "$ST_PASS" "$ST_FAIL" "$((ST_PASS + ST_FAIL))"
  [[ $ST_FAIL -eq 0 ]]
}

main "$@"
