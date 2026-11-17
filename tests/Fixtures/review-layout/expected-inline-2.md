🔴 **Problem:** `expires_at` is written but never read, so a link older than 7 days still adds a member (issue #593, *The rules*).

**Impact:** An expired link keeps working.

**Fix:** accept only an invitation whose expiry is still in the future.

<!-- cr-inline:actor=bot -->
<!-- cr-inline:finding=r-bbbbbbbbbbbbbbbb -->