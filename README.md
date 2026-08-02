<p align="center">
  <img src="assets/logo.png" alt="Laravel Agent Skills" width="280">
</p>

# Laravel Agent Skills — An AI Development Team for Laravel

<p align="center">
  <a href="https://packagist.org/packages/agentic-vibes/laravel-agent-skills"><img src="https://img.shields.io/packagist/v/agentic-vibes/laravel-agent-skills" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/agentic-vibes/laravel-agent-skills"><img src="https://img.shields.io/packagist/dt/agentic-vibes/laravel-agent-skills" alt="Total Downloads"></a>
  <a href="https://packagist.org/packages/agentic-vibes/laravel-agent-skills"><img src="https://img.shields.io/packagist/php-v/agentic-vibes/laravel-agent-skills" alt="PHP Version"></a>
  <a href="https://github.com/agentic-vibes/laravel-agent-skills/blob/master/LICENSE"><img src="https://img.shields.io/packagist/l/agentic-vibes/laravel-agent-skills" alt="License"></a>
  <a href="https://pekral.cz"><img src="https://img.shields.io/badge/by-pekral.cz-blue" alt="by pekral.cz"></a>
</p>

**Laravel Agent Skills** gives a Laravel/PHP team an **AI development team inside Claude Code** — six specialized subagents that resolve GitHub issues, open pull requests, review code, audit security, and write Pest tests. One `composer require --dev` installs the whole roster together with the coding-standard rules and agent skills they run on. It replaces the hand-maintained `CLAUDE.md` and the ad-hoc prompt library every project otherwise reinvents.

## Quickstart

```bash
composer require agentic-vibes/laravel-agent-skills --dev
vendor/bin/agent-skills install --force
```

Then point the front-door agent at real work, inside Claude Code:

```text
@daidalos resolve https://github.com/owner/repo/issues/123
```

`daidalos` picks the route, `talos` implements it, `argos` and `athena` review it to convergence, and you get a pull request back.

## What You Get

| Layer      | What it is                                                            | Installed into   |
|------------|-----------------------------------------------------------------------|------------------|
| **Rules**  | Long-lived project standards Claude Code applies to every edit        | `.claude/rules`  |
| **Skills** | Reusable workflows, from `resolve-issue` to `security-review`         | `.claude/skills` |
| **Agents** | Orchestration roles that combine skills into an issue-to-PR pipeline  | `.claude/agents` |

## Why This Package

- **Ship an issue without writing the boilerplate** — one agent takes the ticket, implements it, and hands back a reviewed pull request
- **Reviews that block on real findings** — quality and security run as separate passes and must reach zero Critical and Moderate before anything merges
- **Tests you did not have to remember to write** — a change lands with Pest coverage for the lines it touched
- **One standard across every repository** — the same PHP/Laravel rules travel with the package instead of being copy-pasted per project
- **50 comprehensive Agent skills** you can invoke directly when you want the workflow without the agent
- **Onboarding measured in one command** — a fresh checkout gets the whole team from `composer require --dev`

## Installation

The [Quickstart](#quickstart) above carries the two commands. This is what they put in your project — the installer targets **Claude Code only**:

- `.claude/rules`, `.claude/skills`, and when `HOME`/`USERPROFILE` is set also `~/.claude/skills`
- `.claude/agents` (the six subagents)
- `CLAUDE.md` in the project root

> [!IMPORTANT]
> By default, the installer only copies missing files and keeps existing content untouched. Use the `--force` flag to overwrite existing files: `vendor/bin/agent-skills install --force`. This is particularly useful when you want to update rules to their latest versions or when you've made local changes that should be replaced. The file `CLAUDE.md` is never overwritten once it exists in the target project, so you can safely customize it.

Everything beyond those two commands — enabling auto-install on `composer install`, the full command list, the installer flow, and every CLI switch — lives in [`docs/installation.md`](docs/installation.md).

---

## Claude Code Subagents

Agents are a thin orchestration layer over the existing skills — they don't replace them and they don't duplicate their prompts. The roster is named after **Greek mythology** by function (see [`docs/agents.md`](docs/agents.md)).

```text
Rules  = long-lived project standards
Skills = reusable workflows
Agents = specialised orchestration roles over multiple skills
```

Each agent has its own avatar under [`assets/agents/`](assets/agents). Full role definitions live in [`docs/agents.md`](docs/agents.md).

<table>
<tr>
<td width="96" valign="top"><img src="assets/agents/argos.png" alt="argos avatar" width="80"></td>
<td valign="top">

**`argos` — code-review gatekeeper** · read-only

Reviews a PR from context or a tracker link, posts the findings back to the PR, and returns a `CR done` handoff. Owns code quality, architecture, and optimisation, and consolidates `athena`'s security findings.

**Orchestrates:** `code-review-github`, `code-review-jira`, `code-review-bugsnag`

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/talos.png" alt="talos avatar" width="80"></td>
<td valign="top">

**`talos` — code-writing implementer**

Implements an issue from context or a tracker link, runs local checks (`composer build`) and fixes their errors, then opens a PR. Stops at the PR — it never reviews its own work or merges.

**Orchestrates:** `resolve-issue`

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/daidalos.png" alt="daidalos avatar" width="80"></td>
<td valign="top">

**`daidalos` — engineering-workflow orchestrator** · the front door

The entry point for a free-form request. Resolves a concrete source, then dispatches `athena` (security-risk analysis, on demand), `talos` (implementation), `apollon` (scoped validation), `argos` (quality CR), and `athena` (security CR) through the Task tool, planning a dependency-aware resolve order. Delegates every step — never does the work itself.

**Orchestrates:** `talos`, `apollon`, `argos`, `athena` (dispatched)

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/apollon.png" alt="apollon avatar" width="80"></td>
<td valign="top">

**`apollon` — test engineer & post-convergence reporter**

Designs test scenarios and writes PHPUnit/Pest tests, runs a fast scoped validation gate after landing steps (after PR-open for high-risk changes, always after convergence), and after convergence publishes a non-technical summary (what changed + how to test) to the source tracker. Write-capable for test code only.

**Orchestrates:** `create-test`, `create-missing-tests-in-pr`, `e2e-testing`, `pr-summary`

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/athena.png" alt="athena avatar" width="80"></td>
<td valign="top">

**`athena` — security analyst & CR sentinel** · read-only

Two modes: an on-demand pre-implementation security analysis (feeding a remediation plan to `talos`), and a security CR run in parallel with `argos` after `talos`. Applies every security rule and labels each finding Critical / Moderate / Minor.

**Orchestrates:** `security-review`, `laravel-security`, `security-bounty-hunter`, `security-threat-analysis`, `analyze-problem`

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/hermes.png" alt="hermes avatar" width="80"></td>
<td valign="top">

**`hermes` — release announcer** · read-only

Turns a merged change or release into announcement content: a Twitter/X tweet (≤280 chars) + thread, release notes, and a marketing summary with pekral.cz promotion. Runs post-delivery and publishes only when explicitly asked.

**Orchestrates:** `resolve-issue/references/source-detection`

</td>
</tr>
</table>

### How to use `argos` in practice

1. Install for Claude Code:

   ```bash
   vendor/bin/agent-skills install
   ```

   Agents land in `.claude/agents/`.

2. Invoke it with a **source** — a GitHub PR/issue, a JIRA key, a Bugsnag error, or just the current branch/PR:

   ```text
   @argos review PR #123
   @argos review https://your.atlassian.net/browse/PROJ-42
   @argos review the current diff
   ```

3. `argos` detects the tracker, runs the matching `code-review-*` skill, lets it **post the review to the PR**, then returns a handoff: `CR done` + PR link + source link + Critical/Moderate/Minor counts + assignment-conformance verdict.

`argos` is **read-only** — it never applies fixes, commits, pushes, or merges. Those belong to separate agents.

### How to use `talos` in practice

1. Install for Claude Code, exactly as for `argos` — agents land in `.claude/agents/`.

2. Invoke it with a **source** — a GitHub issue/PR, a JIRA key, a Bugsnag error, or just the task you want implemented:

   ```text
   @talos implement #123
   @talos implement https://your.atlassian.net/browse/PROJ-42
   @talos implement the failing upload validation
   ```

3. `talos` detects the source, runs `resolve-issue` to implement the change, runs local checks (`composer build`) and fixes their errors, then opens a PR and returns a handoff: `Impl done` + PR link + source link + branch + a summary of what changed and the local-checks result.

`talos` **stops at the PR** — it never reviews its own work or merges. Code quality and architecture CR belong to `argos`; security CR belongs to `athena`. Hand the PR to `argos` (and optionally `athena`) for review next.

> [!NOTE]
> **If `talos` reports `Blocked: sandbox denied file write`:** dispatched subagents run non-interactively, so a write is denied unless the path is pre-allowed. Add scoped `Edit` / `Write` entries for the project tree to `permissions.allow` in `.claude/settings.local.json` (`"Edit(//Users/me/Projects/my-app/**)"`, `"Write(//Users/me/Projects/my-app/**)"`) — or run the installer with `--allow-subagent-writes` to add them for you — then re-run. See [`docs/agents.md`](docs/agents.md) *Troubleshooting — subagent file writes blocked*. The run correctly stops instead of silently finishing the work in the main thread.

### How to use `daidalos` in practice

`daidalos` is the **front door** — the agent you address with a free-form request when you don't want to pick a specialist yourself.

1. Install for Claude Code, exactly as for the other agents.

2. Invoke it with a request — it resolves the source and chooses the route:

   ```text
   @daidalos resolve a random Resolve_by_AI issue
   @daidalos resolve https://github.com/owner/repo/issues/123
   @daidalos implement a dark-mode toggle for the settings page
   ```

3. `daidalos` resolves a concrete source, then **dispatches the matching specialist agent through the Task tool**: a security-focused task → `athena` (security-risk analysis → remediation plan) → `talos`; everything else → `talos` directly; then `argos` for the review-and-fix loop to convergence. A subject too broad for one PR is reported back with the separable pieces instead of being pushed into a single PR — split it up with `create-issues-from-text` and re-run per piece. It returns a handoff naming the chosen route and reason, written in the same language as your request.

   Ask explicitly for **savings mode** (*"run this in savings/token-efficient mode"*, *"úsporný režim"*) to opt into a token-efficient variant of the exact same pipeline — same agents, same convergence gate, same PR/review/feedback artifacts, just less duplicate context re-derivation and fewer repeated build runs. It is off by default; see [`docs/agents.md`](docs/agents.md) *Savings mode* for how it works.

`daidalos` is a **read-only orchestrator** — it never analyses, implements, or reviews itself; it delegates every step by dispatching the matching specialist agent, and (per the one-level subagent-nesting rule) it runs as the top-level agent you talk to, spending that single nesting level on the dispatch rather than being a nested subagent itself. A future top-level `zeus` will sit above it to coordinate non-engineering domains too.

---

## Rules Overview

Rules included in this package:

| File                          | Description                                                | Scope    |
|-------------------------------|------------------------------------------------------------|----------|
| `php/core-standards.mdc`      | Project context, AI behavior, and unified PHP/Laravel coding standards | Always   |
| `php/examples/named-arguments.md` | Named-arguments usage examples (good/avoid) supporting the PHP core standards | Always   |
| `php/dependency-selection.mdc` | Composer dependency selection — activity and compatibility gates before adopting a new package | Dependencies |
| `compound-engineering/general.mdc` | Compound engineering — make future work easier and read the per-project compound memory | Always   |
| `git/general.mdc`             | Unified git workflow, commits, and pull request rules       | Always   |
| `code-review/general.mdc`     | Code review conventions and output rules                   | Always   |
| `code-testing/general.mdc`    | Testing conventions and quality standards                  | Always   |
| `api/general.mdc`             | API design as a consumer-facing contract — REST conventions, HTTP methods, status codes, idempotency | API      |
| `refactoring/general.mdc`     | Shared refactoring definition (legacy → modern, incremental migration) | Refactor |
| `jira/general.mdc`            | JIRA CLI usage and formatting rules                        | JIRA     |
| `reports/general.mdc`         | Language rule for reports published to issue trackers (assignment language) | Always   |
| `laravel/architecture.mdc`    | Laravel architecture and conventions                       | Laravel  |
| `laravel/laravel.mdc`         | Laravel-specific rules and patterns                        | Laravel  |
| `laravel/filament.mdc`        | Filament v4 specific rules                                 | Filament |
| `laravel/livewire.mdc`        | Livewire component rules and conventions                   | Livewire |
| `laravel/queue-debouncing.mdc`| Safe Laravel queue debouncing, urgency separation, and replaceable work | Laravel  |
| `laravel/dynamodb.mdc`        | DynamoDB query safety: scan prevention, key-targeted reads, Tinker debug | Laravel  |
| `sql/optimalize.mdc`          | SQL query optimization, index design, schema standards     | Always   |
| `security/backend.md`         | Backend security rules and OWASP Top 10 checks             | Always   |
| `security/frontend.md`        | Frontend security rules (XSS, CSRF, CSP)                  | Frontend |
| `security/mobile.md`          | Mobile-specific security rules and WebView checks          | Mobile   |

All `.mdc` and `.md` files are ready for automatic injection by Claude Code so every PHP and Laravel edit stays aligned with the enforced standards.

## Development & Testing

### Composer Scripts

```bash
composer check              # run full quality check (skill-check, normalize, phpcs, pint, rector, phpstan, audit, tests)
composer fix                # run all automatic fixes (skill-check-fix, normalize, rector, pint, phpcs)
composer build              # install (agent-skills install --force) then fix then check
composer analyse            # run PHPStan static analysis
composer test:coverage      # run tests with 100% coverage
composer coverage           # alias for test:coverage
composer security-audit     # run security audit of dependencies
```

### Individual Commands

```bash
composer skill-check                # SKILL.md linter (validation + scoring across every skill)
composer skill-check-fix            # SKILL.md linter with auto-fix
composer composer-normalize-check   # validate composer.json normalization (dry-run)
composer composer-normalize-fix     # apply composer.json normalization
composer phpcs-check                # PHP CodeSniffer check
composer phpcs-fix                  # PHP CodeSniffer fix
composer pint-check                 # Laravel Pint check
composer pint-fix                   # Laravel Pint fix
composer rector-check               # Rector check (dry-run)
composer rector-fix                 # Rector fix
```

### Testing

```bash
./vendor/bin/pest           # run all tests
composer test:coverage      # run tests with coverage (min. 100%)
```

Remove `coverage.xml` before committing if it was produced locally.

## Author

**Petr Král** — PHP Developer & Laravel programmer, open source contributor ([pekral.cz](https://pekral.cz)).
