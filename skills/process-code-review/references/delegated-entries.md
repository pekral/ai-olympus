# Delegated entries are not this run's work

Extracted word for word from `skills/process-code-review/SKILL.md` step *Build the checklist*. **Loading trigger:** the CR carries a `## Delegated to another person` section. In the text below, *item 2 above* means the *Unresolved reviewer threads* item of that checklist.

A CR's `## Delegated to another person` section (`@skills/code-review-github/references/cr-wrapper-contract.md` *Delegation of a reviewer comment to another account*) lists reviewer comments a trusted reviewer addressed to another account. Do **not** add such a comment to the checklist, even though its review thread is still unresolved and item 2 above would otherwise pick it up. Do not extract a reproducer for it, do not generate a fix commit for it, and do not file a follow-up issue for it — the instruction already lives on the pull request, where the account it names reads it. Its thread stays unresolved, because nothing here fixed it.
