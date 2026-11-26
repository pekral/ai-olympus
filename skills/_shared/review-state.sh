#!/usr/bin/env bash
# review-state.sh — the state of one review-and-fix loop: its rounds, the status of every finding, the round
# cap, and the convergence verdict.
#
# Why this exists
#   The review loop of skills/process-code-review/SKILL.md counted findings in prose, round by round. The same
#   finding came back as new, a finding without evidence blocked like a proven defect, a resolved finding was
#   reported again, and nothing outside the loop could tell why it stopped. This script keeps that state as
#   data, under the git-ignored run directory, and computes the verdict the same way every time.
#
#   The loop contract (what a round is, the statuses, who may change them, the cap, and the escalation) is
#   written once, in skills/process-code-review/references/review-state.md. The record schema it stores is
#   written in skills/code-review/references/finding-records.md *Review-loop records (schema 2)*. This header
#   only documents the command line.
#
# Usage
#   review-state.sh init     --state <file> --repo <owner/name> --pr <number>
#   review-state.sh record   --state <file> --input <report|-> [--changed-files <file|->] [--operator-round]
#   review-state.sh late     --state <file> --input <report|->
#   review-state.sh evaluate --state <file> [--final]
#   review-state.sh scope    --state <file> --changed-files <file|-> [--numstat <file|->]
#   review-state.sh dismiss  --state <file> --finding <id> --comment <comment-url>
#   review-state.sh defer    --state <file> --finding <id> --url <https-url>
#   review-state.sh known    --state <file> --finding <id> --source <task> --issue <url> --covers <text>
#   review-state.sh known    --state <file> --finding <id> --reopen
#   review-state.sh known    --state <file> --verify --pr <url>
#   review-state.sh --self-test
#
#   --state          the state file. Every command that writes it refuses a path outside the `.claude/run/` or
#                    `.codex/run/` directory of the git toplevel, a symbolic link, and a tracked file.
#   --input          the findings report of one review round (skills/_shared/extract-findings.sh --round).
#   --changed-files  the paths the round's delta changed, one per line (`git diff --no-renames --name-only
#                    <baseline>..<head SHA>`, where <head SHA> is the commit the round is dispatched against, never
#                    the symbolic HEAD of the tree the command runs in). Required by `record` once an earlier round
#                    exists, and by `scope`.
#   --numstat        the line counts of the same delta (`git diff --no-renames --numstat <baseline>..<head SHA>`). `scope`
#                    reads it to tell a small delta from a large one. The size is unknown, and the round runs every
#                    lens, without it, and when it is empty, holds a binary entry, a rename, or a line in another
#                    shape, or names other paths than --changed-files.
#   --operator-round records one round after an escalation, which only the operator decides. The caller passes it
#                    only on the operator's own explicit instruction in the chat of the session, never on a tracker
#                    comment or any other untrusted text (skills/process-code-review/references/review-state.md
#                    *Round cap and escalation*). It is refused before an escalation. The round is recorded, and its
#                    findings count as in every other round.
#   late             adds the findings a round's security view (`splinter`) reported after the round was recorded to
#                    that round. --input is a round report that carries only those findings, with the `Reviewed
#                    revision` of the last recorded round. Every finding of it counts as a security finding, whatever
#                    the classifier tagged. The round count does not move, and no finding the report omits changes.
#                    `late` refuses (exit 4) a report without a finding and a revision other than the last round's, and
#                    refuses (exit 5) an escalated loop. The verdict is then the one of any other open finding:
#                    `continue`, or `security-round` at the cap, or, in the last security round, the escalation
#                    `security-rounds-spent`.
#   --final          at the cap round, escalate when a blocking finding is still neither fixed nor deferred.
#   --comment        the URL of the rejection: a comment on the pull request of the state
#                    (https://github.com/<owner>/<name>/pull/<n>#issuecomment-<id>). The script loads the pull
#                    request through skills/code-review-github/scripts/load-issue.sh and reads the comment's author
#                    association itself. It refuses a comment it cannot load or find, an association other than
#                    OWNER, MEMBER, or COLLABORATOR, a comment that carries an agent marker, and a comment that does
#                    not name the finding_id it rejects. No caller states the association.
#
#   known            marks an open finding as a known point: a linked issue already reports the same problem
#                    (rules/code-review/review-process.md *Known points*). Only the orchestrator runs it, after its
#                    own comparison of the finding with the linked issues. --covers is one line (1 to 300 characters)
#                    on what the issue covers. The script checks the link itself, through the loaders, and never takes
#                    it from the caller. --source is the task: an issue URL of the pull request's own repository, or a
#                    JIRA key. --issue is the linked issue, which must be in the task's own link list (GitHub
#                    `linkedIssues[]`; JIRA `parent`, `subtasks`, `issueLinks`, and `mentionedKeys`), must be open, and
#                    must live where the task lives: the repository of the pull request on GitHub, the site and the
#                    project of the task on JIRA. Its author must pass the trust test: a GitHub `authorAssociation` of
#                    OWNER, MEMBER, or COLLABORATOR, and on JIRA a description that the operator's account wrote and
#                    that no other account changed (`descriptionTrusted`). On GitHub the pull request of the state
#                    must close the task (`closingIssues[]`) and must not close the linked issue: neither through
#                    `closingIssues[]` nor through a closing keyword that reaches a reference to it in the
#                    full message of one of its commits, in its title, in its body, or in its branch name, and the
#                    commit list is shorter than the 100 entries that the loader returns at most. A pull request that cannot be loaded is a refusal.
#                    A security finding a linked issue already
#                    reports is a known point too (owner decision of 2026-11-25): the trust of the link decides, never
#                    the kind of the finding. The state stores the task and the issue. Every refusal exits 4 and
#                    leaves the state unchanged. A known point is not blocking, so it never counts in a verdict. The state
#                    stores the finding as it was marked (`marked`: severity, security, file, line, title, impact, and
#                    evidence), and `known` refuses a finding whose record merges more than one reported finding. A
#                    reviewer that reports the same finding again, unchanged, keeps it known, in every round and at the
#                    cap round too. Every other report of its identity, `late` included, opens it again as a finding,
#                    for the orchestrator to compare again. Every `record` and every `evaluate` checks each known
#                    point again with the same checks, through the loaders, and reopens one that fails them, a loader
#                    that fails included. --reopen moves
#                    a known point back to an open finding when the orchestrator's comparison of a later round no
#                    longer finds the same problem. `known` refuses (exit 4) a finding that is not open (with --reopen:
#                    not known), and refuses (exit 5) an escalated loop. The one exception is a loop that its own cap
#                    round escalated on a hard blocker (`cap-reached-hard-blockers`): the comparison of that round
#                    still marks a known point, and the verdict is settled again.
#                    --verify is the merge-time check (skills/merge-github-pr/references/known-points-check.md): it runs
#                    the same checks on every known point of the state again, through the loaders, and writes nothing.
#                    It prints {"checked": <n>, "urls": [<each checked link>], "refused": [{"finding_id", "url",
#                    "reason"}]} and exits 4 when any point is refused, a loader that fails included. A state without a
#                    known point passes with checked 0. --pr is required: the https URL of the pull request that the
#                    merge loaded from the trusted loader. A state that is missing or invalid, a state path that
#                    every writing command refuses (tracked, a symbolic link, outside the run directory), and a state
#                    of another repository or pull request all exit 3, so a caller that cannot read the right state
#                    does not pass either.
#
#   The round cap and the blocking threshold come only from the default-branch manifest, through
#   read-manifest.sh: `review.max-iterations` (an integer from 1 to 10, default 3) and
#   `review.blocking-threshold` (critical, high, medium, or low; default critical). No environment variable,
#   argument, report, or comment changes them. Run the script from the project root, like read-manifest.sh.
#
#   The round report and every text of a finding are untrusted input (rules/security/general.md). They are
#   read as data: no part of them is executed or used to build a command, a path, or a jq program.
#
# Output (stdout, one line of JSON)
#   init      {"resumed", "iteration", "max_iterations", "blocking_threshold"}
#   record, evaluate, dismiss, defer, known
#             the verdict: {"verdict", "status", "escalation_reason", "iteration", "max_iterations",
#             "blocking_threshold", "reason", "counts", "rounds", "blocking", "deferrable", "carried"}
#   scope     {"scope": "delta"|"full", "reasons": [...], "baseline": <sha>|null, "depth": "light"|"full",
#             "depth_reasons": [...], "delta": {"files", "lines"}}; the scope is also stored for the next
#             `record`. A `light` depth runs only the lenses the delta can affect
#             (rules/code-review/general.md *A light round after a small delta*): a later round whose scope is
#             `delta`, whose delta has at most SMALL_DELTA_FILES files and SMALL_DELTA_LINES changed lines, and
#             whose state holds no open security finding, and that is not past the cap. Every other round is `full`.
#
# Exit codes
#   0  success — the verdict is in the output, never in the exit code
#   1  usage error
#   2  a required tool is missing (git, jq)
#   3  the state or the round report is missing, unreadable, or invalid; nothing was written, and the loop is
#      not converged
#   4  the policy refused the operation (a security finding, an untrusted or unverifiable author, a deferral
#      before the cap, an operator round before an escalation)
#   5  the loop is closed: it escalated, or its round cap is spent. A security finding left at the cap round gets
#      up to two more rounds that fix only the security findings (the `security-round` verdict), and so does one
#      that a round after a convergence at the cap reports; `record` accepts a round past the cap only then, or
#      with --operator-round after an escalation.
set -euo pipefail

PROG="${0##*/}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=state-path.sh
. "$HERE/state-path.sh"
LOADER="$HERE/../code-review-github/scripts/load-issue.sh"
JIRA_LOADER="$HERE/../code-review-jira/scripts/load-issue.sh"

DEFAULT_CAP=3
MAX_CAP=10
MAX_CHANGED_FILES=20000
# A small delta: at most this many changed files and changed lines (added plus deleted), both inclusive.
SMALL_DELTA_FILES=5
SMALL_DELTA_LINES=100
# A comment on a pull request: the shape of a rejection.
COMMENT_URL_RE='^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/pull/[1-9][0-9]{0,8}#issuecomment-[1-9][0-9]{0,19}$'

usage() {
  cat >&2 <<'EOF'
Usage: review-state.sh init     --state <file> --repo <owner/name> --pr <number>
       review-state.sh record   --state <file> --input <report|-> [--changed-files <file|->] [--operator-round]
       review-state.sh late     --state <file> --input <report|->
       review-state.sh evaluate --state <file> [--final]
       review-state.sh scope    --state <file> --changed-files <file|-> [--numstat <file|->]
       review-state.sh dismiss  --state <file> --finding <id> --comment <comment-url>
       review-state.sh defer    --state <file> --finding <id> --url <https-url>
       review-state.sh known    --state <file> --finding <id> --source <task> --issue <url> --covers <text>
       review-state.sh known    --state <file> --finding <id> --reopen
       review-state.sh known    --state <file> --verify --pr <url>
       review-state.sh --self-test
EOF
}

die_usage() {
  echo "$PROG: $1" >&2
  usage
  exit 1
}

fail() {
  echo "$PROG: $2" >&2
  exit "$1"
}

require_tools() {
  local tool
  for tool in git jq; do
    command -v "$tool" >/dev/null 2>&1 || fail 2 "required tool not found: $tool"
  done
}

# The jq definitions every command shares. They are program text this script ships; no input contributes to them.
# finding-defs.jq holds the ones extract-findings.sh shares.
JQ_STATE=''
IFS= read -r -d '' JQ_STATE <<'EOF' || true
include "finding-defs";

def isint($v; $min): ($v | type) == "number" and ($v | floor) == $v and $v >= $min;
# The automatic rounds after the cap round for a security finding: they fix only the security findings.
def security_rounds: 2;
def escalation_reasons: ["cap-reached-hard-blockers", "cap-reached-undeferred-blockers", "cap-spent-after-convergence", "security-rounds-spent"];
def is_open: .status == "new" or .status == "confirmed" or .status == "needs-verification";
# A finding blocks when it carries evidence, or when it is security-relevant even without evidence (the S1-S3 floor).
def is_blocking: .status == "new" or .status == "confirmed" or (.status == "needs-verification" and .security);
# A hard blocker is never deferred: a security finding, or a severity at or above the blocking threshold.
def is_hard($threshold): .security or ((.severity | severity_rank) >= ($threshold | severity_rank));
# A blocking finding in a file the delta did not change: a delta review cannot show whether it is gone. A finding
# whose location names no file (".") is outside every delta.
def outside_delta($changed): . as $f | ($f | is_blocking) and ($changed | index([$f.file])) == null;

# The fields of a finding that a known point stores when it is marked, and that a later report must repeat to keep it
# known: a report that differs in any of them is another finding of the same identity, or the same one changed.
def marked_view: {severity, security, file, line, description, impact, evidence};
def marked_ok: type == "object" and keys == ["description", "evidence", "file", "impact", "line", "security", "severity"]
  and (.severity | severity_rank) > 0 and (.security | type) == "boolean" and (.file | type) == "string"
  and (.line == null or (.line | type) == "number") and (.description | type) == "string" and (.impact | type) == "string"
  and (.evidence == null or (.evidence | type) == "string");

# Every problem the state has; an empty array means the state is valid.
def state_errors:
  . as $s
  | if ($s | type) != "object" then ["the state is not a JSON object"] else
    ["schema", "repo", "pr", "iteration", "max_iterations", "blocking_threshold", "status", "escalation_reason",
     "revision", "scope", "findings", "deferrals", "transitions", "rounds"] as $keys
    | (($s.findings // []) | if type == "array" then map(.finding_id) else [] end) as $ids
    | [
        (($s | keys) - $keys - ["operator_rounds", "known_points"] | .[] | "unknown key \(.)"),
        ($keys - ($s | keys) | .[] | "missing key \(.)"),
        (if $s.schema == 1 then empty else "schema must be 1" end),
        (if isstr($s.repo; "^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$") then empty else "repo must be owner/name" end),
        (if isint($s.pr; 1) then empty else "pr must be a positive integer" end),
        (if isint($s.iteration; 0) then empty else "iteration must be a non-negative integer" end),
        (if ($s.rounds | type) == "array" and ($s.rounds | length) == $s.iteration
            and ([range(0; $s.rounds | length)] | all(. as $i | $s.rounds[$i].iteration == $i + 1))
         then empty else "rounds must list every iteration once, in order, up to the iteration count" end),
        (if ($s.rounds | type) == "array" and all($s.rounds[];
              type == "object" and (keys == (["confirmed", "dismissed", "iteration", "needs_verification", "new", "remaining_blocking",
                "reopened", "resolved", "revision", "verdict"]))
              and all(.new, .confirmed, .resolved, .dismissed, .reopened, .needs_verification, .remaining_blocking; isint(.; 0))
              and isstr(.revision; "^[0-9a-f]{7,64}$")
              and (.verdict as $v | ["continue", "converged", "deferral-required", "security-round", "escalated"] | index([$v])) != null)
         then empty else "a round entry is malformed" end),
        (if isint($s.max_iterations; 1) and $s.max_iterations <= 10 then empty else "max_iterations must be an integer from 1 to 10" end),
        (if (["critical", "high", "medium", "low"] | index([$s.blocking_threshold])) != null then empty
         else "blocking_threshold must be critical, high, medium, or low" end),
        (if (["open", "converged", "escalated"] | index([$s.status])) != null then empty else "status must be open, converged, or escalated" end),
        (if ($s.status == "escalated") == ($s.escalation_reason != null) then empty
         else "escalation_reason must be set exactly when the status is escalated" end),
        (if $s.escalation_reason == null or (escalation_reasons | index([$s.escalation_reason])) != null
         then empty else "escalation_reason is not a known reason" end),
        (if $s.status == "converged" and $s.iteration == 0 then "a state without a review round is never converged" else empty end),
        # The rounds the operator decided after an escalation; an older state has none, and an entry an older version
        # wrote still carries the comment and the association it was verified with.
        (if ($s | has("operator_rounds") | not) or (($s.operator_rounds | type) == "array"
              and ($s.operator_rounds | map(.iteration?) | unique | length) == ($s.operator_rounds | length)
              and all($s.operator_rounds[];
                type == "object" and (keys == ["actor", "after", "iteration"]
                  or (keys == ["actor", "after", "association", "comment", "iteration"]
                    and isstr(.association; "^(OWNER|MEMBER|COLLABORATOR)$")
                    and isstr(.comment; "^\\S+#issuecomment-[1-9][0-9]{0,19}$")
                    and (.comment | startswith("https://github.com/\($s.repo)/pull/\($s.pr)#"))))
                and .actor == "operator"
                and (.after as $a | escalation_reasons | index([$a])) != null
                and isint(.iteration; 2) and .iteration <= $s.iteration))
         then empty else "an operator round is malformed" end),
        (if $s.revision == null or isstr($s.revision; "^[0-9a-f]{7,64}$") then empty else "revision must be null or a commit SHA" end),
        (if $s.scope == null or $s.scope == "delta" or $s.scope == "full" then empty else "scope must be null, delta, or full" end),
        (if ($s.findings | type) == "array" and all($s.findings[]; type == "object" and .repo == $s.repo and .pr == $s.pr)
         then empty else "every finding must belong to the pull request of the state" end),
        (if ($s.deferrals | type) == "array" and all($s.deferrals[];
              type == "object" and keys == ["finding_id", "url"] and (.finding_id as $id | $ids | index([$id])) != null
              and isstr(.url; "^https://\\S+$") and (.url | length) <= 500)
         then empty else "a deferral is malformed or names an unknown finding" end),
        # The known points the orchestrator marked; an older state has none. Each names one finding of the state and
        # the task whose link list holds the issue, so every later round checks the link again. `marked` is the finding
        # as it was marked; an entry an older version wrote has none, and the next report of its identity opens it.
        (if ($s | has("known_points") | not) or (($s.known_points | type) == "array"
              and ($s.known_points | map(.finding_id?) | unique | length) == ($s.known_points | length)
              and all($s.known_points[];
                type == "object" and ((keys - ["marked"]) == ["covers", "finding_id", "source", "url"])
                and ((has("marked") | not) or (.marked | marked_ok))
                and (.finding_id as $id | $ids | index([$id])) != null
                and isstr(.url; "^https://\\S+$") and (.url | length) <= 500
                and isstr(.source; "^(https://github\\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[1-9][0-9]{0,8}|[A-Z][A-Z0-9_]*-[1-9][0-9]{0,8})$")
                and isstr(.covers; "^[^\\x00-\\x1f\\x7f]{1,300}$")))
         then empty else "a known point is malformed or names an unknown finding" end),
        # A finding with the status `known` has exactly one known point, which `known --verify` and every evaluation
        # check again. A `known` finding with none would pass both without a check.
        (if ($s.findings | type) == "array" and ($s.known_points // [] | type) == "array" and all($s.findings[];
              . as $f | .status != "known" or ([($s.known_points // [])[] | select(type == "object" and .finding_id == $f.finding_id)] | length) == 1)
         then empty else "a known finding has no known point, or more than one" end),
        (if ($s.transitions | type) == "array" and all($s.transitions[];
              type == "object" and keys == ["actor", "finding_id", "from", "iteration", "to"]
              and (.finding_id as $id | $ids | index([$id])) != null and isint(.iteration; 1) and .iteration <= $s.iteration
              and (.from == null or (.from | status_rank) > 0) and (.to | status_rank) > 0
              and (if .to == "dismissed" then isstr(.actor; "^trusted:(OWNER|MEMBER|COLLABORATOR)$")
                   elif .to == "known" then .actor == "orchestrator"
                   elif .from == "known" then (.actor == "orchestrator" or .actor == "reviewer")
                   else .actor == "reviewer" end))
         then empty else "a transition is malformed" end),
        # Every status is the end of the finding's own history: no status changes without a recorded transition.
        (if ($s.findings | type) == "array" and ($s.transitions | type) == "array" and all($s.findings[];
              . as $f | ([$s.transitions[] | select(.finding_id == $f.finding_id)] | last | .to?) == $f.status)
         then empty else "a finding status has no matching transition" end)
      ]
    end;

# The verdict of the state under the effective configuration. A state without a review round never converges.
# At the cap round a hard blocker that is not security-relevant escalates at once: a Critical is never deferred
# and never waits. A security finding gets up to `security_rounds` more rounds, whose fix is limited to the security
# findings and whose review runs at full depth, and escalates when they are spent. Every other blocking finding is deferrable, and the loop defers it first.
def verdict($cap; $threshold):
  . as $s
  | ($s.deferrals | map(.finding_id)) as $deferred
  | [ $s.findings[] | select(is_blocking)
      | select(is_hard($threshold) or (.finding_id as $id | $deferred | index([$id])) == null) ] as $open
  | ($open | map(select(is_hard($threshold) and (.security | not)))) as $hard
  | ($open | map(select(.security))) as $sec
  | ($open | map(select(is_hard($threshold) | not))) as $deferrable
  | (if $s.status == "escalated" then "escalated"
     elif $s.iteration == 0 then "continue"
     elif ($open | length) == 0 then "converged"
     elif $s.iteration < $cap then "continue"
     elif ($hard | length) > 0 then "escalated"
     elif ($sec | length) > 0 and $s.iteration >= $cap + security_rounds then "escalated"
     elif ($deferrable | length) > 0 then "deferral-required"
     else "security-round" end) as $v
  | {
      verdict: $v,
      escalation_reason: (if $s.status == "escalated" then $s.escalation_reason
                          elif $v == "escalated" and ($hard | length) > 0 then "cap-reached-hard-blockers"
                          elif $v == "escalated" then "security-rounds-spent" else null end),
      open: $open,
      security: $sec,
      deferrable: $deferrable
    };

def brief: {finding_id, severity, security, status, file, line};

def output($cap; $threshold; $carried):
  . as $s
  | verdict($cap; $threshold) as $v
  | {
      verdict: $v.verdict,
      status: $s.status,
      escalation_reason: $s.escalation_reason,
      iteration: $s.iteration,
      max_iterations: $cap,
      blocking_threshold: $threshold,
      reason: (if $v.verdict == "converged" then "no blocking finding is left"
               elif $v.verdict == "continue" and $s.iteration == 0 then "no review round is recorded yet"
               elif $v.verdict == "continue" then "\($v.open | length) blocking finding(s) are left after round \($s.iteration) of \($cap)"
               elif $v.verdict == "deferral-required" then "the round cap of \($cap) is reached; \($v.deferrable | length) blocking finding(s) are left that are neither security-relevant nor at or above the blocking threshold: fix or defer each one, or escalate"
               elif $v.verdict == "security-round" then "the round cap of \($cap) is reached and \($v.security | length) security finding(s) are left: round \($s.iteration + 1) of at most \($cap + security_rounds) fixes only them and is reviewed at full depth"
               elif $s.escalation_reason == "security-rounds-spent" then "the round cap of \($cap) and the \(security_rounds) security rounds after it are spent, and \($v.security | length) security finding(s) are left: the operator decides"
               elif $s.escalation_reason == "cap-spent-after-convergence" then "the loop converged in the cap round of \($cap), and a later change needs a review round the cap no longer allows"
               elif $s.escalation_reason == "cap-reached-undeferred-blockers" then "the round cap of \($cap) is reached and \($v.open | length) blocking finding(s) were neither fixed nor deferred"
               else "the round cap of \($cap) is reached and \($v.open | length) blocking finding(s) are left, at least one of them security-relevant or at or above the blocking threshold" end),
      counts: {
        new: ($s.rounds | map(.new) | add // 0),
        confirmed: ($s.findings | map(select(.status == "confirmed")) | length),
        resolved: ($s.findings | map(select(.status == "resolved")) | length),
        dismissed: ($s.findings | map(select(.status == "dismissed")) | length),
        reopened: ($s.rounds | map(.reopened) | add // 0),
        needs_verification: ($s.findings | map(select(.status == "needs-verification")) | length),
        deferred: ($s.deferrals | length),
        known: ($s.findings | map(select(.status == "known")) | length),
        remaining_blocking: ($v.open | length)
      },
      rounds: ($s.rounds | map({iteration, new, resolved, reopened, remaining_blocking, verdict})),
      blocking: ($v.open | map(brief)),
      deferrable: ($v.deferrable | map(brief)),
      carried: $carried
    };

# Apply a verdict to the state's own status. Escalation is terminal; nothing reopens an escalated loop.
def settle($cap; $threshold; $final):
  verdict($cap; $threshold) as $v
  | if .status == "escalated" then .
    elif $v.verdict == "converged" then .status = "converged" | .escalation_reason = null
    elif $v.verdict == "escalated" then .status = "escalated" | .escalation_reason = $v.escalation_reason
    elif $v.verdict == "deferral-required" and $final then .status = "escalated" | .escalation_reason = "cap-reached-undeferred-blockers"
    else .status = "open" | .escalation_reason = null end;

# A first report of a finding is a new finding, and a second report with evidence confirms it.
def lift: if . == "new" then "confirmed" else . end;

# An open finding $p merged with a report $c of its identity: the stronger status and severity, and either security flag.
def merge_open($c; $p):
  ([($c.status | lift), ($p.status | lift)] | max_by(status_rank)) as $st
  | $c
  | .status = $st
  | .severity = ([$c.severity, $p.severity] | max_by(severity_rank))
  | .security = ($c.security or $p.security)
  | .evidence = (if $st == "confirmed" then ($c.evidence // $p.evidence) else $c.evidence end);

# One finding a round report carries, merged with the state's finding of the same identity ($prev maps the
# identity to the stored finding, $marks maps it to the finding as its known point was marked). A closed finding
# reopens, an open one keeps the stronger status and severity. A known point stays known only when the report is one
# finding that repeats the marked one: any other report of the identity (a second defect merged into it, another line
# or text, a higher severity, another security flag) opens it as the finding it was, merged with the report.
def merge_reported($prev; $marks):
  . as $c | $prev[$c.finding_id] as $p
  | if $p == null then {rec: $c, from: null, carried: false}
    elif $p.status == "known" and $c.reports == 1 and ($c | marked_view) == $marks[$c.finding_id] then
      {rec: ($c | .status = "known"), from: $p.status, carried: false}
    elif $p.status == "known" then
      {rec: merge_open($c; $p | .status = (if .evidence == null then "needs-verification" else "confirmed" end)), from: $p.status, carried: false}
    elif ($p.status == "resolved" or $p.status == "dismissed") then
      {rec: ($c | .status = (.status | lift)), from: $p.status, carried: false}
    else
      {rec: merge_open($c; $p), from: $p.status, carried: false}
    end;

# The counters of one round: what its moves did, and the status mix of the findings after them.
def round_counts($moves; $next):
  {
    new: ($moves | map(select(.from == null)) | length),
    confirmed: ($next.findings | map(select(.status == "confirmed")) | length),
    resolved: ($moves | map(select(.to == "resolved")) | length),
    dismissed: ($next.findings | map(select(.status == "dismissed")) | length),
    reopened: ($moves | map(select((.from == "resolved" or .from == "dismissed") and (.to | . == "confirmed" or . == "needs-verification"))) | length),
    needs_verification: ($next.findings | map(select(.status == "needs-verification")) | length)
  };

# The known points a merge of round records keeps: an entry leaves the list when its finding leaves the status known.
def keep_known_points($merged):
  ([$merged[] | select(.rec.status == "known") | .rec.finding_id]) as $kept
  | if has("known_points") then .known_points |= map(select(.finding_id as $id | $kept | index([$id]) != null)) else . end;

# Move the known points $ids back to an open finding, as the orchestrator: the comparison no longer matches, or the
# link no longer passes the checks of `known`. A finding with evidence blocks again, one without needs verification.
def reopen_known($ids):
  .iteration as $n
  | reduce $ids[] as $id (.;
      ((.findings[] | select(.finding_id == $id)) | if .evidence == null then "needs-verification" else "confirmed" end) as $to
      | .transitions += [{iteration: $n, finding_id: $id, from: "known", to: $to, actor: "orchestrator"}]
      | .findings = (.findings | map(if .finding_id == $id then .status = $to else . end))
      | .known_points = ((.known_points // []) | map(select(.finding_id != $id))));

# The entry of the last round shows its verdict after the orchestrator's comparison of that round.
def refresh_round($cap; $threshold):
  verdict($cap; $threshold) as $v
  | if (.rounds | length) > 0 then .rounds[-1] |= (.remaining_blocking = ($v.open | length) | .verdict = $v.verdict) else . end;
EOF

# Merge one round into the state. Reads the state; $cur is the round's records, $changed the delta's paths or
# null. A finding the round carries keeps its identity; one it no longer carries is resolved by the reviewer's
# re-read. The one exception is a delta round and a blocking finding whose file is outside the delta: that round
# never read the file, so the finding stays open. A round counts as a delta round unless `scope` recorded
# `full` for it, and round 1 is always full. A non-security point that only needed verification may leave on any
# round: the reviewer's own check settled it.
JQ_RECORD=''
IFS= read -r -d '' JQ_RECORD <<'EOF' || true
. as $s
| ($s.iteration + 1) as $n
| ($s.findings | map({key: .finding_id, value: .}) | from_entries) as $prev
| ($cur | map({key: .finding_id, value: .}) | from_entries) as $now
| (($s.known_points // []) | map({key: .finding_id, value: .marked}) | from_entries) as $marks
| (if $s.iteration == 0 then "full" else ($s.scope // "delta") end) as $scope
| [ $cur[] | merge_reported($prev; $marks) ]
  + [ $s.findings[] | select($now[.finding_id] == null) | . as $f
      | if ($f | is_open | not) then {rec: $f, from: $f.status, carried: false}
        elif $scope == "delta" and $changed != null and ($f | outside_delta($changed)) then {rec: $f, from: $f.status, carried: true}
        else {rec: ($f | .status = "resolved" | .revision = $rev | .time = $time), from: $f.status, carried: false} end ]
  | . as $merged
  | [ $merged[] | select(.from != .rec.status)
      | {iteration: $n, finding_id: .rec.finding_id, from, to: .rec.status, actor: "reviewer"} ] as $moves
  | $s
  | .iteration = $n
  | .revision = $rev
  | .scope = null
  | .findings = ($merged | map(.rec) | sort_by(.finding_id))
  | keep_known_points($merged)
  | .transitions += $moves
  | . as $next
  | verdict($cap; $threshold) as $v
  | .rounds += [{iteration: $n, revision: $rev} + round_counts($moves; $next) + {remaining_blocking: ($v.open | length), verdict: $v.verdict}]
  | settle($cap; $threshold; false)
  | {state: ., carried: ($merged | map(select(.carried) | .rec.finding_id))}
EOF

# Add the findings of a late report to the last recorded round. Reads the state; $cur is the late report's records.
# The round count does not move, and no finding the report omits changes: a late view reads only what it
# reports. A new finding counts as new in the last round, a closed finding it reports again reopens, and the
# round's own counters and verdict are computed again from the findings, as `record` computes them.
JQ_LATE=''
IFS= read -r -d '' JQ_LATE <<'EOF' || true
. as $s
| $s.iteration as $n
| ($s.findings | map({key: .finding_id, value: .}) | from_entries) as $prev
| ($cur | map({key: .finding_id, value: .}) | from_entries) as $now
| (($s.known_points // []) | map({key: .finding_id, value: .marked}) | from_entries) as $marks
| ([ $cur[] | merge_reported($prev; $marks) ]
   + [ $s.findings[] | select($now[.finding_id] == null) | {rec: ., from: .status, carried: false} ]) as $merged
| [ $merged[] | select(.from != .rec.status)
    | {iteration: $n, finding_id: .rec.finding_id, from, to: .rec.status, actor: "reviewer"} ] as $moves
| $s
| .findings = ($merged | map(.rec) | sort_by(.finding_id))
| keep_known_points($merged)
| .transitions += $moves
| . as $next
| verdict($cap; $threshold) as $v
| round_counts($moves; $next) as $c
| .rounds[-1] |= (
    .new += $c.new
    | .reopened += $c.reopened
    | .confirmed = $c.confirmed
    | .dismissed = $c.dismissed
    | .needs_verification = $c.needs_verification
    | .remaining_blocking = ($v.open | length)
    | .verdict = $v.verdict)
| settle($cap; $threshold; false)
EOF

# effective_config — the round cap and the blocking threshold of the default-branch manifest, as
# `<cap> <threshold>`. A value outside its range falls back to the default and is named on stderr.
effective_config() {
  local config
  config="$(manifest_config)"
  [[ "$config" =~ ^[0-9]+\ (critical|high|medium|low)$ ]] || config="$DEFAULT_CAP critical"
  printf '%s\n' "$config"
}

manifest_config() {
  local manifest
  manifest="$("$HERE/read-manifest.sh" 2>/dev/null)" || { [[ $? -ne 3 ]] || "$HERE/read-manifest.sh" >/dev/null || true; manifest='{}'; }
  printf '%s' "$manifest" | jq -r --argjson def "$DEFAULT_CAP" --argjson max "$MAX_CAP" '
    (if type == "object" and (.review | type) == "object" then .review else {} end) as $r
    | $r["max-iterations"] as $c
    | $r["blocking-threshold"] as $t
    | ($c | type == "number" and floor == . and . >= 1 and . <= $max) as $cok
    | ($t | type == "string" and (. as $x | ["critical", "high", "medium", "low"] | index([$x])) != null) as $tok
    | (if $c != null and ($cok | not) then "refused: review.max-iterations must be an integer from 1 to \($max); using \($def)" else empty end),
      (if $t != null and ($tok | not) then "refused: review.blocking-threshold must be critical, high, medium, or low; using critical" else empty end),
      "config \(if $cok then $c else $def end) \(if $tok then $t else "critical" end)"' 2>/dev/null \
    | while IFS= read -r line; do
      case "$line" in
      config\ *) printf '%s\n' "${line#config }" ;;
      *) echo "$PROG: $line" >&2 ;;
      esac
    done
}

# check_writable <file> — refuse a state path outside the git-ignored run directory of the git toplevel
# (the guard `state-path.sh` shares with `quiet-run-check.sh`).
check_writable() {
  local problem
  problem="$(state_path_problem "$1")" || fail 3 "$problem"
}

# load_state <file> <out> — validate the state and copy it to <out>. Exit 3 on a missing or invalid state.
load_state() {
  local file="$1" out="$2" problems
  [[ -f "$file" && -r "$file" ]] || fail 3 "the review state is missing or unreadable; the loop is not converged: $(state_path_display "$file")"
  if ! jq -e -s 'length == 1' "$file" >/dev/null 2>&1; then
    fail 3 "the review state is not one JSON document; the loop is not converged"
  fi
  problems="$(jq -r -L "$HERE" "$JQ_STATE"' state_errors[]' "$file" 2>&1 || true)"
  if [[ -z "$problems" ]]; then
    jq -c '.findings[]' "$file" >"$out.findings"
    if [[ -s "$out.findings" ]]; then
      problems="$("$HERE/extract-findings.sh" --validate "$out.findings" 2>&1 >/dev/null || true)"
    fi
  fi
  if [[ -n "$problems" ]]; then
    while IFS= read -r line; do
      echo "$PROG: invalid review state: $(state_path_display "$line")" >&2
    done <<<"$problems"
    fail 3 "the review state is invalid; the loop is not converged"
  fi
  jq -c . "$file" >"$out"
}

# write_state <json-file> <file> — replace the state atomically.
write_state() {
  local json="$1" file="$2" out
  check_writable "$file"
  out="$(mktemp "$file.XXXXXX")"
  jq -c . "$json" >"$out"
  mv -f "$out" "$file"
}

# read_paths <file|-> <out> — the changed paths as a JSON array of strings. Paths are data only.
read_paths() {
  local input="$1" out="$2"
  if [[ "$input" == '-' ]]; then
    head -n "$MAX_CHANGED_FILES" >"$out.raw"
  else
    [[ -f "$input" ]] || fail 3 "changed-files list not found: $(state_path_display "$input")"
    head -n "$MAX_CHANGED_FILES" <"$input" >"$out.raw"
  fi
  jq -R -s -c 'split("\n") | map(gsub("[\\x00-\\x1f\\x7f]"; "") | sub("^(\\./)+"; "") | .[0:300]) | map(select(. != "" and . != ".")) | unique' "$out.raw" >"$out"
}

new_tmp() {
  TMP="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$TMP'" EXIT
}

cmd_init() {
  local state='' repo='' pr=''
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --state) [[ $# -ge 2 ]] || die_usage '--state needs a value'; state="$2"; shift 2 ;;
    --repo) [[ $# -ge 2 ]] || die_usage '--repo needs a value'; repo="$2"; shift 2 ;;
    --pr) [[ $# -ge 2 ]] || die_usage '--pr needs a value'; pr="$2"; shift 2 ;;
    *) die_usage "unknown argument: $(state_path_display "$1")" ;;
    esac
  done
  [[ -n "$state" ]] || die_usage '--state is required'
  [[ "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die_usage "--repo must be owner/name, got: $(state_path_display "$repo")"
  [[ "$pr" =~ ^[1-9][0-9]{0,8}$ ]] || die_usage "--pr must be a positive integer, got: $(state_path_display "$pr")"
  check_writable "$state"

  local config cap threshold
  config="$(effective_config)"
  cap="${config%% *}"
  threshold="${config#* }"
  new_tmp

  # An existing state is resumed, never replaced: a fresh state would reset the round count. An invalid one
  # stops the loop instead of being overwritten.
  if [[ -e "$state" ]]; then
    load_state "$state" "$TMP/state.json"
    jq -e --arg repo "$repo" --argjson pr "$pr" '.repo == $repo and .pr == $pr' "$TMP/state.json" >/dev/null \
      || fail 3 "the review state belongs to another pull request; refusing to replace it"
    jq -c --argjson cap "$cap" --arg t "$threshold" \
      '{resumed: true, iteration, status, max_iterations: $cap, blocking_threshold: $t}' "$TMP/state.json"
    return 0
  fi

  jq -n -c --arg repo "$repo" --argjson pr "$pr" --argjson cap "$cap" --arg t "$threshold" \
    '{schema: 1, repo: $repo, pr: $pr, iteration: 0, max_iterations: $cap, blocking_threshold: $t, status: "open",
      escalation_reason: null, revision: null, scope: null, findings: [], deferrals: [], transitions: [], rounds: []}' >"$TMP/new.json"
  write_state "$TMP/new.json" "$state"
  jq -c '{resumed: false, iteration, status, max_iterations, blocking_threshold}' "$TMP/new.json"
}

cmd_record() {
  local state='' input='' changed='' operator_round=false
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --state) [[ $# -ge 2 ]] || die_usage '--state needs a value'; state="$2"; shift 2 ;;
    --input) [[ $# -ge 2 ]] || die_usage '--input needs a value'; input="$2"; shift 2 ;;
    --changed-files) [[ $# -ge 2 ]] || die_usage '--changed-files needs a value'; changed="$2"; shift 2 ;;
    --operator-round) operator_round=true; shift ;;
    *) die_usage "unknown argument: $(state_path_display "$1")" ;;
    esac
  done
  [[ -n "$state" ]] || die_usage '--state is required'
  [[ -n "$input" ]] || die_usage '--input is required'
  [[ "$input" != '-' || "$changed" != '-' ]] || die_usage '--input and --changed-files cannot both read standard input'
  check_writable "$state"
  new_tmp
  load_state "$state" "$TMP/state.json"

  local config cap threshold iteration status
  config="$(effective_config)"
  cap="${config%% *}"
  threshold="${config#* }"
  iteration="$(jq -r '.iteration' "$TMP/state.json")"
  status="$(jq -r '.status' "$TMP/state.json")"
  local after_convergence=false
  if [[ "$status" == 'escalated' ]]; then
    [[ "$operator_round" == true ]] || fail 5 "the loop escalated; no further automatic review round starts"
  elif [[ "$operator_round" == true ]]; then
    fail 4 "an operator round is recorded only after the loop escalated; the state is unchanged"
  elif [[ "$status" == 'converged' && "$iteration" -ge "$cap" ]]; then
    # A change after a convergence at the cap needs a review round the cap no longer allows. Only a round whose
    # blocking findings are all security findings may still take the security rounds (decided after the merge).
    after_convergence=true
  elif [[ "$iteration" -ge "$cap" ]]; then
    # Past the cap a round starts only as a security round: a security finding is left, every other finding is
    # deferred, and the security rounds are not spent. The verdict decides it, never the caller.
    local current
    current="$(jq -r -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"' verdict($cap; $threshold).verdict' "$TMP/state.json")"
    [[ "$current" == 'security-round' ]] || fail 5 "the round cap of $cap is spent and the verdict is $current, not security-round; no further automatic review round starts"
  fi
  if [[ "$iteration" -gt 0 && -z "$changed" ]]; then
    die_usage '--changed-files is required once an earlier round exists'
  fi
  # An escalated loop takes one more round only on the operator's own instruction in the chat of the session. The
  # round reopens the loop and changes no finding: every finding it reports counts as in every other round.
  if [[ "$operator_round" == true ]]; then
    jq -c --argjson n "$((iteration + 1))" \
      '.operator_rounds = ((.operator_rounds // []) + [{iteration: $n, actor: "operator", after: .escalation_reason}])
        | .status = "open" | .escalation_reason = null' "$TMP/state.json" >"$TMP/operator.json"
    mv "$TMP/operator.json" "$TMP/state.json"
  fi
  # Every round checks each known point again before it merges its own findings: a point whose link no longer passes
  # the checks of `known` is an open finding of this round.
  reverify_known "$TMP/state.json" "$cap" "$threshold" false

  local repo pr
  repo="$(jq -r '.repo' "$TMP/state.json")"
  pr="$(jq -r '.pr' "$TMP/state.json")"
  if [[ "$input" == '-' ]]; then
    cat >"$TMP/report.md"
    input="$TMP/report.md"
  fi
  "$HERE/extract-findings.sh" --header --input "$input" >"$TMP/header.json" \
    || fail 3 "the round report could not be read; nothing was recorded"
  "$HERE/extract-findings.sh" --round --repo "$repo" --pr "$pr" --input "$input" >"$TMP/round.jsonl" \
    || fail 3 "the round report could not be read; nothing was recorded"

  local paths='null'
  if [[ -n "$changed" ]]; then
    read_paths "$changed" "$TMP/changed.json"
    paths="$(cat "$TMP/changed.json")"
  fi

  jq -c -s -L "$HERE" --slurpfile s "$TMP/state.json" --argjson changed "$paths" \
    --arg rev "$(jq -r '.revision' "$TMP/header.json")" --arg time "$(jq -r '.time' "$TMP/header.json")" \
    --argjson cap "$cap" --arg threshold "$threshold" \
    "$JQ_STATE"$'\n'". as \$cur | \$s[0] | $JQ_RECORD" "$TMP/round.jsonl" >"$TMP/merged.json"
  # After a convergence at the cap, a round whose blocking findings are all security findings takes the security
  # rounds that are left; every other change escalates instead of letting the convergence cover an unreviewed diff.
  if [[ "$after_convergence" == true ]] && ! jq -e -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" --argjson before "$iteration" \
    "$JQ_STATE"' .state | verdict($cap; $threshold).open as $o
      | ($o | length) > 0 and all($o[]; .security) and $before < $cap + security_rounds' "$TMP/merged.json" >/dev/null; then
    jq -c '.status = "escalated" | .escalation_reason = "cap-spent-after-convergence"' "$TMP/state.json" >"$TMP/next.json"
    write_state "$TMP/next.json" "$state"
    jq -c -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"' output($cap; $threshold; [])' "$TMP/next.json"
    fail 5 "the loop converged in the cap round of $cap; a later change needs a review round the cap no longer allows: escalated (cap-spent-after-convergence)"
  fi
  jq -c --argjson cap "$cap" '.state | .max_iterations = $cap' "$TMP/merged.json" \
    | jq -c --arg t "$threshold" '.blocking_threshold = $t' >"$TMP/next.json"
  write_state "$TMP/next.json" "$state"
  jq -c -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" --argjson carried "$(jq -c '.carried' "$TMP/merged.json")" \
    "$JQ_STATE"' output($cap; $threshold; $carried)' "$TMP/next.json"
}

cmd_late() {
  local state='' input=''
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --state) [[ $# -ge 2 ]] || die_usage '--state needs a value'; state="$2"; shift 2 ;;
    --input) [[ $# -ge 2 ]] || die_usage '--input needs a value'; input="$2"; shift 2 ;;
    *) die_usage "unknown argument: $(state_path_display "$1")" ;;
    esac
  done
  [[ -n "$state" ]] || die_usage '--state is required'
  [[ -n "$input" ]] || die_usage '--input is required'
  check_writable "$state"
  new_tmp
  load_state "$state" "$TMP/state.json"

  local iteration
  iteration="$(jq -r '.iteration' "$TMP/state.json")"
  [[ "$iteration" -gt 0 ]] || fail 4 "no review round is recorded yet; a late finding has no round to count for"
  [[ "$(jq -r '.status' "$TMP/state.json")" != 'escalated' ]] \
    || fail 5 "the loop escalated; a late finding opens no further automatic review round"

  local repo pr
  repo="$(jq -r '.repo' "$TMP/state.json")"
  pr="$(jq -r '.pr' "$TMP/state.json")"
  if [[ "$input" == '-' ]]; then
    cat >"$TMP/report.md"
    input="$TMP/report.md"
  fi
  "$HERE/extract-findings.sh" --header --input "$input" >"$TMP/header.json" \
    || fail 3 "the round report could not be read; nothing was recorded"
  "$HERE/extract-findings.sh" --round --repo "$repo" --pr "$pr" --input "$input" >"$TMP/round.jsonl" \
    || fail 3 "the round report could not be read; nothing was recorded"

  # A late view belongs to the last recorded round only: the report names that round's revision and carries a
  # finding. Every finding of it is a finding of the security view, so it counts as a security finding whatever
  # the classifier tagged: it is never deferred, as `SKILL.md` *Review loop* step 4 states for that view.
  jq -e -s --slurpfile s "$TMP/state.json" --slurpfile h "$TMP/header.json" \
    'length > 0 and $h[0].revision == $s[0].rounds[-1].revision' "$TMP/round.jsonl" >/dev/null \
    || fail 4 "a late report must carry at least one finding and the revision of the last recorded round; nothing was recorded"
  jq -c '.security = true' "$TMP/round.jsonl" >"$TMP/round-security.jsonl"
  mv "$TMP/round-security.jsonl" "$TMP/round.jsonl"

  local config cap threshold
  config="$(effective_config)"
  cap="${config%% *}"
  threshold="${config#* }"
  jq -c -s -L "$HERE" --slurpfile s "$TMP/state.json" --argjson cap "$cap" --arg threshold "$threshold" \
    "$JQ_STATE"$'\n'". as \$cur | \$s[0] | $JQ_LATE" "$TMP/round.jsonl" >"$TMP/next.json"
  jq -c --argjson cap "$cap" --arg t "$threshold" '.max_iterations = $cap | .blocking_threshold = $t' "$TMP/next.json" >"$TMP/next2.json"
  write_state "$TMP/next2.json" "$state"
  jq -c -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"' output($cap; $threshold; [])' "$TMP/next2.json"
}

cmd_evaluate() {
  local state='' final=false
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --state) [[ $# -ge 2 ]] || die_usage '--state needs a value'; state="$2"; shift 2 ;;
    --final) final=true; shift ;;
    *) die_usage "unknown argument: $(state_path_display "$1")" ;;
    esac
  done
  [[ -n "$state" ]] || die_usage '--state is required'
  new_tmp
  load_state "$state" "$TMP/state.json"

  local config cap threshold
  config="$(effective_config)"
  cap="${config%% *}"
  threshold="${config#* }"
  # Every evaluation checks each known point again, so the verdict never counts a link that no longer holds. A point
  # that is open again is written to the state.
  cp "$TMP/state.json" "$TMP/before.json"
  reverify_known "$TMP/state.json" "$cap" "$threshold" true
  if ! cmp -s "$TMP/before.json" "$TMP/state.json"; then
    check_writable "$state"
    write_state "$TMP/state.json" "$state"
  fi
  if [[ "$final" == true ]]; then
    check_writable "$state"
    jq -c -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"' settle($cap; $threshold; true)' \
      "$TMP/state.json" >"$TMP/next.json"
    if ! cmp -s <(jq -c . "$TMP/state.json") "$TMP/next.json"; then
      write_state "$TMP/next.json" "$state"
    fi
    cp "$TMP/next.json" "$TMP/state.json"
  fi
  jq -c -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"' output($cap; $threshold; [])' "$TMP/state.json"
}

cmd_scope() {
  local state='' changed='' numstat=''
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --state) [[ $# -ge 2 ]] || die_usage '--state needs a value'; state="$2"; shift 2 ;;
    --changed-files) [[ $# -ge 2 ]] || die_usage '--changed-files needs a value'; changed="$2"; shift 2 ;;
    --numstat) [[ $# -ge 2 ]] || die_usage '--numstat needs a value'; numstat="$2"; shift 2 ;;
    *) die_usage "unknown argument: $(state_path_display "$1")" ;;
    esac
  done
  [[ -n "$state" ]] || die_usage '--state is required'
  [[ -n "$changed" ]] || die_usage '--changed-files is required'
  [[ "$changed" != '-' || "$numstat" != '-' ]] || die_usage '--changed-files and --numstat cannot both read standard input'
  check_writable "$state"
  new_tmp
  load_state "$state" "$TMP/state.json"
  read_paths "$changed" "$TMP/changed.json"
  local config cap
  config="$(effective_config)"
  cap="${config%% *}"

  # The size of the delta: its changed lines (added plus deleted) from `git diff --no-renames --numstat`. The size
  # is known only when every line is a well-formed text entry and the entries name exactly the changed paths. An
  # empty or partial list, a binary entry, a rename (`old => new`), and a line in another shape make it unknown,
  # because a failed or mismatched diff must never read as small.
  local lines='null'
  if [[ -n "$numstat" ]]; then
    if [[ "$numstat" == '-' ]]; then
      head -n "$MAX_CHANGED_FILES" >"$TMP/numstat.raw"
    else
      [[ -f "$numstat" ]] || fail 3 "numstat list not found: $(state_path_display "$numstat")"
      head -n "$MAX_CHANGED_FILES" <"$numstat" >"$TMP/numstat.raw"
    fi
    lines="$(jq -R -s --slurpfile changed "$TMP/changed.json" '[split("\n")[] | select(test("\\S")) | split("\t")] as $rows
      | if ($rows | length) > 0
           and all($rows[]; length == 3 and (.[0] | test("^[0-9]{1,9}$")) and (.[1] | test("^[0-9]{1,9}$"))
             and (.[2] | contains(" => ") | not))
           and ($rows | map(.[2] | gsub("[\\x00-\\x1f\\x7f]"; "") | sub("^(\\./)+"; "") | .[0:300]) | unique) == $changed[0]
        then $rows | map((.[0] | tonumber) + (.[1] | tonumber)) | add else null end' "$TMP/numstat.raw")"
  fi

  # A narrow delta is not enough when it touches a surface whose regressions sit outside the changed lines.
  # The surfaces are the risk classifier's own, so the two never disagree on what is sensitive.
  local signals=''
  if [[ "$(jq 'length' "$TMP/changed.json")" -gt 0 ]]; then
    jq -r '.[]' "$TMP/changed.json" >"$TMP/changed.txt"
    signals="$("$HERE/classify-risk.sh" --files "$TMP/changed.txt" 2>/dev/null | sed -n 's/^signal=[^|]*|\([a-z-]*\)|.*/\1/p' || true)"
  fi
  # The next `record` reads the scope stored here: a blocking finding outside a delta round stays open.
  jq -c -L "$HERE" --arg signals "$signals" --slurpfile changed "$TMP/changed.json" --argjson lines "$lines" \
    --argjson max_files "$SMALL_DELTA_FILES" --argjson max_lines "$SMALL_DELTA_LINES" --argjson cap "$cap" "$JQ_STATE"'
    ($signals | split("\n") | map(select(. != ""))) as $sig
    | [ (if .iteration == 0 then "first-round" else empty end),
        (if (.rounds | last | .reopened? // 0) > 0 then "regression" else empty end),
        (if ($sig | any(. == "auth-security-secrets" or . == "payments-billing" or . == "migrations-data-loss" or . == "project-critical-path"))
         then "security-surface" else empty end),
        (if ($sig | any(. == "shared-core-architecture" or . == "public-api")) then "shared-surface" else empty end),
        (if any(.findings[]; outside_delta($changed[0])) then "open-finding-outside-delta" else empty end) ] as $reasons
    | ($changed[0] | length) as $files
    # A light round runs only the lenses the delta can affect. Every reason below runs the full set.
    | ($reasons
       + (if $files > $max_files or ($lines != null and $lines > $max_lines) then ["large-delta"] else [] end)
       + (if $lines == null then ["delta-size-unknown"] else [] end)
       + (if any(.findings[]; is_blocking and .security) then ["open-security-finding"] else [] end)
       # A round past the cap is a security round or a round the operator decided: never narrowed.
       + (if .iteration >= $cap then ["past-the-cap"] else [] end)) as $depth_reasons
    | .scope = (if ($reasons | length) > 0 then "full" else "delta" end)
    | {state: ., verdict: {scope, reasons: $reasons, baseline: .revision,
        depth: (if ($depth_reasons | length) > 0 then "full" else "light" end), depth_reasons: $depth_reasons,
        delta: {files: $files, lines: $lines}}}' "$TMP/state.json" >"$TMP/scoped.json"
  jq -c '.state' "$TMP/scoped.json" >"$TMP/next.json"
  write_state "$TMP/next.json" "$state"
  jq -c '.verdict' "$TMP/scoped.json"
}

# trusted_comment <comment-url> <pattern> <what> <purpose> — the author association of a comment on the state's own
# pull request, read from the GitHub loader's payload, never from the caller. Exit 4 unless the comment is a trusted
# author's own comment (rules/code-review/general.md *Authorship trust*) with one line of its body that matches
# <pattern>, the regular expression that names <what>.
trusted_comment() {
  local url="$1" pattern="$2" what="$3" purpose="$4" repo pr rc=0 verdict
  repo="$(jq -r '.repo' "$TMP/state.json")"
  pr="$(jq -r '.pr' "$TMP/state.json")"
  set +e
  "$LOADER" "https://github.com/$repo/pull/$pr" >"$TMP/pr.json" 2>"$TMP/pr.err"
  rc=$?
  set -e
  [[ "$rc" -eq 0 ]] || fail 4 "the comment cannot be verified: the pull request cannot be loaded ($(state_path_display "$(head -n 1 "$TMP/pr.err")")); the state is unchanged"
  verdict="$(jq -r -L "$HERE" --arg url "$url" --arg pattern "$pattern" --arg what "$what" --arg repo "$repo" --argjson pr "$pr" 'include "review-trust";
    if type != "object" or .kind != "pr" or .number != $pr
       or ((.repo.nameWithOwner // "") | ascii_downcase) != ($repo | ascii_downcase)
    then "refused:the loader returned another item than the pull request of the state"
    else [ (.comments // [])[] | select(.url == $url) ] as $m
    | if ($m | length) != 1 then "refused:the pull request of the state has no comment with this URL"
      elif ($m[0].body | type) != "string" or ($m[0].body | agent_marker) then
        "refused:the comment carries an agent marker; agent output is never a trusted comment"
      elif ([$m[0].body | splits("\r?\n")] | any(test($pattern)) | not) then "refused:the comment does not name \($what)"
      elif ($m[0].authorAssociation | write_access | not) then
        "refused:the author association \($m[0].authorAssociation // "null") is not trusted"
      else "trusted:\($m[0].authorAssociation)" end
    end' "$TMP/pr.json" 2>/dev/null)" || verdict='refused:the loader payload is not JSON'
  case "$verdict" in
  trusted:OWNER | trusted:MEMBER | trusted:COLLABORATOR) printf '%s\n' "${verdict#trusted:}" ;;
  *) fail 4 "only a trusted author $purpose: $(state_path_display "${verdict#refused:}"); the state is unchanged" ;;
  esac
}

cmd_dismiss() {
  local state='' id='' comment=''
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --state) [[ $# -ge 2 ]] || die_usage '--state needs a value'; state="$2"; shift 2 ;;
    --finding) [[ $# -ge 2 ]] || die_usage '--finding needs a value'; id="$2"; shift 2 ;;
    --comment) [[ $# -ge 2 ]] || die_usage '--comment needs a value'; comment="$2"; shift 2 ;;
    *) die_usage "unknown argument: $(state_path_display "$1")" ;;
    esac
  done
  [[ -n "$state" ]] || die_usage '--state is required'
  [[ "$id" =~ ^r-[0-9a-f]{16}$ ]] || die_usage "--finding must be a finding_id, got: $(state_path_display "$id")"
  [[ "$comment" =~ $COMMENT_URL_RE ]] \
    || die_usage '--comment must be the URL of a comment on the pull request (https://github.com/<owner>/<name>/pull/<n>#issuecomment-<id>)'
  check_writable "$state"
  new_tmp
  load_state "$state" "$TMP/state.json"
  [[ "$(jq -r '.status' "$TMP/state.json")" != 'escalated' ]] || fail 5 "the loop escalated; the state is closed"

  local finding
  finding="$(jq -c --arg id "$id" '.findings[] | select(.finding_id == $id)' "$TMP/state.json")"
  [[ -n "$finding" ]] || fail 4 "no finding $id in the review state"
  jq -e '.security | not' <<<"$finding" >/dev/null || fail 4 "a security finding (S1-S3) is never dismissed; it leaves the loop only by being fixed"
  jq -e '.status == "new" or .status == "confirmed" or .status == "needs-verification"' <<<"$finding" >/dev/null \
    || fail 4 "finding $id is not open; the status is unchanged"
  local association
  association="$(trusted_comment "$comment" "$id" "finding $id" "dismisses a finding")"

  local config cap threshold
  config="$(effective_config)"
  cap="${config%% *}"
  threshold="${config#* }"
  jq -c -L "$HERE" --arg id "$id" --arg actor "trusted:$association" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"'
    (.findings[] | select(.finding_id == $id)) as $f
    | .transitions += [{iteration: .iteration, finding_id: $id, from: $f.status, to: "dismissed", actor: $actor}]
    | .findings = (.findings | map(if .finding_id == $id then .status = "dismissed" else . end))
    | settle($cap; $threshold; false)' "$TMP/state.json" >"$TMP/next.json"
  write_state "$TMP/next.json" "$state"
  jq -c -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"' output($cap; $threshold; [])' "$TMP/next.json"
}

cmd_defer() {
  local state='' id='' url=''
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --state) [[ $# -ge 2 ]] || die_usage '--state needs a value'; state="$2"; shift 2 ;;
    --finding) [[ $# -ge 2 ]] || die_usage '--finding needs a value'; id="$2"; shift 2 ;;
    --url) [[ $# -ge 2 ]] || die_usage '--url needs a value'; url="$2"; shift 2 ;;
    *) die_usage "unknown argument: $(state_path_display "$1")" ;;
    esac
  done
  [[ -n "$state" ]] || die_usage '--state is required'
  [[ "$id" =~ ^r-[0-9a-f]{16}$ ]] || die_usage "--finding must be a finding_id, got: $(state_path_display "$id")"
  [[ "$url" =~ ^https://[^[:space:]]+$ && ${#url} -le 500 ]] || die_usage '--url must be the https URL of the filed sub-issue'
  check_writable "$state"
  new_tmp
  load_state "$state" "$TMP/state.json"
  [[ "$(jq -r '.status' "$TMP/state.json")" != 'escalated' ]] || fail 5 "the loop escalated; the state is closed"

  local config cap threshold
  config="$(effective_config)"
  cap="${config%% *}"
  threshold="${config#* }"
  [[ "$(jq -r '.iteration' "$TMP/state.json")" -ge "$cap" ]] || fail 4 "a finding is deferred only at the cap round ($cap)"
  jq -e -L "$HERE" --arg id "$id" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"'
    verdict($cap; $threshold).deferrable | any(.finding_id == $id)' "$TMP/state.json" >/dev/null \
    || fail 4 "finding $id is not deferrable: it is not blocking, or it is security-relevant or at or above the blocking threshold"
  jq -c -L "$HERE" --arg id "$id" --arg url "$url" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"'
    .deferrals += [{finding_id: $id, url: $url}] | settle($cap; $threshold; false)' "$TMP/state.json" >"$TMP/next.json"
  write_state "$TMP/next.json" "$state"
  jq -c -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"' output($cap; $threshold; [])' "$TMP/next.json"
}

# The shapes of a task and of a linked issue. A GitHub URL captures the repository, a JIRA key its project, and a
# JIRA URL its site and key.
GH_PR_RE='^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/pull/([1-9][0-9]{0,8})$'
GH_ISSUE_RE='^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/issues/[1-9][0-9]{0,8}$'
JIRA_KEY_RE='^([A-Z][A-Z0-9_]*)-[1-9][0-9]{0,8}$'
JIRA_URL_RE='^https://([A-Za-z0-9.-]+)/browse/(([A-Z][A-Z0-9_]*)-[1-9][0-9]{0,8})$'
# The commits that `gh pr view --json commits` returns at most (one page; it does not page).
COMMIT_PAGE=100

lower() { printf '%s' "$1" | LC_ALL=C tr '[:upper:]' '[:lower:]'; }

# linked_issue_verdict <repo> <pr> <source> <issue> — the checks of `known` on the link itself, read from the loaders
# and never from the caller. <repo> and <pr> name the pull request of the state. Prints `ok` or `refused:<reason>` and
# always returns 0: `known` refuses on `refused:`, and the check of a later round reopens the point. A loader that
# fails is a refusal, so the check fails closed.
#   The trust facts of a GitHub issue (its author association) hold only in the repository of that
#   issue, so the task and the linked issue are issues of the repository of the pull request. A JIRA issue is trusted
#   only on the site and in the project of the task, whose permission scheme decides who edits its description.
#   The merge of the pull request closes every issue in its `closingIssues[]` and every issue that a closing keyword
#   names in one of its commit messages, in its title or its body (a merge commit and a squash commit carry them), or
#   in its branch name (the default merge commit message carries it), so such an issue makes no known point. The check
#   is stricter than GitHub on purpose: in a text GitHub may read as a closing one it refuses when a reference to the
#   linked issue (`#n`, `gh-n`, `owner/repo#n`, the issue URL or the pull request URL of that number, any case, leading
#   zeros) stands on the same line as a closing keyword (close, fix, resolve, with or without -s and -d) or on the next
#   line, whatever stands between the two (markup, brackets, a semicolon, the word issue). Every keyword of a line
#   counts, not only the first. It names no markup form, so a new form of markup opens no gap. The branch name is
#   refused when it holds a keyword and the number. The pull request must close the task.
#   The loader cuts a commit headline of 74 or more characters at 69 and puts the rest at the start of the message
#   body, behind an ellipsis, so the check joins the headline and the body the ways that the cut can have split them
#   (with nothing, a space, or a line break between), and tests every join. A commit list of COMMIT_PAGE or more
#   entries is a refusal: `gh pr view --json commits` returns no more than one page of 100 and does not page.
#   A missing title, body, branch name, or commit message is a refusal.
#   Not covered: a title or message that a person edits in the merge dialog of a squash or a merge commit. A closing
#   keyword that reaches the pull request after the last review round is caught by `known --verify` before the merge.
linked_issue_verdict() {
  local repo="$1" pr="$2" source="$3" issue="$4" rc=0 verdict
  if [[ "$source" =~ $GH_ISSUE_RE ]]; then
    [[ "$(lower "${BASH_REMATCH[1]}")" == "$(lower "$repo")" ]] \
      || { echo 'refused:the task is not an issue of the repository of the pull request'; return 0; }
    [[ "$issue" =~ $GH_ISSUE_RE ]] || { echo 'refused:a GitHub task links only a GitHub issue URL'; return 0; }
    [[ "$(lower "${BASH_REMATCH[1]}")" == "$(lower "$repo")" ]] \
      || { echo 'refused:the linked issue is not an issue of the repository of the pull request'; return 0; }
    set +e
    "$LOADER" "$source" </dev/null >"$TMP/source.json" 2>/dev/null && "$LOADER" "$issue" </dev/null >"$TMP/linked.json" 2>/dev/null
    rc=$?
    set -e
    [[ "$rc" -eq 0 ]] || { echo 'refused:the task or the linked issue cannot be loaded'; return 0; }
    set +e
    "$LOADER" "https://github.com/$repo/pull/$pr" </dev/null >"$TMP/closing.json" 2>/dev/null
    rc=$?
    set -e
    [[ "$rc" -eq 0 ]] || { echo 'refused:the pull request cannot be loaded'; return 0; }
    verdict="$(jq -r -n -L "$HERE" --slurpfile src "$TMP/source.json" --slurpfile lnk "$TMP/linked.json" \
      --slurpfile prq "$TMP/closing.json" --arg source "$source" --arg issue "$issue" --arg repo "$repo" \
      --argjson pr "$pr" --argjson page "$COMMIT_PAGE" --arg n "${issue##*/}" 'include "review-trust";
      # Every closing keyword (close, fix, resolve, with or without -s and -d, any case) of a line opens a reach: the
      # rest of its line and the whole next line (the next line with text, when nothing but marks follows the
      # keyword). A reference to issue $n of $repo in the reach closes it, whatever stands between the keyword and
      # the reference: #n, gh-n, owner/name#n, or the issue URL or pull request URL of that number (http or https,
      # with or without www). Leading zeros of the number count. Another repository, another number, and a reference
      # that no keyword reaches do not.
      ($repo | ascii_downcase | gsub("\\."; "\\.")) as $r
      | "(?<![a-z0-9])(close[sd]?|fix(e[sd])?|resolve[sd]?)(?![a-z])" as $kw
      | ("(?<![a-z0-9_./-])(#|gh-)0*\($n)(?![0-9])"
          + "|(?<![a-z0-9_.-])\($r)#0*\($n)(?![0-9])"
          + "|https?://(www\\.)?github\\.com/\($r)/(issues|pull)/0*\($n)(?![0-9])") as $ref
      | # The lines of a text: a line break is CR LF, CR, LF, VT, FF, NEL, LS, or PS. Literal splits keep the cost linear,
        # where a regex split of jq costs more than the square of the line count.
        def lines: reduce ("\r\n", "\r", "\u000b", "\u000c", "\u0085", "\u2028", "\u2029") as $s (.; split($s) | join("\n"))
          | split("\n");
        # The next line with text of each line, computed once from the last line up.
        def next_text($ls): reduce ($ls | to_entries | reverse)[] as $e ({n: "", out: []};
            .out += [.n] | if ($e.value | test("\\S")) then .n = $e.value else . end) | .out | reverse;
        def closing: lines as $ls
          | next_text($ls) as $nt
          | any(range(0; $ls | length); . as $i
              | if ($ls[$i] | test($kw; "i") | not) then false
                else (($ls[$i + 1] // "") | test($ref; "i")) as $imm
                  | ($nt[$i] | test($ref; "i")) as $nxt
                  | any($ls[$i] | match($kw; "gi");
                      ($ls[$i][(.offset + .length):]) as $tail
                      | ($tail | test($ref; "i"))
                        or (if ($tail | test("[a-z0-9]"; "i")) then $imm else $nxt end)) end);
        # The messages a commit can have had before the loader cut its headline: the plain one, and, behind a cut
        # headline, the headline and the body joined with nothing, a space, or a line break between.
        def messages: .messageHeadline as $h | .messageBody as $b
          | [$h + "\n\n" + $b]
            + (if ($h | endswith("\u2026"))
               then ($h | rtrimstr("\u2026")) as $x | ($b | ltrimstr("\u2026")) as $y | [$x + $y, $x + " " + $y, $x + "\n" + $y]
               else [] end);
        def branch_closes: test($kw; "i") and test("(?<![0-9])0*\($n)(?![0-9])");
      ($src[0] // null) as $t | ($lnk[0] // null) as $l | ($prq[0] // null) as $q
      | if ($t | type) != "object" or $t.kind != "issue" or $t.url != $source then "refused:the loader returned another item than the task"
        elif ([($t.linkedIssues // [])[] | .url] | index([$issue])) == null then "refused:the issue is not in the link list of the task"
        elif ($l | type) != "object" or $l.kind != "issue" or $l.url != $issue then "refused:the loader returned another item than the linked issue"
        elif $l.state != "OPEN" then "refused:the linked issue is not open"
        elif ($l.authorAssociation | write_access | not) then "refused:the author association \($l.authorAssociation // "null") of the linked issue is not trusted"
        elif ($q | type) != "object" or $q.kind != "pr" or $q.number != $pr
             or ((($q.repo.nameWithOwner // "") | ascii_downcase) != ($repo | ascii_downcase))
             or ($q.closingIssues | type) != "array" or ($q.commits | type) != "array"
        then "refused:the loader returned another item than the pull request of the state"
        elif ([$q.closingIssues[] | (.url // "") | ascii_downcase] | index([$source | ascii_downcase])) == null
        then "refused:the pull request does not close the task"
        elif ([$q.closingIssues[] | (.url // "") | ascii_downcase] | index([$issue | ascii_downcase])) != null
        then "refused:the pull request closes the linked issue"
        elif ($q.commits | length) >= $page
        then "refused:the loader may have cut the commit list at its page size of \($page)"
        elif ($q.commits | all(.[]; (.messageHeadline | type) == "string" and (.messageBody | type) == "string") | not)
        then "refused:the loader returned a commit without its message headline and body"
        elif ($q.title | type) != "string" or ($q.body | type) != "string"
        then "refused:the loader returned a pull request without its title and body"
        elif ($q.headRefName | type) != "string"
        then "refused:the loader returned a pull request without its branch name"
        elif ($q.commits | any(.[]; messages | any(.[]; closing)))
        then "refused:a commit of the pull request closes the linked issue"
        elif ($q.title | closing)
        then "refused:the title of the pull request closes the linked issue"
        elif ($q.body | closing)
        then "refused:the body of the pull request closes the linked issue"
        elif ($q.headRefName | branch_closes)
        then "refused:the branch name of the pull request names a closing keyword and the linked issue"
        else "ok" end' 2>/dev/null)" || verdict='refused:the loader payload is not JSON'
  elif [[ "$source" =~ $JIRA_KEY_RE ]]; then
    local project="${BASH_REMATCH[1]}" host key
    [[ "$issue" =~ $JIRA_URL_RE ]] || { echo 'refused:a JIRA task links only a JIRA /browse/ URL'; return 0; }
    host="$(lower "${BASH_REMATCH[1]}")"
    key="${BASH_REMATCH[2]}"
    [[ "${BASH_REMATCH[3]}" == "$project" ]] || { echo 'refused:the linked issue is not an issue of the project of the task'; return 0; }
    set +e
    "$JIRA_LOADER" "$source" </dev/null >"$TMP/source.json" 2>/dev/null && "$JIRA_LOADER" "$key" </dev/null >"$TMP/linked.json" 2>/dev/null
    rc=$?
    set -e
    [[ "$rc" -eq 0 ]] || { echo 'refused:the task or the linked issue cannot be loaded'; return 0; }
    verdict="$(jq -r -n --slurpfile src "$TMP/source.json" --slurpfile lnk "$TMP/linked.json" \
      --arg source "$source" --arg key "$key" --arg issue "$issue" --arg host "$host" '
      ($src[0] // null) as $t | ($lnk[0] // null) as $l
      | if ($t | type) != "object" or $t.key != $source then "refused:the loader returned another item than the task"
        elif (($t.url // "") | ascii_downcase | startswith("https://\($host)/browse/") | not) then "refused:the linked issue is not on the JIRA site of the task"
        elif ([($t.parent.key // empty), ($t.subtasks // [])[].key, ($t.issueLinks // [])[].linkedKey, ($t.mentionedKeys // [])[]]
              | index([$key])) == null then "refused:the issue is not in the link list of the task"
        elif ($l | type) != "object" or $l.key != $key or $l.url != $issue then "refused:the loader returned another item than the linked issue"
        elif $l.resolution != null then "refused:the linked issue is not open"
        elif $l.descriptionTrusted != true then "refused:the description of the linked issue is not the text of the operator account alone"
        else "ok" end' 2>/dev/null)" || verdict='refused:the loader payload is not JSON'
  else
    verdict='refused:the task is neither a GitHub issue URL nor a JIRA key'
  fi
  case "$verdict" in
  ok | refused:*) printf '%s\n' "$verdict" ;;
  *) echo 'refused:the check returned no verdict' ;;
  esac
}

# reverify_known <state-file> <cap> <threshold> <current-round> — check every known point of the state again, with
# the checks of `known`, through the loaders. A point whose check fails now moves back to an open finding, and each
# one is named on stderr. <current-round> is true when the check belongs to the round of the state's last entry (an
# evaluation), so that entry shows the new verdict; a new round computes its own. Rewrites <state-file>.
reverify_known() {
  local file="$1" cap="$2" threshold="$3" current="$4" repo pr id source url verdict ids='[]'
  jq -e '(.known_points // []) | length > 0' "$file" >/dev/null || return 0
  repo="$(jq -r '.repo' "$file")"
  pr="$(jq -r '.pr' "$file")"
  while IFS=$'\t' read -r id source url; do
    verdict="$(linked_issue_verdict "$repo" "$pr" "$source" "$url")"
    if [[ "$verdict" != ok ]]; then
      ids="$(jq -c --arg id "$id" '. + [$id]' <<<"$ids")"
      echo "$PROG: known point $id is open again: $(state_path_display "${verdict#refused:}")" >&2
    fi
  done < <(jq -r '.known_points[] | [.finding_id, .source, .url] | @tsv' "$file")
  [[ "$ids" != '[]' ]] || return 0
  jq -c -L "$HERE" --argjson ids "$ids" --argjson cap "$cap" --arg threshold "$threshold" --argjson current "$current" "$JQ_STATE"'
    reopen_known($ids) | settle($cap; $threshold; false) | if $current then refresh_round($cap; $threshold) else . end' \
    "$file" >"$file.reverified"
  mv -f "$file.reverified" "$file"
}

# verify_known <state-file> <pr-url> — the merge-time check: run the checks of `known` on every known point of the state again, as
# `reverify_known` does, but write nothing. Prints {"checked": n, "urls": [...], "refused": [{finding_id, url, reason}]} and exits 4 when
# any point is refused, a loader that fails included. A state with no known point passes. A state that is missing or
# invalid, a state path that `check_writable` refuses, and a state whose repository or pull request is not <pr-url> exit
# 3, so a caller that cannot read the right state does not pass either.
verify_known() {
  local repo pr id source url verdict refused='[]' urls='[]' checked=0 want_repo want_pr
  [[ "$2" =~ $GH_PR_RE ]] || die_usage '--verify needs --pr, the https URL of the pull request that the merge loaded'
  want_repo="${BASH_REMATCH[1]}"
  want_pr="${BASH_REMATCH[2]}"
  check_writable "$1"
  new_tmp
  load_state "$1" "$TMP/state.json"
  repo="$(jq -r '.repo' "$TMP/state.json")"
  pr="$(jq -r '.pr' "$TMP/state.json")"
  [[ "$(lower "$repo")" == "$(lower "$want_repo")" && "$pr" == "$want_pr" ]] \
    || fail 3 'the review state belongs to another pull request than the one being merged'
  while IFS=$'\t' read -r id source url; do
    checked=$((checked + 1))
    urls="$(jq -c --arg url "$url" '. + [$url]' <<<"$urls")"
    verdict="$(linked_issue_verdict "$repo" "$pr" "$source" "$url")"
    [[ "$verdict" == ok ]] \
      || refused="$(jq -c --arg id "$id" --arg url "$url" --arg reason "${verdict#refused:}" '. + [{finding_id: $id, url: $url, reason: $reason}]' <<<"$refused")"
  done < <(jq -r '(.known_points // [])[] | [.finding_id, .source, .url] | @tsv' "$TMP/state.json")
  jq -n -c --argjson checked "$checked" --argjson urls "$urls" --argjson refused "$refused" '{checked: $checked, urls: $urls, refused: $refused}'
  [[ "$refused" == '[]' ]] || fail 4 'a known point no longer holds; the merge is refused'
}

cmd_known() {
  local state='' id='' source='' issue='' covers='' pr_url='' reopen=0 verify=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --state) [[ $# -ge 2 ]] || die_usage '--state needs a value'; state="$2"; shift 2 ;;
    --finding) [[ $# -ge 2 ]] || die_usage '--finding needs a value'; id="$2"; shift 2 ;;
    --source) [[ $# -ge 2 ]] || die_usage '--source needs a value'; source="$2"; shift 2 ;;
    --issue) [[ $# -ge 2 ]] || die_usage '--issue needs a value'; issue="$2"; shift 2 ;;
    --covers) [[ $# -ge 2 ]] || die_usage '--covers needs a value'; covers="$2"; shift 2 ;;
    --reopen) reopen=1; shift ;;
    --verify) verify=1; shift ;;
    --pr) [[ $# -ge 2 ]] || die_usage '--pr needs a value'; pr_url="$2"; shift 2 ;;
    *) die_usage "unknown argument: $(state_path_display "$1")" ;;
    esac
  done
  [[ -n "$state" ]] || die_usage '--state is required'
  [[ "$verify" -eq 1 || -z "$pr_url" ]] || die_usage '--pr belongs to --verify'
  if [[ "$verify" -eq 1 ]]; then
    [[ -z "$id$source$issue$covers" && "$reopen" -eq 0 ]] || die_usage '--verify takes only --state and --pr'
    verify_known "$state" "$pr_url"
    return
  fi
  [[ "$id" =~ ^r-[0-9a-f]{16}$ ]] || die_usage "--finding must be a finding_id, got: $(state_path_display "$id")"
  if [[ "$reopen" -eq 1 ]]; then
    [[ -z "$source$issue$covers" ]] || die_usage '--reopen takes only --state and --finding'
  else
    [[ "$source" =~ $GH_ISSUE_RE || "$source" =~ $JIRA_KEY_RE ]] || die_usage '--source must be the task: a GitHub issue URL or a JIRA key'
    [[ "$issue" =~ ^https://[^[:space:]]+$ && ${#issue} -le 500 ]] || die_usage '--issue must be the https URL of the linked issue'
    [[ ${#covers} -ge 1 && ${#covers} -le 300 && ! "$covers" =~ [[:cntrl:]] ]] || die_usage '--covers must be one line of 1 to 300 characters'
  fi
  check_writable "$state"
  new_tmp
  load_state "$state" "$TMP/state.json"
  # An escalated loop is closed. The one exception is a cap round whose own verdict escalated it on a hard blocker:
  # the comparison of that round runs after its barrier, so a known point it marks settles the verdict again.
  local cap_round=false
  case "$(jq -r '"\(.status) \(.escalation_reason)"' "$TMP/state.json")" in
  'escalated cap-reached-hard-blockers')
    [[ "$reopen" -eq 0 ]] || fail 5 "the loop escalated; the state is closed"
    cap_round=true
    ;;
  escalated\ *) fail 5 "the loop escalated; the state is closed" ;;
  esac

  local finding
  finding="$(jq -c --arg id "$id" '.findings[] | select(.finding_id == $id)' "$TMP/state.json")"
  [[ -n "$finding" ]] || fail 4 "no finding $id in the review state"

  local config cap threshold
  config="$(effective_config)"
  cap="${config%% *}"
  threshold="${config#* }"
  if [[ "$reopen" -eq 1 ]]; then
    jq -e '.status == "known"' <<<"$finding" >/dev/null || fail 4 "finding $id is not a known point; the status is unchanged"
    jq -c -L "$HERE" --arg id "$id" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"'
      reopen_known([$id]) | settle($cap; $threshold; false) | refresh_round($cap; $threshold)' "$TMP/state.json" >"$TMP/next.json"
  else
    jq -e '.status == "new" or .status == "confirmed" or .status == "needs-verification"' <<<"$finding" >/dev/null \
      || fail 4 "finding $id is not open; the status is unchanged"
    # One record can merge several findings of one identity, and a comparison sees only the text of the first.
    jq -e '.reports == 1' <<<"$finding" >/dev/null \
      || fail 4 "finding $id merges more than one reported finding, or does not say how many; the state is unchanged"
    local verdict
    verdict="$(linked_issue_verdict "$(jq -r '.repo' "$TMP/state.json")" "$(jq -r '.pr' "$TMP/state.json")" "$source" "$issue")"
    [[ "$verdict" == ok ]] \
      || fail 4 "the link is not a trusted linked issue: $(state_path_display "${verdict#refused:}"); the state is unchanged"
    jq -c -L "$HERE" --arg id "$id" --arg source "$source" --arg url "$issue" --arg covers "$covers" \
      --argjson cap_round "$cap_round" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"'
      (.findings[] | select(.finding_id == $id)) as $f
      | .transitions += [{iteration: .iteration, finding_id: $id, from: $f.status, to: "known", actor: "orchestrator"}]
      | .findings = (.findings | map(if .finding_id == $id then .status = "known" else . end))
      | .known_points = (((.known_points // []) | map(select(.finding_id != $id)))
          + [{finding_id: $id, source: $source, url: $url, covers: $covers, marked: ($f | marked_view)}])
      | (if $cap_round then .status = "open" | .escalation_reason = null else . end)
      | settle($cap; $threshold; false) | refresh_round($cap; $threshold)' "$TMP/state.json" >"$TMP/next.json"
  fi
  write_state "$TMP/next.json" "$state"
  jq -c -L "$HERE" --argjson cap "$cap" --arg threshold "$threshold" "$JQ_STATE"' output($cap; $threshold; [])' "$TMP/next.json"
}

# The fixtures below are Markdown that carries backticks and dollar signs; none of it is meant to expand.
# shellcheck disable=SC2016
self_test() {
  local failures=0 script tmp
  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmp'" EXIT

  # The scripts are copied into a temporary tree, so the GitHub loader is a fixture and not the network.
  mkdir -p "$tmp/pkg/skills/_shared" "$tmp/pkg/skills/code-review-github/scripts" "$tmp/pkg/skills/code-review-jira/scripts"
  cp "$HERE"/{review-state,state-path,extract-findings,cr-header,read-manifest,classify-risk,domain-vocabulary}.sh "$HERE/finding-defs.jq" "$HERE/review-trust.jq" "$tmp/pkg/skills/_shared/"
  script="$tmp/pkg/skills/_shared/$PROG"
  cat >"$tmp/pkg/skills/code-review-github/scripts/load-issue.sh" <<EOF
#!/usr/bin/env bash
printf '%s\n' "\$1" >>"$tmp/loader-calls"
if [[ "\$1" =~ ^https://github\.com/([a-z]+)/([a-z]+)/issues/([0-9]+)\$ ]]; then
  f="$tmp/gh-issue-\${BASH_REMATCH[1]}-\${BASH_REMATCH[2]}-\${BASH_REMATCH[3]}.json"
  [[ -f "\$f" ]] || { echo 'no such issue' >&2; exit 4; }
  cat "\$f"
  exit 0
fi
[[ "\$1" == 'https://github.com/acme/shop/pull/7' && -f "$tmp/pr.json" ]] || { echo 'no such pull request' >&2; exit 4; }
cat "$tmp/pr.json"
EOF
  chmod +x "$tmp/pkg/skills/code-review-github/scripts/load-issue.sh"
  cat >"$tmp/pkg/skills/code-review-jira/scripts/load-issue.sh" <<EOF
#!/usr/bin/env bash
[[ "\$1" =~ ^[A-Z]+-[0-9]+\$ && -f "$tmp/jira-\$1.json" ]] || { echo 'no such issue' >&2; exit 4; }
cat "$tmp/jira-\$1.json"
EOF
  chmod +x "$tmp/pkg/skills/code-review-jira/scripts/load-issue.sh"
  # gh_issue <[owner/repo#]number> <association> <state> <author> — the loader payload of the issue
  # (acme/shop when no repository is named), which links #12, #13, #14, and, as a loader of another version could list
  # them, evil/x#1, evil/x#12, and acme/shopx#1.
  gh_issue() {
    local ref="$1" a="$2" st="$3" au="$4" nwo=acme/shop n
    [[ "$ref" != *'#'* ]] || nwo="${ref%%#*}"
    n="${ref##*#}"
    jq -n -c --arg nwo "$nwo" --argjson n "$n" --arg a "$a" --arg st "$st" --arg au "$au" \
      '{kind: "issue", number: $n, url: "https://github.com/\($nwo)/issues/\($n)", state: $st, author: $au,
        authorAssociation: $a,
        linkedIssues: [{relation: "sub-issue", number: 12, title: "Known", url: "https://github.com/acme/shop/issues/12", state: "OPEN"},
                       {relation: "mentioned", number: 13, title: null, url: "https://github.com/acme/shop/issues/13", state: null},
                       {relation: "mentioned", number: 14, title: null, url: "https://github.com/acme/shop/issues/14", state: null},
                       {relation: "blocked-by", number: 1, title: "Upstream", url: "https://github.com/evil/x/issues/1", state: "OPEN"},
                       {relation: "sub-issue", number: 12, title: "Upstream", url: "https://github.com/evil/x/issues/12", state: "OPEN"},
                       {relation: "blocks", number: 1, title: "Fork", url: "https://github.com/acme/shopx/issues/1", state: "OPEN"}]}' \
      >"$tmp/gh-issue-${nwo%/*}-${nwo#*/}-$n.json"
  }
  # pr_comments <comment-json>... — the loader payload of pull request acme/shop#7 with these comments.
  pr_comments() {
    jq -n -c '{kind: "pr", number: 7, url: "https://github.com/acme/shop/pull/7",
      repo: {owner: "acme", name: "shop", nameWithOwner: "acme/shop"}, comments: $ARGS.positional}' --jsonargs "$@" >"$tmp/pr.json"
  }
  # pr_closes <commits-json> <issue-url>... — the loader payload of pull request acme/shop#7 that closes these issues
  # and carries these commits.
  pr_closes() {
    local commits="$1"
    shift
    jq -n -c --argjson commits "$commits" --arg title "${PR_TITLE-feat(orders): add the export (#10)}" --arg body "${PR_BODY-Adds the export.}" \
      --arg branch "${PR_BRANCH-feat/orders-export}" \
      '{kind: "pr", number: 7, url: "https://github.com/acme/shop/pull/7",
      repo: {owner: "acme", name: "shop", nameWithOwner: "acme/shop"}, comments: [],
      title: $title, body: $body, headRefName: $branch,
      closingIssues: [$ARGS.positional[] | {url: .}], commits: $commits}' --args "$@" >"$tmp/pr.json"
  }
  # pr_page <count> <issue-url> — the loader payload of pull request acme/shop#7 with <count> commits that close nothing.
  pr_page() {
    pr_closes "$(jq -n -c --argjson n "$1" '[range($n) | {oid: "p\(.)", messageHeadline: "fix: z", messageBody: ""}]')" "$2"
  }
  # comment <id> <association> <body> — one loader comment object.
  comment() {
    jq -n -c --arg id "$1" --arg a "$2" --arg b "$3" \
      '{author: "someone", authorAssociation: $a, body: $b, createdAt: "2026-10-01T10:00:00Z", updatedAt: null,
        url: "https://github.com/acme/shop/pull/7#issuecomment-\($id)"}'
  }

  check() {
    if [[ "$2" == 0 ]]; then
      printf 'ok    %s\n' "$1"
    else
      printf 'FAIL  %s\n' "$1" >&2
      failures=$((failures + 1))
    fi
  }

  # run_case <label> <expected-exit> -- <args...>   (runs in the project repository; output in $tmp/out, $tmp/err)
  run_case() {
    local label="$1" expected="$2" rc=0
    shift 3
    set +e
    (cd "$repo" && "$script" "$@") >"$tmp/out" 2>"$tmp/err"
    rc=$?
    set -e
    if [[ "$rc" -eq "$expected" ]]; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s (expected exit %s, got %s: %s)\n' "$label" "$expected" "$rc" "$(head -n 1 "$tmp/err")" >&2
      failures=$((failures + 1))
    fi
  }
  # q [jq-option]... <jq-program> — the exit of `jq -e` over the last output, as 0 or 1.
  q() { jq -e "$@" "$tmp/out" >/dev/null 2>&1 && echo 0 || echo 1; }

  # manifest <json> — commit a manifest and make it the default branch.
  manifest() {
    printf '{ "name": "acme/shop", "extra": { "ai-olympus": %s } }\n' "$1" >"$repo/composer.json"
    git -C "$repo" add composer.json
    git -C "$repo" -c user.name=t -c user.email=t@t commit -q -m manifest
    git -C "$repo" update-ref refs/remotes/origin/master HEAD
    git -C "$repo" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/master
  }

  # finding <severity> <title> <location> <rule> <evidence|-> [level]
  finding() {
    printf '### 🔴 %s 1. %s\n\n- **Location:** `%s`\n- **Rule:** `%s`\n' "$1" "$2" "$3" "$4"
    [[ -n "${6:-}" ]] && printf -- '- **Level:** %s\n' "$6"
    printf -- '- **Provenance:** `regression — introduced in this revision`\n- **Impact:** %s impact.\n' "$2"
    [[ "$5" != '-' ]] && printf -- '- **Evidence:** %s\n' "$5"
    printf -- '- **Faulty Example:** x\n- **Expected behavior:** y\n- **Test hint:** z\n- **Suggested fix:** n/a — w\n\n'
  }
  # report <file> <round> [finding-block]... — a round report; the round number picks the revision and the time.
  report() {
    local file="$1" n="$2"
    shift 2
    {
      printf '# Code Review\n\n**Status:** needs-fix\n**Counts:** Critical 0 · Moderate 0\n'
      printf '**Reviewed revision:** %s\n' "$(printf '%040d' "$n" | tr '0' 'a')"
      printf '**Last updated:** 2026-10-0%sT10:00:00Z\n\n## Findings\n\n' "$n"
      [[ $# -eq 0 ]] || printf '%s\n\n' "$@"
    } >"$file"
  }

  local repo="$tmp/repo"
  mkdir -p "$repo/.claude/run" "$repo/.codex/run"
  git -C "$repo" init -q
  manifest '{}'
  local S=".claude/run/review-state.json"
  local SQL="@rules/security/backend.md#database" STRUCT="@rules/php/core-standards.md#structure"
  local F_SQL F_SQL2 F_STRUCT F_NOEV F_SEC_NOEV F_LOW_SEC F_MED F_CRIT
  F_SQL="$(finding Critical 'Raw query' 'app/Http/Orders.php:42' "$SQL" 'exploit-path — sort=1;DROP reaches DB::raw')"
  F_SQL2="$(finding Critical 'Raw query again' 'app/Http/Invoices.php:7' "$SQL" 'exploit-path — the same path in invoices')"
  F_STRUCT="$(finding Moderate 'Copied step' 'src/Foo/Bar.php:10' "$STRUCT" 'rule-violation — the step is copied')"
  # A point without evidence goes under `## Needs verification`; a report puts it after every proven finding.
  F_NOEV="$(printf '## Needs verification\n\n'; finding Moderate 'Maybe a copied step' 'src/Foo/Baz.php:3' "$STRUCT" '-')"
  F_SEC_NOEV="$(printf '## Needs verification\n\n'; finding Moderate 'Token may be logged' 'src/Foo/Log.php:3' "$STRUCT" '-')"
  F_LOW_SEC="$(finding Moderate 'Weak escaping' 'src/View/Render.php:3' "$SQL" 'exploit-path — <b> reaches the page' Low)"
  F_MED="$(finding Moderate 'Copied step' 'src/Foo/Bar.php:10' "$STRUCT" 'rule-violation — the step is copied' Medium)"
  F_CRIT="$(finding Critical 'Wrong total' 'src/Foo/Total.php:5' "$STRUCT" 'logic-trace — the total skips the discount')"
  printf 'src/Foo/Bar.php\napp/Http/Orders.php\n' >"$tmp/changed-both"
  printf 'docs/readme.md\n' >"$tmp/changed-none"

  # --- the state lives only under the git-ignored run directory --------------------------------------------
  run_case 'a state outside the run directory is refused' 3 -- init --state review.json --repo acme/shop --pr 7
  check 'the refused state is not written' "$([[ ! -e "$repo/review.json" ]] && echo 0 || echo 1)"
  ln -s "$repo/.claude/run/elsewhere.json" "$repo/.claude/run/link.json"
  run_case 'a state that is a symbolic link is refused' 3 -- init --state .claude/run/link.json --repo acme/shop --pr 7
  run_case 'a state under .codex/run is accepted' 0 -- init --state .codex/run/review-state.json --repo acme/shop --pr 7
  run_case 'init writes a fresh state under .claude/run' 0 -- init --state "$S" --repo acme/shop --pr 7
  check 'a fresh state starts at round 0 with the default cap 3 and threshold critical' "$(q '.resumed == false and .iteration == 0 and .max_iterations == 3 and .blocking_threshold == "critical"')"
  run_case 'a state without a review round is evaluated' 0 -- evaluate --state "$S"
  check 'a state without a review round is never converged' "$(q '.verdict == "continue" and .reason == "no review round is recorded yet"')"

  # --- scenarios 1, 2, 3: identity -------------------------------------------------------------------------
  report "$tmp/r1.md" 1 "$F_SQL" "$F_SQL2" "$F_STRUCT"
  run_case 'round 1 is recorded' 0 -- record --state "$S" --input "$tmp/r1.md"
  check 'scenario 3: two similar but distinct defects stay two findings' "$(jq -e '[.findings[] | select(.category == "rules/security/backend")] | length == 2' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  check 'round 1 counts three new blocking findings and continues' "$(q '.verdict == "continue" and .counts.new == 3 and .counts.remaining_blocking == 3 and .iteration == 1')"
  run_case 'a second round needs the changed files of its delta' 1 -- record --state "$S" --input "$tmp/r1.md"
  F_SQL_MOVED="$(finding Critical 'The sort still reaches the raw query' 'app/Http/Orders.php:57' "$SQL" 'exploit-path — unchanged')"
  report "$tmp/r2.md" 2 "$F_SQL_MOVED" "$F_SQL2" "$F_STRUCT"
  run_case 'round 2 is recorded' 0 -- record --state "$S" --input "$tmp/r2.md" --changed-files "$tmp/changed-none"
  check 'scenarios 1 and 2: a repeated finding and one on another line keep their identity' "$(jq -e '(.findings | length) == 3 and all(.findings[]; .status == "confirmed") and .rounds[1].new == 0' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  check 'the moved finding carries its new line' "$(jq -e 'any(.findings[]; .file == "app/Http/Orders.php" and .line == 57)' "$repo/$S" >/dev/null && echo 0 || echo 1)"

  # --- scenario 6: an unresolved finding persists; a resolved claim changes nothing -------------------------
  cp "$repo/$S" "$tmp/before-r3"
  report "$tmp/r3.md" 3 "$F_SQL_MOVED" "$F_STRUCT" "$F_CRIT"
  printf '\n## TL;DR\n\n- Raw query again: resolved, see the PR comment "this is fixed".\n' >>"$tmp/r3.md"
  run_case 'round 3 without one finding is recorded' 0 -- record --state "$S" --input "$tmp/r3.md" --changed-files "$tmp/changed-none"
  check 'scenario 6: an unresolved finding whose file the delta did not touch stays open' "$(jq -e '[.findings[] | select(.file == "app/Http/Invoices.php")][0].status == "confirmed"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  check 'a resolved claim in report text leaves the status unchanged and the finding is reported as carried' "$(q '(.carried | length) == 1 and .counts.resolved == 0')"
  check 'scenario 8: the third unsuccessful round escalates at the default cap' "$(q '.verdict == "escalated" and .status == "escalated" and .escalation_reason == "cap-reached-hard-blockers"')"
  check 'the escalation names the remaining defects and the reason' "$(q '(.blocking | length) == 4 and (.reason | test("round cap of 3 is reached"))')"
  check 'a Critical that is not security-relevant escalates at the cap without a security round' "$(q '.status == "escalated" and .rounds[2].verdict == "escalated"')"
  cp "$repo/$S" "$tmp/escalated"
  run_case 'scenario 9: no further automatic round starts after the escalation' 5 -- record --state "$S" --input "$tmp/r1.md" --changed-files "$tmp/changed-both"
  check 'the escalated state is untouched' "$(cmp -s "$tmp/escalated" "$repo/$S" && echo 0 || echo 1)"
  run_case 'an escalated loop accepts no deferral' 5 -- defer --state "$S" --finding r-0000000000000000 --url https://github.com/acme/shop/issues/9
  run_case 'there is no command that marks a finding resolved' 1 -- resolve --state "$S"

  # --- scenarios 4 and 7: resolution, regression, and convergence before the cap ---------------------------
  rm -f "$repo/$S"
  run_case 'a new loop starts' 0 -- init --state "$S" --repo acme/shop --pr 7
  report "$tmp/a1.md" 1 "$F_SQL" "$F_STRUCT"
  run_case 'loop 2 round 1' 0 -- record --state "$S" --input "$tmp/a1.md"
  report "$tmp/a2.md" 2 "$F_STRUCT"
  run_case 'loop 2 round 2 without the fixed finding' 0 -- record --state "$S" --input "$tmp/a2.md" --changed-files "$tmp/changed-both"
  check 'a finding the round no longer carries in a changed file is resolved' "$(jq -e '[.findings[] | select(.file == "app/Http/Orders.php")][0].status == "resolved"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  report "$tmp/a3.md" 3 "$F_SQL_MOVED"
  run_case 'loop 2 round 3 brings the defect back' 0 -- record --state "$S" --input "$tmp/a3.md" --changed-files "$tmp/changed-both"
  check 'scenario 4: a resolved finding that reappears is reopened, not new' "$(q '.counts.reopened == 1 and .rounds[2].new == 0 and .rounds[2].reopened == 1')"
  check 'the reopened finding blocks again and keeps its history' "$(jq -e '([.findings[] | select(.file == "app/Http/Orders.php")][0].status == "confirmed")
      and ([.transitions[] | select(.from == "resolved" and .to == "confirmed")] | length == 1)' "$repo/$S" >/dev/null && echo 0 || echo 1)"

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/b1.md" 1 "$F_STRUCT"
  run_case 'loop 3 round 1' 0 -- record --state "$S" --input "$tmp/b1.md"
  report "$tmp/b2.md" 2
  run_case 'loop 3 round 2 is clean' 0 -- record --state "$S" --input "$tmp/b2.md" --changed-files "$tmp/changed-both"
  check 'scenario 7: the review passes before reaching the round cap' "$(q '.verdict == "converged" and .status == "converged" and .iteration == 2 and .counts.resolved == 1')"

  # --- scenarios 5 and 10: evidence and non-blocking findings ----------------------------------------------
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/c1.md" 1 "$F_NOEV"
  run_case 'a round with only a finding without evidence' 0 -- record --state "$S" --input "$tmp/c1.md"
  check 'scenarios 5 and 10: a finding without evidence cannot block, and only non-blocking findings converge' "$(q '.verdict == "converged" and .counts.needs_verification == 1 and .counts.remaining_blocking == 0')"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/c2.md" 1 "$F_SEC_NOEV"
  run_case 'a round with a security finding without evidence' 0 -- record --state "$S" --input "$tmp/c2.md"
  check 'a security (S1-S3) finding that needs verification still blocks' "$(q '.verdict == "continue" and .blocking[0].status == "needs-verification" and .blocking[0].security == true')"
  report "$tmp/c3.md" 2 "$(finding Moderate 'Token may be logged' 'src/Foo/Log.php:3' "$STRUCT" 'logic-trace — the token reaches Log::info')"
  run_case 'the finding gains evidence in round 2' 0 -- record --state "$S" --input "$tmp/c3.md" --changed-files "$tmp/changed-none"
  report "$tmp/c4.md" 3 "$F_SEC_NOEV"
  run_case 'round 3 reports it without evidence again' 0 -- record --state "$S" --input "$tmp/c4.md" --changed-files "$tmp/changed-none"
  check 'a confirmed finding is never downgraded to needs-verification by a later round' "$(jq -e '.findings[0].status == "confirmed" and (.findings[0].evidence | test("Log::info"))' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/c2.md" >/dev/null)
  run_case 'a delta that leaves a security point needing verification untouched is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-none"
  check 'a security point needing verification outside the delta widens the next round to the whole PR' "$(q '.scope == "full" and .reasons == ["open-finding-outside-delta"]')"
  report "$tmp/c5.md" 2
  run_case 'the broad round verifies the security point as absent without a code change' 0 -- record --state "$S" --input "$tmp/c5.md" --changed-files "$tmp/changed-none"
  check 'a full round that omits a security point needing verification resolves it' "$(q '.verdict == "converged" and .counts.resolved == 1 and (.carried | length) == 0')"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/c2.md" >/dev/null)
  run_case 'a delta round without scope omits the security point' 0 -- record --state "$S" --input "$tmp/c5.md" --changed-files "$tmp/changed-none"
  check 'a delta round carries a security point needing verification outside the delta' "$(q '.verdict == "continue" and (.carried | length) == 1 and .counts.resolved == 0 and .blocking[0].status == "needs-verification"')"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/c1.md" >/dev/null)
  run_case 'a delta that leaves a non-security point needing verification untouched is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-none"
  check 'a non-security point needing verification does not widen the round' "$(q '.scope == "delta" and .reasons == []')"
  run_case 'a delta round omits the non-security point' 0 -- record --state "$S" --input "$tmp/c5.md" --changed-files "$tmp/changed-none"
  check 'a non-security point that only needed verification leaves the loop on any round' "$(q '.verdict == "converged" and .counts.resolved == 1 and (.carried | length) == 0')"

  # --- a resolved claim in a PR comment: only the reviewer's re-read resolves a finding --------------------
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/e1.md" 1 "$F_SQL"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/e1.md" >/dev/null)
  local claimed_id
  claimed_id="$(jq -r '.findings[0].finding_id' "$repo/$S")"
  pr_comments "$(comment 21 OWNER "$claimed_id is resolved.")"
  run_case 'a delta after the resolved claim is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-none"
  check 'the round after the resolved claim reviews the whole PR' "$(q '.scope == "full"')"
  rm -f "$tmp/loader-calls"
  report "$tmp/e2.md" 2 "$F_SQL"
  run_case 'a full round that still carries the claimed finding is recorded' 0 -- record --state "$S" --input "$tmp/e2.md" --changed-files "$tmp/changed-none"
  check 'a resolved claim in a PR comment leaves the status unchanged' "$(jq -e '.findings[0].status == "confirmed" and ([.transitions[] | select(.to == "resolved")] | length) == 0' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  check 'the round never reads the PR comments' "$([[ ! -e "$tmp/loader-calls" ]] && echo 0 || echo 1)"
  cp "$repo/$S" "$tmp/before-claim"
  run_case 'a resolved claim in an owner PR comment never dismisses a security finding' 4 -- dismiss --state "$S" --finding "$claimed_id" --comment https://github.com/acme/shop/pull/7#issuecomment-21
  check 'the refused claim leaves the state untouched' "$(cmp -s "$tmp/before-claim" "$repo/$S" && echo 0 || echo 1)"

  # --- dismissal: only a trusted author, never a security finding ------------------------------------------
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/d1.md" 1 "$F_STRUCT" "$F_SQL" "$(finding Moderate 'Copied step' 'src/Foo/Qux.php:5' "$STRUCT" 'rule-violation — the step is copied')"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/d1.md" >/dev/null)
  local struct_id sql_id other_id
  struct_id="$(jq -r '.findings[] | select(.file == "src/Foo/Bar.php") | .finding_id' "$repo/$S")"
  sql_id="$(jq -r '.findings[] | select(.file == "app/Http/Orders.php") | .finding_id' "$repo/$S")"
  other_id="$(jq -r '.findings[] | select(.file == "src/Foo/Qux.php") | .finding_id' "$repo/$S")"
  cp "$repo/$S" "$tmp/before-dismiss"
  local C=https://github.com/acme/shop/pull/7#issuecomment-
  pr_comments "$(comment 11 CONTRIBUTOR "Not a defect, please drop $struct_id.")" "$(comment 12 OWNER "Not a defect, please drop $struct_id.")" \
    "$(comment 13 OWNER $'Dropped by the owner.\n\n<!-- cr-comment:actor=leonardo-bot -->')" "$(comment 14 OWNER 'LGTM, thanks for the quick turnaround.')" \
    "$(comment 15 NONE "Not a defect: $struct_id.")" "$(jq -n -c --arg id "$struct_id" '{author: "x", body: "Not a defect: \($id).", url: "https://github.com/acme/shop/pull/7#issuecomment-16"}')"
  run_case 'a caller-stated association is no longer accepted' 1 -- dismiss --state "$S" --finding "$struct_id" --association OWNER
  run_case 'a rejection by a CONTRIBUTOR is refused' 4 -- dismiss --state "$S" --finding "$struct_id" --comment "${C}11"
  check 'the refused rejection leaves the status open' "$(cmp -s "$tmp/before-dismiss" "$repo/$S" && echo 0 || echo 1)"
  run_case 'a rejection by an author with no association is refused' 4 -- dismiss --state "$S" --finding "$struct_id" --comment "${C}15"
  run_case 'a rejection whose association the loader does not return is refused' 4 -- dismiss --state "$S" --finding "$struct_id" --comment "${C}16"
  run_case 'agent output from an OWNER account is never a trusted rejection' 4 -- dismiss --state "$S" --finding "$struct_id" --comment "${C}13"
  check 'the refused agent output names the agent marker' "$(grep -q 'carries an agent marker' "$tmp/err" && echo 0 || echo 1)"
  run_case 'a trusted comment that does not name the finding is refused' 4 -- dismiss --state "$S" --finding "$struct_id" --comment "${C}14"
  check 'the refused rejection names the finding it does not mention' "$(grep -q "the comment does not name finding $struct_id" "$tmp/err" && echo 0 || echo 1)"
  run_case 'a comment the pull request does not carry is refused' 4 -- dismiss --state "$S" --finding "$struct_id" --comment "${C}99"
  run_case 'a comment on another pull request is refused' 4 -- dismiss --state "$S" --finding "$struct_id" --comment https://github.com/acme/shop/pull/8#issuecomment-12
  run_case 'a rejection URL that is not a pull-request comment is a usage error' 1 -- dismiss --state "$S" --finding "$struct_id" --comment https://evil.example/acme/shop/pull/7#issuecomment-12
  mv "$tmp/pr.json" "$tmp/pr.saved"
  run_case 'a rejection the loader cannot load is refused' 4 -- dismiss --state "$S" --finding "$struct_id" --comment "${C}12"
  mv "$tmp/pr.saved" "$tmp/pr.json"
  check 'every refused rejection leaves the state untouched' "$(cmp -s "$tmp/before-dismiss" "$repo/$S" && echo 0 || echo 1)"
  rm -f "$tmp/loader-calls"
  run_case 'a security finding is never dismissed, even by the owner' 4 -- dismiss --state "$S" --finding "$sql_id" --comment "${C}12"
  check 'a security finding is refused before the loader runs' "$([[ ! -e "$tmp/loader-calls" ]] && echo 0 || echo 1)"
  run_case 'the owner dismisses a non-security finding' 0 -- dismiss --state "$S" --finding "$struct_id" --comment "${C}12"
  check 'the dismissal reads the association from the loaded pull request' "$(grep -qx 'https://github.com/acme/shop/pull/7' "$tmp/loader-calls" && echo 0 || echo 1)"
  check 'the dismissal is recorded with its trusted actor' "$(jq -e --arg id "$struct_id" '(.findings[] | select(.finding_id == $id) | .status) == "dismissed"
      and any(.transitions[]; .finding_id == $id and .to == "dismissed" and .actor == "trusted:OWNER")' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  run_case 'one comment dismisses only the finding it names' 4 -- dismiss --state "$S" --finding "$other_id" --comment "${C}12"
  check 'the finding the comment does not name stays open' "$(jq -e --arg id "$other_id" '(.findings[] | select(.finding_id == $id) | .status) == "new"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  report "$tmp/d2.md" 2 "$F_STRUCT" "$F_SQL"
  run_case 'the reviewer reports the dismissed finding again' 0 -- record --state "$S" --input "$tmp/d2.md" --changed-files "$tmp/changed-both"
  check 'a dismissed finding the code still carries reopens instead of being suppressed' "$(jq -e --arg id "$struct_id" '(.findings[] | select(.finding_id == $id) | .status) == "confirmed"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  run_case 'a deferral before the cap round is refused' 4 -- defer --state "$S" --finding "$struct_id" --url https://github.com/acme/shop/issues/9

  # --- known points: a trusted linked issue already reports the finding; shown, never blocking ----------------
  # The task is acme/shop#10. It links #12 (a trusted author), #13 (an untrusted author), and #14 (closed, otherwise
  # like #12). #15 is a trusted, open issue that the task does not link. JIRA task ACME-1 links ACME-2 and
  # names ACME-3, whose reporter is not the operator account. ACME-9 is trusted and open, and ACME-1 does not link it.
  gh_issue 10 OWNER OPEN maintainer
  gh_issue 12 OWNER OPEN maintainer
  gh_issue 13 NONE OPEN stranger
  gh_issue 14 MEMBER CLOSED maintainer
  gh_issue 15 OWNER OPEN maintainer
  # The issues of other repositories, each written by its own owner: OWNER there, never here.
  gh_issue 'evil/x#1' OWNER OPEN attacker
  gh_issue 'evil/x#12' OWNER OPEN attacker
  gh_issue 'acme/shopx#1' OWNER OPEN attacker
  gh_issue 'evil/x#5' OWNER OPEN attacker
  # ACME-1 also links OTHER-5 and has the parent OTHER-7: trusted, open issues of another JIRA project.
  jq -n -c '{key: "ACME-1", url: "https://acme.atlassian.net/browse/ACME-1", resolution: null, descriptionTrusted: true,
    parent: {key: "OTHER-7"}, subtasks: [], issueLinks: [{linkedKey: "ACME-2"}, {linkedKey: "OTHER-5"}], mentionedKeys: ["ACME-3"]}' >"$tmp/jira-ACME-1.json"
  jq -n -c '{key: "OTHER-5", url: "https://acme.atlassian.net/browse/OTHER-5", resolution: null, descriptionTrusted: true}' >"$tmp/jira-OTHER-5.json"
  jq -n -c '{key: "OTHER-7", url: "https://acme.atlassian.net/browse/OTHER-7", resolution: null, descriptionTrusted: true}' >"$tmp/jira-OTHER-7.json"
  jq -n -c '{key: "ACME-2", url: "https://acme.atlassian.net/browse/ACME-2", resolution: null, descriptionTrusted: true}' >"$tmp/jira-ACME-2.json"
  jq -n -c '{key: "ACME-3", url: "https://acme.atlassian.net/browse/ACME-3", resolution: null, descriptionTrusted: false}' >"$tmp/jira-ACME-3.json"
  jq -n -c '{key: "ACME-9", url: "https://acme.atlassian.net/browse/ACME-9", resolution: null, descriptionTrusted: true}' >"$tmp/jira-ACME-9.json"
  local task=https://github.com/acme/shop/issues/10 known_issue=https://github.com/acme/shop/issues/12
  # The pull request closes the task, and its one commit names the task with a closing keyword.
  local closes_task='[{"oid":"a1","messageHeadline":"feat(orders): add the export (#10)","messageBody":"Closes #10"}]'
  pr_closes "$closes_task" "$task"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/n1.md" 1 "$F_STRUCT" "$F_SQL"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/n1.md" >/dev/null)
  # A security finding that a trusted, open linked issue already reports is a known point too (owner decision of
  # 2026-11-25): the trust of the link decides, never the kind of the finding. A finding no linked issue reports blocks.
  local SURF=".claude/run/review-state-surface.json" sec_id spl_id kiss
  raised() { printf '%s\n' "$2" | awk -v o="$1" '/^- \*\*Provenance:\*\*/ { print "- **Raised by:** " o } { print }'; }
  local F_SPL
  F_SPL="$(raised splinter "$(finding Critical 'Merge gate reads a stale head' 'src/Gate/Spl.php:3' "$STRUCT" 'rule-violation — the head is read once')")"
  (cd "$repo" && "$script" init --state "$SURF" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/n0.md" 1 "$F_SQL" "$F_SPL"
  (cd "$repo" && "$script" record --state "$SURF" --input "$tmp/n0.md" >/dev/null)
  sec_id="$(jq -r '.findings[] | select(.file == "app/Http/Orders.php") | .finding_id' "$repo/$SURF")"
  spl_id="$(jq -r '.findings[] | select(.file == "src/Gate/Spl.php") | .finding_id' "$repo/$SURF")"
  check 'both security findings of the round are security records' "$(jq -e '[.findings[].security] == [true, true]' "$repo/$SURF" >/dev/null && echo 0 || echo 1)"
  cp "$repo/$SURF" "$tmp/surface-before"
  # The trust checks of the link stay: an untrusted author, another repository, a closed issue, and an issue the task
  # does not link make no known point, so the security finding stays a finding that blocks.
  for kiss in https://github.com/acme/shop/issues/13 https://github.com/evil/x/issues/1 https://github.com/acme/shop/issues/14 \
    https://github.com/acme/shop/issues/15; do
    run_case "a security finding is no known point from the untrusted link ${kiss#https://github.com/}" 4 -- known --state "$SURF" --finding "$sec_id" --source "$task" --issue "$kiss" --covers 'The issue describes it.'
  done
  check 'every refused link leaves the state unchanged' "$(cmp -s "$tmp/surface-before" "$repo/$SURF" && echo 0 || echo 1)"
  run_case 'a security finding that a trusted open linked issue reports is marked known' 0 -- known --state "$SURF" --finding "$sec_id" --source "$task" --issue "$known_issue" --covers 'The issue describes it.'
  check 'the security known point leaves the counts, and the security finding no linked issue reports still blocks' \
    "$(q --arg s "$spl_id" '.counts.known == 1 and .counts.remaining_blocking == 1 and (.blocking | length) == 1 and .blocking[0].finding_id == $s and .blocking[0].security == true')"
  check 'the state keeps the security flag of the marked finding' "$(jq -e '.known_points[0].marked.security == true' "$repo/$SURF" >/dev/null && echo 0 || echo 1)"
  run_case 'the state with a security known point is valid' 0 -- evaluate --state "$SURF"
  # The same security finding reported again, unchanged, stays known; reported changed, it is a finding again.
  report "$tmp/n0b.md" 2 "$F_SQL" "$F_SPL"
  run_case 'the security known point is reported again unchanged' 0 -- record --state "$SURF" --input "$tmp/n0b.md" --changed-files "$tmp/changed-both"
  check 'an unchanged report keeps the security known point known' "$(jq -e --arg id "$sec_id" '(.findings[] | select(.finding_id == $id) | .status) == "known" and [.known_points[].finding_id] == [$id]' "$repo/$SURF" >/dev/null && echo 0 || echo 1)"
  report "$tmp/n0c.md" 3 "$(finding Critical 'Raw query' 'app/Http/Orders.php:42' "$SQL" 'exploit-path — sort=1;DROP now also reaches the export')" "$F_SPL"
  run_case 'the security known point is reported again wider than it was marked' 0 -- record --state "$SURF" --input "$tmp/n0c.md" --changed-files "$tmp/changed-both"
  check 'a report that differs from the marked finding opens it as a blocking security finding' \
    "$(jq -e --arg id "$sec_id" '(.findings[] | select(.finding_id == $id) | .status) == "confirmed" and ((.known_points // []) == [])' "$repo/$SURF" >/dev/null && echo 0 || echo 1)"
  run_case 'the state after the wider report is valid' 0 -- evaluate --state "$SURF"
  check 'the reopened security finding blocks again' "$(q --arg id "$sec_id" '.counts.known == 0 and any(.blocking[]; .finding_id == $id and .security == true)')"
  rm -f "$repo/$SURF"
  run_case 'the owner argument of the removed security exception is a usage error' 1 -- known --state "$S" --finding "$sql_id" --source "$task" --issue "$known_issue" --covers 'x' --owner maintainer
  run_case 'a finding the linked issue reports is marked known' 0 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'The issue describes the copied step.'
  check 'a known point is not blocking and is counted on its own' "$(q '.counts.known == 1 and .counts.remaining_blocking == 1 and (.blocking | length) == 1 and .blocking[0].file == "app/Http/Orders.php"')"
  check 'the state keeps the task, the issue, the sentence, and an orchestrator transition' "$(jq -e --arg id "$struct_id" '(.findings[] | select(.finding_id == $id) | .status) == "known"
      and (.known_points | map(del(.marked))) == [{finding_id: $id, source: "https://github.com/acme/shop/issues/10", url: "https://github.com/acme/shop/issues/12",
        covers: "The issue describes the copied step."}]
      and .known_points[0].marked == {severity: "medium", security: false, file: "src/Foo/Bar.php", line: 10, description: "Copied step",
        impact: "Copied step impact.", evidence: "rule-violation — the step is copied"}
      and any(.transitions[]; .finding_id == $id and .to == "known" and .actor == "orchestrator")' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  run_case 'the state with a known point is valid' 0 -- evaluate --state "$S"
  run_case 'a known point is not marked twice' 4 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'again'
  cp "$repo/$S" "$tmp/known-good"
  # A known point entry that an earlier version wrote with an owner key is malformed.
  jq -c '.known_points[0].owner = "maintainer"' "$tmp/known-good" >"$repo/$S"
  run_case 'a known point with the owner key of an earlier version makes the state invalid' 3 -- evaluate --state "$S"
  jq -c '.known_points[0].url = "http://insecure.example"' "$tmp/known-good" >"$repo/$S"
  run_case 'a known point with an insecure URL makes the state invalid' 3 -- evaluate --state "$S"
  cp "$tmp/known-good" "$repo/$S"
  run_case 'the known point is reopened for the refusal table' 0 -- known --state "$S" --finding "$struct_id" --reopen
  # Each refused link leaves the open finding as it is.
  local case_args label src iss
  for case_args in \
    "an untrusted author|$task|https://github.com/acme/shop/issues/13" \
    "a closed issue|$task|https://github.com/acme/shop/issues/14" \
    "an issue the task does not link|$task|https://github.com/acme/shop/issues/15" \
    "an issue that cannot be loaded|$task|https://github.com/acme/shop/issues/99" \
    "a task that cannot be loaded|https://github.com/acme/shop/issues/98|$known_issue" \
    "a JIRA link of a GitHub task|$task|https://acme.atlassian.net/browse/ACME-2" \
    "an issue of another repository|$task|https://github.com/evil/x/issues/1" \
    "an issue of another repository with the number of a linked issue|$task|https://github.com/evil/x/issues/12" \
    "an issue of a repository whose name starts with the task's|$task|https://github.com/acme/shopx/issues/1" \
    "a task of another repository than the pull request|https://github.com/evil/x/issues/5|https://github.com/evil/x/issues/1" \
    "a linked issue of another JIRA project|ACME-1|https://acme.atlassian.net/browse/OTHER-5" \
    "a parent of another JIRA project|ACME-1|https://acme.atlassian.net/browse/OTHER-7" \
    "an issue of another JIRA site|ACME-1|https://example.atlassian.net/browse/ACME-2" \
    "a JIRA issue whose reporter is not the operator|ACME-1|https://acme.atlassian.net/browse/ACME-3" \
    "a JIRA issue the task does not link|ACME-1|https://acme.atlassian.net/browse/ACME-9"; do
    IFS='|' read -r label src iss <<<"$case_args"
    run_case "a known point is refused for $label" 4 -- known --state "$S" --finding "$struct_id" --source "$src" --issue "$iss" --covers 'x'
    # A row that got through reopens, so the next row starts from the same open finding.
    (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --reopen >/dev/null 2>&1) || true
  done
  check 'the refused finding stays open' "$(jq -e --arg id "$struct_id" '(.findings[] | select(.finding_id == $id) | .status) == "confirmed"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  # An issue that the pull request closes makes no known point: the merge closes it. The pull request is read from the
  # state, through the loader, and a pull request that cannot be read, or that does not close the task, is a refusal.
  local pr_case pr_mut
  for pr_case in \
    "a linked issue in the closing issues of the pull request|pr_closes '$closes_task' '$task' '$known_issue'" \
    "a linked issue that a commit headline closes|pr_closes '[{\"oid\":\"c3\",\"messageHeadline\":\"fix(orders): the total, fixes #12\",\"messageBody\":\"\"}]' '$task'" \
    "a linked issue that a commit body resolves by its repository and number|pr_closes '[{\"oid\":\"c4\",\"messageHeadline\":\"fix: z\",\"messageBody\":\"Some text.\\n\\nResolved: acme/shop#12\"}]' '$task'" \
    "a linked issue that a commit body closes by its URL|pr_closes '[{\"oid\":\"c5\",\"messageHeadline\":\"fix: z\",\"messageBody\":\"CLOSES https://github.com/acme/shop/issues/12\"}]' '$task'" \
    "a linked issue that a commit closes second in a list|pr_closes '[{\"oid\":\"c6\",\"messageHeadline\":\"fix: z\",\"messageBody\":\"Closes #10, #12\"}]' '$task'" \
    "a commit without its message body|pr_closes '[{\"oid\":\"c7\",\"messageHeadline\":\"fix: z\"}]' '$task'" \
    "a pull request that does not close the task|pr_closes '$closes_task' 'https://github.com/acme/shop/issues/15'" \
    "a pull request that cannot be loaded|rm -f '$tmp/pr.json'"; do
    IFS='|' read -r label pr_mut <<<"$pr_case"
    eval "$pr_mut"
    cp "$repo/$S" "$tmp/pr-before"
    run_case "a known point is refused for $label" 4 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'x'
    check "the state is unchanged for $label" "$(cmp -s "$repo/$S" "$tmp/pr-before" && echo 0 || echo 1)"
    (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --reopen >/dev/null 2>&1) || true
  done
  # A closing keyword for another number, a word that only ends in a keyword, and a reference without a keyword close
  # nothing, so the link is a known point.
  pr_closes '[{"oid":"c8","messageHeadline":"fix: the total (#10)","messageBody":"Closes #123.\n\nPrefix #12.\n\nSee #12."}]' "$task"
  run_case 'a known point is marked when no commit closes the linked issue' 0 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'x'
  (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --reopen >/dev/null 2>&1) || true
  # known_row <accept|refuse> <label> <pr title> <commit headline> <commit body> <pr body> [branch] — the pull request
  # closes the task and carries this title, one commit with this headline and body, this body, and this branch name; `known` for the linked issue
  # (#12) must refuse it (exit 4, state unchanged) or mark it (exit 0).
  known_row() {
    local want="$1" label="$2" ptitle="$3" head="$4" cbody="$5" pbody="$6" rc=4
    [[ "$want" == accept ]] && rc=0
    PR_BRANCH="${7-feat/orders-export}" PR_TITLE="$ptitle" PR_BODY="$pbody" pr_closes "$(jq -n -c --arg h "$head" --arg b "$cbody" \
      '[{oid: "k1", messageHeadline: $h, messageBody: $b}]')" "$task"
    cp "$repo/$S" "$tmp/row-before"
    run_case "a known point is $([[ "$want" == accept ]] && echo marked || echo refused) for $label" "$rc" -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'x'
    [[ "$rc" -eq 0 ]] || check "the state is unchanged for $label" "$(cmp -s "$repo/$S" "$tmp/row-before" && echo 0 || echo 1)"
    (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --reopen >/dev/null 2>&1) || true
  }
  # The pull request title reaches the merge commit and the squash commit, and the body the squash commit.
  known_row refuse 'a pull request title that closes the linked issue' 'fix(orders): the total, fixes #12' 'fix: z' '' ''
  known_row refuse 'a pull request title that closes it by gh-N' 'Fix the export, closes GH-12' 'fix: z' '' ''
  known_row refuse 'a pull request body that closes the linked issue' 'feat: x' 'fix: z' '' 'Adds x. Resolves #12.'
  known_row refuse 'a pull request body that closes it by its URL' 'feat: x' 'fix: z' '' 'Fixes: https://github.com/acme/shop/issues/12'
  # The reference forms of a closing keyword.
  known_row refuse 'a commit body that closes it by gh-N' 'feat: x' 'fix: z' 'Closes gh-12' ''
  known_row refuse 'a commit headline that closes it by GH-N' 'feat: x' 'fix: y, fixes GH-12' '' ''
  known_row refuse 'a commit that closes it by gh-N in a list' 'feat: x' 'fix: z' 'Closes #10, gh-12' ''
  known_row refuse 'a commit that closes it by an http URL' 'feat: x' 'fix: z' 'Fixes: http://github.com/acme/shop/issues/12' ''
  known_row refuse 'a commit that closes it by a www URL' 'feat: x' 'fix: z' 'Fixes https://www.github.com/acme/shop/issues/12' ''
  known_row refuse 'a commit that closes it by its number with leading zeros' 'feat: x' 'fix: z' 'Closes #012' ''
  known_row refuse 'a commit that closes it by an upper-case repository' 'feat: x' 'fix: z' 'Closes ACME/Shop#12' ''
  # Several issues in one sentence, and a second keyword.
  known_row refuse 'a commit that closes it third in a list with and' 'feat: x' 'fix: z' 'Closes #10, #11 and #12' ''
  known_row refuse 'a commit that closes it after a comma and and' 'feat: x' 'fix: z' 'Fixes #10, and #12' ''
  known_row refuse 'a commit that closes it after an ampersand' 'feat: x' 'fix: z' 'Resolves #10 & #12' ''
  known_row refuse 'a commit that closes it with a second keyword' 'feat: x' 'fix: z' 'Closes #10, closes #12' ''
  known_row refuse 'a commit that closes it on the next line' 'feat: x' 'fix: z' $'Closes\n#12' ''
  local kw kwu
  for kw in close closes closed fix fixes fixed resolve resolves resolved; do
    kwu="$(printf '%s' "$kw" | LC_ALL=C tr '[:lower:]' '[:upper:]')"
    known_row refuse "the keyword $kw" 'feat: x' 'fix: z' "$kw #12" ''
    known_row refuse "the keyword $kwu with a colon" 'feat: x' 'fix: z' "$kwu: #12" ''
    known_row refuse "the keyword $kw in the title of the pull request" "feat: x, $kw #12" 'fix: z' '' ''
  done
  # The loader cuts a commit headline of 74 or more characters at 69 characters plus an ellipsis and starts the body
  # with an ellipsis and the rest. The closing keyword can stand across the cut, inside a word or between the words.
  local ell=$'\xe2\x80\xa6'
  known_row refuse 'a commit whose closing keyword stands across the headline cut inside the word' 'feat: x' "feat(orders): export every order of the shop into one archive file fi$ell" "${ell}xes #12 for the big shops" ''
  known_row refuse 'a commit whose closing keyword stands across the headline cut before its reference' 'feat: x' "feat(orders): export every order of the shop into one archive file fixes $ell" "$ell#12 for the big shops" ''
  known_row refuse 'a commit whose closing keyword ends the cut headline and whose reference starts the body' 'feat: x' "feat(orders): export every order of the shop into one archive file fixes$ell" "$ell #12" ''
  known_row accept 'a cut headline that closes another number' 'feat: x' "feat(orders): export every order of the shop into one archive file fi$ell" "${ell}xes #13 for the big shops" ''
  known_row accept 'a headline that ends in an ellipsis with no keyword' 'feat: x' "feat(orders): export every order of the shop into one archive file$ell" "${ell}for #12 and the big shops" ''
  # A reference of the linked issue on the same line as a closing keyword, or on the next line, closes it whatever
  # stands between the two: markup, brackets, a semicolon, the word issue, or text.
  known_row refuse 'a Markdown link after the keyword' 'feat: x' 'fix: z' 'Fixes [#12](https://github.com/acme/shop/issues/12)' ''
  known_row refuse 'a bold keyword' 'feat: x' 'fix: z' '**Fixes** #12' ''
  known_row refuse 'a bold reference' 'feat: x' 'fix: z' 'Fixes **#12**' ''
  known_row refuse 'an italic keyword' 'feat: x' 'fix: z' '_Fixes_ #12' ''
  known_row refuse 'a reference in brackets' 'feat: x' 'fix: z' 'Fixes (#12)' ''
  known_row refuse 'a reference after a semicolon' 'feat: x' 'fix: z' 'Closes #10; #12' ''
  known_row refuse 'the word issue between keyword and reference' 'feat: x' 'fix: z' 'Closes issue #12' ''
  known_row refuse 'a URL in angle brackets' 'feat: x' 'fix: z' 'Fixes <https://github.com/acme/shop/issues/12>' ''
  known_row refuse 'a reference in brackets in the title' 'Closes (#12)' 'fix: z' '' ''
  known_row refuse 'a bold keyword in the pull request body' 'feat: x' 'fix: z' '' '**Closes** #12'
  known_row refuse 'a Markdown link in the pull request body' 'feat: x' 'fix: z' '' 'Fixes [#12](https://github.com/acme/shop/issues/12)'
  known_row refuse 'a keyword and a reference with text between them' 'feat: x' 'fix: z' 'Resolves the shipping total in the export, see #12' ''
  known_row refuse 'a reference on the line after the keyword' 'feat: x' 'fix: z' $'Fixes:\n\n#12' ''
  known_row refuse 'a reference on the line after a keyword in a list' 'feat: x' 'fix: z' $'- Closes\n- #12' ''
  # Every closing keyword of a line reaches its reference, the first one and each later one, across a blank line too.
  known_row refuse 'a second keyword on the line, a blank line, and the reference in a commit body' 'feat: x' 'fix: z' $'Fixes the export; closes\n\n#12' ''
  known_row refuse 'a second keyword on the line, a blank line, and the reference in the pull request body' 'feat: x' 'fix: z' '' $'Fixes the export, closes\n\n#12'
  known_row refuse 'a second keyword and the reference apart by two U+2028 separators' 'feat: x' 'fix: z' $'Fixes the export; closes\xe2\x80\xa8\xe2\x80\xa8#12' ''
  known_row refuse 'a second keyword at the end of a commit headline and the reference in its body' 'feat: x' 'fix(export): the total; closes' '#12' ''
  known_row refuse 'a second keyword that holds the reference against its own line start' 'feat: x' 'fix: z' 'Closes the total, then fixes#12' ''
  known_row refuse 'a pull request URL of the number of the linked issue' 'feat: x' 'fix: z' 'Fixes https://github.com/acme/shop/pull/12' ''
  known_row accept 'a second keyword and a blank line before text with no reference' 'feat: x' 'fix: z' $'Fixes the export; closes\n\nSee the thread.' ''
  known_row accept 'a pull request URL of another number' 'feat: x' 'fix: z' 'Fixes https://github.com/acme/shop/pull/13' ''
  # The default merge commit message names the branch.
  known_row refuse 'a branch named after a keyword and the number' 'feat: x' 'fix: z' '' '' 'acme/fixes#12'
  known_row refuse 'a branch with a keyword and the number with leading zeros' 'feat: x' 'fix: z' '' '' 'fix/gh-0012-export'
  known_row accept 'a branch with a keyword and another number' 'feat: x' 'fix: z' '' '' 'acme/fixes#13'
  known_row accept 'a branch with the number and no keyword' 'feat: x' 'fix: z' '' '' 'feat/gh-12-export'
  known_row accept 'a branch whose word only contains a keyword' 'feat: x' 'fix: z' '' '' 'bugfix/prefix-12-fixture'
  known_row accept 'a keyword and the number on lines apart' 'feat: x' 'fix: z' $'Fixes the export total.\n\nSee the discussion in #12.' ''
  known_row accept 'a cross-repository reference after a keyword' 'feat: x' 'fix: z' 'Fixes other/repo#12' ''
  known_row accept 'a cross-repository URL after a keyword in the title' 'Fixes https://github.com/other/repo/issues/12' 'fix: z' '' ''
  # What closes nothing: another number, another repository, a word that only ends in a keyword, a keyword with no
  # reference, and a reference with no keyword.
  known_row accept 'a closing keyword for another number' 'feat: x' 'fix: z' 'Closes #123. Closes gh-1.' ''
  known_row accept 'a closing keyword for an issue of another repository' 'feat: x' 'fix: z' 'Fixes evil/x#12 and https://github.com/evil/x/issues/12' ''
  known_row accept 'a word that only ends in a keyword' 'feat: x, bugfix #12' 'fix: z' 'Prefix #12, unresolved gh-12' ''
  known_row accept 'a reference without a keyword in the title and the body' 'feat: x (#12)' 'fix: z' 'See gh-12.' 'Related to #12.'
  # A long text with no match ends: the pattern has no catastrophic backtracking.
  known_row accept 'a long list of references that never reaches the linked issue' 'feat: x' 'fix: z' "Closes $(printf '#1, %.0s' {1..4000})#13" ''
  known_row accept 'a long list of lines that each hold a keyword and no reference to the linked issue' 'feat: x' 'fix: z' "$(printf 'Fixes\n\n%.0s' {1..4000}; printf '#13')" ''
  # The loader lists at most 100 commits and does not page: a full page may lack the commits after it.
  pr_page 100 "$task"
  cp "$repo/$S" "$tmp/row-before"
  run_case 'a known point is refused for a commit list of 100 entries' 4 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'x'
  check 'the state is unchanged for a commit list of 100 entries' "$(cmp -s "$repo/$S" "$tmp/row-before" && echo 0 || echo 1)"
  pr_page 99 "$task"
  run_case 'a known point is marked for a commit list of 99 entries' 0 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'x'
  (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --reopen >/dev/null 2>&1) || true
  # A payload without a branch name is not the pull request of the state.
  pr_closes "$closes_task" "$task"
  jq -c 'del(.headRefName)' "$tmp/pr.json" >"$tmp/pr-cut" && mv "$tmp/pr-cut" "$tmp/pr.json"
  run_case 'a known point is refused for a pull request without its branch name' 4 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'x'
  # A payload without a title or a body is not the pull request of the state.
  for kw in title body; do
    pr_closes "$closes_task" "$task"
    jq -c --arg k "$kw" 'del(.[$k])' "$tmp/pr.json" >"$tmp/pr-cut" && mv "$tmp/pr-cut" "$tmp/pr.json"
    run_case "a known point is refused for a pull request without its $kw" 4 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'x'
  done
  pr_closes "$closes_task" "$task"
  run_case 'an unknown finding is refused' 4 -- known --state "$S" --finding r-0000000000000000 --source "$task" --issue "$known_issue" --covers 'x'
  run_case 'an issue that is not an https URL is a usage error' 1 -- known --state "$S" --finding "$struct_id" --source "$task" --issue 'http://x' --covers 'x'
  run_case 'an empty sentence is a usage error' 1 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers ''
  run_case 'a task that is neither a GitHub issue nor a JIRA key is a usage error' 1 -- known --state "$S" --finding "$struct_id" --source 'https://example.com/x' --issue "$known_issue" --covers 'x'
  run_case 'a JIRA known point is marked from a linked issue of the operator' 0 -- known --state "$S" --finding "$struct_id" --source ACME-1 --issue https://acme.atlassian.net/browse/ACME-2 --covers 'ACME-2 describes the copied step.'
  run_case 'reopen with a link argument is a usage error' 1 -- known --state "$S" --finding "$struct_id" --reopen --covers 'x'
  run_case 'the JIRA known point is reopened' 0 -- known --state "$S" --finding "$struct_id" --reopen
  run_case 'the finding is marked known again' 0 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'The issue describes the copied step.'
  check 'the open security finding still blocks the loop' "$(q '.verdict == "continue" and .counts.known == 1 and .counts.remaining_blocking == 1 and .blocking[0].security == true')"
  report "$tmp/n2.md" 2 "$F_STRUCT" "$F_SQL"
  run_case 'the reviewer reports the known point and the security finding again' 0 -- record --state "$S" --input "$tmp/n2.md" --changed-files "$tmp/changed-both"
  check 'a known point the reviewer reports again stays known and keeps its entry' "$(jq -e --arg a "$struct_id" '([.findings[] | select(.finding_id == $a) | .status] == ["known"])
      and [.known_points[].finding_id] == [$a]' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  check 'the re-reported known point is no blocker, and the security finding still blocks' "$(q '.verdict == "continue" and .counts.known == 1 and .counts.remaining_blocking == 1 and .blocking[0].security == true')"
  run_case 'a known point that the comparison no longer matches is reopened' 0 -- known --state "$S" --finding "$struct_id" --reopen
  check 'the reopened point blocks again and leaves the known points' "$(jq -e --arg id "$struct_id" '(.findings[] | select(.finding_id == $id) | .status) == "confirmed"
      and ([.known_points[].finding_id] | index([$id]) == null)
      and any(.transitions[]; .finding_id == $id and .from == "known" and .actor == "orchestrator")' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  run_case 'a finding that is not a known point is not reopened' 4 -- known --state "$S" --finding "$struct_id" --reopen

  # The cap round: a Critical known point that every round reports again never escalates the loop.
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  local round crit_id
  report "$tmp/k1.md" 1 "$F_CRIT"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k1.md" >/dev/null)
  crit_id="$(jq -r '.findings[0].finding_id' "$repo/$S")"
  run_case 'a Critical finding is marked known in round 1' 0 -- known --state "$S" --finding "$crit_id" --source "$task" --issue "$known_issue" --covers 'The issue describes the wrong total.'
  for round in 2 3; do
    report "$tmp/k$round.md" "$round" "$F_CRIT"
    run_case "round $round reports the known Critical again" 0 -- record --state "$S" --input "$tmp/k$round.md" --changed-files "$tmp/changed-both"
  done
  check 'at the cap round the known Critical does not escalate and the loop converges' "$(q '.iteration == 3 and .verdict == "converged" and .status == "converged" and .escalation_reason == null and .counts.known == 1')"

  # The merge-time check: `known --verify` runs the checks of `known` on every known point again and writes nothing.
  local pr7=https://github.com/acme/shop/pull/7
  pr_closes "$closes_task" "$task"
  cp "$repo/$S" "$tmp/verify-before"
  run_case 'verify passes when every known point still holds' 0 -- known --state "$S" --verify --pr "$pr7"
  check 'verify reports the one point it checked, its link, and refuses none' "$(q '.checked == 1 and .urls == ["https://github.com/acme/shop/issues/12"] and .refused == []')"
  check 'verify leaves the state unchanged' "$(cmp -s "$repo/$S" "$tmp/verify-before" && echo 0 || echo 1)"
  PR_TITLE='fix(orders): the total, fixes #12' pr_closes "$closes_task" "$task"
  run_case 'verify refuses a pull request whose title now closes the linked issue' 4 -- known --state "$S" --verify --pr "$pr7"
  check 'verify names the point, its link, and the reason' "$(q --arg id "$crit_id" '.checked == 1 and .refused[0].finding_id == $id and .refused[0].url == "https://github.com/acme/shop/issues/12" and (.refused[0].reason | test("title"))')"
  check 'a refused verify leaves the state unchanged' "$(cmp -s "$repo/$S" "$tmp/verify-before" && echo 0 || echo 1)"
  PR_BODY=$'Closes the total,\n#12' pr_closes "$closes_task" "$task"
  run_case 'verify refuses a pull request whose body now closes the linked issue on the next line' 4 -- known --state "$S" --verify --pr "$pr7"
  pr_closes "$closes_task" "$task"
  mv "$tmp/gh-issue-acme-shop-12.json" "$tmp/gh-issue-12.hold"
  run_case 'verify refuses a linked issue that cannot be loaded' 4 -- known --state "$S" --verify --pr "$pr7"
  check 'a loader that fails is a refusal with its reason' "$(q '.refused[0].reason | test("cannot be loaded")')"
  mv "$tmp/gh-issue-12.hold" "$tmp/gh-issue-acme-shop-12.json"
  mv "$tmp/pr.json" "$tmp/pr.hold"
  run_case 'verify refuses a pull request that cannot be loaded' 4 -- known --state "$S" --verify --pr "$pr7"
  mv "$tmp/pr.hold" "$tmp/pr.json"
  run_case 'verify passes again once the checks hold' 0 -- known --state "$S" --verify --pr "$pr7"
  run_case 'verify with --finding is a usage error' 1 -- known --state "$S" --verify --pr "$pr7" --finding "$crit_id"
  run_case 'verify with --reopen is a usage error' 1 -- known --state "$S" --verify --pr "$pr7" --reopen
  run_case 'verify of a missing state exits 3' 3 -- known --state "$S.missing" --verify --pr "$pr7"
  # The state must be the one the loop wrote for the pull request being merged, never a file the branch supplies.
  run_case 'verify without --pr is a usage error' 1 -- known --state "$S" --verify
  run_case 'verify with a --pr that is not a pull request URL is a usage error' 1 -- known --state "$S" --verify --pr 'https://github.com/acme/shop/issues/7'
  run_case 'verify refuses a state of another pull request than the one being merged' 3 -- known --state "$S" --verify --pr https://github.com/acme/shop/pull/8
  run_case 'verify refuses a state of another repository than the one being merged' 3 -- known --state "$S" --verify --pr https://github.com/acme/other/pull/7
  jq -c '.pr = 8 | .findings |= map(.pr = 8)' "$repo/$S" >"$repo/.claude/run/verify-other.json"
  run_case 'verify refuses the state of another pull request that closes only the task' 3 -- known --state .claude/run/verify-other.json --verify --pr "$pr7"
  ln -s "$repo/$S" "$repo/.claude/run/verify-link.json"
  run_case 'verify refuses a state that is a symbolic link' 3 -- known --state .claude/run/verify-link.json --verify --pr "$pr7"
  cp "$repo/$S" "$repo/.claude/run/verify-tracked.json"
  git -C "$repo" add -f .claude/run/verify-tracked.json
  run_case 'verify refuses a state that git tracks' 3 -- known --state .claude/run/verify-tracked.json --verify --pr "$pr7"
  git -C "$repo" rm -q --cached -f .claude/run/verify-tracked.json
  cp "$repo/$S" "$tmp/outside-state.json"
  run_case 'verify refuses a state outside the run directory' 3 -- known --state "$tmp/outside-state.json" --verify --pr "$pr7"
  jq -c 'del(.known_points)' "$repo/$S" >"$repo/.claude/run/verify-bare.json"
  run_case 'verify refuses a known finding with no known point' 3 -- known --state .claude/run/verify-bare.json --verify --pr "$pr7"
  run_case 'evaluate refuses a known finding with no known point' 3 -- evaluate --state .claude/run/verify-bare.json
  rm -f "$repo/.claude/run/verify-other.json" "$repo/.claude/run/verify-link.json" "$repo/.claude/run/verify-tracked.json" "$repo/.claude/run/verify-bare.json"
  run_case 'verify of the state of the pull request passes after the refusals' 0 -- known --state "$S" --verify --pr "$pr7"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  run_case 'verify of a state with no known point passes' 0 -- known --state "$S" --verify --pr "$pr7" --pr "$pr7"
  check 'verify of a state with no known point checks none' "$(q '.checked == 0 and .urls == [] and .refused == []')"

  # A finding that a round first reports at the cap round is compared too, before the cap verdict takes effect.
  local F_CRIT2 F_MOD2 crit2_id mod2_id
  F_CRIT2="$(finding Critical 'Wrong tax' 'src/Foo/Tax.php:5' "$STRUCT" 'logic-trace — the tax skips the rate')"
  F_MOD2="$(finding Moderate 'Long method' 'src/Foo/Long.php:3' "$STRUCT" 'rule-violation — the method is too long')"
  # cap_state <round-3 finding>... — rounds 1 and 2 report the Moderate F_STRUCT, round 3 reports only these.
  cap_state() {
    rm -f "$repo/$S"
    (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
    report "$tmp/c1.md" 1 "$F_STRUCT"
    (cd "$repo" && "$script" record --state "$S" --input "$tmp/c1.md" >/dev/null)
    report "$tmp/c2.md" 2 "$F_STRUCT"
    (cd "$repo" && "$script" record --state "$S" --input "$tmp/c2.md" --changed-files "$tmp/changed-both" >/dev/null)
    report "$tmp/c3.md" 3 "$@"
    run_case 'the cap round records its first report of a finding' 0 -- record --state "$S" --input "$tmp/c3.md" --changed-files "$tmp/changed-both"
  }
  cap_state "$F_CRIT"
  check 'a non-security Critical first reported at the cap round escalates the round' "$(q '.verdict == "escalated" and .escalation_reason == "cap-reached-hard-blockers"')"
  crit_id="$(jq -r '.blocking[0].finding_id' "$tmp/out")"
  run_case 'it is still marked known after the cap verdict, from a verified linked issue' 0 -- known --state "$S" --finding "$crit_id" --source "$task" --issue "$known_issue" --covers 'The issue describes the wrong total.'
  check 'with the Critical known the cap round converges' "$(q '.verdict == "converged" and .status == "converged" and .escalation_reason == null and .counts.known == 1')"
  check 'the round entry shows the verdict after the comparison' "$(jq -e '.rounds[-1].verdict == "converged" and .rounds[-1].remaining_blocking == 0' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  cap_state "$F_CRIT" "$F_CRIT2"
  crit2_id="$(jq -r --arg id "$crit_id" '[.blocking[] | select(.finding_id != $id)][0].finding_id' "$tmp/out")"
  run_case 'one of two cap-round Criticals is marked known' 0 -- known --state "$S" --finding "$crit_id" --source "$task" --issue "$known_issue" --covers 'The issue describes the wrong total.'
  check 'the other Critical keeps the loop escalated' "$(q --arg id "$crit2_id" '.verdict == "escalated" and .status == "escalated" and .escalation_reason == "cap-reached-hard-blockers" and .counts.known == 1 and [.blocking[].finding_id] == [$id]')"
  run_case 'a known point of an escalated loop is not reopened' 5 -- known --state "$S" --finding "$crit_id" --reopen
  cap_state "$F_STRUCT" "$F_MOD2"
  check 'two Moderates at the cap round need the triage' "$(q '.verdict == "deferral-required"')"
  mod2_id="$(jq -r --arg id "$struct_id" '[.blocking[] | select(.finding_id != $id)][0].finding_id' "$tmp/out")"
  run_case 'a Moderate at the cap round is marked known before the triage' 0 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'The issue describes the copied step.'
  check 'the triage then sees only the Moderate the issue does not report' "$(q --arg id "$mod2_id" '.verdict == "deferral-required" and [.deferrable[].finding_id] == [$id]')"
  run_case 'the final evaluation escalates the undeferred Moderate' 0 -- evaluate --state "$S" --final
  run_case 'a loop the final evaluation escalated takes no known point' 5 -- known --state "$S" --finding "$mod2_id" --source "$task" --issue "$known_issue" --covers 'x'

  # Every round, and every evaluation, checks each known point again with the checks of `known`. A point whose
  # check fails now, a failed load included, is open again, and the round counts it.
  # known_pair — round 1 reports F_STRUCT and F_MOD2; both are known points of the default fixture #12.
  report "$tmp/p1.md" 1 "$F_STRUCT" "$F_MOD2"
  report "$tmp/p2.md" 2 "$F_STRUCT" "$F_MOD2"
  known_pair() {
    gh_issue 10 OWNER OPEN maintainer
    gh_issue 12 OWNER OPEN maintainer
    pr_closes "$closes_task" "$task"
    rm -f "$repo/$S"
    (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
    (cd "$repo" && "$script" record --state "$S" --input "$tmp/p1.md" >/dev/null)
    (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'The issue describes the copied step.' >/dev/null)
    (cd "$repo" && "$script" known --state "$S" --finding "$mod2_id" --source "$task" --issue "$known_issue" --covers 'The issue describes the long method.' >/dev/null)
  }
  # shellcheck disable=SC2329  # invoked through the eval of the table below
  unlink_task() { jq -c '.linkedIssues = []' "$tmp/gh-issue-acme-shop-10.json" >"$tmp/unlinked" && mv "$tmp/unlinked" "$tmp/gh-issue-acme-shop-10.json"; }
  # pair_status — the statuses of F_STRUCT and F_MOD2 in the state, in that order.
  pair_status() { jq -r --arg a "$struct_id" --arg b "$mod2_id" '[(.findings[] | select(.finding_id == $a) | .status), (.findings[] | select(.finding_id == $b) | .status)] | join(" ")' "$repo/$S"; }
  local mut want
  for case_args in \
    "the linked issue is closed|gh_issue 12 OWNER CLOSED maintainer|confirmed confirmed" \
    "its author lost write access|gh_issue 12 NONE OPEN maintainer|confirmed confirmed" \
    "the task no longer links the issue|unlink_task|confirmed confirmed" \
    "the linked issue cannot be loaded|rm -f '$tmp/gh-issue-acme-shop-12.json'|confirmed confirmed" \
    "the task cannot be loaded|rm -f '$tmp/gh-issue-acme-shop-10.json'|confirmed confirmed" \
    "the pull request now closes the linked issue|pr_closes '$closes_task' '$task' '$known_issue'|confirmed confirmed" \
    "a new commit closes the linked issue|pr_closes '[{\"oid\":\"b2\",\"messageHeadline\":\"fix: y\",\"messageBody\":\"Fixes #12\"}]' '$task'|confirmed confirmed" \
    "a new commit closes the linked issue by gh-N|pr_closes '[{\"oid\":\"b3\",\"messageHeadline\":\"fix: y\",\"messageBody\":\"Closes gh-12\"}]' '$task'|confirmed confirmed" \
    "a new commit closes the linked issue across the cut of its headline|pr_closes '[{\"oid\":\"b4\",\"messageHeadline\":\"feat(orders): export every order of the shop into one archive file fi\u2026\",\"messageBody\":\"\u2026xes #12 for the big shops\"}]' '$task'|confirmed confirmed" \
    "a new commit closes the linked issue with markup around the keyword|pr_closes '[{\"oid\":\"b5\",\"messageHeadline\":\"fix: y\",\"messageBody\":\"**Fixes** #12\"}]' '$task'|confirmed confirmed" \
    "the branch of the pull request is renamed after a keyword and the number|PR_BRANCH='acme/fixes#12' pr_closes '$closes_task' '$task'|confirmed confirmed" \
    "the title of the pull request now closes the linked issue|PR_TITLE='feat: x, fixes #12' pr_closes '$closes_task' '$task'|confirmed confirmed" \
    "the commit list reaches the page size of the loader|pr_page 100 '$task'|confirmed confirmed" \
    "the pull request cannot be loaded|rm -f '$tmp/pr.json'|confirmed confirmed" \
    "nothing changed|true|known known"; do
    IFS='|' read -r label mut want <<<"$case_args"
    known_pair
    eval "$mut"
    run_case "round 2 checks the known points again when $label" 0 -- record --state "$S" --input "$tmp/p2.md" --changed-files "$tmp/changed-both"
    check "when $label, the statuses after round 2 are: $want" "$([[ "$(pair_status)" == "$want" ]] && echo 0 || echo 1)"
  done
  known_pair
  gh_issue 12 OWNER CLOSED maintainer
  run_case 'an evaluation checks the known points again' 0 -- evaluate --state "$S"
  check 'the evaluation reopens both points, writes the state, and the verdict counts them' "$(q '.verdict == "continue" and .counts.known == 0 and .counts.remaining_blocking == 2')"
  check 'the reopened points are open in the state, moved by the orchestrator' "$([[ "$(pair_status)" == 'confirmed confirmed' ]] && jq -e '[.transitions[] | select(.from == "known")] | length == 2 and all(.actor == "orchestrator")' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  known_pair
  run_case 'an evaluation with verified known points changes nothing' 0 -- evaluate --state "$S"
  check 'the verified points stay known' "$([[ "$(pair_status)" == 'known known' ]] && echo 0 || echo 1)"
  # A JIRA known point is checked again the same way.
  (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --reopen >/dev/null)
  (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --source ACME-1 --issue https://acme.atlassian.net/browse/ACME-2 --covers 'ACME-2 describes the copied step.' >/dev/null)
  jq -c '.resolution = "Done"' "$tmp/jira-ACME-2.json" >"$tmp/jira-done" && mv "$tmp/jira-done" "$tmp/jira-ACME-2.json"
  run_case 'an evaluation checks a JIRA known point again' 0 -- evaluate --state "$S"
  check 'a JIRA known point whose issue is resolved is open again' "$([[ "$(pair_status)" == 'confirmed known' ]] && echo 0 || echo 1)"
  gh_issue 10 OWNER OPEN maintainer
  gh_issue 12 OWNER OPEN maintainer

  # A known point stays known only while the report of its identity is the finding that was marked. Any other
  # report of the identity, a second defect merged into it and a security flag included, opens it again for a new
  # comparison.
  local F_STRUCT_OTHER F_STRUCT_CRIT
  F_STRUCT_OTHER="$(finding Moderate 'Copied step twice' 'src/Foo/Bar.php:40' "$STRUCT" 'rule-violation — the step is copied again')"
  F_STRUCT_CRIT="$(finding Critical 'Step before the check' 'src/Foo/Bar.php:30' "$STRUCT" 'logic-trace — the step runs before the check')"
  # marked_alone — a new loop whose round 1 reports F_STRUCT alone, marked known.
  marked_alone() {
    rm -f "$repo/$S"
    (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
    report "$tmp/m1.md" 1 "$F_STRUCT"
    (cd "$repo" && "$script" record --state "$S" --input "$tmp/m1.md" >/dev/null)
    (cd "$repo" && "$script" known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'The issue describes it.' >/dev/null)
  }
  marked_alone
  report "$tmp/m2.md" 2 "$F_STRUCT" "$F_STRUCT_OTHER"
  run_case 'a round reports the known point and a second defect of its identity' 0 -- record --state "$S" --input "$tmp/m2.md" --changed-files "$tmp/changed-both"
  check 'a second defect of the identity on another line opens the known point again' "$(q '.verdict == "continue" and .counts.known == 0 and .counts.remaining_blocking == 1')"
  check 'the opened point leaves the known points' "$(jq -e '(.known_points // []) == []' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  marked_alone
  report "$tmp/m3.md" 2 "$F_STRUCT_OTHER"
  run_case 'a round reports only another defect of the identity of a known point' 0 -- record --state "$S" --input "$tmp/m3.md" --changed-files "$tmp/changed-both"
  check 'another defect of the identity opens the known point again' "$(q '.verdict == "continue" and .counts.known == 0 and .blocking[0].line == 40')"
  marked_alone
  report "$tmp/m4.md" 2 "$F_STRUCT_CRIT"
  run_case 'a round reports a Critical of the identity of a known Moderate' 0 -- record --state "$S" --input "$tmp/m4.md" --changed-files "$tmp/changed-both"
  check 'a higher severity under a known Moderate blocks' "$(q '.verdict == "continue" and .counts.known == 0 and .blocking[0].severity == "critical"')"
  marked_alone
  report "$tmp/m5.md" 1 "$F_STRUCT_OTHER"
  run_case 'a late report carries another defect of the identity of a known point' 0 -- late --state "$S" --input "$tmp/m5.md"
  check 'a late report of another defect of the identity opens the known point again' "$(q '.verdict == "continue" and .counts.known == 0 and .counts.remaining_blocking == 1')"
  run_case 'the evaluation after the late report keeps the point open' 0 -- evaluate --state "$S"
  check 'the late defect still blocks after the evaluation' "$(q '.verdict == "continue" and .counts.remaining_blocking == 1')"
  # The security view reports the known point itself, unchanged: the finding is now a security finding, so it opens,
  # and only a new comparison of the orchestrator marks it known again, with the security flag in its marked finding.
  marked_alone
  report "$tmp/m9.md" 1 "$F_STRUCT"
  run_case 'the security view reports a known point again' 0 -- late --state "$S" --input "$tmp/m9.md"
  check 'a known point that the security view reports opens as a blocking security finding' "$(q '.verdict == "continue" and .counts.known == 0 and .blocking[0].security == true')"
  run_case 'a known point that became a security finding is marked known again only by a new comparison' 0 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'The issue describes it.'
  check 'the point marked again keeps the security flag in its marked finding' "$(jq -e '.known_points[0].marked.security == true' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  marked_alone
  report "$tmp/m6.md" 2 "$F_STRUCT"
  run_case 'a round reports the known point unchanged' 0 -- record --state "$S" --input "$tmp/m6.md" --changed-files "$tmp/changed-both"
  check 'an unchanged known point stays known' "$(q '.verdict == "converged" and .counts.known == 1 and .counts.remaining_blocking == 0')"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/m7.md" 1 "$F_STRUCT" "$F_STRUCT_OTHER"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/m7.md" >/dev/null)
  run_case 'a finding that merges two defects of one identity is never marked known' 4 -- known --state "$S" --finding "$struct_id" --source "$task" --issue "$known_issue" --covers 'x'
  marked_alone
  jq -c '.known_points[0].marked.severity = "none"' "$repo/$S" >"$tmp/marked-bad"
  cp "$repo/$S" "$tmp/marked-good"
  cp "$tmp/marked-bad" "$repo/$S"
  run_case 'a known point with a malformed marked finding makes the state invalid' 3 -- evaluate --state "$S"
  jq -c 'del(.known_points[].marked)' "$tmp/marked-good" >"$repo/$S"
  report "$tmp/m8.md" 2 "$F_STRUCT"
  run_case 'a known point that an older version marked is recorded' 0 -- record --state "$S" --input "$tmp/m8.md" --changed-files "$tmp/changed-both"
  check 'a known point with no marked finding opens on the next report of its identity' "$(q '.verdict == "continue" and .counts.known == 0 and .counts.remaining_blocking == 1')"

  # --- the cap and the threshold: default branch only, range-checked ---------------------------------------
  manifest '{ "review": { "max-iterations": 2, "blocking-threshold": "medium" } }'
  printf '{ "extra": { "ai-olympus": { "review": { "max-iterations": 9, "blocking-threshold": "low" } } } }\n' >"$repo/composer.json"
  run_case 'the configuration is read' 0 -- evaluate --state "$S"
  check 'a working-tree composer.json changes neither the cap nor the threshold' "$(q '.max_iterations == 2 and .blocking_threshold == "medium"')"
  (cd "$repo" && AI_OLYMPUS_REVIEW_MAX_ITERATIONS=9 REVIEW_MAX_ITERATIONS=9 MAX_ITERATIONS=9 maxIterations=9 "$script" evaluate --state "$S") >"$tmp/out" 2>/dev/null
  check 'an environment variable does not change the cap' "$(q '.max_iterations == 2')"
  git -C "$repo" checkout -q -- composer.json
  local bad
  for bad in 0 -1 '"3"' 2.5 11 null; do
    manifest "{ \"review\": { \"max-iterations\": $bad } }"
    run_case "a cap of $bad is evaluated" 0 -- evaluate --state "$S"
    check "a cap of $bad falls back to 3" "$(q '.max_iterations == 3')"
  done
  check 'a refused cap is named on stderr' "$(manifest '{ "review": { "max-iterations": 0 } }'; (cd "$repo" && "$script" evaluate --state "$S" 2>&1 >/dev/null) | grep 'refused: review.max-iterations' >/dev/null && echo 0 || echo 1)"
  manifest '{ "review": { "blocking-threshold": "none" } }'
  run_case 'an unknown threshold is evaluated' 0 -- evaluate --state "$S"
  check 'an unknown threshold falls back to critical' "$(q '.blocking_threshold == "critical"')"

  # The most permissive threshold still blocks every Moderate with evidence; a security low blocks too.
  manifest '{ "review": { "blocking-threshold": "critical" } }'
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/t1.md" 1 "$F_MED"
  run_case 'a Moderate under the most permissive threshold' 0 -- record --state "$S" --input "$tmp/t1.md"
  check 'the most permissive threshold leaves a Moderate blocking' "$(q '.verdict == "continue" and .counts.remaining_blocking == 1 and .blocking[0].severity == "medium"')"
  for level in High Medium Low; do
    rm -f "$repo/$S"
    (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
    report "$tmp/t2.md" 1 "$(finding Moderate 'Weak escaping' 'src/View/Render.php:3' "$SQL" 'exploit-path — <b> reaches the page' "$level")"
    run_case "a security-lens $level finding is recorded" 0 -- record --state "$S" --input "$tmp/t2.md"
    check "a security-lens $level finding maps to a blocking severity" "$(q ".blocking[0].severity == \"$(printf '%s' "$level" | tr '[:upper:]' '[:lower:]')\" and .counts.remaining_blocking == 1")"
  done

  # --- cap 1: an open security finding is a hard stop, never a deferral ------------------------------------
  manifest '{ "review": { "max-iterations": 1 } }'
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/k1.md" 1 "$F_LOW_SEC"
  run_case 'cap 1 with a security low finding' 0 -- record --state "$S" --input "$tmp/k1.md"
  check 'at cap 1 an open security low finding is never deferred and never converged: it gets a security round' "$(q '.verdict == "security-round" and .status == "open" and .escalation_reason == null and (.deferrable | length) == 0 and (.reason | test("round 2 of at most 3"))')"
  run_case 'a security finding is never deferred at the cap' 4 -- defer --state "$S" --finding "$(jq -r '.findings[0].finding_id' "$repo/$S")" --url https://github.com/acme/shop/issues/9
  report "$tmp/k1b.md" 2 "$F_LOW_SEC"
  run_case 'the first security round past the cap is recorded' 0 -- record --state "$S" --input "$tmp/k1b.md" --changed-files "$tmp/changed-both"
  check 'the first security round past the cap is round 2 and continues as a security round' "$(q '.iteration == 2 and .verdict == "security-round" and .rounds[1].verdict == "security-round"')"
  report "$tmp/k1c.md" 3 "$F_LOW_SEC"
  run_case 'the second security round past the cap is recorded' 0 -- record --state "$S" --input "$tmp/k1c.md" --changed-files "$tmp/changed-both"
  check 'a security finding still open after the two security rounds escalates to the operator' "$(q '.iteration == 3 and .verdict == "escalated" and .status == "escalated" and .escalation_reason == "security-rounds-spent" and (.reason | test("the operator decides"))')"
  run_case 'the recorded security rounds keep the state valid' 0 -- evaluate --state "$S"
  check 'the state lists every round past the cap in order' "$(jq -e '.iteration == 3 and ([.rounds[].iteration] == [1, 2, 3]) and ([.rounds[].verdict] == ["security-round", "security-round", "escalated"])' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  report "$tmp/k1d.md" 4 "$F_LOW_SEC"
  run_case 'no round starts after the security rounds are spent' 5 -- record --state "$S" --input "$tmp/k1d.md" --changed-files "$tmp/changed-both"

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k1.md" >/dev/null)
  printf 'src/View/Render.php\n' >"$tmp/changed-render"
  report "$tmp/k1e.md" 2
  run_case 'a security round that fixes the finding is recorded' 0 -- record --state "$S" --input "$tmp/k1e.md" --changed-files "$tmp/changed-render"
  check 'a security finding fixed in a security round converges the loop' "$(q '.verdict == "converged" and .iteration == 2 and .counts.resolved == 1')"
  report "$tmp/k1f.md" 3 "$F_STRUCT"
  run_case 'a round after a convergence in a security round is refused' 5 -- record --state "$S" --input "$tmp/k1f.md" --changed-files "$tmp/changed-both"
  check 'a change after a convergence in a security round escalates with cap-spent-after-convergence' "$(q '.status == "escalated" and .escalation_reason == "cap-spent-after-convergence"')"

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/k1g.md" 1 "$F_SQL"
  run_case 'cap 1 with a security Critical' 0 -- record --state "$S" --input "$tmp/k1g.md"
  check 'a security Critical at the cap gets the security rounds and is never deferrable' "$(q '.verdict == "security-round" and (.deferrable | length) == 0 and .blocking[0].severity == "critical"')"
  run_case 'a security Critical is never deferred' 4 -- defer --state "$S" --finding "$(jq -r '.findings[0].finding_id' "$repo/$S")" --url https://github.com/acme/shop/issues/9
  report "$tmp/k1h.md" 2 "$F_SQL" "$F_CRIT"
  run_case 'a security round that reports a new Critical outside security is recorded' 0 -- record --state "$S" --input "$tmp/k1h.md" --changed-files "$tmp/changed-both"
  check 'a new Critical that is not security-relevant in a security round escalates at once' "$(q '.verdict == "escalated" and .escalation_reason == "cap-reached-hard-blockers" and .iteration == 2')"

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/k1i.md" 1 "$F_CRIT" "$F_LOW_SEC"
  run_case 'cap 1 with a Critical outside security and a security finding' 0 -- record --state "$S" --input "$tmp/k1i.md"
  check 'a Critical outside security at the cap escalates without a security round' "$(q '.verdict == "escalated" and .escalation_reason == "cap-reached-hard-blockers" and (.deferrable | length) == 0')"
  run_case 'a Critical outside security is never deferred' 5 -- defer --state "$S" --finding "$(jq -r '.findings[] | select(.file == "src/Foo/Total.php") | .finding_id' "$repo/$S")" --url https://github.com/acme/shop/issues/9

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/k1j.md" 1 "$F_LOW_SEC" "$F_STRUCT"
  run_case 'cap 1 with a security finding and a Moderate outside security' 0 -- record --state "$S" --input "$tmp/k1j.md"
  check 'the Moderate outside security is deferred first, before any security round' "$(q '.verdict == "deferral-required" and (.deferrable | length) == 1 and .deferrable[0].file == "src/Foo/Bar.php"')"
  report "$tmp/k1k.md" 2 "$F_LOW_SEC" "$F_STRUCT"
  run_case 'no security round starts while a deferrable finding is not deferred' 5 -- record --state "$S" --input "$tmp/k1k.md" --changed-files "$tmp/changed-both"
  run_case 'the Moderate outside security is deferred without an operator decision' 0 -- defer --state "$S" --finding "$(jq -r '.findings[] | select(.file == "src/Foo/Bar.php") | .finding_id' "$repo/$S")" --url https://github.com/acme/shop/issues/9
  check 'after the deferral the security finding gets its security round' "$(q '.verdict == "security-round" and .counts.deferred == 1')"
  run_case 'the final evaluation keeps the security round open' 0 -- evaluate --state "$S" --final
  check 'the final evaluation never escalates a pending security round' "$(q '.verdict == "security-round" and .status == "open"')"
  report "$tmp/k1l.md" 2 "$F_LOW_SEC"
  run_case 'the security round after the deferral is recorded' 0 -- record --state "$S" --input "$tmp/k1l.md" --changed-files "$tmp/changed-both"
  check 'a deferred Moderate stays deferred in the security round' "$(q '.verdict == "security-round" and .counts.deferred == 1 and .iteration == 2 and (.blocking | length) == 1')"

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/k2.md" 1 "$F_STRUCT" "$F_NOEV"
  run_case 'cap 1 with a non-security Moderate' 0 -- record --state "$S" --input "$tmp/k2.md"
  check 'at the cap a non-security Moderate below the threshold requires a deferral' "$(q '.verdict == "deferral-required" and (.deferrable | length) == 1')"
  run_case 'a deferral without an https URL is a usage error' 1 -- defer --state "$S" --finding "$struct_id" --url 'http://x'
  run_case 'the filed sub-issue defers the finding' 0 -- defer --state "$S" --finding "$struct_id" --url https://github.com/acme/shop/issues/9
  check 'scenario 10: a loop left with only deferred and unverified findings converges' "$(q '.verdict == "converged" and .counts.deferred == 1 and .counts.needs_verification == 1')"

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k2.md" >/dev/null)
  run_case 'the final evaluation of an undeferred Moderate' 0 -- evaluate --state "$S" --final
  check 'a Moderate neither fixed nor deferred at the cap escalates on the final evaluation' "$(q '.verdict == "escalated" and .escalation_reason == "cap-reached-undeferred-blockers"')"

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/m1.md" 1
  run_case 'cap 1 with a clean round' 0 -- record --state "$S" --input "$tmp/m1.md"
  check 'the loop converges in the cap round' "$(q '.verdict == "converged" and .iteration == 1')"
  report "$tmp/m2.md" 2 "$F_STRUCT"
  run_case 'a round after a convergence in the cap round is refused' 5 -- record --state "$S" --input "$tmp/m2.md" --changed-files "$tmp/changed-both"
  check 'a change after a convergence in the cap round escalates with cap-spent-after-convergence' "$(q '.verdict == "escalated" and .status == "escalated" and .escalation_reason == "cap-spent-after-convergence" and (.reason | test("converged in the cap round of 1"))')"
  check 'the escalation is stored and the refused round is not recorded' "$(jq -e '.status == "escalated" and .escalation_reason == "cap-spent-after-convergence" and .iteration == 1 and (.findings | length) == 0' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  run_case 'the escalated loop is evaluated' 0 -- evaluate --state "$S"
  check 'the evaluation after the refused round is escalated, never converged' "$(q '.verdict == "escalated" and .escalation_reason == "cap-spent-after-convergence"')"
  manifest '{ "review": { "max-iterations": 1, "blocking-threshold": "medium" } }'
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  run_case 'a tighter threshold makes a medium Moderate a hard blocker' 0 -- record --state "$S" --input "$tmp/k2.md"
  check 'a medium finding at or above the threshold is never deferrable' "$(q '.verdict == "escalated" and (.deferrable | length) == 0')"

  # --- a round past the cap is never narrowed, and a security round records every new finding ---------------
  manifest '{ "review": { "max-iterations": 1 } }'
  printf 'src/Foo/Bar.php\n' >"$tmp/changed-bar"
  printf '1\t0\tsrc/Foo/Bar.php\n' >"$tmp/ns-bar"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k1.md" >/dev/null)
  cp "$repo/$S" "$tmp/security-round"
  run_case 'a small delta in a security round is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-bar" --numstat "$tmp/ns-bar"
  check 'a security round runs every lens, however small the delta' "$(q '.depth == "full" and (.depth_reasons | index("past-the-cap"))')"
  local extra
  for extra in 'security finding:F_SQL:security-round' 'Moderate outside security:F_STRUCT:deferral-required' 'Critical outside security:F_CRIT:escalated'; do
    IFS=: read -r extra probe_changed probe_numstat <<<"$extra"
    cp "$tmp/security-round" "$repo/$S"
    report "$tmp/sr.md" 2 "${!probe_changed}"
    run_case "a security round that reports a new $extra is recorded" 0 -- record --state "$S" --input "$tmp/sr.md" --changed-files "$tmp/changed-both"
    check "a security round that reports a new $extra never converges" "$(q ".verdict == \"$probe_numstat\" and .verdict != \"converged\" and .rounds[1].new == 1")"
  done

  # A security finding the splinter view raises in the cap round reaches the state one round later: it still gets
  # the security rounds after a convergence at the cap (default cap 3).
  manifest '{}'
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/b1.md" >/dev/null)
  report "$tmp/v2.md" 2 "$F_STRUCT"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/v2.md" --changed-files "$tmp/changed-none" >/dev/null)
  report "$tmp/v3.md" 3
  run_case 'a clean cap round converges the loop' 0 -- record --state "$S" --input "$tmp/v3.md" --changed-files "$tmp/changed-both"
  check 'the loop converged in the cap round of 3' "$(q '.verdict == "converged" and .iteration == 3')"
  cp "$repo/$S" "$tmp/converged-at-cap"
  run_case 'a small delta after a convergence at the cap is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-bar" --numstat "$tmp/ns-bar"
  check 'a round after a convergence at the cap runs every lens' "$(q '.depth == "full" and .depth_reasons == ["past-the-cap"]')"
  for extra in 'security Moderate:F_LOW_SEC' 'security Critical:F_SQL'; do
    IFS=: read -r extra probe_changed <<<"$extra"
    cp "$tmp/converged-at-cap" "$repo/$S"
    report "$tmp/v4.md" 4 "${!probe_changed}"
    run_case "a $extra after a convergence at the cap is recorded" 0 -- record --state "$S" --input "$tmp/v4.md" --changed-files "$tmp/changed-both"
    check "a $extra reported after a convergence in the cap round gets the security rounds" "$(q '.verdict == "security-round" and .status == "open" and .iteration == 4')"
  done
  report "$tmp/v5.md" 5 "$F_SQL"
  run_case 'the second security round after a convergence at the cap is recorded' 0 -- record --state "$S" --input "$tmp/v5.md" --changed-files "$tmp/changed-both"
  check 'a security finding reported after a convergence at the cap escalates when the security rounds are spent' "$(q '.verdict == "escalated" and .escalation_reason == "security-rounds-spent" and .iteration == 5')"
  for extra in 'Moderate outside security:F_STRUCT' 'Critical outside security:F_CRIT' 'security finding and a Moderate outside security:F_LOW_SEC F_STRUCT' 'clean round:'; do
    IFS=: read -r extra probe_changed <<<"$extra"
    cp "$tmp/converged-at-cap" "$repo/$S"
    local blocks=()
    for probe_numstat in $probe_changed; do blocks+=("${!probe_numstat}"); done
    report "$tmp/v4.md" 4 ${blocks[@]+"${blocks[@]}"}
    run_case "a $extra after a convergence at the cap is refused" 5 -- record --state "$S" --input "$tmp/v4.md" --changed-files "$tmp/changed-both"
    check "a $extra after a convergence at the cap escalates with cap-spent-after-convergence and is not recorded" "$(jq -e '.status == "escalated" and .escalation_reason == "cap-spent-after-convergence" and .iteration == 3' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  done
  # A convergence in the last security round leaves no security round to take.
  manifest '{ "review": { "max-iterations": 1 } }'
  cp "$tmp/security-round" "$repo/$S"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k1b.md" --changed-files "$tmp/changed-both" >/dev/null)
  report "$tmp/w3.md" 3
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/w3.md" --changed-files "$tmp/changed-render" >/dev/null)
  report "$tmp/w4.md" 4 "$F_LOW_SEC"
  run_case 'a security finding after a convergence in the last security round is refused' 5 -- record --state "$S" --input "$tmp/w4.md" --changed-files "$tmp/changed-both"
  check 'a security finding after a convergence in the last security round escalates with cap-spent-after-convergence' "$(q '.escalation_reason == "cap-spent-after-convergence" and .iteration == 3')"

  # --- a security finding reported late counts for the round the reviewer already recorded -------------------
  # The security view can report after `leonardo` recorded the round as converged. `late` adds that finding to the
  # recorded round, without a new round, so the loop opens the next fix round as for any other open finding.
  manifest '{}'
  cp "$tmp/converged-at-cap" "$repo/$S"
  report "$tmp/l3.md" 3 "$F_SQL"
  run_case 'a security finding reported late for a round converged at the cap is recorded' 0 -- late --state "$S" --input "$tmp/l3.md"
  check 'a late security finding counts for the cap round and opens a security round' "$(q '.verdict == "security-round" and .status == "open" and .escalation_reason == null and .iteration == 3 and .counts.remaining_blocking == 1')"
  check 'the late finding is counted as new in the round it was reported for' "$(jq -e '.rounds | length == 3 and .[2].new == 1 and .[2].remaining_blocking == 1 and .[2].verdict == "security-round"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  run_case 'the same late report is recorded again' 0 -- late --state "$S" --input "$tmp/l3.md"
  check 'a late report repeated does not count the finding twice' "$(jq -e '.rounds[2].new == 1 and .iteration == 3' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  report "$tmp/l4.md" 4
  run_case 'the next round after a late finding is recorded as the security round' 0 -- record --state "$S" --input "$tmp/l4.md" --changed-files "$tmp/changed-both"
  check 'the fix of a late finding converges the loop in the security round' "$(q '.verdict == "converged" and .status == "converged" and .iteration == 4 and .counts.resolved == 2 and .counts.remaining_blocking == 0')"
  run_case 'a late report for a round that is no longer the last is refused' 4 -- late --state "$S" --input "$tmp/l3.md"
  check 'a late report for an earlier round changes nothing' "$(jq -e '.iteration == 4 and .status == "converged"' "$repo/$S" >/dev/null && echo 0 || echo 1)"

  cp "$tmp/converged-at-cap" "$repo/$S"
  run_case 'a late report that reads another revision than the recorded round is refused' 4 -- late --state "$S" --input "$tmp/v5.md"
  local HIST="@skills/git-history-exposure-audit/SKILL.md" F_HIST
  F_HIST="$(finding Critical 'An API key is committed in the history' 'config/services.php:12' "$HIST" 'exploit-path — the key is readable in an earlier commit')"
  # Every finding of a late report is a finding of the security view, so the classifier tag never decides: each
  # variant counts for the round, is stored as a security finding, and gives the security round at the cap.
  for extra in 'a finding the classifier tags as security:F_SQL' 'a finding the classifier does not tag as security:F_STRUCT' 'a finding of the history-exposure lens:F_HIST' 'a point that needs verification:F_SEC_NOEV' 'a Moderate of Low level:F_LOW_SEC'; do
    IFS=: read -r extra probe_changed <<<"$extra"
    cp "$tmp/converged-at-cap" "$repo/$S"
    report "$tmp/l3x.md" 3 "${!probe_changed}"
    run_case "$extra reported late is recorded" 0 -- late --state "$S" --input "$tmp/l3x.md"
    check "$extra reported late opens a security round and is stored as a security finding" "$(q '.verdict == "security-round" and .status == "open" and .escalation_reason == null and .iteration == 3 and .counts.remaining_blocking == 1')"
    check "$extra reported late is never deferrable" "$(jq -e '[.findings[] | select(.status != "resolved")] | length == 1 and all(.[]; .security == true)' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  done
  report "$tmp/l3h.md" 3 "$F_HIST"
  check 'a finding of the history-exposure lens is security-relevant in its round record too' "$(jq -e -s 'all(.[]; .security == true)' <("$tmp/pkg/skills/_shared/extract-findings.sh" --round --repo acme/shop --pr 7 --input "$tmp/l3h.md") >/dev/null && echo 0 || echo 1)"
  cp "$tmp/converged-at-cap" "$repo/$S"
  report "$tmp/l3x.md" 3 "$F_STRUCT"
  (cd "$repo" && "$script" late --state "$S" --input "$tmp/l3x.md" >/dev/null)
  run_case 'the next round of a late finding the classifier did not tag is not refused after the convergence' 0 -- record --state "$S" --input "$tmp/l4.md" --changed-files "$tmp/changed-both"
  check 'the next round of a late finding the classifier did not tag never escalates with cap-spent-after-convergence' "$(q '.escalation_reason == null and .iteration == 4')"
  cp "$tmp/converged-at-cap" "$repo/$S"
  report "$tmp/l3c.md" 3
  run_case 'a late report without a finding is refused' 4 -- late --state "$S" --input "$tmp/l3c.md"
  check 'a refused late report leaves the convergence in place' "$(jq -e '.status == "converged" and .iteration == 3 and ([.findings[] | select(.status != "resolved")] | length) == 0' "$repo/$S" >/dev/null && echo 0 || echo 1)"

  # A round below the cap that converged takes the late finding as an ordinary open finding.
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/b1.md" >/dev/null)
  report "$tmp/l1.md" 1 "$F_SQL"
  run_case 'a security finding reported late for a converged round below the cap is recorded' 0 -- late --state "$S" --input "$tmp/l1.md"
  check 'a late finding below the cap continues the loop' "$(q '.verdict == "continue" and .status == "open" and .iteration == 1')"
  run_case 'a small delta after a late finding below the cap is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-bar" --numstat "$tmp/ns-bar"
  check 'a late finding open in the state makes the next round run every lens' "$(q '.depth == "full" and (.depth_reasons | index("open-security-finding"))')"
  (cd "$repo" && "$script" init --state .claude/run/review-state-empty.json --repo acme/shop --pr 7 >/dev/null)
  run_case 'a late report for a state without a round is refused' 4 -- late --state .claude/run/review-state-empty.json --input "$tmp/l1.md"

  # A late finding in the last security round leaves no security round: the operator decides.
  manifest '{ "review": { "max-iterations": 1 } }'
  cp "$tmp/security-round" "$repo/$S"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k1b.md" --changed-files "$tmp/changed-both" >/dev/null)
  report "$tmp/w3.md" 3
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/w3.md" --changed-files "$tmp/changed-render" >/dev/null)
  report "$tmp/lw3.md" 3 "$F_LOW_SEC"
  run_case 'a security finding reported late for the last security round is recorded' 0 -- late --state "$S" --input "$tmp/lw3.md"
  check 'a late finding in the last security round escalates with security-rounds-spent, for the operator to decide' "$(q '.verdict == "escalated" and .escalation_reason == "security-rounds-spent" and .iteration == 3')"
  run_case 'a late report for an escalated loop is refused' 5 -- late --state "$S" --input "$tmp/lw3.md"

  # --- an operator round past the cap: only on the operator's own instruction, never on a tracker comment -------
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k1i.md" >/dev/null)
  cp "$repo/$S" "$tmp/escalated-hard"
  report "$tmp/o2.md" 2 "$F_CRIT"
  run_case 'a round after an escalation without the operator round is refused' 5 -- record --state "$S" --input "$tmp/o2.md" --changed-files "$tmp/changed-both"
  run_case 'the retired comment authorisation is a usage error' 1 -- record --state "$S" --input "$tmp/o2.md" --changed-files "$tmp/changed-both" --operator-authorised "${C}31"
  check 'every refused round leaves the state untouched' "$(cmp -s "$tmp/escalated-hard" "$repo/$S" && echo 0 || echo 1)"
  run_case 'the operator decides a round after cap-reached-hard-blockers' 0 -- record --state "$S" --input "$tmp/o2.md" --changed-files "$tmp/changed-both" --operator-round
  check 'the operator round is recorded with the operator as its actor' "$(jq -e '.iteration == 2 and .operator_rounds == [{iteration: 2, actor: "operator", after: "cap-reached-hard-blockers"}]' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  check 'an operator round never lifts a Critical from the convergence gate' "$(q '.verdict == "escalated" and .escalation_reason == "cap-reached-hard-blockers" and ([.blocking[] | select(.severity == "critical")] | length) == 1')"
  run_case 'the state after an operator round stays valid' 0 -- evaluate --state "$S"
  report "$tmp/o3.md" 3
  printf 'src/Foo/Total.php\nsrc/View/Render.php\nsrc/Foo/Bar.php\n' >"$tmp/changed-fixed"
  run_case 'the operator decides the next round' 0 -- record --state "$S" --input "$tmp/o3.md" --changed-files "$tmp/changed-fixed" --operator-round
  check 'an operator round converges when it leaves no blocking finding' "$(q '.verdict == "converged" and .iteration == 3')"
  check 'every operator round keeps its own entry' "$(jq -e '[.operator_rounds[].iteration] == [2, 3]' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  cp "$repo/$S" "$tmp/operator-rounds"
  jq -c '.operator_rounds[0] += {comment: "https://github.com/acme/shop/pull/7#issuecomment-31", association: "OWNER"}' "$tmp/operator-rounds" >"$repo/$S"
  run_case 'an operator round an older version verified by a comment stays valid' 0 -- evaluate --state "$S"
  jq -c '.operator_rounds[0].actor = "reviewer"' "$tmp/operator-rounds" >"$repo/$S"
  run_case 'an operator round with another actor is invalid' 3 -- evaluate --state "$S"
  jq -c '.operator_rounds[0].comment = "https://github.com/acme/shop/pull/7#issuecomment-31"' "$tmp/operator-rounds" >"$repo/$S"
  run_case 'an operator round with a comment but no association is invalid' 3 -- evaluate --state "$S"
  cp "$tmp/security-round" "$repo/$S"
  run_case 'an operator round of a loop that did not escalate is refused' 4 -- record --state "$S" --input "$tmp/k1b.md" --changed-files "$tmp/changed-both" --operator-round
  check 'the refused operator round of an open loop leaves the state untouched' "$(cmp -s "$tmp/security-round" "$repo/$S" && echo 0 || echo 1)"
  # After the security rounds are spent, an operator round still counts the security finding.
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k1b.md" --changed-files "$tmp/changed-both" >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k1c.md" --changed-files "$tmp/changed-both" >/dev/null)
  run_case 'the operator decides a round after security-rounds-spent' 0 -- record --state "$S" --input "$tmp/k1d.md" --changed-files "$tmp/changed-both" --operator-round
  check 'an operator round never lifts a security finding from the convergence gate' "$(q '.iteration == 4 and .verdict == "escalated" and .escalation_reason == "security-rounds-spent" and (.blocking | length) == 1 and .blocking[0].security == true')"
  check 'the operator round after security-rounds-spent is recorded' "$(jq -e '.operator_rounds[0].after == "security-rounds-spent"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  # After a convergence at the cap and after an undeferred Moderate, the operator may decide one round too.
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/m1.md" >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/m2.md" --changed-files "$tmp/changed-both" >/dev/null 2>&1) || true
  run_case 'the operator decides a round after cap-spent-after-convergence' 0 -- record --state "$S" --input "$tmp/m2.md" --changed-files "$tmp/changed-both" --operator-round
  check 'the round after cap-spent-after-convergence is recorded and its Moderate still needs a deferral' "$(q '.iteration == 2 and .verdict == "deferral-required"')"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/k2.md" >/dev/null)
  (cd "$repo" && "$script" evaluate --state "$S" --final >/dev/null)
  run_case 'the operator decides a round after cap-reached-undeferred-blockers' 0 -- record --state "$S" --input "$tmp/o3.md" --changed-files "$tmp/changed-fixed" --operator-round
  check 'the round after cap-reached-undeferred-blockers is recorded' "$(jq -e '.iteration == 2 and .operator_rounds[0].after == "cap-reached-undeferred-blockers"' "$repo/$S" >/dev/null && echo 0 || echo 1)"

  # --- scenario 11: recovery after an interrupted workflow -------------------------------------------------
  manifest '{}'
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/r1.md" >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/r2.md" --changed-files "$tmp/changed-none" >/dev/null)
  run_case 'scenario 11: init after an interruption resumes the existing state' 0 -- init --state "$S" --repo acme/shop --pr 7
  check 'the resumed state keeps its round count' "$(q '.resumed == true and .iteration == 2')"
  run_case 'the next round continues the count' 0 -- record --state "$S" --input "$tmp/r3.md" --changed-files "$tmp/changed-none"
  check 'the recovered loop counts the round as round 3' "$(q '.iteration == 3')"
  run_case 'init for another pull request refuses to replace the state' 3 -- init --state "$S" --repo acme/shop --pr 8

  jq -c '.iteration = 0' "$repo/$S" >"$tmp/lowered" && cp "$tmp/lowered" "$repo/$S"
  run_case 'a state whose round count was lowered is invalid' 3 -- evaluate --state "$S"
  printf '{ broken' >"$repo/$S"
  cp "$repo/$S" "$tmp/corrupt"
  run_case 'a corrupted state is never converged' 3 -- evaluate --state "$S"
  check 'the corrupted state prints no verdict' "$([[ ! -s "$tmp/out" ]] && echo 0 || echo 1)"
  run_case 'init never overwrites a corrupted state' 3 -- init --state "$S" --repo acme/shop --pr 7
  check 'the corrupted state is left for inspection' "$(cmp -s "$tmp/corrupt" "$repo/$S" && echo 0 || echo 1)"
  run_case 'a missing state is never converged' 3 -- evaluate --state .claude/run/none.json
  run_case 'record without a state is refused' 3 -- record --state .claude/run/none.json --input "$tmp/r1.md"

  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/b1.md" >/dev/null)
  jq -c '.findings[0].status = "resolved"' "$repo/$S" >"$tmp/edited" && cp "$tmp/edited" "$repo/$S"
  run_case 'a status edited without a transition is invalid' 3 -- evaluate --state "$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null 2>&1) || true
  jq -c '.findings[0].status = "dismissed" | .findings[0].security = true
      | .transitions += [{iteration: 1, finding_id: .findings[0].finding_id, from: "new", to: "dismissed", actor: "trusted:OWNER"}]' "$tmp/edited" >"$repo/$S"
  run_case 'a dismissed security finding in a recovered state is invalid' 3 -- evaluate --state "$S"
  jq -c '.status = "converged" | .rounds = [] | .iteration = 0 | .transitions = [] | .findings = []' "$tmp/edited" >"$repo/$S"
  run_case 'a state that claims convergence without a round is invalid' 3 -- evaluate --state "$S"

  # --- scenario 12: the broad review -----------------------------------------------------------------------
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  run_case 'the first round is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-none"
  check 'the first round reviews the full change set' "$(q '.scope == "full" and .reasons == ["first-round"]')"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/b1.md" >/dev/null)
  printf 'src/Foo/Bar.php\n' >"$tmp/changed-plain"
  run_case 'a plain delta is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain"
  check 'a delta that touches no sensitive surface stays incremental' "$(q '.scope == "delta" and .reasons == [] and (.baseline | test("^a{39}1$"))')"
  printf 'src/Foo/Bar.php\napp/Http/Middleware/Authenticate.php\n' >"$tmp/changed-auth"
  run_case 'a delta that touches authentication is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-auth"
  check 'scenario 12: a delta on a security surface triggers the broad review' "$(q '.scope == "full" and (.reasons | index("security-surface"))')"
  printf 'config/app.php\n' >"$tmp/changed-config"
  run_case 'a delta that touches shared configuration is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-config"
  check 'a delta on a shared surface triggers the broad review' "$(q '.scope == "full" and (.reasons | index("shared-surface"))')"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/b2.md" --changed-files "$tmp/changed-both" >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/b1.md" --changed-files "$tmp/changed-both" >/dev/null 2>&1) || true
  run_case 'a delta after a regression is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain"
  check 'a regression of a resolved finding triggers the broad review' "$(q '.scope == "full" and (.reasons | index("regression"))')"

  # --- the depth of a later round: a light round after a small delta, the full set otherwise ------------------
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  run_case 'the depth of the first round is computed' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain" --numstat - <<<$'1\t0\tsrc/Foo/Bar.php'
  check 'the first round runs every lens, however small its delta' "$(q '.depth == "full" and (.depth_reasons | index("first-round"))')"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/b1.md" >/dev/null)
  # numstat <file> <added> <deleted> <path>... — one numstat line per path, every path with the same counts.
  numstat() {
    local file="$1" added="$2" deleted="$3"
    shift 3
    : >"$file"
    for path in "$@"; do printf '%s\t%s\t%s\n' "$added" "$deleted" "$path" >>"$file"; done
  }
  numstat "$tmp/ns-99" 60 39 src/Foo/Bar.php
  run_case 'a delta of 99 changed lines is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain" --numstat "$tmp/ns-99"
  check 'a delta just under the line threshold runs a light round' "$(q '.scope == "delta" and .depth == "light" and .depth_reasons == [] and .delta == {files: 1, lines: 99}')"
  numstat "$tmp/ns-100" 60 40 src/Foo/Bar.php
  run_case 'a delta of 100 changed lines is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain" --numstat "$tmp/ns-100"
  check 'a delta at the line threshold runs a light round' "$(q '.depth == "light" and .delta.lines == 100')"
  numstat "$tmp/ns-101" 61 40 src/Foo/Bar.php
  run_case 'a delta of 101 changed lines is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain" --numstat "$tmp/ns-101"
  check 'a delta over the line threshold runs every lens' "$(q '.scope == "delta" and .depth == "full" and .depth_reasons == ["large-delta"] and .delta.lines == 101')"
  printf 'src/Foo/Bar.php\nsrc/Foo/A.php\nsrc/Foo/B.php\nsrc/Foo/C.php\n' >"$tmp/changed-4"
  printf 'src/Foo/Bar.php\nsrc/Foo/A.php\nsrc/Foo/B.php\nsrc/Foo/C.php\nsrc/Foo/D.php\n' >"$tmp/changed-5"
  printf 'src/Foo/Bar.php\nsrc/Foo/A.php\nsrc/Foo/B.php\nsrc/Foo/C.php\nsrc/Foo/D.php\nsrc/Foo/E.php\n' >"$tmp/changed-6"
  numstat "$tmp/ns-4" 1 1 src/Foo/Bar.php src/Foo/A.php src/Foo/B.php src/Foo/C.php
  numstat "$tmp/ns-5" 1 1 src/Foo/Bar.php src/Foo/A.php src/Foo/B.php src/Foo/C.php src/Foo/D.php
  numstat "$tmp/ns-6" 1 1 src/Foo/Bar.php src/Foo/A.php src/Foo/B.php src/Foo/C.php src/Foo/D.php src/Foo/E.php
  run_case 'a delta of four files is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-4" --numstat "$tmp/ns-4"
  check 'a delta just under the file threshold runs a light round' "$(q '.depth == "light" and .delta == {files: 4, lines: 8}')"
  run_case 'a delta of five files is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-5" --numstat "$tmp/ns-5"
  check 'a delta at the file threshold runs a light round' "$(q '.depth == "light" and .delta.files == 5')"
  run_case 'a delta of six files is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-6" --numstat "$tmp/ns-6"
  check 'a delta over the file threshold runs every lens' "$(q '.depth == "full" and .depth_reasons == ["large-delta"] and .delta.files == 6')"
  run_case 'a delta without line counts is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain"
  check 'a delta of unknown size runs every lens' "$(q '.depth == "full" and .depth_reasons == ["delta-size-unknown"] and .delta.lines == null')"
  printf -- '-\t-\tsrc/Foo/Bar.php\n' >"$tmp/ns-binary"
  run_case 'a delta with a binary change is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain" --numstat "$tmp/ns-binary"
  check 'a binary change makes the delta size unknown and runs every lens' "$(q '.depth == "full" and .depth_reasons == ["delta-size-unknown"]')"
  printf '3 lines changed\n' >"$tmp/ns-prose"
  run_case 'a numstat list in another shape is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain" --numstat "$tmp/ns-prose"
  check 'a numstat list in another shape is never read as small' "$(q '.depth == "full" and .depth_reasons == ["delta-size-unknown"]')"
  # A failed, partial, or mismatched diff must never read as a small delta: every probe below runs every lens.
  printf 'src/Foo/Bar.php\nsrc/Foo/A.php\nsrc/Foo/B.php\n' >"$tmp/changed-3"
  : >"$tmp/ns-empty"
  printf '\n\n\n' >"$tmp/ns-blank"
  numstat "$tmp/ns-partial" 1 1 src/Foo/Bar.php
  numstat "$tmp/ns-other" 1 1 src/Foo/A.php src/Foo/B.php src/Foo/C.php src/Foo/D.php src/Foo/E.php src/Foo/F.php
  printf '0\t0\tapp/{Http/Middleware/Authenticate.php => Support/Helper.php}\n' >"$tmp/ns-rename"
  printf 'app/Support/Helper.php\n' >"$tmp/changed-renamed"
  local probe probe_changed probe_numstat
  for probe in 'empty:changed-3:ns-empty' 'blank:changed-3:ns-blank' 'partial:changed-3:ns-partial' 'other:changed-plain:ns-other' \
    'rename:changed-renamed:ns-rename' 'nothing:changed-empty:ns-empty'; do
    IFS=: read -r probe probe_changed probe_numstat <<<"$probe"
    : >"$tmp/changed-empty"
    run_case "the numstat probe '$probe' is scoped" 0 -- scope --state "$S" --changed-files "$tmp/$probe_changed" --numstat "$tmp/$probe_numstat"
    check "the numstat probe '$probe' makes the delta size unknown and runs every lens" "$(q '.depth == "full" and (.depth_reasons | index("delta-size-unknown")) and .delta.lines == null')"
  done
  # The documented commands pass --no-renames, so a renamed security file reaches the classifier under its old path.
  mkdir -p "$repo/app/Http/Middleware"
  printf '<?php\n' >"$repo/app/Http/Middleware/Authenticate.php"
  git -C "$repo" add app && git -C "$repo" -c user.name=t -c user.email=t@t commit -q -m auth
  git -C "$repo" mv app/Http/Middleware/Authenticate.php app/Http/Helper.php
  git -C "$repo" -c user.name=t -c user.email=t@t commit -q -m rename
  git -C "$repo" diff --no-renames --name-only HEAD~1..HEAD >"$tmp/changed-git"
  git -C "$repo" diff --no-renames --numstat HEAD~1..HEAD >"$tmp/ns-git"
  run_case 'a rename read with --no-renames is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-git" --numstat "$tmp/ns-git"
  check 'a renamed security file read with --no-renames runs every lens' "$(q '.depth == "full" and (.depth_reasons | index("security-surface")) and .delta == {files: 2, lines: 2}')"
  numstat "$tmp/ns-auth" 2 1 src/Foo/Bar.php app/Http/Middleware/Authenticate.php
  run_case 'a small delta on a security path is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-auth" --numstat "$tmp/ns-auth"
  check 'a small delta on a security-relevant path runs every lens' "$(q '.scope == "full" and .depth == "full" and (.depth_reasons | index("security-surface")) and .delta == {files: 2, lines: 6}')"
  run_case 'a numstat list and a changed-files list cannot both read standard input' 1 -- scope --state "$S" --changed-files - --numstat -
  run_case 'a missing numstat list is refused' 3 -- scope --state "$S" --changed-files "$tmp/changed-plain" --numstat "$tmp/no-such-numstat"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/h1.md" 1 "$F_LOW_SEC"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/h1.md" >/dev/null)
  numstat "$tmp/ns-render" 2 1 src/View/Render.php
  run_case 'a small delta while a security finding is open is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-render" --numstat "$tmp/ns-render"
  check 'an open security finding runs every lens, however small the delta' "$(q '.scope == "delta" and .depth == "full" and .depth_reasons == ["open-security-finding"]')"

  # A fix can land in another file than the finding it fixes. The next round then reads the whole PR diff.
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/f1.md" 1 "$F_SQL"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/f1.md" >/dev/null)
  printf 'app/Support/SortColumns.php\n' >"$tmp/changed-elsewhere"
  run_case 'a delta that leaves a proven finding untouched is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-elsewhere"
  check 'a proven finding outside the delta widens the next round to the whole PR' "$(q '.scope == "full" and .reasons == ["open-finding-outside-delta"]')"
  check 'the computed scope is stored for the next record' "$(jq -e '.scope == "full"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  report "$tmp/f2.md" 2
  run_case 'the broad round no longer reports the finding' 0 -- record --state "$S" --input "$tmp/f2.md" --changed-files "$tmp/changed-elsewhere"
  check 'a finding fixed in another file is resolved by the broad round and the loop converges' "$(q '.verdict == "converged" and .counts.resolved == 1 and (.carried | length) == 0')"
  check 'the record consumes the stored scope' "$(jq -e '.scope == null' "$repo/$S" >/dev/null && echo 0 || echo 1)"

  # A blocking finding whose location names no file sits outside every delta: no delta round reads it.
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/g1.md" 1 "$(finding Critical 'The policy is never checked' 'the pull request as a whole' "$SQL" 'exploit-path — no route calls the policy')"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/g1.md" >/dev/null)
  check 'a finding whose location names no file is stored with the file "."' "$(jq -e '.findings[0].file == "." and .findings[0].status == "new"' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  run_case 'a delta that leaves a path-less blocking finding is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-plain"
  check 'a path-less blocking finding widens the next round to the whole PR' "$(q '.scope == "full" and .reasons == ["open-finding-outside-delta"]')"
  report "$tmp/g2.md" 2
  run_case 'the broad round no longer reports the path-less finding' 0 -- record --state "$S" --input "$tmp/g2.md" --changed-files "$tmp/changed-plain"
  check 'a full round that omits a path-less blocking finding resolves it' "$(q '.verdict == "converged" and .counts.resolved == 1 and (.carried | length) == 0')"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/g1.md" >/dev/null)
  run_case 'a delta round without scope omits the path-less finding' 0 -- record --state "$S" --input "$tmp/g2.md" --changed-files "$tmp/changed-plain"
  check 'a delta round carries a path-less blocking finding' "$(q '.verdict == "continue" and (.carried | length) == 1 and .counts.resolved == 0 and .blocking[0].file == "."')"
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/g1.md" >/dev/null)
  printf '.\n./\nsrc/Foo/Bar.php\n' >"$tmp/changed-dot"
  run_case 'a delta whose changed files name "." is scoped' 0 -- scope --state "$S" --changed-files "$tmp/changed-dot"
  check 'a changed-files entry "." never puts a path-less finding inside the delta' "$(q '.scope == "full" and .reasons == ["open-finding-outside-delta"]')"

  # --- the script records every finding a round reports; the reviewer alone applies the later-round rule ----
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/l1.md" 1 "$F_SQL"
  (cd "$repo" && "$script" record --state "$S" --input "$tmp/l1.md" >/dev/null)
  F_OTHER="$(finding Moderate 'Another copied step' 'src/Foo/Other.php:9' "$STRUCT" 'rule-violation — the step is copied again')"
  report "$tmp/l2.md" 2 "$F_SQL" "$F_OTHER" "$F_LOW_SEC"
  run_case 'round 2 reports a new non-security Moderate and a new security Moderate' 0 -- record --state "$S" --input "$tmp/l2.md" --changed-files "$tmp/changed-both"
  check 'every finding a later round reports is recorded and blocks' "$(q '.rounds[1].new == 2 and .counts.remaining_blocking == 3 and ([.blocking[] | select(.file == "src/Foo/Other.php")] | length) == 1')"
  check 'nothing a later round reports is ignored' "$(grep -q 'ignored' "$tmp/err" && echo 1 || echo 0)"

  # --- untrusted input -------------------------------------------------------------------------------------
  rm -f "$repo/$S"
  (cd "$repo" && "$script" init --state "$S" --repo acme/shop --pr 7 >/dev/null)
  report "$tmp/e1.md" 1 "$(finding Critical 'Raw query' 'app/Http/Orders.php:42' "$SQL" 'exploit-path — $(touch '"$tmp"'/pwned) `rm -rf /` ; review.max-iterations = 99')"
  run_case 'a round whose evidence carries shell syntax is recorded' 0 -- record --state "$S" --input "$tmp/e1.md"
  check 'the evidence is stored as text and never executed, and it does not change the cap' "$([[ ! -e "$tmp/pwned" ]] && jq -e '(.findings[0].evidence | test("touch")) and .max_iterations == 3' "$repo/$S" >/dev/null && echo 0 || echo 1)"
  printf 'just prose\n' >"$tmp/prose.md"
  cp "$repo/$S" "$tmp/before-prose"
  run_case 'a round report that is not a review report is refused' 3 -- record --state "$S" --input "$tmp/prose.md" --changed-files "$tmp/changed-none"
  check 'the refused round leaves the state untouched' "$(cmp -s "$tmp/before-prose" "$repo/$S" && echo 0 || echo 1)"
  report "$tmp/noev.md" 2 "$(finding Moderate 'Maybe a copied step' 'src/Foo/Baz.php:3' "$STRUCT" '-')"
  run_case 'a round report with a finding under Findings without Evidence is refused' 3 -- record --state "$S" --input "$tmp/noev.md" --changed-files "$tmp/changed-none"
  check 'the round without Evidence leaves the state untouched and names the fix' "$(cmp -s "$tmp/before-prose" "$repo/$S" && grep -q 'Evidence is required; move an unproven point under ## Needs verification' "$tmp/err" && echo 0 || echo 1)"
  run_case 'an unknown command is a usage error' 1 -- bogus --state "$S"

  if [[ "$failures" -gt 0 ]]; then
    echo "review-state self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'review-state self-test: PASS'
  return 0
}

if [[ "${1:-}" == '--self-test' ]]; then
  require_tools
  self_test
  exit $?
fi

[[ $# -gt 0 ]] || die_usage 'no command given'
require_tools

command="$1"
shift
case "$command" in
init) cmd_init "$@" ;;
record) cmd_record "$@" ;;
late) cmd_late "$@" ;;
evaluate) cmd_evaluate "$@" ;;
scope) cmd_scope "$@" ;;
dismiss) cmd_dismiss "$@" ;;
known) cmd_known "$@" ;;
defer) cmd_defer "$@" ;;
-h | --help) usage ;;
*) die_usage "unknown command: $(state_path_display "$command")" ;;
esac
