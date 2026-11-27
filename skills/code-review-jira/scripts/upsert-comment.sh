#!/usr/bin/env bash
# upsert-comment.sh — JIRA issue comment publisher used by CR-track skills.
# Without `--create`, each invocation looks for a comment already carrying this
# actor's marker on the target issue and updates the newest match; only when no
# match exists does it create a new comment. Such a namespace keeps one comment
# per actor and marker namespace instead of a growing chain. With `--create`,
# every invocation creates a new comment: every code-review report is published
# that way, so each review run adds its own comment.
#
# This reverses the always-new behaviour a previous explicit request
# introduced (see CHANGELOG). The lookup-and-update branch is added on a newer
# explicit request from the same owner; the older CHANGELOG entry stays as
# history.
#
# JIRA has no hidden-comment mechanism, so the marker is an italic line:
#   _cr-comment:actor=<actor-digest>_
# A source that carries the slot line `{agent-marker}` gets the marker in place of
# that slot, which the code-review template puts inside a collapsed block, so a
# reader sees it only on purpose. Any other source gets the marker as the last
# line of the body.
#
# The actor half is the first 16 hex characters of the SHA-256 digest of the
# authenticated account e-mail, never the address itself. The marker is readable
# by everyone who can browse the issue, including an external customer on a
# Service Management project, so publishing the address there would expose the
# very field Jira Cloud hides from its own API responses for privacy. The digest
# is stable across runs, which is all the lookup needs.
#
# Usage:
#   upsert-comment.sh [--create | --comment-id <ID>] <KEY|URL> <BODY_FILE> [<MARKER_KEY>]
#   <body-producer> | upsert-comment.sh [--create | --comment-id <ID>] <KEY|URL> - [<MARKER_KEY>]
#   upsert-comment.sh --create --screenshots <MANIFEST> --slug <SLUG> <KEY|URL> <BODY_FILE> [<MARKER_KEY>]
#
# Optional media require an accepted GitHub finding and verified screenshot. Media and explicit operator edits use the bound public API publication context.
# CR summaries begin with the AI Olympus byline and are create-only. A one-time migration of a marked
# comment requires its exact ID plus --previous-adf with the previously verified, unmodified ADF.
#
#   --comment-id <ID>
#               Optional. Update exactly that comment instead of the newest one of this actor and
#               namespace. The comment must be authored by this actor and carry this actor's marker of the
#               namespace; otherwise nothing is written and the script exits 3. `skills/merge-review-comments`
#               uses it to put merged text into the comment that has a reply. It never combines with
#               `--create` or with the `agent-note` namespace.
#   --create    Optional. Always create a new comment in the given namespace and
#               never update an existing one, so nothing is carried over from an
#               earlier comment. The before-create snapshot still runs, because
#               the new comment ID is resolved against it. Every code-review
#               report publish passes it (the `pr-summary` JIRA comment). The
#               marker namespace stays unchanged, so every marker-based check
#               reads the newest comment, and a later run without the flag
#               updates the newest comment as usual.
#   KEY|URL     Bare JIRA issue key (e.g. ACME-1234), a /browse/<KEY> URL,
#               or any URL containing ?selectedIssue=<KEY>.
#   BODY_FILE   Path to a file holding the JIRA Wiki Markup source, or `-` to
#               read from stdin. The helper converts it to ADF before publish.
#   MARKER_KEY  Optional. Marker namespace, defaults to `cr-comment`; it must
#               match [a-z][a-z0-9-]*. `agent-note` switches the marker to
#               `agent-note:actor=` and skips the lookup-and-update behaviour
#               below entirely — every call in this mode CREATEs a fresh
#               comment, never looks up or updates an existing one, so a
#               runbook or a note the operator asked an agent to leave as its
#               own comment is never picked up and overwritten by a later CR
#               publish. Every other namespace behaves like `cr-comment`: the
#               run looks up and updates this actor's newest comment in that
#               namespace, and never touches a comment of another namespace. A
#               project skill passes its own namespace so its comment neither
#               overwrites the CR comment nor duplicates itself on a rerun.
#
# Behavior:
#   1. Detect the site and the account e-mail from `acli jira auth status`.
#      That status output carries no account ID, and no `acli` subcommand
#      returns one for the current user, so the account ID is resolved through
#      JQL `currentUser()` (`jira_actor_account_id` in jira-actor.sh).
#   2. Derive the actor digest from that e-mail and write the marker line
#      `_<namespace>:actor=<actor-digest>_` into the Wiki Markup source (only when
#      the source does not already carry it): in place of the last `{agent-marker}`
#      slot line, or appended as the last line when the source has no slot. The raw address never leaves this
#      process: it is used only for the local author comparison in step 4.
#   3. Convert the Wiki Markup source to Atlassian Document Format (ADF).
#   4. Read the issue's comments through `acli jira workitem view <KEY>
#      --fields comment --json` and pick the newest one this account authored
#      whose body carries that marker. The author matches on the account ID,
#      or on the e-mail when no account ID was resolved; a visible e-mail that
#      differs disqualifies the comment either way. `comment list` is not used:
#      acli 1.3.x returns the author there as a bare display name and the body
#      as flattened text, so neither half of the match can be made against it.
#      Both halves
#      are load-bearing: the marker is visible text anyone can copy into their
#      own comment, so the author
#      must match too, and the marker is matched against the comment body alone
#      rather than against the whole comment object.
#   5. Carry every top-level ADF node (every child of a collapsed `expand`
#      section) the operator added to the matched comment
#      into the new body verbatim (`skills/_shared/carry-operator-lines.php`),
#      mentions and formatting included. The operator posts under the same
#      account, so a node counts as the operator's when the fingerprint of
#      agent-written nodes the previous version recorded does not know it. Every
#      published body records that fingerprint as ` lines=<h>,<h>` inside the
#      visible marker line. `agent-note` is never rewritten, so it skips this step.
#      stderr carries `carried_lines=<n>` and one `carried: <text>` per carried
#      node; the calling skill states every carried line in its report.
#   6. When a match exists, update it via
#      `acli jira workitem comment update --body-adf`. Otherwise create a fresh
#      comment from the ADF file and immediately update that same new comment
#      through the same `--body-adf` path. Passing ADF to both calls ensures a
#      failed update never leaves Wiki Markup behind. `comment create --json`
#      reports a per-work-item status and no comment ID on acli 1.3.x, so the
#      new ID is resolved by re-reading the comments. The target is the one
#      comment that was absent before the create, has this author, and whose
#      stored visible text equals the visible text of the ADF this run sent, the
#      marker text left out on both sides. One such comment that carries the
#      marker ends the re-reads; any other result waits for all three re-reads,
#      one second apart, because Jira Cloud can answer a read after a create
#      without the new comment. No single match leaves every comment untouched.
#   7. Read the comment back, up to three times, and require this actor's marker
#      in the stored body and this account as its author. A created comment that
#      fails the read-back is deleted; an updated one is reported with its ID.
#      Either way the helper exits 3, so no agent comment stays on the issue
#      without a marker unannounced.
#
# One result is one comment, for every call without `--create`. The helper never
# creates a comment it cannot prove is the first one, so a rerun — including a
# caller's retry after exit 3 — updates the comment an earlier run created or
# refuses, and never duplicates it. A rerun with `--create` creates a second
# comment, so its caller reads the issue back before it retries:
#   - A failed or unparsable comment lookup exits 3 and creates nothing. Without
#     the lookup, a create could duplicate a comment this actor already owns.
#   - A comment carrying this actor's marker whose author can be neither
#     confirmed nor ruled out exits 3 and creates nothing. Jira Cloud hides
#     `author.emailAddress` whenever the account restricts its e-mail
#     visibility, so the account ID carries the match; only when that is
#     unresolvable too is the author undecidable. Updating a comment the helper
#     cannot prove it owns would risk overwriting a stranger's, so it neither
#     updates nor duplicates it.
# An unresolvable account e-mail is not published unmarked: without a marker,
# a later run cannot tell this agent comment from the operator's own, so the
# script exits 3 and creates nothing instead.
# `agent-note` mode (MARKER_KEY == "agent-note") is the deliberate exception to
# the lookup-and-update half of this contract, never to the marker: every call
# still takes the before-create snapshot and still requires a marker, but
# creates a fresh comment by design — never an update — so a separate agent
# comment (a runbook, a note) is never picked up and overwritten by a later
# cr-comment publish, and a create that returns no ID never resolves to an
# older note either.
#
# Output:
#   The published comment URL on stdout. `action=updated id=<id>` (an existing
#   comment was updated) or `action=created id=<id>` (a new one was created) on
#   stderr, for the calling skill to log in its summary line.
#
# Audit trail:
#   Each write appends its own line to the run's `.audit` file at the moment it
#   happens, through `skills/_shared/audit-trail.sh record`: an `external-write`
#   line with the comment URL, or a `note` line for a failed attempt, both with
#   the acting role and the real exit status of the `acli` call. The ledger
#   comes from AUDIT_TRAIL_FILE and AUDIT_TRAIL_ROLE; a missing ledger prints a
#   hint on stderr and never changes the output or the exit code.
#
# Exit codes:
#   1  usage / argument error
#   2  missing required tool (acli, jq, php)
#   3  JIRA API call failed, or the agent marker could not be derived (no
#      account e-mail in `acli jira auth status`)
set -euo pipefail

usage() {
  cat >&2 <<'EOF'
Usage: upsert-comment.sh [--create | --comment-id <ID>] <KEY|URL> <BODY_FILE|-> [<MARKER_KEY>]
       upsert-comment.sh --create --screenshots <MANIFEST> --slug <SLUG> <KEY|URL> <BODY_FILE|-> [<MARKER_KEY>]

  --summary   require the attributed CR-summary path
  --operator-edit
              with --summary, --comment-id and --previous-adf: requires the operator's direct request and run publication context
  --create    always create a new comment; never update an existing one
  --comment-id <ID>
              update exactly this comment of this actor and namespace; never create
  --screenshots <MANIFEST> --slug <SLUG>
              with --summary --create and the run publication context: attach verified images to their matching findings
  --previous-adf <file>
              previously verified stored ADF for an exact-ID migration of a marked summary
  KEY         JIRA issue key (e.g. ACME-1234)
  URL         /browse/<KEY> URL or any URL containing ?selectedIssue=<KEY>
  BODY_FILE   path to a file containing the comment body, or `-` for stdin
  MARKER_KEY  optional marker namespace matching [a-z][a-z0-9-]* (default:
              cr-comment); `agent-note` is create-only, every other namespace
              is looked up and updated in place
EOF
}

CREATE_ONLY=0
TARGET_COMMENT_ID=""
PREVIOUS_ADF=""
SUMMARY_REQUIRED=0
OPERATOR_EDIT=0
SHOTS_MANIFEST=""
SHOTS_SLUG=""
while [[ "${1:-}" == --* ]]; do
  case "$1" in
    --screenshots)
      if [[ $# -lt 2 ]]; then
        usage
        exit 1
      fi
      SHOTS_MANIFEST="$2"
      shift 2
      ;;
    --slug)
      if [[ $# -lt 2 ]]; then
        usage
        exit 1
      fi
      SHOTS_SLUG="$2"
      shift 2
      ;;
    --summary) SUMMARY_REQUIRED=1; shift ;;
    --operator-edit) OPERATOR_EDIT=1; shift ;;
    --previous-adf)
      [[ $# -ge 2 && -z "$PREVIOUS_ADF" && -f "$2" && -r "$2" ]] || { usage; exit 1; }
      PREVIOUS_ADF="$2"
      shift 2
      ;;
    --create)
      CREATE_ONLY=1
      shift
      ;;
    --comment-id)
      if [[ $# -lt 2 || -n "$TARGET_COMMENT_ID" || ! "$2" =~ ^[1-9][0-9]*$ ]]; then
        usage
        exit 1
      fi
      TARGET_COMMENT_ID="$2"
      shift 2
      ;;
    *)
      usage
      exit 1
      ;;
  esac
done
if [[ "$OPERATOR_EDIT" == 1 && ( "$SUMMARY_REQUIRED" != 1 || "$CREATE_ONLY" == 1 || -z "$TARGET_COMMENT_ID" || -z "$PREVIOUS_ADF" ) ]]; then
  usage
  exit 1
fi
if [[ "$CREATE_ONLY" -eq 1 && -n "$TARGET_COMMENT_ID" ]]; then
  usage
  exit 1
fi
if [[ -n "$SHOTS_MANIFEST" || -n "$SHOTS_SLUG" ]]; then
  if [[ -z "$SHOTS_MANIFEST" || -z "$SHOTS_SLUG" || "$CREATE_ONLY" -ne 1 ]]; then
    echo "upsert-comment.sh: --screenshots and --slug go together and need --create" >&2
    exit 1
  fi
fi

if [[ $# -lt 2 || $# -gt 3 || -z "${1:-}" || -z "${2:-}" ]]; then
  usage
  exit 1
fi

INPUT="$1"
BODY_SRC="$2"
# $3 (MARKER_KEY): the marker namespace. "agent-note" is create-only (see
# below); every other namespace is looked up and updated in place.
MODE="${3:-cr-comment}"

if [[ ! "$MODE" =~ ^[a-z][a-z0-9-]*$ ]]; then
  echo "upsert-comment.sh: MARKER_KEY must match [a-z][a-z0-9-]* — got: $MODE" >&2
  exit 1
fi

if [[ -n "$TARGET_COMMENT_ID" && "$MODE" == "agent-note" ]]; then
  echo "upsert-comment.sh: --comment-id never combines with the create-only agent-note namespace" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ "$OPERATOR_EDIT" == 1 || -n "$SHOTS_MANIFEST" ]]; then
  [[ "$SUMMARY_REQUIRED" == 1 && "$MODE" == cr-comment ]] || { usage; exit 1; }
  for bin in acli jq php python3; do
    command -v "$bin" >/dev/null 2>&1 || { echo "upsert-comment.sh: required tool not found: $bin" >&2; exit 2; }
  done
  exec python3 -B "$SCRIPT_DIR/jira_comment_api.py" "$INPUT" "$BODY_SRC" "$TARGET_COMMENT_ID" "$PREVIOUS_ADF" "$SHOTS_MANIFEST" "$SHOTS_SLUG"
fi

for bin in acli jq php; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "upsert-comment.sh: required tool not found: $bin" >&2
    exit 2
  fi
done

KEY=""
if [[ "$INPUT" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ ]]; then
  KEY="$INPUT"
elif [[ "$INPUT" == *"/browse/"* ]]; then
  KEY="$(printf '%s' "$INPUT" | sed -nE 's#.*/browse/([A-Z][A-Z0-9_]+-[0-9]+).*#\1#p')"
elif [[ "$INPUT" == *"selectedIssue="* ]]; then
  KEY="$(printf '%s' "$INPUT" | sed -nE 's#.*selectedIssue=([A-Z][A-Z0-9_]+-[0-9]+).*#\1#p')"
fi

if [[ -z "$KEY" ]]; then
  echo "upsert-comment.sh: could not extract JIRA key from input: $INPUT" >&2
  exit 1
fi

if [[ "$BODY_SRC" == "-" ]]; then
  BODY="$(cat)"
else
  if [[ ! -r "$BODY_SRC" ]]; then
    echo "upsert-comment.sh: cannot read body file: $BODY_SRC" >&2
    exit 1
  fi
  BODY="$(cat "$BODY_SRC")"
fi

if [[ -z "$BODY" ]]; then
  echo "upsert-comment.sh: refusing to publish an empty comment" >&2
  exit 1
fi

# Resolve the site from `acli jira auth status` to build the output URL.
# The installed acli build prints them as human-readable lines:
#   ✓ Authenticated
#     Site: your-org.atlassian.net
#     Email: someone@example.com
AUDIT_TRAIL="$SCRIPT_DIR/../../_shared/audit-trail.sh"
# shellcheck source=jira-actor.sh
source "$SCRIPT_DIR/jira-actor.sh"


AUTH_STATUS="$(acli jira auth status 2>/dev/null || true)"
SITE="$(jira_auth_status_field "$AUTH_STATUS" site)"
if [[ -z "$SITE" ]]; then
  echo "upsert-comment.sh: failed to resolve JIRA site — is acli authenticated? (run: acli jira auth status)" >&2
  exit 3
fi

# The same status output carries the authenticated account e-mail. The marker
# publishes a digest of it rather than the address: JIRA has no hidden-comment
# syntax, so every reader of the issue can open the marker line, even when it
# sits in a collapsed block. `php` computes the digest because this
# script already requires it for the ADF conversion, so no further tool has to
# be present for the publisher to work.
EMAIL="$(jira_auth_status_field "$AUTH_STATUS" email)"
ACTOR_ID="$(jira_actor_digest "$EMAIL")"

# An unresolvable identity is fatal: no marker can be built, and a comment
# published without one is indistinguishable from the operator's own.
MARKER_NAMESPACE="$MODE"

if [[ -z "$ACTOR_ID" ]]; then
  echo "upsert-comment.sh: cannot derive the agent marker (no account e-mail in acli auth status); nothing was published" >&2
  exit 3
fi

MARKER_TEXT="${MARKER_NAMESPACE}:actor=${ACTOR_ID}"
MARKER_SLOT='{agent-marker}'
SUMMARY=0
# The PHP interpreter reads $argv, not the shell.
# shellcheck disable=SC2016
if printf '%s' "$BODY" | php -r 'require $argv[1]; exit(jiraReviewByline((string) stream_get_contents(STDIN)) ? 0 : 1);' "$SCRIPT_DIR/../../_shared/report-check-common.php"; then
  SUMMARY=1
  if [[ "$INPUT" != "$KEY" && "$INPUT" != "https://${SITE}/browse/${KEY}" ]]; then
    echo "upsert-comment.sh: summary target must be a key or its canonical URL on the authenticated site" >&2
    exit 1
  fi
  if [[ "$MODE" != cr-comment || ( "$CREATE_ONLY" != 1 && ( -z "$TARGET_COMMENT_ID" || -z "$PREVIOUS_ADF" ) ) ]]; then
    echo "upsert-comment.sh: summaries require --create or an exact legacy comment ID with --previous-adf" >&2
    exit 1
  fi
  if [[ "$BODY" == *🤖* || "$BODY" == *❌* || "$BODY" == *ℹ* ]]; then
    echo "upsert-comment.sh: summary contains a retired icon" >&2
    exit 1
  fi
  if [[ "$BODY" == *'{agent-marker}'* || "$BODY" == *':actor='* || "$BODY" == *'=== END OF COMMENT BODY'* || "$BODY" == *'<!--'* ]]; then
    echo "upsert-comment.sh: summary contains technical template content" >&2
    exit 1
  fi
elif [[ "$SUMMARY_REQUIRED" == 1 || -n "$PREVIOUS_ADF" || "$BODY" == *"AI Olympus ·"* || "$BODY" == *"🤖 AI Olympus"* ]]; then
  usage
  exit 1
elif ! grep -Fq "$MARKER_TEXT" <<<"$BODY"; then
  if grep -Fxq "$MARKER_SLOT" <<<"$BODY"; then
    BODY="$(printf '%s\n' "$BODY" | awk -v slot="$MARKER_SLOT" -v marker="_${MARKER_TEXT}_" '
      { lines[NR] = $0; if ($0 == slot) { last = NR } }
      END { for (i = 1; i <= NR; i++) { print (i == last ? marker : lines[i]) } }')"
  else
    BODY="${BODY}

_${MARKER_TEXT}_"
  fi
fi

# The slot lines of the screenshots never reach the ADF path: a comment that shows no image carries none.
BODY="$(printf '%s\n' "$BODY" | php "$SCRIPT_DIR/screenshot-slots.php" strip)"

# Build valid ADF before the external write. The create call has no dedicated
# `--body-adf` flag, but `--body-file` accepts an ADF document. The update call
# then applies the same payload to that exact new comment ID through the
# explicitly requested `--body-adf` path.
ADF_FILE_TMP="$(mktemp)"
CREATE_STDERR="$(mktemp)"
LIST_STDERR="$(mktemp)"
UPDATE_STDERR="$(mktemp)"
CARRIED_ADF_TMP="$(mktemp)"
PREVIOUS_BODY_TMP="$(mktemp)"
SENT_TEXT_TMP="$(mktemp)"
trap 'rm -rf "$ADF_FILE_TMP" "$CREATE_STDERR" "$LIST_STDERR" "$UPDATE_STDERR" "$CARRIED_ADF_TMP" "$PREVIOUS_BODY_TMP" "$SENT_TEXT_TMP"' EXIT

if ! printf '%s' "$BODY" | php "$SCRIPT_DIR/wiki-markup-to-adf.php" > "$ADF_FILE_TMP"; then
  echo "upsert-comment.sh: failed to convert the JIRA comment to ADF" >&2
  exit 3
fi

if ! jq -e '.version == 1 and .type == "doc" and (.content | type == "array")' "$ADF_FILE_TMP" >/dev/null; then
  echo "upsert-comment.sh: converter produced invalid ADF" >&2
  exit 3
fi

# Print the issue's comments as one JSON array, or nothing when the read fails.
# `jq -s` slurps the stream so a response of several documents still flattens;
# the envelope differs between acli builds, hence the fallbacks and the `[]`
# default for a shape none of them matches.
read_comments() {
  if [[ "$SUMMARY" == 1 ]]; then
    read_summary_comments
    return
  fi
  local raw=""
  if ! raw="$(acli jira workitem view "$KEY" --fields comment --json 2>"$LIST_STDERR")"; then
    return 1
  fi


  printf '%s' "$raw" \
    | jq -s 'map(
          if type == "array" then .
          elif type == "object" then (.fields.comment.comments // .comments // .results // .values // [])
          else [] end
        ) | add // []' 2>/dev/null
}

# The account ID carries the author match wherever Jira Cloud hides
# `author.emailAddress`, which it does whenever the account restricts its e-mail
# visibility.
ACCOUNT_ID="$(jira_actor_account_id)"

# `owned`: the author carries this account ID, or this e-mail when no account ID
# was resolved, and no visible e-mail that differs. `decidable`: the response
# carries enough of the author to confirm or rule out ownership. The e-mail is
# compared raw; the marker carries only its digest, so it never identifies the
# account to a reader. `marked`: the body carries the marker with no namespace
# character right before it, so the namespace `comment` never matches a
# `cr-comment:actor=` line.
AUTHOR_JQ="$(cat <<'JQ'
  def author_of: .author | if type == "object" then . else {} end;
  def owned: author_of as $a
    | (($a.emailAddress // "") as $e | $e == "" or $e == $email)
      and (if $account != "" and ($a.accountId // "") != "" then $a.accountId == $account
           else $email != "" and ($a.emailAddress // "") == $email end);
  def decidable: author_of as $a
    | ($a.emailAddress // "") != "" or ($account != "" and ($a.accountId // "") != "");
  def marked: (.body | tojson) | test("(^|[^a-z0-9-])" + $marker);
  def visible_text: [.. | objects | select(.type? == "text") | .text? | strings
    | select(test("(^|[^a-z0-9-])" + $marker) | not)] | join("");
JQ
)"

# Take the before-create snapshot in every mode, including `agent-note`: the
# post-create ID resolution below identifies the new comment as "marked and
# not in this snapshot", so without it a create that returns no ID could
# resolve to an older comment instead — the one case `agent-note` mode exists
# to prevent overwriting or deleting. A lookup that cannot be made publishes
# nothing: it could hide the comment a create would duplicate, or the older
# note the snapshot exists to protect.
if [[ "$SUMMARY" == 1 ]]; then
  source "$SCRIPT_DIR/publish-summary.sh"
fi
EXISTING_ID=""
if ! COMMENTS_JSON="$(read_comments)" || [[ -z "$COMMENTS_JSON" ]]; then
  echo "upsert-comment.sh: comment lookup failed on $KEY, nothing was published: $(<"$LIST_STDERR")" >&2
  echo "upsert-comment.sh: a create without the lookup could duplicate a comment this actor already owns; rerun once the issue is readable" >&2
  exit 3
fi

if [[ "$SUMMARY" == 1 ]]; then
  publish_summary
  exit $?
fi

# Look for a comment this actor already published under the same marker. A
# marked comment whose author cannot be decided also publishes nothing: it
# could hide the comment a create would duplicate. `agent-note` mode skips
# this match entirely — it is create-only, so an existing agent-note comment
# (a runbook, a note the operator asked an agent to leave as its own comment)
# is never picked up or overwritten, whatever the snapshot found. `--create`
# skips the match for the same reason, in any namespace.
if [[ "$MODE" != "agent-note" && "$CREATE_ONLY" -eq 0 ]]; then
  EXISTING_ID="$(printf '%s' "$COMMENTS_JSON" \
    | jq -r --arg marker "$MARKER_TEXT" --arg email "$EMAIL" --arg account "$ACCOUNT_ID" --arg target "$TARGET_COMMENT_ID" "${AUTHOR_JQ}"'
        map(select(marked and owned))
        | map(select($target == "" or ((.id? // "") | tostring) == $target))
        | sort_by((.updated? // .created? // "") | tostring)
        | last
        | (.id? // empty)
        | tostring' 2>/dev/null || true)"

  if [[ -n "$TARGET_COMMENT_ID" && "$EXISTING_ID" != "$TARGET_COMMENT_ID" ]]; then
    echo "upsert-comment.sh: comment $TARGET_COMMENT_ID is not this actor's ${MODE} comment on $KEY, nothing was published" >&2
    exit 3
  fi

  if [[ ! "$EXISTING_ID" =~ ^[0-9]+$ ]]; then
    UNDECIDABLE_ID="$(printf '%s' "$COMMENTS_JSON" \
      | jq -r --arg marker "$MARKER_TEXT" --arg email "$EMAIL" --arg account "$ACCOUNT_ID" "${AUTHOR_JQ}"'
          map(select(marked and (decidable | not)))
          | last
          | (.id? // empty)
          | tostring' 2>/dev/null || true)"

    if [[ -n "$UNDECIDABLE_ID" ]]; then
      echo "upsert-comment.sh: refusing to create a duplicate — comment $UNDECIDABLE_ID on $KEY carries this actor's marker, but its author identity could not be verified from the acli response" >&2
      echo "upsert-comment.sh: update that comment through the JIRA MCP server with an ADF payload; never create a second one" >&2
      exit 3
    fi
  fi
fi

if [[ "$MODE" != "agent-note" ]]; then
  CARRY_ARGS=(adf "$MARKER_NAMESPACE" "$ADF_FILE_TMP")
  if [[ "$EXISTING_ID" =~ ^[0-9]+$ ]]; then
    printf '%s' "$COMMENTS_JSON" \
      | jq -c --arg id "$EXISTING_ID" '[.[] | select(((.id? // "") | tostring) == $id)] | last | .body // null' > "$PREVIOUS_BODY_TMP"
    CARRY_ARGS+=("$PREVIOUS_BODY_TMP")
  fi
  if ! php "$SCRIPT_DIR/../../_shared/carry-operator-lines.php" "${CARRY_ARGS[@]}" > "$CARRIED_ADF_TMP"; then
    echo "upsert-comment.sh: carrying the operator lines failed on $KEY, nothing was published" >&2
    exit 3
  fi
  cp "$CARRIED_ADF_TMP" "$ADF_FILE_TMP"
fi

if [[ "$EXISTING_ID" =~ ^[0-9]+$ ]]; then
  TARGET_ID="$EXISTING_ID"
  ACTION="updated"
else
  CREATE_RC=0
  CREATE_JSON="$(acli jira workitem comment create --key "$KEY" --body-file "$ADF_FILE_TMP" --json 2>"$CREATE_STDERR")" || CREATE_RC=$?
  if [[ "$CREATE_RC" -ne 0 ]]; then
    bash "$AUDIT_TRAIL" record --target "jira comment create on $KEY" --status "$CREATE_RC" || true
    echo "upsert-comment.sh: acli comment create failed on $KEY: $(<"$CREATE_STDERR")" >&2
    exit 3
  fi

  # Read the new comment's ID from whichever shape this acli build returns. The explicit paths come
  # first; the recursive search is the fallback so a renamed envelope key aborts nothing. An aborted
  # publish is not a neutral outcome — it is what tempts a caller to improvise a raw plain-text
  # `acli` write, which is the exact failure this helper exists to prevent.
  TARGET_ID="$(printf '%s' "$CREATE_JSON" | jq -r '
    ( .id? // .commentId? // .comment.id? // .comments[0].id? // .results[0].id?
      // ([.. | objects | .id? | select(type == "string" or type == "number")] | first)
      // empty
    ) | tostring' 2>/dev/null || true)"

  # acli 1.3.x reports no comment ID here. The account is shared with the operator, so a new
  # comment of this account is the created one only when its stored visible text equals the visible
  # text of the ADF this run sent, the marker text left out on both sides: JIRA may store the comment
  # without the marker, and a comment that only quotes the marker must not pass. A matching comment
  # that carries the marker wins and ends the re-reads. Any other result waits for all three
  # re-reads, because Jira Cloud can answer a read after a create without the new comment, and the
  # last successful read decides. No single match leaves every new comment untouched and exits 3.
  # The snapshot above was taken before the create in every mode, so an older `agent-note` comment
  # is always "before" and never a candidate.
  CANDIDATE_IDS=""
  if [[ ! "$TARGET_ID" =~ ^[0-9]+$ ]]; then
    jq -j --arg marker "$MARKER_TEXT" --arg email "$EMAIL" --arg account "$ACCOUNT_ID" "${AUTHOR_JQ}"' visible_text' \
      "$ADF_FILE_TMP" > "$SENT_TEXT_TMP" 2>/dev/null || : > "$SENT_TEXT_TMP"
    BEFORE_IDS="$(printf '%s' "${COMMENTS_JSON:-[]}" | jq -c '[.[] | (.id? // empty) | tostring]' 2>/dev/null || echo '[]')"
    SENT_MATCH=""
    for attempt in 1 2 3; do
      if AFTER_JSON="$(read_comments)" && [[ -n "$AFTER_JSON" ]]; then
        NEW_OWNED="$(printf '%s' "$AFTER_JSON" \
          | jq -r --argjson before "$BEFORE_IDS" --arg marker "$MARKER_TEXT" --arg email "$EMAIL" --arg account "$ACCOUNT_ID" \
              --rawfile sent "$SENT_TEXT_TMP" "${AUTHOR_JQ}"'
              map(select(((.id? // "") | tostring) as $id | ($before | index($id)) == null)
                  | select(owned)
                  | {id: (.id | tostring), marked: marked, sent: ($sent != "" and (.body | visible_text) == $sent)}
                  | select(.id | test("^[0-9]+$")))
              | (map(select(.sent and .marked) | .id)) as $marked
              | (map(select(.sent) | .id)) as $matched
              | if ($marked | length) == 1 then "marked " + $marked[0]
                elif ($marked | length) == 0 and ($matched | length) == 1 then "matched " + $matched[0]
                else "none " + (map(.id | tonumber) | sort | map(tostring) | join(" ")) end' 2>/dev/null || true)"
        case "$NEW_OWNED" in
          "marked "*) TARGET_ID="${NEW_OWNED#marked }"; break ;;
          "matched "*) SENT_MATCH="${NEW_OWNED#matched }"; CANDIDATE_IDS="" ;;
          "none "*) SENT_MATCH=""; CANDIDATE_IDS="${NEW_OWNED#none }" ;;
        esac
      fi
      [[ $attempt -lt 3 ]] && sleep 1
    done
    if [[ ! "$TARGET_ID" =~ ^[0-9]+$ && "$SENT_MATCH" =~ ^[0-9]+$ ]]; then
      TARGET_ID="$SENT_MATCH"
    fi
  fi

  if [[ ! "$TARGET_ID" =~ ^[0-9]+$ && -n "$CANDIDATE_IDS" ]]; then
    bash "$AUDIT_TRAIL" record --target "jira comment create on $KEY" --status 0 --detail "created, ID not resolved, ADF update aborted" || true
    echo "upsert-comment.sh: created a comment on $KEY but its ID is missing; ADF update aborted" >&2
    echo "upsert-comment.sh: new comments ${CANDIDATE_IDS} of this account on $KEY do not show exactly one stored body that matches the body this run sent, so none of them was updated or deleted" >&2
    echo "upsert-comment.sh: check them and remove the created comment by hand when it shows no agent marker; a comment the operator wrote stays as it is" >&2
    echo "upsert-comment.sh: do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload" >&2
    exit 3
  fi

  if [[ ! "$TARGET_ID" =~ ^[0-9]+$ ]]; then
    bash "$AUDIT_TRAIL" record --target "jira comment create on $KEY" --status 0 --detail "created, ID not resolved, ADF update aborted" || true
    echo "upsert-comment.sh: created a comment on $KEY but its ID is missing; ADF update aborted" >&2
    if [[ -s "$LIST_STDERR" ]]; then
      echo "upsert-comment.sh: the last of 3 re-reads failed: $(<"$LIST_STDERR")" >&2
    fi
    echo "upsert-comment.sh: do not publish this result again — the created comment was not found, so its marker is not confirmed; check $KEY for it and remove it by hand when it shows no agent marker" >&2
    echo "upsert-comment.sh: do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload" >&2
    exit 3
  fi

  ACTION="created"
fi

{
  UPDATE_RC=0
  acli jira workitem comment update --key "$KEY" --id "$TARGET_ID" --body-adf "$ADF_FILE_TMP" >/dev/null 2>"$UPDATE_STDERR" || UPDATE_RC=$?
  if [[ "$UPDATE_RC" -ne 0 ]]; then
    if [[ "$ACTION" == "created" ]]; then
      # The create happened before the update: record it first, as the write it was.
      bash "$AUDIT_TRAIL" record --target "jira comment $TARGET_ID on $KEY" --status 0 --detail "created, its ADF update failed next" || true
    fi
    bash "$AUDIT_TRAIL" record --target "jira comment $TARGET_ID ADF update on $KEY" --status "$UPDATE_RC" || true
    if [[ "$ACTION" == "created" ]]; then
      # The comment this run just created may render as Wiki Markup. Remove it rather than leave an
      # unformatted comment behind for a reader to find, then fail loudly. A comment an earlier run
      # published is never deleted here — a failed update leaves the previous body in place.
      DELETE_RC=0
      acli jira workitem comment delete --key "$KEY" --id "$TARGET_ID" >/dev/null 2>&1 || DELETE_RC=$?
      bash "$AUDIT_TRAIL" record --target "jira comment $TARGET_ID delete on $KEY" --status "$DELETE_RC" --detail "removed after the ADF update failed" || true
      echo "upsert-comment.sh: ADF update failed on $KEY comment $TARGET_ID: $(<"$UPDATE_STDERR")" >&2
      if [[ "$DELETE_RC" -eq 0 ]]; then
        echo "upsert-comment.sh: the created comment was removed; do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload" >&2
      else
        echo "upsert-comment.sh: the created comment $TARGET_ID could not be removed (acli exit $DELETE_RC); remove it by hand, and do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload" >&2
      fi
    else
      echo "upsert-comment.sh: ADF update failed on $KEY comment $TARGET_ID: $(<"$UPDATE_STDERR")" >&2
      echo "upsert-comment.sh: the existing comment was left unchanged; do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload" >&2
    fi
    exit 3
  fi
}

# Print "marked", "unmarked", or "missing" for the stored body of comment
# $TARGET_ID, after up to three reads.
read_back_marker() {
  local state="missing"
  for attempt in 1 2 3; do
    state="$(read_comments | jq -r --arg id "$TARGET_ID" --arg marker "$MARKER_TEXT" --arg email "$EMAIL" --arg account "$ACCOUNT_ID" "${AUTHOR_JQ}"'
        [.[] | select(((.id? // "") | tostring) == $id)] | last
        | if . == null then "missing" elif (marked and owned) then "marked" else "unmarked" end' 2>/dev/null || true)"
    if [[ "$state" == "marked" || "$state" == "unmarked" ]]; then
      break
    fi
    [[ $attempt -lt 3 ]] && sleep 1
  done
  printf '%s' "$state"
}

# An updated comment that lost the marker reads as the operator's own, so put
# the previous body back: it carried the marker, because the update target is
# always a marked comment of this account. The restore counts only when the
# stored body shows the marker again.
restore_previous_body() {
  local restore_rc=0
  if jq -e 'type == "object" and .type == "doc" and (.content | type == "array")' "$PREVIOUS_BODY_TMP" >/dev/null 2>&1; then
    acli jira workitem comment update --key "$KEY" --id "$TARGET_ID" --body-adf "$PREVIOUS_BODY_TMP" >/dev/null 2>"$UPDATE_STDERR" || restore_rc=$?
  else
    restore_rc=65
  fi
  if [[ "$restore_rc" -eq 0 && "$(read_back_marker)" == "marked" ]]; then
    bash "$AUDIT_TRAIL" record --target "jira comment $TARGET_ID restore on $KEY" --status 0 --detail "previous body put back after the marker read-back failed" || true
    echo "upsert-comment.sh: comment $TARGET_ID on $KEY has its previous body back, and that body carries the agent marker; the new body was not published" >&2
    return
  fi
  bash "$AUDIT_TRAIL" record --target "jira comment $TARGET_ID restore on $KEY" --status "$(( restore_rc == 0 ? 1 : restore_rc ))" || true
  echo "upsert-comment.sh: comment $TARGET_ID stays on $KEY without the agent marker and reads as the operator's own: putting its previous body back failed; restore the marker or remove the comment by hand" >&2
}

# Read the stored comment back. The marker sits inside a collapsed block of the
# body, and an agent comment whose stored body lost it reads as the operator's
# own (rules/code-review/general.md *Authorship trust*), so the publish counts
# only when the stored body carries the marker and the author is this account.
STORED="$(read_back_marker)"

if [[ "$STORED" != "marked" ]]; then
  if [[ "$STORED" == "unmarked" ]]; then
    echo "upsert-comment.sh: comment $TARGET_ID on $KEY does not carry the agent marker in its stored body" >&2
  else
    echo "upsert-comment.sh: comment $TARGET_ID on $KEY could not be read back to confirm the agent marker" >&2
  fi
  if [[ "$ACTION" == "created" ]]; then
    bash "$AUDIT_TRAIL" record --target "jira comment $TARGET_ID on $KEY" --status 0 --detail "created, its marker read-back failed next" || true
    DELETE_RC=0
    acli jira workitem comment delete --key "$KEY" --id "$TARGET_ID" >/dev/null 2>&1 || DELETE_RC=$?
    bash "$AUDIT_TRAIL" record --target "jira comment $TARGET_ID delete on $KEY" --status "$DELETE_RC" --detail "removed after the marker read-back failed" || true
    if [[ "$DELETE_RC" -eq 0 ]]; then
      echo "upsert-comment.sh: the created comment was removed; do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload and read it back" >&2
    else
      echo "upsert-comment.sh: comment $TARGET_ID stays on $KEY without the agent marker and reads as the operator's own; remove it by hand (acli exit $DELETE_RC)" >&2
    fi
  else
    bash "$AUDIT_TRAIL" record --target "https://${SITE}/browse/${KEY}?focusedCommentId=${TARGET_ID}" --status 0 --detail "updated, its marker read-back failed next" || true
    restore_previous_body
  fi
  exit 3
fi

bash "$AUDIT_TRAIL" record --target "https://${SITE}/browse/${KEY}?focusedCommentId=${TARGET_ID}" --status 0 --detail "$ACTION" || true
echo "https://${SITE}/browse/${KEY}?focusedCommentId=${TARGET_ID}"
echo "action=${ACTION} id=${TARGET_ID}" >&2
