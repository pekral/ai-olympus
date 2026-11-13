# Security Threat Analysis — <Threat Title>

## Source
- **URL / Identifier**: <link or CVE/GHSA id>
- **Published**: <date>
- **Patched in**: <upstream fixed version(s)>

## Summary (non-technical)
<One short paragraph in plain language: what the threat is, why it matters, and what happens if it is not fixed. No code, no jargon.>

## Classification
- **Category (OWASP / CWE)**: <e.g. A03:2021 / CWE-89>
- **Severity**: Critical | High | Medium | Low
- **CVSS**: <score if published>
- **Attack vector**: <network/local/…>, <authenticated/unauthenticated>
- **Preconditions**: <feature, role, configuration>

## Project Exposure
- **Status**: Affected | Not affected | Indeterminate
- **Evidence**:
  - `<file>:<line>` — <what was found>
  - `<dependency>@<version>` — <vulnerable range comparison>

## Remediation Plan
1. <Exact action>
   - **Target**: <file or command>
   - **Change**: <minimal diff or command>
   - **Why**: <one sentence linking the step to the threat>

## Verification
- **Manual check**: <request, screen, or log line that confirms the fix>
- **Automated test**: <test name and assertion that fails before the fix and passes after>
- **Coverage note**: <which changed code paths must reach 100 %>

## Residual Risks and Follow-ups
- <anything the upstream fix does not cover, or No residual risk identified.>

<!-- Every section is filled. A section with nothing to report carries a short explicit note. -->
<!-- If the status is Not affected, the Project Exposure section states which check disproved exposure. -->
<!-- Never put an exploit payload ready for live attack, a secret, or personal data in the report. -->
