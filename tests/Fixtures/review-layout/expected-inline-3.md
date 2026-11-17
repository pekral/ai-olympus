🟠 **Problem:** `app/Support/RateLimit/DailyQuota.php:12` already counts a per-user daily quota, and the API entry point skips the new counter.

**Impact:** The two entry points count differently.

**Fix:** use the existing daily quota in both entry points, with a limit of 20 invitations per owner a day.

<!-- cr-inline:actor=bot -->
<!-- cr-inline:finding=r-cccccccccccccccc -->