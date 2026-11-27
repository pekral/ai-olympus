# review-trust.jq — the one definition of a trusted tracker comment that the shell helpers share.
#
# A jq module, never run on its own. A script loads it from its own directory with
# `jq -L "$HERE" 'include "review-trust"; …'`. It is program text this package ships; no comment, payload, or
# other input contributes to it. rules/code-review/general.md *Authorship trust* owns the trust value; this file
# only encodes it, per tracker, for the fields the deterministic loaders return.
#
# Users: collect-findings.sh (review_reports, trusted_review), review-state.sh (agent_marker, write_access),
# review-carry-forward.sh (all of them), and cr-header.sh --report (newest_trusted_report). Passing a test here
# decides whose comment is read; it never makes the comment text trusted.

# write_access — the input is a GitHub `authorAssociation` value. True only for an account with write access.
def write_access: . as $a | ["OWNER", "MEMBER", "COLLABORATOR"] | index([$a]) != null;

# agent_marker — the input is a comment body. True when it carries any `<namespace>:actor=` marker, which makes it
# agent output, never a trusted comment, whatever the namespace.
def agent_marker: type == "string" and test("[a-z][a-z0-9-]*:actor=");

# trusted_review — the input is one entry of review_reports below. True when it is a published code review: it
# carries the `cr-comment` marker of its own author, and that author holds write access. The marker is visible
# text anyone can copy, so the marker alone proves nothing.
def trusted_review:
  (.author | type) == "string" and (.body | type) == "string"
  and (.authorAssociation | write_access)
  and (.author as $actor | .body | contains("<!-- cr-comment:actor=\($actor) -->"));

# review_reports — the input is the GitHub loader output of one pull request. Every place a code-review report can
# live, oldest home first: each pull-request comment (`home: "comment"`, the legacy `cr-comment` report) and each
# submitted pull-request review (`home: "review"`, whose body carries the hidden record that cr-header.sh unwraps).
# A pending review is never read. A review takes its `submittedAt` as `createdAt`, so one time orders both homes.
def review_reports:
  ((.comments // []) | map(. + {home: "comment"}))
  + ((.reviews // []) | map(select(.state != "PENDING") | . + {home: "review", createdAt: (.createdAt // .submittedAt)}));

# newest_trusted_report — the input is the GitHub loader output of one pull request. The newest entry of
# review_reports that passes trusted_review, or nothing. The newest wins across both homes, so a pull request whose
# newest report is still a legacy `cr-comment` keeps that report.
def newest_trusted_report:
  [review_reports[] | select(trusted_review)] | sort_by(.createdAt // "") | last // empty;

# trusted_github_comment — the input is one GitHub loader comment, review submission, or line-anchored review
# comment. True when a write-access account wrote it and it carries no agent marker.
def trusted_github_comment:
  (.body | type) == "string" and (.body | agent_marker | not) and (.authorAssociation | write_access);

# trusted_jira_comment($actor; $assignee) — the input is one JIRA loader comment. True when it carries no agent
# marker and its author is the operator's account ($actor, the account ID jira-actor.sh resolves) or the issue's
# assignee ($assignee, a display name: the weaker fallback rules/compound-engineering/tracker.md names).
def jira_summary: type == "string" and (sub("^([ \\t\\u0000]*\\R)+"; "") | startswith("🤖 AI Olympus · "));

def jira_machine_output: agent_marker or jira_summary;

def trusted_jira_comment($actor; $assignee):
  (.body | type) == "string" and (.body | jira_machine_output | not)
  and ((($actor | type) == "string" and $actor != "" and .authorAccountId == $actor)
    or (($assignee | type) == "string" and $assignee != "" and .author == $assignee));
