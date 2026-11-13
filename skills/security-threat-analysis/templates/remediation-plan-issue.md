# Security remediation plan — <subject>

## Security-risk analysis
- **Attack surface**: <the entry points and data flows in scope>
- **Threat 1** — [Critical] <the concrete threat> — affected code: `<file>:<line>`
- **Threat 2** — [Moderate] <the concrete threat> — affected code: `<file>:<line>`

## Remediation plan
<Goal, Architecture, Implementation steps, and Sources, rendered from skills/analyze-problem/templates/analysis-report.md.>

## Success criteria
- [ ] [Critical] <one independently verifiable security invariant, checkable against the diff>
- [ ] [Moderate] <one independently verifiable security invariant, checkable against the diff>

<!-- Success criteria is a GFM task list, unchecked, one criterion per item, each prefixed with the severity of its finding. Never reflow it into prose. -->
<!-- Public repository: apply the disclosure guard of agents/leonardo.md Security analysis mode step 4 before publishing. It covers the Security-risk analysis section and the Success criteria. An unfixed Critical is never published by rewording alone. -->
<!-- Published by leonardo through skills/_shared/audit-trail.sh run -- gh issue create. -->
