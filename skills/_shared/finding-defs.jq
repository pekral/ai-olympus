# finding-defs.jq — the jq definitions that extract-findings.sh and review-state.sh share.
#
# A jq module, never run on its own. Each script loads it from its own directory with
# `jq -L "$HERE" 'include "finding-defs"; …'`. It is program text this package ships; no report, state, or
# other input contributes to it.

def isstr($v; $re): ($v | type) == "string" and ($v | test($re));
def severity_rank: {"low": 1, "medium": 2, "high": 3, "critical": 4}[.] // 0;
def status_rank: {"dismissed": 1, "known": 1, "resolved": 2, "needs-verification": 3, "new": 4, "confirmed": 5}[.] // 0;
# The categories a known point never takes (rules/code-review/general.md S3): the security surfaces that no security
# word names, which are the merge gate, the orchestration, memory, and tracker rules, and the merge skills, and a
# finding of no rule. This list is the one owner; review-state.sh `known` and its state check read it.
def known_refused_category:
  test("^(unclassified|rules/git/pull-requests|rules/compound-engineering/(orchestration|memory|tracker)|skills/merge-github-pr[a-z0-9-]*(/.*)?)$");
