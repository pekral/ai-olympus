# API Design Review — <Project / PR>

## Critical
1. [file:line] Description
   Rule: @rules/api/general.md#<section>
   Risk/Impact: consumer-facing consequence (double-charge, wrong status branch, broken payload shape, bypassed validation).
   Faulty Example: one sentence that describes the route, the request, or the payload that reproduces the issue, with no code.
   Expected Behavior: single assertable statement (status code, response shape, idempotent outcome, rejection before side effect).
   Test Hint: one sentence — test layer (feature/HTTP, integration) and entry point.
   Suggested Fix: one plain sentence that says what to change and where, with no snippet and no fenced block (`@rules/code-review/general.md` *A review carries no code*); the change complies with @rules/api/general.md, @rules/php/core-standards.md, and @rules/laravel/architecture.md on Laravel projects.

## Moderate
- ... (same fields as Critical)

> Omit any section that has no findings. Faulty Example, Expected Behavior, Test Hint, and Suggested Fix are mandatory for every Critical and Moderate finding so `@skills/process-code-review/SKILL.md` can turn each into a reproducer test and apply the fix.
