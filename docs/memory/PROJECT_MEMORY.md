# Project memory — ai-olympus

### auto-mode-external-write-blocked — Publish/comment writes can be silently blocked in auto-mode environments
- Trigger: an agent (`hephaestus`/`athena`) publishes a comment (`pr-summary`, `upsert-comment.sh`, or a technical CR comment) to a GitHub/JIRA issue or PR under auto-mode write classification.
- Rule:    In auto-mode, the external-write classifier can silently block a comment/publish write — no error, the comment never appears. Non-deterministic and target-dependent: a state-transition write (`gh pr ready`) can succeed while a comment POST is denied in the same run; the same write can be denied on one target but succeed on another; a plain retry can succeed later. Always verify the publish landed via the deterministic loader (the tracker URL, not the handoff/exit code). Retry once before dispatching a downstream step with a hard gate on it (e.g. `merge-github-pr`'s PR-comment gate). Document as `Blocked: external-write blocked by auto-mode classifier`.
- Example: Issue #629: `pr-summary` mirror blocked, posted manually. Recurrence PR #47/issue #41: CR comment POST denied while `gh pr ready` and `apollon`'s mirror to #41 succeeded; `merge-github-pr` correctly Blocked; a bare retry of the POST then succeeded (0→1).
- Source:  https://github.com/pekral/ai-olympus/pull/636   Added: 2026-06-20   Updated: 2026-07-13 (PR #47)
- Role:    shared

### agent-file-vs-registration — Adding agents/<name>.md does not make the agent dispatchable
- Trigger: daedalus tries to dispatch a newly documented agent via the Task tool, assuming a new `agents/<name>.md` file is immediately executable.
- Rule:    `agents/<name>.md` is documentation only — the agent type must also be installed/registered (installer syncs into `.claude/`) before it is dispatchable. Until then, fall back to registered agents or treat the step as blocked. Document the dependency in the agent's own file and the introducing issue.
- Example: `agents/apollon.md` added in #628, daedalus correctly deferred to `talos`/`argos`. Update #654: `apollon` now registered — point-in-time, see [[verify-agent-registration-premise]].
- Source:  https://github.com/pekral/ai-olympus/pull/633   Added: 2026-06-20
- Role:    daedalus

### parallel-agent-publication-contract — Parallel-dispatched agents must route findings through the shared brief, not publish directly
- Trigger: a new CR/review agent daedalus dispatches in parallel with another (e.g. `athena` alongside `argos`) publishes findings via raw `gh pr comment`/`gh issue comment`.
- Rule:    A parallel-dispatched agent must hand off findings via the shared brief so the consolidating agent (e.g. `argos`) publishes one report. Direct publication is allowed only in standalone mode, and even then only via the canonical `upsert-comment.sh` wrapper — never raw `gh pr comment`/`gh issue comment`, which breaks consolidation and duplicates comment threads.
- Example: `agents/athena.md` step 5 used `gh pr comment` directly; argos flagged Moderate in PR #638 (`82abc16`); fixed to hand off via brief / `upsert-comment.sh` standalone.
- Source:  https://github.com/pekral/ai-olympus/pull/638   Added: 2026-06-20
- Role:    daedalus

### agent-new-mode-status-result-parity — A new agent run-mode needs both Status and Result updated in the handoff section
- Trigger: a new run-mode/output branch is added to an agent (e.g. a `Decomposition done` path); `Result:` is updated but `Status:` is left unchanged.
- Rule:    Every new run-mode must appear in both `Status:` and `Result:` in the agent's *Output — handoff* section, consistent with every cross-file peer (e.g. `daedalus.md` ↔ `hephaestus.md`); update all affected files atomically. A missing `Status` value is an incomplete contract the CR loop flags as Moderate. Same parity for rule ↔ skill: when a skill defines a sanctioned exception to a `rules/**` mandate, the rule must name that exception inline (the "Bugsnag has no auto-claim" precedent) — an unqualified absolute the skill legitimately violates is the same Moderate inconsistency.
- Example: `agents/daedalus.md` *Output — handoff* omitted `Decomposition done` vs the peer analysis-agent + issue #639 step 4; argos caught Moderate in PR #640 iteration 1 (`392203d`). Recurrence PR #23: *File deferred points…* rule demanded filing with no exception vs `resolve-issue`'s PR opt-out; fixed by naming the exception (`57bb49c`).
- Source:  https://github.com/pekral/ai-olympus/pull/640   Added: 2026-06-20   Updated: 2026-07-11 (PR #23)
- Role:    hephaestus

### cr-rule-severity-collision — A new CR rule for an antipattern an existing rule already covers at a different severity needs gating
- Trigger: a PR adds a new detection bullet (e.g. Moderate) for an antipattern an existing bullet already covers at a different severity, with no dedup/gating clause.
- Rule:    Apply the dedup pattern from `skills/code-review/SKILL.md` "Inline validation guards" — one finding per violation, never both. Gate the two bullets with mutually exclusive conditions in every file carrying either half. They collide only when they can fire on the *same* line — mentally place one code line under both; if it doesn't match both, no collision. Extends to 3-way: an explicit "never raise two of these three on the same line" clause keeps three mutually exclusive.
- Example: PR #646 added ungated Moderate bullets (`skills/code-review/SKILL.md`~115, `rules/laravel/architecture.mdc`~279) duplicating Critical bullets; fixed `2b1ebe4` with symmetric gating. Counter-example: PR #703's `SomeData::from($request)` bullet vs an existing Critical "Inline data mapping" bullet — no collision, 0 findings. 3-way: issue #55/PR #73 added a third storage bullet beside an existing gated pair (issue #38); same test + clause, argos+athena converged 0/0.
- Source:  https://github.com/pekral/ai-olympus/pull/646   Added: 2026-06-20   Updated: 2026-07-19 (PR #73)
- Role:    hephaestus

### agent-rename-sync-points — Renaming an agent must sync its pinned and derived points too
- Trigger: an agent is renamed and the author updates the obvious files (`agents/<name>.md`, `docs/agents.md`, `README.md`) but forgets a pinned or derived sync point.
- Rule:    (1) `git mv` `agents/<old>.md` + `assets/agents/<old>.png`, rewrite `name:`/prose/handoff; (2) `tests/InstallerTest.php` pins prose verbatim — byte-identical or the build fails; (3) grep all case variants, confirm 0; (4) redirect any `docs/agents.md` reservation of the new name. Two are **derived from `agents/*.md` at test time**, so a grep for the old name misses them: `assets/social-preview.svg` (a longer name needs a wider chip **and** the row re-centred on x=640, then `rsvg-convert -w 1280 -h 640` + both digests in `SocialPreviewAssetTest`) and `.github/ISSUE_TEMPLATE/feature_request.yml`. A third, `src/AgentBashBoundaryPolicy.php`, was removed with the bash-guard in #265. Rewrite etymology by hand; a mechanical replace picks the wrong myth. `CHANGELOG.md` and dated `Example:` lines stay history; re-point only live `Trigger:`/`Rule:`/`Role:`.
- Example: PR #647 `keryx` → `hermes`: pinned `tests/InstallerTest.php` phrases rewritten in lockstep with `agents/hermes.md`, the `hermes` reservation redirected to `iris`, 0/0/0. Recurrence PR #237 (#231) `talos` → `hephaestus`: the three derived points were the whole cost; 34 files, 0/0/0.
- Source:  https://github.com/pekral/ai-olympus/pull/647   Added: 2026-06-20   Updated: 2026-08-11 (PR #237)
- Role:    hephaestus

### verify-agent-registration-premise — Verify an agent's registration status against the live roster before relying on a recorded premise about it
- Trigger: a task generalizes across "all agents" (per-role parity, a push-level gate, a dispatch decision) and leans on a recorded premise about whether a specific agent is registered/dispatchable.
- Rule:    Registration status is point-in-time, not permanent. Before implementing anything scoped to "every agent", verify against current state: `ls .claude/agents/` (and `agents/`) plus a grep of `tests/InstallerTest.php` for the agent set — treat the live roster as source of truth over memory/issue text. A stale premise produces incomplete parity the CR loop flags as Moderate.
- Example: #653/#654 — `apollon` assumed documentation-only (per [[agent-file-vs-registration]]), already registered; per-role parity missed, 2 Moderate, fixed `43b6c07`. Recurrence (#109): #116 `CLOSED`/`COMPLETED` while `gh repo view` returned `isPrivate: true` — a closed issue records intent, not outcome.
- Role:    shared
- Source:  https://github.com/pekral/ai-olympus/pull/654, /pull/271   Added: 2026-06-21   Updated: 2026-08-18 (PR #271)

### installer-security-doc-source-of-truth — Security docs must list unconditional installer writes, not only opt-in-gated ones
- Trigger: writing/reviewing security/trust-model docs (SECURITY.md, README CLI Switches, installer trust model) for a PHP Composer installer + ComposerPlugin, describing which files the installer writes.
- Rule:    Source of truth is `src/Installer.php`, `src/InstallerClaudeSettings.php`, `src/ComposerPlugin.php` — not the CLI flag list (installer targets Claude Code only since issue #16 — the `--editor` flag was removed in PR #33). Name explicitly: (1) `Installer::install()` unconditionally calls `applyCoAuthoredByPreference()`, writing `includeCoAuthoredBy: false` into `~/.claude/settings.json` when absent (never overwrites); (2) `allow-plugins: true` in `composer.json` unlocks `ComposerPlugin`'s `post-install-cmd`/`post-update-cmd` hook, which (when `extra.ai-olympus.auto-install: true`) runs the installer with `--force` on every `composer install`/`update` — an ungated code-execution surface. Omitting either lets CR flag Critical (home-dir write) or Moderate (auto-install hook).
- Example: PR #672 (issue #664) SECURITY.md gated all home-dir writes behind opt-in flags; argos found the unconditional `includeCoAuthoredBy` write (Critical) and `allow-plugins` hook (Moderate) missing. Refs: `src/Installer.php:91`, `src/InstallerClaudeSettings.php:60-95`, `src/ComposerPlugin.php:43-69`.
- Source:  https://github.com/pekral/ai-olympus/pull/672   Added: 2026-06-22
- Role:    shared

### skills-tree-verbatim-distribution — Any file under skills/ ships verbatim to consumer trees via the installer
- Trigger: a PR adds a non-`SKILL.md` file under `skills/` (dataset, fixture, payload, README), especially one parseable/executable by a runtime (`.php` with `<?php`, `.sh` with a shebang).
- Rule:    `src/Installer.php` copies the entire `skills/` subtree verbatim into `.claude/skills/` and `~/.claude/skills/` with no extension filter (Cursor/Codex targets — `.cursor/skills/`, `.codex/skills/` — removed in PR #33/issue #16). Every file added under `skills/` ships to every consumer. Dataset/fixture payloads must be INERT: no `<?php` tag, no shebang, no executable syntax — represent payloads as plain text (e.g. `[php-open-tag] echo "xss"; [php-close-tag]`). Add a regression assertion in `tests/Installer/SecurityContentTest.php` that the dataset dir contains no PHP open tags (or equivalent runtime entry point) whenever a new executable-syntax category is introduced.
- Example: `skills/security-review/datasets/malicious-uploads/mime-double-extension/evil.php.jpg` contained a real `<?php … ?>` block passing `php -l`; argos caught Moderate in PR #685 iteration 1; fix `a940708` neutered it to plain text + regression guard (`no dataset file in malicious-uploads/ contains a PHP open tag`) in `tests/Installer/SecurityContentTest.php`. Dataset inertness is declared in per-directory READMEs and per-file INERT headers.
- Source:  https://github.com/pekral/ai-olympus/pull/685   Added: 2026-06-22
- Role:    shared

### os-branch-coverage-ignore-test-via-observable-api — Test OS-gated branches wrapped in @codeCoverageIgnore via observable behaviour, not by rewriting PHP_OS
- Trigger: a new OS-conditional branch in `src/Installer.php` is wrapped in `@codeCoverageIgnoreStart/End` because the deciding value (`PHP_OS`) cannot be overwritten in a test, but the task requires a smoke test without breaking `--min=100`.
- Rule:    Do not rewrite `PHP_OS`, inject a fake OS parameter, or use runkit/uopz. Instead: (1) leave the branch `@codeCoverageIgnore`; (2) test the public API (`Installer::run`) for observable behaviour; (3) use `installerSymlinkUnsupported()` (`tests/Pest.php:65`) as a gate — assert copy-fallback (`is_link === false`) on Windows-like hosts, real symlink (`is_link === true`) elsewhere. Never leave a branch-conditional test with an empty assertion.
- Example: `tests/InstallerTest.php` "install creates regular files... when symlinks are unsupported" (#665); `tests/Pest.php:65` helper; `src/Installer.php:351-360` `canSymlink()` Windows branch.
- Source:  https://github.com/pekral/ai-olympus/pull/673   Added: 2026-06-22
- Role:    hephaestus

### cross-cutting-rule-belongs-in-compound-engineering — A cross-cutting contract for all agents and skills belongs in rules/compound-engineering/general.mdc, not in skills/ or per-agent copy-paste
- Trigger: a new rule/contract must apply to every agent and skill uniformly, and the implementer considers a new file under `skills/`, copy-pasting into each `agents/*.md`, or a new standalone rule file.
- Rule:    Place it in `rules/compound-engineering/general.mdc` (`alwaysApply: true`, globs `*`) — the project's single source of truth for cross-cutting contracts. Do not add to `skills/` — `src/Installer.php` distributes it verbatim (see [[skills-tree-verbatim-distribution]]); do not copy-paste into each `agents/*.md` — a one-liner reference per agent is sufficient. Add a pinning assertion in `tests/Installer/CompoundEngineeringContentTest.php`.
- Example: issue #694 hygiene contract added as `## Temporary-file hygiene` (PR #697) with a one-sentence reference per agent + pinning test; converged 0 CR findings iteration 1.
- Source:  https://github.com/pekral/ai-olympus/pull/697   Added: 2026-06-23
- Role:    shared

### agent-shared-task-brief-section-append-only — Adding text to Shared task brief sections in agents/*.md must not overwrite or reorder pinned phrases
- Trigger: a task adds sentences to the *Shared task brief* (or other prose) section of every `agents/*.md`, and the implementer edits freely without knowing which phrases are pinned verbatim.
- Rule:    Before editing an `agents/*.md` section, grep `tests/Installer/AgentsTest.php` and `CompoundEngineeringContentTest.php` for the heading and surrounding prose to find pinned phrases. Append new sentences at the end (or an unpinned position); never reorder, split pinned paragraphs, or reword pinned lines. Run `composer build` after — a `toContain` failure pinpoints the broken phrase.
- Example: PR #697 added a one-sentence hygiene reference to 7 agent files; `composer build` passed (295/295, 100%) because each sentence was appended without reordering.
- Source:  https://github.com/pekral/ai-olympus/pull/697   Added: 2026-06-23
- Role:    hephaestus

### skills-tree-convention-removal-grep-full-tree — Removing a shared convention across skills/ needs a full-tree grep, not just named files
- Trigger: a task removes/renames a shared convention (marker text, function name, section title, anchor pattern) referenced across `skills/`, and the implementer updates only the explicitly named files.
- Rule:    Before the PR, `grep -r '<pattern>' skills/` across the whole tree — including verbatim-distributed templates (`skills/code-review/templates/`), cross-skill SKILL.md files, helper scripts. A file still mentioning it is a live artifact `src/Installer.php` ships — likely Moderate. Pin the absence with `not->toContain(...)` in the relevant installer content test. When the removed skill is a delegation target, inline its contract into the agent file in the same commit, not just delete the pointer.
- Example: PR #700 removed `{anchor:cr-comment-actor-<slug>}` from 3 SKILL.md files but missed 2 refs (`skills/code-review/templates/review-output.md`, `skills/process-code-review/SKILL.md`) — 2 Moderate, fixed `197a442`, pinned in `tests/Installer/CodeReviewContentTest.php`. PR #7 (issue #6, 13 skills removed) reconfirmed: full-tree grep found 3 more refs (`skills/product-capability/SKILL.md`, `skills/resolve-issue/SKILL.md`, `skills/skill-creator/SKILL.md`); 3 agents (`agents/hermes.md`→`article-writing`, `agents/apollon.md`→`test-like-human`, `agents/daedalus.md`→`autoresolve-oldest-github-issue`) needed inlined behavior, pinned via `not->toContain('test-like-human')`.
- Source:  https://github.com/pekral/ai-olympus/pull/700   Added: 2026-06-23   Updated: 2026-07-01 (PR #7)
- Role:    hephaestus

### laravel-rules-tracked-source — The canonical tracked source of Laravel rules is rules/laravel/architecture.mdc at the repo root, not .claude/rules/
- Trigger: a task adds/modifies a Laravel CR rule (detection bullet, severity, convention text) and the implementer looks for the file to edit.
- Rule:    Canonical, git-tracked source is `rules/laravel/architecture.mdc` (repo root). `.claude/rules/laravel/architecture.mdc` is git-ignored, installer-generated — editing it changes nothing. Always edit the root file. Any new phrase must be byte-identically pinned (via `toContain()`) in `tests/Installer/LaravelRulesContentTest.php`; a companion `skills/code-review/SKILL.md` bullet is pinned in `tests/Installer/CodeReviewContentTest.php`. Run `composer build` before opening the PR.
- Example: PR #703 (issue #698) — gather step confirmed the tracked file since both paths had identical content. 4 byte-identical phrases pinned in each test file; all passed the first `composer build` run.
- Source:  https://github.com/pekral/ai-olympus/pull/703   Added: 2026-06-23
- Role:    hephaestus

### post-convergence-comment-publish-needs-explicit-scope — Posting the feedback comment to the source tracker is blocked when the user only asked to "report back"
- Trigger: a full-delivery run reaches post-convergence reporting (step 6a) and dispatches `hephaestus` in reporting mode (`pr-summary`) to publish a "Hotovo" comment on the source issue/PR.
- Rule:    Publishing an external comment under the user's identity is a separate consent surface from resolving+merging. When the request says only "report back" (to the user) without asking to post on the tracker, the auto-mode classifier denies the publish. Fall back to the in-chat summary and re-dispatch `hephaestus` for the final scoped validation only, carrying the How-to-test summary into the final report yourself. Don't retry the publish.
- Example: gh-699 run; `apollon` dispatch denied: "[External System Writes] ... user only asked to report back ... not to post on the issue".
- Source:  https://github.com/pekral/ai-olympus/pull/702   Added: 2026-06-23
- Role:    daedalus

### per-tracker-claim-belongs-in-resolve-issue-and-selection — A claim mechanism needs an idempotent abort-on-conflict claim AND a selection-exclusion filter
- Trigger: a task asks to mark a tracker issue "In progress"/claimed at work-start so two AI agents don't pick the same task in parallel; the naive implementation only sets a status.
- Rule:    A claim alone doesn't prevent the collision. The guard is two-sided: (1) the claim step is idempotent, apply-and-verify (re-read, never trust the write exit code — [[auto-mode-external-write-blocked]]), and ABORTs if already claimed; (2) the selection step EXCLUDEs already-claimed issues. GitHub: claim label (`Resolve_by_AI:in-progress`) + `-label:"${CLAIM_LABEL}"` negation in the issue-selection query (today `agents/daedalus.md` step 1). JIRA: a second sanctioned transition helper (clone of `transition-to-code-review.sh`). Bugsnag stays hands-off. Release the claim on Blocked/abort before the PR opens; keep it on success.
- Example: issue #704/PR #706 — `rules/compound-engineering/general.mdc` gained *Claim a tracker issue…*; `skills/code-review-jira/scripts/transition-to-in-progress.sh` (new); `skills/resolve-issue/SKILL.md` plus the then-existing `autoresolve-oldest-github-issue` skill (removed from the repo since, no longer supported) updated. Converged argos+athena 0/0/0 iteration 1.
- Source:  https://github.com/pekral/ai-olympus/pull/706   Added: 2026-06-23
- Role:    shared

### second-sanctioned-jira-transition-clones-the-first — A new sanctioned JIRA transition helper should clone the existing one verbatim rather than extract a shared lib
- Trigger: adding a second auto-allowed JIRA status transition (e.g. an "In Progress" claim alongside "Code Review"), tempted to extract shared logic into a sourced `lib.sh`.
- Rule:    Keep each transition helper self-contained, mirroring `transition-to-code-review.sh` (anchored KEY regex, name guard, idempotent no-op, acli false-positive re-verify). Do NOT extract a sourced `lib.sh` — `src/Installer.php` distributes `skills/` verbatim ([[skills-tree-verbatim-distribution]]), breaking the self-contained convention. Update `rules/jira/general.mdc` to enumerate BOTH sanctioned transitions ("two exceptions") — the old "single sanctioned transition" wording is now wrong.
- Source:  https://github.com/pekral/ai-olympus/pull/706   Added: 2026-06-23
- Role:    hephaestus

### claim-mechanism-converges-clean-when-it-mirrors-an-existing-pattern — daedalus: a feature that mirrors an already-reviewed sibling pattern converges in one CR iteration
- Trigger: orchestrating a feature whose core artifact is structurally near-identical to an existing, already-reviewed artifact (a new JIRA transition helper cloning an existing one; a claim label mirroring `ready for review`).
- Rule:    Settle the design first when the *mechanism* is ambiguous (which signal, where the contract lives) even if the *code* is a clone — the ambiguity is in the design, not the implementation. Once fixed, implementation is low-risk and argos+athena converge in iteration 1. Scope a similar "claim/status/follow-up" request as design-then-clone, not net-new high-risk work.
- Source:  https://github.com/pekral/ai-olympus/pull/706   Added: 2026-06-23
- Role:    daedalus

### github-sub-issues-only-via-graphql — GitHub native sub-issues are reachable only through GraphQL, not `gh ... --json`
- Trigger: extending `skills/code-review-github/scripts/load-issue.sh` (or any GitHub loader) to read native sub-issues/parent-child relations.
- Rule:    `gh issue view --json subIssues` fails (`Unknown JSON field`) — the `gh` CLI doesn't expose the relation. Fetch via `gh api graphql` against `repository.issue.subIssues` (`subIssues(first:N){ nodes{...} }`; REST `/issues/{n}/sub_issues` also works). Bind owner/repo/number as GraphQL variables with `-F`/`-f` — never string-interpolate the query. Sub-issues exist on issues only (`issue(number)` is null for a PR), gate on `kind == "issue"`, default `[]` on failure. JIRA exposes `subtasks` shallowly via `acli ... view --fields '*all'`; full body/comments/attachments need one extra `acli view` + `comment list` per subtask. GitHub issues have no attachment field — uploads are inline URLs.
- Example: issue #721/PR #723 — added `subIssues[]` (GraphQL) to the GitHub loader, deepened JIRA `subtasks[]`. Both reviews converged 0/0/0 iteration 1; `composer build` green (315 tests, 100%).
- Source:  https://github.com/pekral/ai-olympus/pull/723   Added: 2026-06-29
- Role:    shared

### shared-skills-helper-dir-and-readme-skill-count — A non-skill helper dir under skills/ needs the README count test gated on SKILL.md
- Trigger: adding a shared helper dir under `skills/` (e.g. `skills/_shared/` with sourced libs/scripts) reused by more than one skill, instead of duplicating logic or a per-skill `_lib.sh`.
- Rule:    Two gotchas: (1) the README skill-count test in `tests/Installer/SkillsContentTest.php` (`readme reports the current skill count …`) counted every dir under `skills/`, inflating the count on a non-skill helper dir — fix it to count only dirs with a `SKILL.md` (matches `skill-check`'s own definition). (2) Cross-skill sourcing via `${SCRIPT_DIR}/../../_shared/lib.sh` resolves fine in consumer trees too, since `src/Installer.php` copies the whole `skills/` tree verbatim (see [[skills-tree-verbatim-distribution]]) — a shared `_shared/` lib is compatible with verbatim distribution; the self-contained convention only applies to the JIRA transition-helper siblings.
- Example: issue #725/PR #726 — `skills/_shared/attachments.sh` (sourced) + `skills/_shared/scan-attachments.sh` (standalone gate) reused by 3 `download-attachments.sh` wrappers; auth token kept out of argv via a 0600 curl `--config` file, TLS pinned (`--proto`/`--proto-redir '=https'`). No exec tests (test-isolation rule) — proof lives in `scan-attachments.sh --self-test`, content-pinned in Pest.
- Source:  https://github.com/pekral/ai-olympus/pull/726   Added: 2026-06-29
- Role:    hephaestus

### attachment-download-urls-need-an-ssrf-host-guard — fetching tracker-supplied URLs must block non-public hosts before the request
- Trigger: writing/reviewing a skill/script that downloads a tracker-supplied URL (attachment `contentUrl`, a scraped comment/body URL, a webhook payload), especially when user-controllable (Bugsnag comment, GitHub issue body).
- Rule:    TLS+size-cap+quarantine isn't enough — the URL is an SSRF vector. Block loopback/link-local (incl. `169.254.169.254`)/RFC-1918/ULA hosts *before* the request, recording the rejection in the manifest. Put the guard in the shared path (`skills/_shared/attachments.sh` `att_host_block_reason`, called from `att_run`). Give self-hosted trackers an opt-out (`ATT_ALLOW_PRIVATE_HOSTS=1`). GitHub/JIRA already constrain URLs by regex/server-issued `contentUrl`; the open surface is free-text scraping (Bugsnag). DNS-rebinding needs `curl --resolve` pinning — a residual gap to note, not ignore.
- Example: issue #725/PR #726 — CR raised the Bugsnag SSRF Moderate; fixed with `att_host_block_reason` + a pinning Pest test. Converged 0/0 in 2 iterations; `composer build` green (323 tests). Pairs with [[shared-skills-helper-dir-and-readme-skill-count]]'s token-out-of-argv pattern.
- Source:  https://github.com/pekral/ai-olympus/pull/726   Added: 2026-06-29
- Role:    shared

### brand-rename-completeness-grep-all-token-forms — Brand/namespace rename completeness grep must enumerate all token forms including bare PascalCase symbols
- Trigger: a task renames a brand/package/PHP namespace across the repo, with a completeness gate (`git grep`) defined to verify zero remaining references.
- Rule:    Enumerate every token form of the old brand: (1) hyphen-slug `old/package-name`, (2) backslash-namespace `Old\Namespace`, AND (3) the bare PascalCase token `OldPascal` in PHP symbol names (methods/properties/variables, e.g. `getOldPascalConfig()`). Omitting (3) lets stale symbols pass the gate. Gate: `git grep -niI -e '<old-slug>' -e '<Old\Namespace>' -e '<OldPascalToken>' -- src/ bin/ tests/` → 0 occurrences.
- Example: a rename's grep covered the slug + namespace but missed the PascalCase token; `get<OldPascal>Config()` in `src/ComposerPlugin.php` passed the gate despite already using the new identifier internally — Moderate in review iteration 1, renamed to `getAiOlympusConfig()`.
- Source:  https://github.com/pekral/ai-olympus/pull/731   Added: 2026-06-30
- Role:    shared

### git-grep-pathspec-exclude-silently-empty — Verify a pathspec-exclude reference scan against a known counter-example before trusting it
- Trigger: an orchestration gather step scans for references to something about to be removed using a pathspec-exclude pattern (`git grep -l -- "$s" -- ':!skills/'"$s"'/*'`).
- Rule:    This form can silently return empty results even for genuine matches (observed against `README.md`, known to carry a per-skill catalog entry) — a false negative that under-reports the reference map a plan/brief gets built on. Use the reliable two-step form instead: `git grep -ln -- "$s" | grep -v "^skills/$s/"`. Sanity-check any scan result feeding a plan/brief against at least one known-matching file before trusting it.
- Example: gh-6 gather step building the reference map for issue #6 (13 skills to remove) — pathspec-exclude returned 0 hits for `README.md` despite a known catalog entry; the two-step form produced the correct map, reconfirmed by talos's own full-tree grep (see [[skills-tree-convention-removal-grep-full-tree]]).
- Source:  https://github.com/pekral/ai-olympus/pull/7   Added: 2026-07-01
- Role:    daedalus

### readme-structure-is-referenced-by-instruction-files-not-just-tests — Deleting a README section orphans instruction references, not only pinned tests
- Trigger: a task removes/restructures a named README section (e.g. deleting `Skills Overview`, converting `Claude Code Subagents` into cards).
- Rule:    Three coupled places, same commit: (1) `tests/Installer/SkillsContentTest.php` pins README strings verbatim — see [[shared-skills-helper-dir-and-readme-skill-count]]; (2) `skills/skill-creator/SKILL.md` *Repository updates* names README sections by title in its how-to; (3) `docs/agents.md` *Adding a new agent* step 3 names the Subagents table. Grep the repo for the section title (not just skill slugs) before deleting; update instruction files + test in lockstep. Complements [[skills-tree-convention-removal-grep-full-tree]].
- Example: issue #10/PR #13 — removed `Skills Overview`, rewrote Subagents table into avatar cards; updated `SkillsContentTest.php`, `skill-creator/SKILL.md`, `docs/agents.md` in the same commit. `composer build` green (320 tests, 100%); avatars in `assets/agents/` downscaled 1254px→256px.
- Source:  https://github.com/pekral/ai-olympus/pull/13   Added: 2026-07-01
- Role:    hephaestus

### github-user-attachments-need-auth-to-download — GitHub issue image attachments (user-attachments/assets/<uuid>) 404 unauthenticated
- Trigger: a resolve-issue/analyze task must fetch an image/file pasted into a GitHub issue (`https://github.com/user-attachments/assets/<uuid>`, the inline-paste form).
- Rule:    A bare `curl -L <url>` returns `404 Not Found` (9-byte body) — these assets require a GitHub credential. Download with the `gh` auth token kept out of `argv`: write it into a 0600 curl `--config` file (`header = "Authorization: token <token>"`), mirroring [[shared-skills-helper-dir-and-readme-skill-count]]/`skills/_shared/attachments.sh`. Keep TLS on (`--proto '=https'`), never pipe to an interpreter, verify the result type with `file` (and visually `Read` an image) — a 404/redirect can masquerade as a tiny text file. The repo's `download-attachments.sh` wrappers already encapsulate this.
- Example: issue #12/PR #14 — logo pasted as `github.com/user-attachments/assets/91331de3-…`; unauthenticated `curl -L` returned a 9-byte `Not Found`, `curl --config <0600 token file>` fetched the real 1254×1254 PNG, confirmed via `file` + visual `Read` before replacing `assets/logo.png`.
- Source:  https://github.com/pekral/ai-olympus/pull/14   Added: 2026-07-01
- Role:    shared

### jq-alternative-operator-false-collapse — jq `//` treats `false` as empty, so `value // null` corrupts boolean fields in JSON projections
- Trigger: writing/reviewing a jq projection in a skill script (`skills/*/scripts/*.sh`) mapping a boolean field with `//` (`($p.someFlag // null)`, `(.enabled // false)`).
- Rule:    jq's `//` falls through on both `null` and `false`, so `($p.isDraft // null)` emits `null` for every non-draft PR — consumers can't distinguish `false` from missing. Pass booleans through untouched (`$p.isDraft` — jq yields `null` for a missing key already), or use `if has("key") then .key else null end` when the missing-key case must be explicit. Same footgun for numeric `0`/empty-string fields when the fallback differs from the natural value (e.g. `0 // 5` still yields `5`; `false // x`/`null // x` both yield `x`). Pin the fix with a content assertion in `tests/Installer/SkillsContentTest.php`.
- Example: PR #22 — `load-issue.sh` emitted `isDraft: null` for a freshly-promoted non-draft PR right after `gh pr ready`, breaking the `merge-github-pr` Draft gate. Fix `a23c0bc` dropped `// null` + added the regression test.
- Source:  https://github.com/pekral/ai-olympus/pull/22   Added: 2026-07-10
- Role:    shared

### state-detection-heuristics-authoritative-vs-corroborating — Skill state detection must key on authoritative tracker signals, not correlated artifacts
- Trigger: writing/reviewing skill instructions that derive an issue/PR state (reopened, claimed, regressed) from loader JSON fields, especially when it gates a hard consequence.
- Rule:    Key detection on the tracker's authoritative field (GitHub `stateReason: REOPENED`); demote correlated artifacts (a closed-unmerged PR in `closingPullRequests[]`, a merged PR on a still-active JIRA issue) to corroboration only — they occur legitimately on unrelated states. Walk every signal against its false-positive case before wiring a hard consequence; degrade to fresh-assignment behavior when signals are only heuristic. Pin the wording in `tests/Installer/SkillsContentTest.php`, anchoring on a unique string.
- Example: PR #24 — reopen detection in `skills/resolve-issue/SKILL.md` fired on any closed PR in `closingPullRequests[]`, wrongly flagging an abandoned-attempt issue as reopened; fix `0e2116c` made `stateReason: REOPENED` authoritative + required recorded prior Done/Resolved evidence on JIRA.
- Source:  https://github.com/pekral/ai-olympus/pull/24   Added: 2026-07-12
- Role:    shared

### github-social-preview-image-has-no-api-field — Custom social preview (OG image) upload cannot be automated via gh CLI or GitHub REST/GraphQL
- Trigger: a task asks to set/update a repo's custom social preview/OG image (`usesCustomOpenGraphImage`).
- Rule:    Neither REST `PATCH /repos/{owner}/{repo}` nor GraphQL `UpdateRepositoryInput` expose this field — the upload is reachable only via the web UI (Settings → General → Social preview). Commit the generated asset (SVG source + rendered PNG at exactly 1280×640, verified with `sips`/`file`) and document the web-UI upload as a manual step the repo owner must complete after merge — do not report the sub-task done while `usesCustomOpenGraphImage` is still `false` (`gh repo view --json usesCustomOpenGraphImage`).
- Example: issue #9/PR #31 — `assets/social-preview.svg` + `assets/social-preview.png` committed; PR description + reporting comment on #9 both flagged the Settings upload as an open manual step for the owner.
- Source:  https://github.com/pekral/ai-olympus/pull/31   Added: 2026-07-12
- Role:    hephaestus

### background-cr-dispatch-can-silently-lose-output — A background CR review reporting "completed" is not proof it actually published
- Trigger: daedalus dispatches argos/athena in parallel with `run_in_background: true` for the review-and-fix loop (step 6), and later needs to confirm the review actually landed.
- Rule:    A background agent can return a `task-notification` with `status: completed` and a full-looking summary while no corresponding PR comment/review/brief handoff section exists. Before trusting a background CR completion, verify with `gh pr view <n> --json comments,reviews` (or the loader) and grep the brief for the handoff section; if either is empty, treat the run as lost and re-dispatch synchronously (`run_in_background: false`). Do not proceed to the merge gate on an unverified background handoff. Preventive complement: when a task explicitly worries output may be lost, dispatch argos+athena foreground from the start instead of background-plus-verify.
- Example: issue #9/PR #31 — first parallel background dispatch returned "done" summaries but left zero PR comments/brief sections; re-dispatched synchronously, producing verifiable comments (`gh api .../issues/31/comments` confirmed) and correct brief entries (0 Critical/0 Moderate/1 Minor). Recurrence PR #49/issue #39: pre-merge re-verification dispatched foreground from the start, sidestepping the async gap.
- Source:  https://github.com/pekral/ai-olympus/pull/31   Added: 2026-07-12   Updated: 2026-07-15 (PR #49)
- Role:    daedalus

### unpublished-package-prefers-hard-removal-over-deprecation — For a pre-1.0/unpublished package, remove a deprecated feature outright instead of adding a compatibility shim
- Trigger: an issue asks to "remove support for X" (a CLI flag, installer target, public option) on a package with no stable release / real external consumers yet.
- Rule:    Before choosing hard removal vs a deprecation shim, check whether the package is actually published: `git tag` (any releases?), Packagist (404 = never published), `CHANGELOG.md` (still `[Unreleased]`/pre-1.0?). With no external user base, prefer **hard removal** — fail closed with a clear error rather than adding speculative compatibility code nobody depends on (`Simplicity First`, CLAUDE.md). Record the removal in `CHANGELOG.md` under `[Unreleased] → Removed` with a recommended next version bump.
- Example: issue #16/PR #33 — removed `--editor` entirely; verified via `git tag` (none) and Packagist (404) hard removal was safe, added the `CHANGELOG.md` entry recommending `0.10.0`. Converged 0/0/1 Minor (non-blocking `--editor` guard only matches the `=` form, not space-separated).
- Source:  https://github.com/pekral/ai-olympus/pull/33   Added: 2026-07-12
- Role:    shared

### editor-target-removal-touches-docs-and-non-code-assets-too — Removing a CLI target ripples into README, assets, and agent prose, not only src/tests
- Trigger: a task removes a supported CLI target/mode (an `--editor` value, a feature flag) from an installer whose compatibility matrix is documented in multiple places.
- Rule:    A completeness grep for the removed token (case-insensitive, whole-word) must run over the **entire** tree, not just `src/`/`tests/`. In this repo that meant `README.md`, `SECURITY.md`, `docs/agents.md`, `rules/compound-engineering/general.mdc`, `skills/record-project-memory/SKILL.md` (`.cursor/rules/project.mdc` mentions), `agents/athena.md`/`agents/hermes.md`, and even a binary/SVG asset (`assets/social-preview.svg`/`.png`) needing a re-render. Grep alone isn't exhaustive — cross-check every file category (docs, rules, skills, agents, assets) and re-render any generated asset the text change invalidates.
- Example: issue #16/PR #33 — the initial grep list missed 5 files (`rules/compound-engineering/general.mdc`, `skills/record-project-memory/SKILL.md`, `skills/refactor-entry-point-to-action/SKILL.md`, `agents/athena.md`, `agents/hermes.md`) caught only by a final full-tree grep, plus the social-preview asset re-render.
- Source:  https://github.com/pekral/ai-olympus/pull/33   Added: 2026-07-12
- Role:    hephaestus

### pr-body-closing-keyword-must-be-literal-english — A translated GitHub closing keyword in a PR body leaves the issue unlinked pre-merge
- Trigger: resolve-issue/process-code-review opens a PR whose description is in the assignment language (Czech per `@rules/reports/general.mdc`), and the PR must close its issue on merge.
- Rule:    GitHub only recognises English closing keywords (`close(s)`/`fix(es)`/`resolve(s)` + past tense). A translated keyword (e.g. Czech "Uzavírá #42") is NOT parsed — `closingIssuesReferences` stays empty, and `code-review-github`'s linked-issue `pr-summary` step skips. Always put the literal English `Closes #<N>` in the PR body (exempt from the assignment-language rule, like a code identifier). The commit body's `Closes #<N>` still auto-closes on rebase-merge, masking the missing PR-level link — verify with `gh api graphql` on `closingIssuesReferences` after opening.
- Example: PR #43 (issue #42) used "Uzavírá #42" → `closingIssuesReferences` empty, summary skipped; PR #44 (issue #40) used "Closes #40" → `closingIssuesReferences` = [40].
- Source:  https://github.com/pekral/ai-olympus/pull/44   Added: 2026-07-13
- Role:    shared

### empirical-probe-beats-static-source-read-for-tool-behavior — Verify an external tool's exact behavior by running it, not only by reading its source
- Trigger: a technical claim about an external tool's runtime behavior (e.g. "does PHPCS honor an `@`-prefixed annotation?") is made by reading its vendored source, and will drive a rule's wording or a Suggested-Fix.
- Rule:    A careful static read of complex source (tokenizers, parsers, multi-branch logic) can still miss an earlier normalization step invalidating a conclusion drawn from a later branch. Before finalizing a technical claim a rule/fix depends on, empirically probe the tool with minimal variations and compare real output — a real run is authoritative in a way an isolated source excerpt isn't.
- Example: issue #41/PR #47 — reading only `Tokenizer.php:355-469` concluded `// @phpcs:ignore` wasn't honored; running PHPCS 4.0.1 against bare/`phpcs:ignore`/`@phpcs:ignore`/`@phpcs:disable` variants found all three suppress identically — an `@`-stripping branch at `Tokenizer.php:269-277` had been missed. Corrected the plan's Suggested-Fix before implementation.
- Source:  https://github.com/pekral/ai-olympus/pull/47   Added: 2026-07-13
- Role:    shared

### pest-worktree-avoid-digit-leading-path — A disposable Pest worktree must not sit under a digit-leading path segment
- Trigger: creating a disposable `git worktree` (e.g. for an empirical mutation test) under the session scratchpad path, whose directory segment is a UUID commonly starting with a digit.
- Rule:    Pest/PHPUnit's test-file namespace inference treats path segments as candidate namespace components; a digit-leading segment isn't a valid PHP identifier and produces a namespace-inference error unrelated to the code under test. Nest the worktree one level deeper under an alphabetic-prefixed subdirectory first (`mkdir -p "$SCRATCHPAD/wt" && git worktree add "$SCRATCHPAD/wt/<name>" ...`) rather than at the scratchpad root.
- Example: `apollon`'s mutation-test worktree for PR #47 (issue #41), created directly under a UUID-leading scratchpad path, failed with a namespace-inference error unrelated to the lock-in test; worked around by confirming meaningfulness statically instead (a `toContain()` substring assertion), worktree removed (`git worktree remove --force`).
- Source:  https://github.com/pekral/ai-olympus/pull/47   Added: 2026-07-13
- Role:    hephaestus

### load-issue-top-level-comment-updated-at-always-null — `load-issue.sh` never returns `updatedAt` for a PR's top-level comments; use an equivalent staleness check
- Trigger: a staleness check (`@skills/merge-github-pr/SKILL.md` step 2, or `@skills/code-review-github/SKILL.md`'s upsert-in-place convergence check) needs a PR-level top-level comment's `updatedAt`.
- Rule:    This is a structural loader limitation, not the [[jq-alternative-operator-false-collapse]] bug — the field is simply absent from `gh`'s `--json comments` projection for top-level PR comments (present only for `subIssues[].comments[].updatedAt` via GraphQL). Do not assume staleness from a `null` `updatedAt`. Use an equivalent check: `createdAt` is after the head commit's `authoredDate`, `commits` count/list unchanged since, and the comment body names the reviewed head SHA.
- Example: PR #47 (issue #41) — `updatedAt` was `null`; `talos` verified currency via `createdAt` (`2026-07-13T18:10:01Z`) after the commit's `authoredDate`, unchanged commit count, and the comment naming head `568e2ae`.
- Source:  https://github.com/pekral/ai-olympus/pull/47   Added: 2026-07-13
- Role:    shared

### repo-empty-review-decision-non-blocking-verify-precedent — An empty `reviewDecision` doesn't block merge-github-pr's approval check here — verify via precedent
- Trigger: running `@skills/merge-github-pr/SKILL.md` step 2 and finding `reviewDecision == ""`/`reviewsCount == 0` while every other pre-check passes.
- Rule:    This repo has no branch protection requiring a native review approval, so an empty `reviewDecision` is a known non-blocking state — but confirm per merge, not by hardcoding: check a recently, successfully merged PR for the identical pattern via the deterministic loader. `@rules/git/general.mdc` *Merging* only states an "Approved" `reviewDecision` alone is insufficient without a converged review — it does not say the reverse, so this repo-specific fact needs its own verification each time.
- Example: PR #47 (issue #41) — `reviewDecision: ""`, `reviewsCount: 0`; verified against precedent PR #44 (same pattern, `state: MERGED`) before proceeding, re-confirmed independently right before running the merge command.
- Source:  https://github.com/pekral/ai-olympus/pull/47   Added: 2026-07-13
- Role:    hephaestus

### merge-delete-branch-repo-flag-skips-local-branch — `gh pr merge --repo ... --delete-branch` deletes only the remote branch, not local
- Trigger: running `gh pr merge <n> --repo <owner/repo> ... --delete-branch` (the explicit `--repo` form) as the merge step of `@skills/merge-github-pr/SKILL.md` or any manual merge.
- Rule:    `--delete-branch` reliably deletes the **remote** branch (verify via `git fetch --prune`/`git ls-remote --heads origin`) but does **not** touch the **local** branch when `--repo` is passed explicitly. After merging, verify the local branch was removed (`git branch -a`); if not, confirm it's safe (no worktree holds it, `git diff <base> <branch>` empty) and remove manually (`git branch -D` if `-d` refuses — expected for rebase-merge **or** squash-merge, since git's ancestry check doesn't recognize either as fast-forward-reachable).
- Example: PR #47 (issue #41) — rebase-merge deleted the remote branch but left the local one; `git branch -d` refused ("not fully merged"), empty diff confirmed, `git branch -D` removed it. Recurrence PR #49/issue #39 — same refusal with **squash-merge** (single-parent merge commit `4054b42` confirmed via `git log -1 --format=%P`), same resolution — confirming the refusal is a general history-rewriting-merge mechanic, not rebase-specific.
- Source:  https://github.com/pekral/ai-olympus/pull/47   Added: 2026-07-13   Updated: 2026-07-15 (PR #49)
- Role:    hephaestus

### embedded-issue-number-may-be-foreign-legacy-reference — An "(issue #NNN)" heading from a file's earliest commit may be a foreign/legacy reference
- Trigger: a skill/rule file carries an "(issue #NNN)" heading, and the current task references a different, seemingly-related issue number — tempting to treat the embedded number as a live cross-reference.
- Rule:    Verify provenance with `git log --follow -- <file>` before treating an embedded issue-number heading as a live cross-reference. If the text traces back only to `583ffa6 Initial commit` rather than a later, dated commit, it predates this repo's own tracker and cannot be a genuine self-reference. This repo mixes both: foreign/legacy (`#493` in `skills/class-refactoring/SKILL.md`/`rules/refactoring/general.mdc`; `#540`/`#549`/`#680`/`#714` in `rules/security/backend.md`) and real self-references (`#17`/`#20`/`#25` in `rules/code-review/general.mdc`; `#498`/`#530` in `skills/code-review-github/SKILL.md`). Never renumber or cross-reference a foreign heading as this task's own issue.
- Example: issue #39 revised `skills/class-refactoring/SKILL.md`; `git log --follow` confirmed its only prior commit was `583ffa6`, proving "issue #493" in its Test Coverage Gate heading was unrelated — left untouched.
- Source:  https://github.com/pekral/ai-olympus/pull/49   Added: 2026-07-15
- Role:    shared

### ambiguous-optimization-ask-resolves-to-non-regression-under-1-1-constraint — A preservation constraint plus an improvement request resolves as non-regression, not a mandate
- Trigger: a single issue states both a strict preservation constraint ("1:1, no behavior change") and an improvement-sounding goal ("optimized for X") in the same breath, where an active reading of the second would risk violating the first.
- Rule:    Do not treat the two clauses as contradictory or escalate by default. Read the improvement clause as a **non-regression contract** (must not get worse than the original) rather than an active-optimization mandate — the only reading consistent with the stricter constraint, mirroring any existing non-regression precedent for an analogous dimension. State the interpretation explicitly in the published plan so reviewers validate the reasoning rather than discover it in the diff.
- Example: issue #39 asked for a "1:1" refactor with "no business logic change" plus "optimized for high-load applications"; resolved as a **runtime-efficiency non-regression** requirement (mirroring the file's SQL-performance non-regression bullet). `skills/class-refactoring/SKILL.md`'s new bullet (PR #49) implements that reading.
- Source:  https://github.com/pekral/ai-olympus/pull/49   Added: 2026-07-15
- Role:    shared

### ci-workflow-covers-only-a-subset-of-composer-build — Green CI is not proof that the full `composer build` passes; the workflow runs only a subset of its steps
- Trigger: a task's merge gate requires "`composer build` must pass with zero errors" and CI is already green — tempting to treat green CI as satisfying the gate.
- Rule:    `.github/workflows/pr.yml` runs only `security-audit`, `phpcs-check`, `pint-check`, `rector-check`, `analyse`, `test:coverage` — NOT `skill-check` or `composer-normalize-check`, both part of the full `composer build` (`install --force` + `@fix` + `@check`, 8 steps). Green CI therefore does not prove the full local `composer build` is clean. Whenever a task states an explicit "`composer build` must pass" gate, run the full local build independently — never substitute CI status.
- Example: PR #49 (issue #39) — CI was green when the explicit merge-gate mandate called for independent verification; a dedicated full local run (all 8 `@check` steps) confirmed 0 errors, 308/308 tests, 100% coverage.
- Source:  https://github.com/pekral/ai-olympus/pull/49   Added: 2026-07-15
- Role:    shared

### pinned-test-mandate-conflict-defers-to-owner-decision — A recommendation conflicting with a pinned-test mandate defers to an owner-decision issue
- Trigger: an analysis/optimization issue recommends changing something in `agents/*.md`/`skills/**`/`rules/**`, and an installer content test pins the current state as an explicit prior assignment.
- Rule:    A pinning test citing an issue number encodes a deliberate, user-mandated invariant. A newer recommendation contradicting it must not be implemented by just updating the test (silently reverting an owner decision). Defer the conflicting recommendation into its own tracker issue naming the collision (recommendation X vs mandate issue Y) and let the owner decide; implement only recommendations that don't touch a pinned mandate. Grep `tests/Installer/*Test.php` for "(issue #" citations before scoping.
- Example: issue #62 R4 (hermes → low/medium effort) conflicted with `tests/Installer/AgentsTest.php`'s "every agent sets effort to max (issue #40)"; PR #63 implemented R1–R3 and deferred R4 as decision issue #64.
- Source:  https://github.com/pekral/ai-olympus/pull/63   Added: 2026-07-18
- Role:    shared

### unconditional-behavior-ask-defaults-not-opt-in — An assignment phrased as an unconditional statement about a skill's own output resolves to a default behavior change, not an opt-in toggle
- Trigger: an issue asks a skill/feature to behave a certain way without conditional language ("volitelně", a flag/level list) — tempting to add an opt-in toggle so existing behavior stays reachable.
- Rule:    Read the absence of conditional language as intentional — an unconditional statement about a skill's own output is a default-behavior-change request; an opt-in flag/level is unrequested configurability CLAUDE.md's Simplicity First forbids. State this reading explicitly in the published plan so reviewers validate it rather than re-derive it from the diff.
- Example: issue #51 ("aby tento skill měl podobné výstupy jako <tool>") resolved as `pr-summary`'s output becoming terse **by default**, not behind an intensity flag (the referenced tool ships several levels, which made the toggle reading tempting). PR #72 implemented it with zero configuration surface added.
- Source:  https://github.com/pekral/ai-olympus/pull/72   Added: 2026-07-19
- Role:    shared

### write-lock-staleness-needs-corroborating-evidence-not-bare-pid — Reclaiming a write-lock on a dead PID alone isn't enough — corroborate with the run's own outcome
- Trigger: resuming a daedalus run from a shared brief written by a previous, interrupted instance, and the write-lock (`.claude/run/.daedalus-write.lock`) is held — especially when the brief's own "concurrency note" names a *different* holder than the one actually found live.
- Rule:    A `kill -0 <holder PID>` probe is unreliable in this sandbox — each Bash call spawns a fresh subprocess, so a captured PID reflects only that one transient command. Before reclaiming, corroborate with the referenced run's own outcome (issue `CLOSED`, PR `MERGED`, working tree clean on the base branch). Re-derive the *current* concurrency picture from live state (`ls .claude/run/`, the lock holder file, `gh issue/pr view`) rather than trusting a brief's stale "concurrency note" verbatim.
- Example: the `gh-51` brief warned about a lock held by `gh-56`; by resume time `gh-56` had finished (issue #56 `CLOSED`, PR #61 `MERGED`) and the lock was instead held under `SLUG=gh-52` (not even mentioned in the brief) — reclaim justified by corroborating evidence (#52 `CLOSED`, PR #70 `MERGED`), not the dead-PID probe alone.
- Source:  https://github.com/pekral/ai-olympus/pull/72   Added: 2026-07-19
- Role:    daedalus

### process-code-review-completion-skips-duplicate-cr-comment-after-upstream-publish — When argos/athena already published the CR comment, Completion publishes only `cr-status`
- Trigger: `process-code-review` runs (typically as `hephaestus`) on a PR where `argos` (optionally consolidating `athena`) already published the single technical `cr-comment` upstream of this skill's own Review loop.
- Rule:    Taken literally, `@skills/process-code-review/SKILL.md` Completion re-triggers a second technical-review publish, duplicating the `cr-comment` thread. When the upstream review already exists and the diff is unchanged or only gained a trivial, re-verified-safe fix commit, treat that comment as satisfying the review and publish only the distinct `cr-status` comment, then promote out of Draft. Do not also publish the linked-issue mirror if another pipeline step already owns that duty.
- Example: issue #55/PR #73 — `argos` published the consolidated `cr-comment` (0/0/2 Minor) before `process-code-review` started; `talos` added one trivial CHANGELOG commit, re-verified `composer build` green, published only `cr-status`, promoted out of Draft — the linked-issue summary left to `apollon`'s dedicated reporting step.
- Source:  https://github.com/pekral/ai-olympus/pull/73   Added: 2026-07-19
- Role:    hephaestus

### plan-tracking-issue-may-outlive-its-own-implementation-merge — A "Plan (issue #N)" tracking issue can stay OPEN after merge, since `Closes #N` targets the source issue
- Trigger: daedalus resolves a GitHub issue that is a published plan artifact (title names another issue as its target, phrasing varies) rather than an original source issue — check whether the plan's content has already shipped before dispatching `hephaestus`.
- Rule:    A plan issue and its source issue are distinct tracker items; `Closes #N` in the implementing commit names the source, leaving the plan issue OPEN even when realized. Verify: (1) source issue CLOSED; (2) a merged PR references this plan issue; (3) plan's named files match the PR's changed-file list; (4) fresh local `composer build` passes. If all four hold, it's reconciliation, not implementation — post an explanatory comment + `gh issue close`, no `hephaestus` dispatch, no new PR.
- Example: Issue #71 ("Plan (issue #51)") stayed OPEN after PR #72 implemented it via `Closes #51` in commit `250c943`; verified + closed. Recurred: issue #69/PR #70 (issue #52), issue #57/PR #58 (issue #54) — differently-punctuated titles, same pattern.
- Source:  https://github.com/pekral/ai-olympus/pull/72   Added: 2026-07-19   Updated: 2026-07-19 (issue #69 / PR #70)
- Role:    daedalus

### cleanup-must-verify-lock-ownership-before-unconditional-release — daedalus step-7 cleanup must confirm this run acquired the write-lock before removing it
- Trigger: daedalus reaches step-7 cleanup and runs `rm -rf .claude/run/.daedalus-write.lock` mechanically, without checking whether *this* run's own step 5 ever created that lock — especially on a non-standard path (already-resolved issue, analysis-only) that never dispatched `hephaestus`.
- Rule:    The cleanup is conditional on step 5 having acquired the lock *in this run* — not an unconditional habit. `ls -la .claude/run/` first: if briefs/the lock predate this run's step 5, or `hephaestus` was never dispatched, do not touch it — it may belong to another active daedalus instance. If removed by mistake, recreate a placeholder with a transparent incident note and disclose it in the final report — never silently continue, never fabricate the holder's PID.
- Example: The `gh-71` run (issue #71, already shipped via merged PR #72, see [[plan-tracking-issue-may-outlive-its-own-implementation-merge]]) ran `rm -rf .daedalus-write.lock` unconditionally, deleting `gh-60`'s live lock (confirmed via its `Resolve_by_AI:in-progress` label + mtime); no actual collision occurred, so the lock was recreated with an incident note disclosed to the user.
- Source:  https://github.com/pekral/ai-olympus/issues/71   Added: 2026-07-19
- Role:    daedalus

### plan-issue-may-be-only-partially-superseded-verify-each-criterion — A plan issue can be *partially* superseded by an alternate merged design — verify each criterion
- Trigger: daedalus resolves a "Plan (issue #N)" tracking issue and finds its target source issue already CLOSED via a different, already-merged PR than the plan proposed (see [[plan-tracking-issue-may-outlive-its-own-implementation-merge]] for the fully-redundant variant).
- Rule:    Do not treat "resolved differently" as proof the plan is moot, nor implement it verbatim. Walk the plan's success criteria one at a time against the current codebase: some may already be satisfied by the alternate design, some permanently superseded, some may still be open if the superseding PR flagged them as deferred. Verify each by direct file inspection and checking for an existing pinning test. Scope implementation to only the still-open criteria; frame the PR body to explain the scope reduction.
- Example: Issue #60 ("Plan (issue #56)") proposed an option contradicted on 2 of 3 criteria by PR #61's merged alternate design; the 3rd (a missing Summary-line token in a `code-review-bugsnag` template) was confirmed still unfixed (per PR #61's own "future follow-up" note) — implementation scoped to just that edit.
- Source:  https://github.com/pekral/ai-olympus/pull/76   Added: 2026-07-19
- Role:    daedalus

### daedalus-executes-final-merge-directly-no-specialist-owns-it — No specialist agent owns `gh pr merge` — daedalus executes merge-github-pr itself
- Trigger: a daedalus run reaches convergence on a PR and needs to perform the actual merge into the base branch.
- Rule:    `hephaestus` explicitly never merges; `athena` is a read-only reviewer with no merge capability either (though `argos` may flip `gh pr ready` as part of consolidating a barrier-gated review — not the same operation as the merge). No agent performs `gh pr merge`. daedalus reads `@skills/merge-github-pr/SKILL.md` itself and executes it directly via `Bash` — load the PR via the deterministic loader (`skills/code-review-github/scripts/load-issue.sh`), verify every step-2 pre-check itself, then run `gh pr merge` directly. This is the one procedural/deterministic skill not on the "never invoke yourself" list.
- Example: PR #76 (issue #60) — after convergence (argos 0/0/0/0 + athena 0/0/0), daedalus independently verified all 6 pre-checks and ran `gh pr merge --squash --delete-branch` directly — no specialist dispatched for the merge.
- Source:  https://github.com/pekral/ai-olympus/pull/76   Added: 2026-07-19
- Role:    daedalus

### memory-file-append-has-no-lock-anchor-substring-replace-mitigates — Concurrent memory-file writes aren't lock-protected — anchor-based substring edits keep them safe
- Trigger: two or more daedalus runs reach step 7 (record durable lessons) close together and both edit the same `docs/memory/PROJECT_MEMORY.md` entry — most likely both reconciliation-only runs, since neither ever acquired `.claude/run/.daedalus-write.lock` (scoped to the full-delivery/`hephaestus`-dispatch path only).
- Rule:    Never edit `PROJECT_MEMORY.md` (or any unlocked, concurrently-touchable file) via captured line numbers (`sed -i '350s/.../'`) — line numbers go stale the instant a concurrent edit lands between your read and write. Instead: read the whole file fresh, locate the edit point by matching a unique, sufficiently long existing substring (e.g. `str.replace(exact_old_text, exact_old_text + addition, 1)`), write, then immediately re-read and eyeball the merged result (`grep -c '^### '` heading count, no duplicated/truncated text).
- Example: the `gh-69` run (issue #69) and concurrent `gh-57` run (issue #57) both appended a recurrence sentence to [[plan-tracking-issue-may-outlive-its-own-implementation-merge]]'s Example field within ~1 minute of each other; anchor-based `str.replace` let both compose correctly with zero corruption, confirmed via `git status` + unchanged `grep -c '^### '` count.
- Source:  https://github.com/pekral/ai-olympus/issues/69   Added: 2026-07-19
- Role:    daedalus

### claude-code-plugin-ships-no-rules-or-claude-md — A Claude Code plugin distributes skills and agents only; rules and CLAUDE.md need a command
- Trigger: work on the plugin-marketplace distribution channel (`.claude-plugin/*.json`), or a claim that installing the plugin gives a project the same result as the Composer installer.
- Rule:    Claude Code reads `skills/` and `agents/` out of a plugin directory and reads **neither `rules/` nor a `CLAUDE.md`** — no plugin mechanism exists for a project-scoped always-on instruction file. State the limit; never imply the two channels are equivalent. Rules travel by `commands/install-rules.md` (`/ai-olympus:install-rules`), which copies from `${CLAUDE_PLUGIN_ROOT}` and never overwrites an existing `CLAUDE.md`. The plugin is the repo root (`"source": "./"`), so no manifest carries a `version` and none may carry `hooks` — that would ship a runtime component unconditionally, the invariant #265 restored. Verify manifest changes by running them (`claude plugin marketplace add <dir>` + `claude plugin details`), per [[empirical-probe-beats-static-source-read-for-tool-behavior]]; the `owner/repo` form reads the default branch and is unverifiable pre-merge.
- Example: `.claude-plugin/marketplace.json`, `commands/install-rules.md`, `tests/Installer/PluginMarketplaceTest.php`.
- Role:    hephaestus
- Source:  https://github.com/pekral/ai-olympus/pull/270   Added: 2026-08-18

### content-pin-threshold-assertion-can-be-vacuous — A `>=` count assertion pins nothing when the base branch already meets it
- Trigger: authoring a content-pin test over a prose file (`agents/*.md`, `rules/**`, `skills/**`) — the dominant test shape here — with `substr_count(...)` or a `toContain` on a string the file may already carry.
- Rule:    Assert the exact count, never a `>=` threshold, and prove RED against the base branch first: `git show origin/<base>:<file> | grep -cF '<pinned string>'` must return the pre-change count. A threshold the baseline already meets passes with the change fully reverted — it pins nothing while looking like coverage. Pair every count assertion with a positive assertion on a string the change actually introduces.
- Example: PR #245 (issue #228) — `substr_count($content, 'gh repo view --json isPrivate') >= 2` was already true on `master` (step 9's rule + the Bash boundary); replaced with `toBe(3)` plus a `toContain` on the new step-4 sentence.
- Source:  https://github.com/agentic-vibes/laravel-agent-skills/pull/245   Added: 2026-08-11
- Role:    shared
