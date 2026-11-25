# finding-defs.jq — the jq definitions that extract-findings.sh and review-state.sh share.
#
# A jq module, never run on its own. Each script loads it from its own directory with
# `jq -L "$HERE" 'include "finding-defs"; …'`. It is program text this package ships; no report, state, or
# other input contributes to it.

def isstr($v; $re): ($v | type) == "string" and ($v | test($re));
def severity_rank: {"low": 1, "medium": 2, "high": 3, "critical": 4}[.] // 0;
def status_rank: {"dismissed": 1, "known": 1, "resolved": 2, "needs-verification": 3, "new": 4, "confirmed": 5}[.] // 0;
# Known points (rules/code-review/review-process.md *Known points*) take a fail-closed allow-list, never a deny-list.
# A finding may become a known point only when it carries no security signal and every rule reference of its Rule and
# Suggested fix fields is one of the entries below, in canonical form. An unknown, unreadable, or missing reference
# refuses it. Each entry is a rule that is no security surface and no gate (S1-S3 of rules/code-review/general.md):
#   rules/php/core-standards            PHP style and design; a misleading security control is the security-review lens's
#   rules/code-testing/general          how tests are written and organised
#   rules/writing/general               the style of outputs that a machine or an agent reads
#   rules/code-review/core-analysis     the maintainability catalog; a race or a defect on an S3 surface is a security finding
#   rules/code-review/general#reuse-existing-logic, #dry-check-every-new-rule-is-searched-for-in-the-whole-codebase,
#   #test-organization                  duplicated logic and test placement; every other section of that file holds S1-S3,
#                                       the scope of the review, or its publishing, so the file is never allowed whole
#   skills/simplification-audit/skill   the simplicity lens of the review
# This list is the one owner; extract-findings.sh writes `known_rules`, and review-state.sh `known` and its state check
# read `known_eligible`.
def known_allowed_rules: ["rules/php/core-standards", "rules/code-testing/general", "rules/writing/general",
  "rules/code-review/core-analysis", "skills/simplification-audit/skill"];
def known_allowed_sections: {"rules/code-review/general": ["reuse-existing-logic",
  "dry-check-every-new-rule-is-searched-for-in-the-whole-codebase", "test-organization"]};

# The tokens of a field text that name a rule: every backticked span, and every other word, that holds `rules/` or
# `skills/` in any case, also with a backslash or %2f for the slash.
def rule_tokens:
  ([match("`[^`]*`"; "g") | .string | .[1:-1]]
   + [gsub("`[^`]*`"; " ") | splits("[\\s*|,;<>()\\[\\]\"']+")])
  | map(select(test("(rules|skills)([/\\\\]|%2f)"; "i")));

# One reference in canonical form: lowercase, without the leading @ and ./, with // and /./ collapsed, the .md suffix
# dropped, and the anchor as a slug after one #. Null when it is not a path under rules/ or skills/, holds a ..
# segment, or carries a path or an @ in its anchor.
def canon_rule:
  ascii_downcase | sub("^[(\\[<'\"]+"; "") | sub("[.,;:)\\]>'\"!?]+$"; "") | sub("^@"; "")
  | split("#") as $parts
  | $parts[0] as $path | (if ($parts | length) > 1 then $parts[1:] | join("#") else null end) as $anchor
  | ($path | gsub("/+"; "/") | [splits("/")] | map(select(. != ".")) | join("/")) as $p
  | if ($p | test("^(rules|skills)/[a-z0-9_-]+(/[a-z0-9_.-]+)+$") | not) or ($p | split("/") | index([".."])) != null
      or ($anchor != null and ($anchor | test("[/@\\\\]")))
    then null
    else ($p | sub("\\.md$"; "")) + (if $anchor == null then ""
      else ($anchor | gsub("[^a-z0-9]+"; "-") | sub("^-+"; "") | sub("-+$"; "") | if . == "" then "" else "#" + . end) end)
    end;

# One canonical reference is on the allow-list: its file whole, or its file and section.
def known_rule_allowed:
  (split("#")) as [$file, $section]
  | (known_allowed_rules | index([$file])) != null
    or ($section != null and ((known_allowed_sections[$file] // []) | index([$section])) != null);

# A record may be a known point: no security flag, and at least one rule reference, each on the allow-list.
def known_eligible:
  .security == false and (.known_rules | type) == "array" and (.known_rules | length) > 0
  and all(.known_rules[]; type == "string" and known_rule_allowed);
