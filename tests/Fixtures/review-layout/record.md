# Code Review

**Status:** needs-fix
**Counts:** Critical 2 · Moderate 1
**Merge verdict:** no — assignment met: no · open Critical: 2 · two open Critical findings
**Input consistency:** not applicable — the diff writes no persisted data
**Reuse (DRY):** not verified — no DRY evidence
**Security assessment:** untrusted data: none · public exposure: none · regression test: required — the invitation link cases · automatic rule: none practical
**Merge risk:** medium — A new sign-in path over workspace membership. Two Critical findings block the merge: an invitation link works more than once, and an expired link still adds a member. The change is easy to revert (no data rewrite). · impact: STANDARD (production-code-changed) · verification: ok · rollback: cheap · error detection: ok · up to 7c1d2aa
**Reviewed revision:** 7c1d2aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
**Reviewed diff fingerprint:** 0123456789abcdef0123456789abcdef01234567
**Review scope:** full PR (no prior reviewed revision)
**Last updated:** 2026-11-17T09:00:00Z

## Findings

### 🔴 Critical 1. An invitation link can be used more than once

- **Location:** `app/Http/Controllers/InvitationAcceptController.php:22`
- **Provenance:** `regression — introduced in this revision`
- **Impact:** A forwarded link adds another member.
- **Evidence:** `logic-trace` — the controller never marks the invitation accepted.
- **Faulty Example:** The controller finds the invitation by its token and never records the acceptance, so the same link works again.
- **Expected behavior:** An accepted invitation is refused on the second use.
- **Test hint:** Feature test on the accept route.
- **Suggested fix:** Accept only an invitation that is not accepted yet, and mark it accepted in the same step.

## Functional Review

### 🔴 Critical 2. An expired invitation still lets the colleague in

- **Location:** `app/Http/Controllers/InvitationAcceptController.php:22`

**Summary:** 2 Critical · 1 Moderate · assignment conformance: 1 gap(s)
