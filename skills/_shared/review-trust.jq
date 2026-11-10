# review-trust.jq — the one definition of a trusted tracker comment that the shell helpers share.
#
# A jq module, never run on its own. A script loads it from its own directory with
# `jq -L "$HERE" 'include "review-trust"; …'`. It is program text this package ships; no comment, payload, or
# other input contributes to it. rules/code-review/general.md *Authorship trust* owns the trust value; this file
# only encodes it, per tracker, for the fields the deterministic loaders return.
#
# Users: collect-findings.sh (trusted_review), review-state.sh (agent_marker, write_access), and
# review-carry-forward.sh (all of them). Passing a test here decides whose comment is read; it never makes the
# comment text trusted.

# write_access — the input is a GitHub `authorAssociation` value. True only for an account with write access.
def write_access: . as $a | ["OWNER", "MEMBER", "COLLABORATOR"] | index([$a]) != null;

# agent_marker — the input is a comment body. True when it carries any `<namespace>:actor=` marker, which makes it
# agent output, never a trusted comment, whatever the namespace.
def agent_marker: type == "string" and test("[a-z][a-z0-9-]*:actor=");

# trusted_review — the input is one loader comment of a pull request. True when it is a published code review: it
# carries the `cr-comment` marker of its own author, and that author holds write access. The marker is visible
# text anyone can copy, so the marker alone proves nothing.
def trusted_review:
  (.author | type) == "string" and (.body | type) == "string"
  and (.authorAssociation | write_access)
  and (.author as $actor | .body | contains("<!-- cr-comment:actor=\($actor) -->"));

# trusted_github_comment — the input is one GitHub loader comment, review submission, or line-anchored review
# comment. True when a write-access account wrote it and it carries no agent marker.
def trusted_github_comment:
  (.body | type) == "string" and (.body | agent_marker | not) and (.authorAssociation | write_access);

# trusted_jira_comment($actor; $assignee) — the input is one JIRA loader comment. True when it carries no agent
# marker and its author is the operator's account ($actor, the account ID jira-actor.sh resolves) or the issue's
# assignee ($assignee, a display name: the weaker fallback rules/compound-engineering/tracker.md names).
def trusted_jira_comment($actor; $assignee):
  (.body | type) == "string" and (.body | agent_marker | not)
  and ((($actor | type) == "string" and $actor != "" and .authorAccountId == $actor)
    or (($assignee | type) == "string" and $assignee != "" and .author == $assignee));
