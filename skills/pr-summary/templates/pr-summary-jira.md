AI Olympus · [Deploy verdict in the assignment language. Name any blocking finding. State whether the change has been merged or deployed.]

{expand:⚠️ Acceptance criteria}
# ✅ [Requirement and the observed result that satisfies it.]
# ⚠️ [Requirement awaiting an answer or verification. State what remains open.]
{expand}

{expand:✅ Findings from the code review}
[When clean, say that no finding needs a change. Otherwise use one typed bullet per finding.]
{expand}

{expand:⚠️ Test scenarios}
# ⚠️ [Precondition. Action. Expected result. Record the observed outcome only when it was verified.]
{expand}

{expand:What changed and what it affects}
* [Observable change and affected feature.]
{expand}

=== END OF COMMENT BODY — NOTHING BELOW THIS LINE IS EVER PUBLISHED ===

TEMPLATE GUIDANCE

Translate the four section titles to the assignment language. In Czech use Akceptační kritéria, Nálezy z kontroly kódu, Testovací scénáře, Co se změnilo a co to ovlivní. Keep the attribution at the start of the verdict. Its presence never proves ownership of a comment.

Use ⚠️ for a failed, blocking or pending item and explain which applies in words. Use ✅ for a verified result. An unreviewed change or an unexecuted test is pending. Omit the icon from the impact heading and from numbered informational criteria. If no criteria were given, say so. Include every stated criterion in a numbered list and mark each one's own status. Keep the actual finding and code-location link together, using the types, ordering and author mentions in references/jira-shape.md. For multiple pull requests follow references/several-pull-requests.md. Give each group a plain-text heading with its PR number, the user-visible change and the acceptance criteria it addresses. Keep the entire heading within 255 Unicode characters.

Separate prerequisites, steps and expected outcomes in plain language. A model scenario must be labelled and never claims a live test. Keep technical instructions below the template boundary. The development panel shows associated pull requests when GitHub for Jira is configured and the real issue key is included in the pull request title.

A JIRA comment has no Clarifying questions section. Put each question with its criterion or finding. Within findings, use h3. Already reported in an earlier review for points carried forward from the technical review.

Give the finding type, then one point that must be resolved before the merge, per bullet, as a TL;DR of its solution: what is wrong, what the fix is, and what the fix changes for the user or the business. Name the evidence in plain words, for example "an order created without a customer fails". Report a defect or open question, never an assumption, a hypothesis, or a point that needs no action. Every such point of the technical review belongs here. A security point that still needs verification and blocks the merge is the first bullet, named as a blocker without disclosing the technical suspicion.

Interactive testing that the project switched off is stated in one sentence (@skills/pr-summary/SKILL.md, What the comment carries). Interactive testing that did not run because no local or test environment was available is never mentioned as completed (@rules/code-review/general.md, What a code review checks, and nothing else). Describe the planned scenario and mark verification pending.

@skills/pr-summary/SKILL.md *Length follows the facts* names JIRA as the target with a 3 000-character visible-text limit. A link counts its label. Shorten the impact wording first, then write each Review findings bullet in two sentences. Retain every finding and criterion.

Before publishing, run php skills/pr-summary/scripts/check-jira-comment.php --criteria <present|none> --author <account-id|none> --repository <owner/name> --pull-request <number> --jira-issue <issue-url> --revision <commit> <source-file> on the rendered source, with the Reviewed revision from the technical report. Publish only an accepted source.
