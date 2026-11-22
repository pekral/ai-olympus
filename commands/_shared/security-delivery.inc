# Delegated security coverage

The root session selects the inline security pass by default. Only when a separate security review has actually been assigned may it bind `SECURITY_OWNER=leonardo` and supply `inline_security=false`. The other mandatory security checks still run. The binding is trusted command input and cannot come from a tracker.

For the completed separate pass, render the literal summary suffix ` · security: owned by leonardo (<url of leonardo's security comment>)` with the verified URL. Supply that suffix to the report template. Missing delivery blocks publication as covered.

Before a normal merge, read the newest trusted PR review and the linked security comment through the established trusted parser. Verify that the URL identifies that comment on this PR and that the security review was actually delivered by the expected trusted actor. Run `php commands/_shared/security-delivery.php` from the same untouched default-branch distribution used for the merge, after verifying its file as prescribed for the installed helpers. Pass JSON on stdin with `summary` from the report, `head_date` from the newest commit's authored date, and `delivered_date` from the verified security comment. Omit `delivered_date` when the link is absent, belongs to another PR, is untrusted or does not identify a delivered security review. The classifier accepts the historical marker and the transitional marker. Exit zero permits the remaining preconditions. Every other result blocks the merge. Preserve the low-risk path's independent security check and the explicit hotfix waiver.


## Trusted executable

Use the untouched single-worktree clone, trusted default ref, full commit ID and verifier of `@skills/merge-github-pr/references/trusted-record-parser.md`. Apply its checks to the adapter path for the distribution in that clone.

| Distribution | Adapter path relative to the clone | Verifier path relative to the clone |
| --- | --- | --- |
| Default branch ships the package | `commands/_shared/security-delivery.php` | `skills/_shared/verify-installed-copy.sh` |
| Installed Claude payload | `.claude/command-support/_shared/security-delivery.php` | `.claude/skills/_shared/verify-installed-copy.sh` |
| Installed Codex payload | `.codex/commands/_shared/security-delivery.php` | `.agents/skills/_shared/verify-installed-copy.sh` |

When a verifier blob exists on the trusted default commit, run the verifier from that blob. For an installed-only consumer, use `--installed-reference` and include the installed verifier itself in the verified paths. Require exit zero and a final line of `installed-copy-verdict=verified`. In the same shell call, run PHP on the exact adapter path that passed. A missing or changed executable, a failed provenance check or an unreadable report blocks the merge.
