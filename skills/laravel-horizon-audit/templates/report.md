# Laravel Horizon audit

**Project:** {{project}}
**Environment:** {{environment}}
**Laravel / Horizon:** {{laravel version}} / {{horizon version, or "not verified: <reason>"}}
**Mode:** {{static|runtime|recommendations}}
**Overall result:** {{PASS|WARN|FAIL|NOT_APPLICABLE|UNVERIFIED}}

## Summary
{{the largest risks, then what stays unverified}}

## Findings
Order: severity, then rule id, then file path, then line. A `PASS` shows as one line each at the end.

### {{HZ-002}} — {{title}}
- **Severity:** {{CRITICAL|HIGH|MEDIUM|LOW|INFO}}
- **Status:** {{PASS|FAIL|WARN|UNVERIFIED|NOT_APPLICABLE}}
- **Evidence basis:** {{read|computed|not_verified}}
- **Evidence:** {{path:line and the redacted, non-secret value; the computed condition}}
- **Impact:** {{practical effect}}
- **Recommendation:** {{the smallest-risk change, and how to roll it back}}
- **Verification:** {{the test or measurement that confirms the fix, or the step that settles an UNVERIFIED point}}

## Queue and worker map
| Consumer | Source | Connection | Driver | Redis connection | Database | Prefix | Queue | Environment |
|----------|--------|------------|--------|------------------|----------|--------|-------|-------------|
| {{consumer}} | {{file:line}} | {{name}} | {{driver}} | {{name or unknown}} | {{n or unknown}} | {{prefix or unknown}} | {{queue}} | {{environment}} |

## Recommended regression tests
{{sketches that stop the findings from returning; propose_tests=false omits this section}}

## Missing information
{{what the audit cannot settle without runtime access or supplied data, one line each with the step that settles it}}

## Next steps
{{ordered by impact and risk}}
