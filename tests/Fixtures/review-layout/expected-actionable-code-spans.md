<details>
<summary><strong>Actionable comments (3)</strong></summary>
<dl>
<dd>
<br>

**🔴 Critical 1 — <code>$this-&#62;authorize&#40;&#41;</code> is never called** · <code>app/Http/Controllers/InvitationAcceptController.php&#58;22</code>
<code>App&#92;Http&#92;Controllers&#92;OrderController</code> returns <code>Collection&#60;int, Order&#62;</code> to a guest.
**Fix:** call <code>$this-&#62;authorize&#40;&#41;</code> before the query. An unclosed &#96;tick before &lt;img src=x&gt; stays text.

**🔴 Critical 2 — An expired invitation still lets the colleague in** · <code>app/Http/Controllers/InvitationAcceptController.php&#58;22</code>
<code>expires&#95;at</code> is written but never read, so a link older than 7 days still adds a member (issue #593, *The rules*).
**Fix:** accept only an invitation whose expiry is still in the future.

**🟠 Moderate 1 — The daily invitation limit has its own counter** · <code>app/Http/Controllers/Workspace/InvitationController.php&#58;27</code>
<code>app/Support/RateLimit/DailyQuota.php&#58;12</code> already counts a per-user daily quota, and the API entry point skips the new counter.
**Fix:** use the existing daily quota in both entry points, with a limit of 20 invitations per owner a day.

</dd>
</dl>
</details>