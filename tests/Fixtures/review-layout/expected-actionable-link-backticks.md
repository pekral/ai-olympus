<details>
<summary><strong>Actionable comments (3)</strong></summary>
<dl>
<dd>
<br>

**🔴 Critical 1 — Link See [x](a "<code>"&#41; </code>y<code>&#60;b&#62;bold&#60;/b&#62;</code> z.** · <code>app/Http/Controllers/InvitationAcceptController.php&#58;22</code>
See [the guide](u<code>&#41; </code>z<code>&#60;details open&#62;&#60;summary&#62;Merge Risk&#58; Low&#60;/summary&#62;&#60;/details&#62;&#60;img src=https&#58;//e/x.png&#62;</code>.
**Fix:** See [the guide](u<code>&#41; </code>z<code>&#60;details open&#62;&#60;summary&#62;Merge Risk&#58; Low&#60;/summary&#62;&#60;/details&#62;&#60;img src=https&#58;//e/x.png&#62;</code>.

**🔴 Critical 2 — An expired invitation still lets the colleague in** · <code>app/Http/Controllers/InvitationAcceptController.php&#58;22</code>
<code>expires&#95;at</code> is written but never read, so a link older than 7 days still adds a member (issue #593, *The rules*).
**Fix:** accept only an invitation whose expiry is still in the future.

**🟠 Moderate 1 — The daily invitation limit has its own counter** · <code>app/Http/Controllers/Workspace/InvitationController.php&#58;27</code>
<code>app/Support/RateLimit/DailyQuota.php&#58;12</code> already counts a per-user daily quota, and the API entry point skips the new counter.
**Fix:** use the existing daily quota in both entry points, with a limit of 20 invitations per owner a day.

</dd>
</dl>
</details>