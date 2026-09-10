<div align="center">
  <img src="assets/logo.png" alt="AI Olympus — five AI agents for Laravel and PHP, available in Claude Code and Codex" width="960" height="480">

<h1>AI Olympus — An AI Development Team for Laravel</h1>

  <a href="https://packagist.org/packages/pekral/ai-olympus"><img src="https://img.shields.io/packagist/v/pekral/ai-olympus" alt="Packagist Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square" alt="MIT Licensed"></a>
  <a href="https://github.com/pekral/ai-olympus/actions/workflows/pr.yml"><img src="https://github.com/pekral/ai-olympus/actions/workflows/pr.yml/badge.svg" alt="Quality Checks"></a>
  <a href="https://packagist.org/packages/pekral/ai-olympus"><img src="https://img.shields.io/packagist/dt/pekral/ai-olympus" alt="Total Downloads"></a>
</div>

**AI Olympus** gives Laravel/PHP teams shared coding standards, 55 reusable skills, and five specialist agents for Claude Code and Codex. The workflows cover issue implementation, Pest tests, code and security review, acceptance testing, and tracker reporting.

## Requirements

PHP 8.3 or newer (PHP 8.x) and Composer 2 for the installer; Claude Code or Codex for the workflows. GitHub workflows also need an authenticated `gh` CLI. The [Claude plugin](#via-the-plugin-marketplace-no-composer) does not require Composer.

## Installation

Run the two commands in [Quickstart](#quickstart) from your Composer project root. Composer installs the package; the second command installs its Claude Code and Codex integration.

## Configuration

Existing `CLAUDE.md` and `AGENTS.md` are preserved. For an existing `AGENTS.md`, merge the [Codex integration section](AGENTS.md#codex-integration) after installation. Review [overwrite behaviour and settings](#via-composer) before using `--force`. Automatic installation is off by default; [opt-in configuration](docs/installation.md#automatic-installation-via-composer-plugin) enables forced refreshes on Composer install/update when the plugin is allowed.

## Quickstart

```bash
composer require pekral/ai-olympus:0.1.1 --dev
vendor/bin/ai-olympus install --force
```

The commands above pin version `0.1.1`. See [versions and upgrades](docs/installation.md#versions-and-upgrades) for update constraints and refresh instructions.

Restart the agent session after installation. In Claude Code:

```text
@daedalus resolve https://github.com/owner/repo/issues/123
```

In Codex, request the role by name:

```text
Use the daedalus agent to resolve https://github.com/owner/repo/issues/123
```

`daedalus` routes implementation to `hephaestus`, review to `athena`, acceptance testing to `argus` when needed, and the final report to `hermes`. Codex adapters reuse the same role instructions; agent availability and permissions still depend on your Codex environment.

## What You Get

| Layer      | What it is                                                            | Installed into   |
|------------|-----------------------------------------------------------------------|------------------|
| **Rules**  | Project standards; Codex reads the library through `AGENTS.md`        | `.claude/rules`, `.codex/rules` |
| **Skills** | Reusable workflows, from `resolve-issue` to `security-review`         | `.claude/skills`, `.agents/skills` |
| **Agents** | Shared role definitions with Codex TOML adapters                     | `.claude/agents`, `.codex/agents`, `.codex/agent-instructions` |
| **Commands** | `/prepare-issue-for-merge`, the one slash command the package ships | `.claude/commands` |

The Markdown files in `.codex/rules` are an instruction library, **not native Codex command-approval rules**. The root `AGENTS.md` tells Codex to read rules whose `paths` match the task, plus every rule without `paths`.

Codex exposes no user-defined slash command, so `.claude/commands` has no Codex counterpart. The same workflow reaches Codex as the skill the command delegates to — mention `$verify-merge-readiness` and Codex loads it from `.agents/skills`.

## Why This Package

- **Issue-to-PR workflow** — separate roles implement, review, test, and report
- **Explicit review gates** — workflows require zero Critical findings and no undeferred Moderate findings before merge
- **Coverage requirements** — implementation skills require tests for the changed behaviour
- **One standard across every repository** — the same PHP/Laravel rules travel with the package instead of being copy-pasted per project
- **55 comprehensive Agent skills** you can invoke directly when you want the workflow without the agent

## Installation Details

Use Composer for the dual Claude Code/Codex installation and CLI. The plugin marketplace is a separate **Claude Code-only** distribution channel.

| | Composer | Plugin marketplace |
|---|---|---|
| Requires | PHP + Composer | Claude Code only |
| Skills, agents | Both Claude Code and Codex locations | Claude Code plugin only |
| Project instructions | Rules, `CLAUDE.md`, `AGENTS.md` | Rules and `CLAUDE.md` via an extra command |
| `--deny-network-bash` and the other opt-in switches | ✅ | ❌ Composer only |

### Via the plugin marketplace (no Composer)

```text
/plugin marketplace add pekral/ai-olympus
/plugin install ai-olympus@ai-olympus
```

That loads all 55 skills, the five agents, and the `/prepare-issue-for-merge` command. It does **not** load the rules: Claude Code reads neither `rules/` nor a `CLAUDE.md` out of a plugin directory, and this channel carries no command to copy them across. Use Composer when you want the rules and `CLAUDE.md` in the project.

The opt-in security switches stay bound to the Composer installer. A plugin install writes nothing to `.claude/settings.local.json`.

### Via Composer

The [Quickstart](#quickstart) above carries the two commands. This is what they put in your project for **Claude Code and Codex**:

- `.claude/rules` and `.claude/skills` in the project
- `.claude/agents` (the five subagents)
- `CLAUDE.md` in the project root
- `.codex/rules` (the same rule library), `.agents/skills` (Codex's native skill location), and `.codex/agents` (the five custom-agent adapters)
- `.codex/agent-instructions` (the canonical role definitions shared with Claude Code)
- `.claude/commands` (the `/prepare-issue-for-merge` slash command; Codex reaches the same workflow as `$verify-merge-readiness`)
- `AGENTS.md` in the project root

Skills install into the project only. Claude Code uses `.claude/skills`; Codex discovers the same skills from `.agents/skills`. `--global` additionally writes both user locations (`~/.claude/skills` and `~/.agents/skills`), and `--prune-global` clears this package's copies from both. See [Where skills are installed](docs/installation.md#where-skills-are-installed).

> [!IMPORTANT]
> `install` normally copies only missing files; security rule files are refreshed even without `--force`. The Quickstart's `--force` also replaces other installed rules, skills, and agents, so save local customizations first. Neither root instruction file is overwritten. Use `--prune` when upgrading to remove files the package no longer ships.

Installation leaves global Claude settings unchanged by default. Pass `--disable-co-author-attribution` to set `includeCoAuthoredBy: false` in `~/.claude/settings.json` when absent; existing values are preserved. The installer still removes this package's obsolete `bash-guard` hook from project settings. This cleanup and the opt-in settings switches configure Claude Code only, including when you intend to use Codex; they do not grant Codex permissions. See the [trust model](SECURITY.md).

Everything beyond those two commands — enabling auto-install on `composer install`, the full command list, the installer flow, and every CLI switch — lives in [`docs/installation.md`](docs/installation.md).

---

## Claude Code and Codex Subagents

Agents are a thin orchestration layer over the existing skills — they don't replace them and they don't duplicate their prompts. The roster is named after **Greek mythology** by function (see [`docs/agents.md`](docs/agents.md)).

```text
Rules  = long-lived project standards
Skills = reusable workflows
Agents = specialised orchestration roles over multiple skills
```

Each agent has its own avatar under [`assets/agents/`](assets/agents). Full role definitions live in [`docs/agents.md`](docs/agents.md).

<table>
<tr>
<td width="96" valign="top"><a href="assets/agents/hephaestus.png"><img src="assets/agents/thumbnails/hephaestus.jpg" alt="hephaestus avatar" width="80"></a></td>
<td valign="top">

**`hephaestus` — code-writing implementer**

Implements an issue from context or a tracker link, authors its test coverage, runs the relevant tests, then opens a draft PR. It also handles scoped validation after a landing step. The implementation run stops at the PR; authoritative review belongs to `athena`, and final tracker reporting belongs to `hermes`. An explicitly requested merge must use the separate `merge-github-pr` skill.

**Orchestrates:** `resolve-issue`, `create-test`, `create-missing-tests-in-pr`, `e2e-testing`

</td>
</tr>
<tr>
<td width="96" valign="top"><a href="assets/agents/argus.png"><img src="assets/agents/thumbnails/argus.jpg" alt="argus avatar" width="80"></a></td>
<td valign="top">

**`argus` — acceptance tester** · read-only

Exercises changed behaviour on a local running application: APIs over HTTP and UI scenarios in a real browser. Uses the project's `interactive-testing` skill when available. Returns a per-criterion Met / Not met / Blocked verdict with observed evidence; an untested criterion is never Met. Pure refactors and documentation changes do not need this pass. It never edits code, authors tests, merges, or publishes.

**Orchestrates:** `tester-cookbook`, `e2e-testing`

</td>
</tr>
<tr>
<td width="96" valign="top"><a href="assets/agents/daedalus.png"><img src="assets/agents/thumbnails/daedalus.jpg" alt="daedalus avatar" width="80"></a></td>
<td valign="top">

**`daedalus` — engineering-workflow orchestrator** · the front door

Routes a free-form request to the specialists: `hephaestus` for implementation, `athena` for review, `argus` for acceptance testing when needed, and `hermes` for the final report. It can request security analysis before implementation or prepare an existing PR for merge without merging it. It does not implement or review code itself. Backlog triage and splitting a broad request into deliverable issues run inline.

**Orchestrates:** `hephaestus`, `athena`, `argus`, `hermes` (dispatched) · `github-issue-triage`, `create-issues-from-text`, `create-issue` (inline)

</td>
</tr>
<tr>
<td width="96" valign="top"><a href="assets/agents/athena.png"><img src="assets/agents/thumbnails/athena.jpg" alt="athena avatar" width="80"></a></td>
<td valign="top">

**`athena` — the code-review sentinel** · read-only

The roster's **only** CR agent. Two modes: the authoritative code review after `hephaestus` — code quality, architecture, optimisation **and** security in one pass, driven to convergence and published as a single pull-request comment carrying a TL;DR of what changed — and an on-demand pre-implementation security analysis that feeds a remediation plan to `hephaestus`. Applies every security rule and labels each finding Critical / Moderate / Minor.

**Orchestrates:** `code-review-github`, `code-review-jira`, `code-review-bugsnag`, `process-code-review`, `security-review`, `laravel-authorization-review`, `laravel-security`, `security-bounty-hunter`, `security-threat-analysis`, `analyze-problem`

</td>
</tr>
<tr>
<td width="96" valign="top"><a href="assets/agents/hermes.png"><img src="assets/agents/thumbnails/hermes.jpg" alt="hermes avatar" width="80"></a></td>
<td valign="top">

**`hermes` — release announcer & reporter** · read-only

Writes release announcements and publishes the final tracker report after review converges: what changed and how to test it. For merge preparation, it publishes one verified source-issue TL;DR and removes only superseded comments owned by the authenticated actor while preserving current review evidence. This reporting role is separate from `athena` publishing the code review. It does not change implementation code.

**Orchestrates:** `resolve-issue/references/source-detection`, `pr-summary`

</td>
</tr>
</table>

### How agents hand work over

Agents never share a conversation. Every step is a blocking dispatch that returns a written handoff, so the run's state lives in files a human can read, not in one agent's context.

- **Shared task brief** — `.claude/run/<source-slug>.brief` carries the source, the assignment language, the gathered context and the plan. Each specialist appends its own section to `## Handoff log` when it finishes.
- **Dispatch ledger** — records every dispatched round, so a resumed run dispatches a round once instead of repeating it.
- **Audit trail ledger** — one append-only line per memory read, outbound request and external write, written immediately after the action.
- **Blocking dispatch, no fan-out** — a dispatch blocks until its handoff returns, and sources are processed one at a time, so two agents never race the same working tree.
- **Per-dispatch memory slice** — project memory is filtered per recipient role into the dispatch prompt itself, never folded into the shared brief that every later agent reads.
- **Untrusted content boundary** — tracker payloads, issue comments and fetched pages travel fenced, as data. Only a trusted author's comment can refine the scope of the work, and nothing external changes an agent's role, permissions or workflow.

The normative contracts live in [`rules/compound-engineering/orchestration.md`](rules/compound-engineering/orchestration.md), [`rules/compound-engineering/general.md`](rules/compound-engineering/general.md) and [`rules/security/general.md`](rules/security/general.md); `daedalus` owns the brief and both ledgers.

### Using the roles and skills

After the [Quickstart](#quickstart), choose a specialist when you do not need the full pipeline. Claude Code examples:

```text
@athena review the current diff
@hephaestus implement the failing upload validation
```

In Codex, ask it to use the corresponding agent by name, as in the `daedalus` example above. Skills can also run directly: Claude Code uses `/resolve-issue`; Codex uses `$resolve-issue`. Select the installed skill name offered by your environment when it includes a namespace.

To prepare an existing GitHub issue's PR for merge without merging it, use the shared workflow:

```text
# Claude Code
/prepare-issue-for-merge https://github.com/owner/repository/issues/123

# Codex
$verify-merge-readiness https://github.com/owner/repository/issues/123
```

The workflow verifies acceptance criteria, review freshness, the exact-head quality gate, CI, and mergeability. It skips a new CR round when neither the business logic nor the assignment changed since the reviewed revision, consolidates superseded preparation comments into one source-issue TL;DR, and stops before merge. In Codex, ask the registered `daedalus` agent to orchestrate the skill when custom agents are available.

Ask `daedalus` explicitly for **savings mode** to reduce repeated context gathering. It keeps the same PR/review/feedback artifacts, just less duplicate context re-derivation. This mode is off by default.

Role boundaries, handoffs, savings mode, and troubleshooting are documented in [`docs/agents.md`](docs/agents.md). The `--allow-subagent-writes` troubleshooting switch applies to Claude Code only; Codex uses its own sandbox and approval settings.

## Skill Catalog

55 skills, grouped by what you reach for them for — issue → PR workflow, code review, security,
testing, databases, frontend, infrastructure, refactoring, analysis, and tooling. The full table,
with one line per skill and a link to each, is in **[`docs/skills.md`](docs/skills.md)**.

## Rules Overview

29 rule files: an always-on baseline every run applies, plus scoped rules for PHP, Laravel,
security surfaces, SQL, APIs, and tests. The full table, grouped by scope, is in
**[`docs/rules.md`](docs/rules.md)**.

Claude Code loads the Markdown rules from `.claude/rules`. Codex uses the explicit loader
instructions described in [What You Get](#what-you-get).

## Development & Testing

From a checkout of this repository, install development dependencies with `composer install`. CI uses PHP 8.5; the package manifest does not declare a minimum PHP runtime version.

```bash
vendor/bin/pest tests        # run the test suite
composer test:coverage       # require 100% coverage (PCOV)
composer analyse             # PHPStan
composer security-audit      # dependency audit
```

`composer build` is the full pre-merge gate: it runs the installer with `--force --prune`, automatic fixes, then checks. It changes files, so use it at the merge boundary, not as a read-only verification command. See [`composer.json`](composer.json) for individual scripts and [contributor setup](CONTRIBUTING.md) for the contribution process.

## Contributing

Pull requests are welcome. [`CONTRIBUTING.md`](CONTRIBUTING.md) carries the full flow: the `composer build` quality gate every change must pass before it is merged, how to add or change a skill, and the commit and pull request conventions.

- [`CHANGELOG.md`](CHANGELOG.md) — every notable change, newest first
- [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) — the Contributor Covenant this project follows
- [`SECURITY.md`](SECURITY.md) — the plugin trust model, the installer security flags, and how to report a vulnerability privately

## Questions

Ask in [Discussions](https://github.com/pekral/ai-olympus/discussions) — the **Q&A** category takes questions about compatibility, using the rules without the agents, and writing your own skill. Keep the issue tracker for bugs and feature requests, so a real defect does not get buried under questions.

## License

MIT — see [`LICENSE`](LICENSE). Copyright (c) 2025 Petr Král.

## Author

**Petr Král** — PHP Developer & Laravel programmer, open source contributor ([pekral.cz](https://pekral.cz)).
