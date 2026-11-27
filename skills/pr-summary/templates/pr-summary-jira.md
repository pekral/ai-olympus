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

Use ⚠️ for a failed, blocking or pending item and explain which applies in words. Use ✅ for a verified result. An unreviewed change or an unexecuted test is pending. Omit the icon from the impact heading and from numbered informational criteria. If no criteria were given, say so. Include every stated criterion in a numbered list and mark each one's own status. Keep the actual finding and code-location link together, using the types, ordering and author mentions in references/jira-shape.md. For multiple pull requests follow references/several-pull-requests.md.

Separate prerequisites, steps and expected outcomes in plain language. A model scenario must be labelled and never claims a live test. Keep technical instructions below the template boundary. The development panel shows associated pull requests when GitHub for Jira is configured and the real issue key is included in the pull request title.
