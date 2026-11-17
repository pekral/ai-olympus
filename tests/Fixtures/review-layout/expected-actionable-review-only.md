<details>
<summary><strong>Actionable comments (5)</strong></summary>
<dl>
<dd>
<br>

**🔴 Critical 1 — An invitation link can be used more than once** · `app/Http/Controllers/InvitationAcceptController.php:22`
The controller finds the invitation by token and never marks it accepted, so a forwarded link adds another member.
**Fix:** accept only an invitation that is not accepted yet, and mark it accepted in the same step.

**🔴 Critical 2 — An expired invitation still lets the colleague in** · `app/Http/Controllers/InvitationAcceptController.php:22`
`expires_at` is written but never read, so a link older than 7 days still adds a member (issue #593, *The rules*).
**Fix:** accept only an invitation whose expiry is still in the future.

**🟠 Moderate 1 — The daily invitation limit has its own counter** · `app/Http/Controllers/Workspace/InvitationController.php:27`
`app/Support/RateLimit/DailyQuota.php:12` already counts a per-user daily quota, and the API entry point skips the new counter.
**Fix:** use the existing daily quota in both entry points, with a limit of 20 invitations per owner a day.

**🟠 Moderate 2 — No test covers a cancelled invitation** · `app/Models/Invitation.php:999`
No test opens the link of a cancelled invitation.
**Fix:** add a feature test that cancels an invitation and then opens its link.
@octocat

**🟠 Moderate 3 — The daily count is computed twice**
The API controller counts the daily invitations a second time.
**Fix:** Use the existing implementation in `app/Support/RateLimit/DailyQuota.php:12` instead of repeating the logic.
@octocat

</dd>
</dl>
</details>