## TL;DR
<the number of Critical findings, and what the implementation changes because of them, in one or two sentences>

### Critical findings
1. **<short title>** — found by <review | the second reviewing agent>
   - **Reason:** <why the proposal cannot stand as written>
   - **Correction:** <how `## Proposed solution` changed>

=== END OF COMMENT BODY — NOTHING BELOW THIS LINE IS EVER PUBLISHED ===

TEMPLATE GUIDANCE. The published text ends at the line above. The intermediate source of JIRA and the plain text of Bugsnag have no comment syntax, so this guidance sits below the marker instead of inside the body.

- Published by command as one new comment in the proposal-review marker namespace with --create on GitHub and JIRA, and without a namespace on Bugsnag.
- When no Critical finding exists, publish nothing. Apply the Disclosure bullet of the command contract step 6: no finding about an unfixed vulnerability of existing code, marked or not, is in this comment.
- A JIRA ticket takes the non-technical reader shape of rules/reports/general.md: no file name, no code, no SHA.
