## Security Audit Report - <Project Name>

### Plain Page Load
- `<METHOD> <uri>` — change on load: none | records a visit or a statistic | data changed (what) | return from an external flow (one-time value `file:line`, check `file:line`) — verdict: finding | not a finding (reason) | not verified (step that settles it)
- `Plain page load: not applicable — the diff changes no page that a plain page load opens`

### Route Access
- `<METHOD> <uri>` — reached by: unauthenticated caller | signed-in user | privileged role — evidence: route `file:line`, middleware `file:line`, authorization `file:line` — verdict: finding | public by design (reason) | not verified (step that settles it)
- `Route access: not applicable — the diff changes no route`

### Critical
1. [file:line] Description
   Category (OWASP): ...
   Exploit Scenario: ...
   Recommended Fix: ...
   Faulty Example: one sentence that describes the input, the request, or the state that reproduces the vulnerability, with no code and no runnable payload.
   Expected Behavior: what the application must do instead (rejected input, thrown exception, denied authorization, sanitized output).
   Test Hint: one-sentence outline of the security test (request shape, assertion target, layer).
   Suggested Fix: one plain sentence that says what to change and where, with no snippet and no fenced block (`@rules/code-review/general.md` *A review carries no code*); the change complies with @rules/php/core-standards.md, @rules/security/backend.md, and @rules/laravel/architecture.md on Laravel projects.

### High
- ...

### Medium
- ...

### Low
- ...

> **Faulty Example, Expected Behavior, Test Hint, and Suggested Fix are mandatory for every finding** so `process-code-review` can turn each finding into a regression test and apply the fix from the report.
> - Faulty Example must be a sentence that describes the faulty input or state, with no code and no runnable attacker payload — redact real secrets, tokens, and PII with placeholders.
> - Expected Behavior must be a single assertable security guarantee (rejection, authorization denial, escaped output, no side effect).
> - Test Hint must point at the layer the test belongs in (unit, feature, HTTP) and the entry point to call.
> - Suggested Fix must be one plain sentence that says what closes the vulnerability (no snippet, no fenced block), and the change complies with `@rules/php/core-standards.md`, `@rules/security/backend.md`, and (on Laravel projects) `@rules/laravel/architecture.md`. Use `n/a — <reason>` only when the fix is purely configurational and already described in Recommended Fix.

### Action Items
1. [ ] ...
