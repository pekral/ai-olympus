<details>
<summary><strong>Actionable comments (3)</strong></summary>
<dl>
<dd>
<br>

**🔴 Critical 1 — `$this->authorize()` is never called** · `app/Http/Controllers/InvitationAcceptController.php:22`
`App\Http\Controllers\OrderController` returns `Collection<int, Order>` to a guest.
**Fix:** call `$this->authorize()` before the query. An unclosed &#96;tick before &lt;img src=x&gt; stays text.

**🔴 Critical 2 — An expired invitation still lets the colleague in** · `app/Http/Controllers/InvitationAcceptController.php:22`
`expires_at` is written but never read, so a link older than 7 days still adds a member (issue #593, *The rules*).
**Fix:** accept only an invitation whose expiry is still in the future.

**🟠 Moderate 1 — The daily invitation limit has its own counter** · `app/Http/Controllers/Workspace/InvitationController.php:27`
`app/Support/RateLimit/DailyQuota.php:12` already counts a per-user daily quota, and the API entry point skips the new counter.
**Fix:** use the existing daily quota in both entry points, with a limit of 20 invitations per owner a day.

</dd>
</dl>
</details>