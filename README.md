<div align="center">
  <img src="assets/logo.png" alt="AI Olympus" width="280">

<h1>AI Olympus — An AI Development Team for Laravel</h1>

  <a href="https://packagist.org/packages/pekral/ai-olympus"><img src="https://img.shields.io/packagist/v/pekral/ai-olympus" alt="Packagist Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square" alt="MIT Licensed"></a>
  <a href="https://github.com/pekral/ai-olympus/actions/workflows/pr.yml"><img src="https://github.com/pekral/ai-olympus/actions/workflows/pr.yml/badge.svg" alt="Quality Checks"></a>
  <a href="https://packagist.org/packages/pekral/ai-olympus"><img src="https://img.shields.io/packagist/dt/pekral/ai-olympus" alt="Total Downloads"></a>
</div>

**AI Olympus** gives Laravel/PHP teams shared coding standards, 54 reusable skills, and five specialist agents for Claude Code and Codex. The workflows cover issue implementation, Pest tests, code and security review, acceptance testing, and tracker reporting.

> [!WARNING]
> Experimental. Updates can change agent behaviour. The example below follows `dev-master`; review the [changelog](CHANGELOG.md) and proposed changes before upgrading or merging.

## Requirements

PHP and Composer 2 for the installer; Claude Code or Codex for the workflows. GitHub workflows also need an authenticated `gh` CLI. The [Claude plugin](#via-the-plugin-marketplace-no-composer) does not require Composer.

## Installation

Run the two commands in [Quickstart](#quickstart) from your Composer project root. Composer installs the package; the second command installs its Claude Code and Codex integration.

## Configuration

Existing `CLAUDE.md` and `AGENTS.md` are preserved. For an existing `AGENTS.md`, merge the [Codex integration section](AGENTS.md#codex-integration) after installation. Review [overwrite behaviour and settings](#via-composer) before using `--force`. Automatic installation is off by default; [opt-in configuration](docs/installation.md#automatic-installation-via-composer-plugin) enables forced refreshes on Composer install/update when the plugin is allowed.

## Quickstart

```bash
composer require pekral/ai-olympus:dev-master --dev
vendor/bin/ai-olympus install --force
```

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

The Markdown files in `.codex/rules` are an instruction library, **not native Codex command-approval rules**. The root `AGENTS.md` tells Codex to read rules whose `paths` match the task, plus every rule without `paths`.

## Why This Package

- **Issue-to-PR workflow** — separate roles implement, review, test, and report
- **Explicit review gates** — workflows require zero Critical findings and no undeferred Moderate findings before merge
- **Coverage requirements** — implementation skills require tests for the changed behaviour
- **One standard across every repository** — the same PHP/Laravel rules travel with the package instead of being copy-pasted per project
- **54 comprehensive Agent skills** you can invoke directly when you want the workflow without the agent

## Installation Details

Use Composer for the dual Claude Code/Codex installation and CLI. The plugin marketplace is a separate **Claude Code-only** distribution channel.

| | Composer | Plugin marketplace |
|---|---|---|
| Requires | PHP + Composer | Claude Code only |
| Skills, agents | Both Claude Code and Codex locations | Claude Code plugin only |
| Project instructions | Rules, `CLAUDE.md`, `AGENTS.md` | Rules and `CLAUDE.md` via an extra command |
| `--deny-network-bash` and the other opt-in switches | ✅ | ❌ Composer only |
| Unattended runs (`ai-olympus resolve-next`) | ✅ | ❌ Composer only |

### Via the plugin marketplace (no Composer)

```text
/plugin marketplace add pekral/ai-olympus
/plugin install ai-olympus@ai-olympus
```

That loads all 54 skills and the five agents. It does **not** load the rules: Claude Code reads neither `rules/` nor a `CLAUDE.md` out of a plugin directory, so one command copies them into the project once.

```text
/ai-olympus:install-rules
```

It writes `.claude/rules/` and, when the project has none, a `CLAUDE.md` — it never overwrites one you already have. Restart the session afterwards; rules are read at session start.

The opt-in security switches stay bound to the Composer installer. A plugin install writes nothing to `.claude/settings.local.json`.

### Via Composer

The [Quickstart](#quickstart) above carries the two commands. This is what they put in your project for **Claude Code and Codex**:

- `.claude/rules` and `.claude/skills` in the project
- `.claude/agents` (the five subagents)
- `CLAUDE.md` in the project root
- `.codex/rules` (the same rule library), `.agents/skills` (Codex's native skill location), and `.codex/agents` (the five custom-agent adapters)
- `.codex/agent-instructions` (the canonical role definitions shared with Claude Code)
- `AGENTS.md` in the project root

Skills install into the project only. Claude Code uses `.claude/skills`; Codex discovers the same skills from `.agents/skills`. `--global` additionally writes both user locations (`~/.claude/skills` and `~/.agents/skills`), and `--prune-global` clears this package's copies from both. See [Where skills are installed](docs/installation.md#where-skills-are-installed).

> [!IMPORTANT]
> `install` normally copies only missing files; security rule files are refreshed even without `--force`. The Quickstart's `--force` also replaces other installed rules, skills, and agents, so save local customizations first. Neither root instruction file is overwritten. Use `--prune` when upgrading to remove files the package no longer ships.

Installation also sets `includeCoAuthoredBy: false` in `~/.claude/settings.json` when absent and removes this package's obsolete `bash-guard` hook from project settings. These Claude settings apply even when you intend to use Codex. The opt-in `--allow-subagent-writes`, `--allow-bundled-scripts`, and `--deny-network-bash` switches configure Claude Code only; they do not grant Codex permissions. See the [trust model](SECURITY.md).

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
<td width="96" valign="top"><img src="assets/agents/hephaestus.png" alt="hephaestus avatar" width="80"></td>
<td valign="top">

**`hephaestus` — code-writing implementer**

Implements an issue from context or a tracker link, authors its test coverage, runs the relevant tests, then opens a draft PR. It also handles scoped validation after a landing step. The implementation run stops at the PR; authoritative review belongs to `athena`, and final tracker reporting belongs to `hermes`. An explicitly requested merge must use the separate `merge-github-pr` skill.

**Orchestrates:** `resolve-issue`, `create-test`, `create-missing-tests-in-pr`, `e2e-testing`

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/argus.svg" alt="argus avatar" width="80"></td>
<td valign="top">

**`argus` — acceptance tester** · read-only

Exercises changed behaviour on a local running application: APIs over HTTP and UI scenarios in a real browser. Uses the project's `interactive-testing` skill when available. Returns a per-criterion Met / Not met / Blocked verdict with observed evidence; an untested criterion is never Met. Pure refactors and documentation changes do not need this pass. It never edits code, authors tests, merges, or publishes.

**Orchestrates:** `tester-cookbook`, `e2e-testing`

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/daedalus.png" alt="daedalus avatar" width="80"></td>
<td valign="top">

**`daedalus` — engineering-workflow orchestrator** · the front door

Routes a free-form request to the specialists: `hephaestus` for implementation, `athena` for review, `argus` for acceptance testing when needed, and `hermes` for the final report. It can request security analysis before implementation. It does not implement or review code itself. Backlog triage and splitting a broad request into deliverable issues run inline.

**Orchestrates:** `hephaestus`, `athena`, `argus`, `hermes` (dispatched) · `github-issue-triage`, `create-issues-from-text`, `create-issue` (inline)

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/athena.png" alt="athena avatar" width="80"></td>
<td valign="top">

**`athena` — the code-review sentinel** · read-only

The roster's **only** CR agent. Two modes: the authoritative code review after `hephaestus` — code quality, architecture, optimisation **and** security in one pass, one published review, driven to convergence — and an on-demand pre-implementation security analysis that feeds a remediation plan to `hephaestus`. Applies every security rule and labels each finding Critical / Moderate / Minor.

**Orchestrates:** `code-review-github`, `code-review-jira`, `code-review-bugsnag`, `process-code-review`, `security-review`, `laravel-authorization-review`, `laravel-security`, `security-bounty-hunter`, `security-threat-analysis`, `analyze-problem`

</td>
</tr>
<tr>
<td width="96" valign="top"><img src="assets/agents/hermes.png" alt="hermes avatar" width="80"></td>
<td valign="top">

**`hermes` — release announcer & reporter** · read-only

Writes release announcements and publishes the final tracker report after review converges: what changed and how to test it. It uses the shared brief and validation handoff. This reporting role is separate from `athena` publishing the code review. It does not change implementation code.

**Orchestrates:** `resolve-issue/references/source-detection`, `pr-summary`

</td>
</tr>
</table>

### Using the roles and skills

After the [Quickstart](#quickstart), choose a specialist when you do not need the full pipeline. Claude Code examples:

```text
@athena review the current diff
@hephaestus implement the failing upload validation
```

In Codex, ask it to use the corresponding agent by name, as in the `daedalus` example above. Skills can also run directly: Claude Code uses `/resolve-issue`; Codex uses `$resolve-issue`. Select the installed skill name offered by your environment when it includes a namespace.

Ask `daedalus` explicitly for **savings mode** to reduce repeated context gathering. It keeps the same PR/review/feedback artifacts, just less duplicate context re-derivation. This mode is off by default.

Role boundaries, handoffs, savings mode, and troubleshooting are documented in [`docs/agents.md`](docs/agents.md). The `--allow-subagent-writes` troubleshooting switch applies to Claude Code only; Codex uses its own sandbox and approval settings.

## Skill Catalog

All 54 skills, grouped by what you reach for them for. Each description is the skill's own `description:` front-matter, trimmed to one line — nothing here claims a capability the skill does not declare.

### Issue → PR workflow

| Skill | What it is for |
|-------|----------------|
| [`resolve-issue`](skills/resolve-issue/) | Resolving an issue from any supported tracker (GitHub, JIRA, Bugsnag) |
| [`prepare-issue-context`](skills/prepare-issue-context/) | Preparing data and context before /resolve-issue, TDD, or CR runs |
| [`process-code-review`](skills/process-code-review/) | Processing pull request code review feedback |
| [`merge-github-pr`](skills/merge-github-pr/) | Safely merge GitHub pull requests that are ready |
| [`pr-summary`](skills/pr-summary/) | Summarizing current PR changes for the development and product team |
| [`create-issue`](skills/create-issue/) | Create a single issue from provided text without modifying its content |
| [`create-issues-from-text`](skills/create-issues-from-text/) | Break down assignment into multiple structured issues |
| [`github-issue-triage`](skills/github-issue-triage/) | GitHub issues must be prioritized, sorted, or labelled by type |
| [`github-release-roadmap`](skills/github-release-roadmap/) | Planning a GitHub release roadmap for one repository |

### Code review

| Skill | What it is for |
|-------|----------------|
| [`code-review`](skills/code-review/) | Senior PHP code review focused on architecture, business logic, and risk detection |
| [`code-review-github`](skills/code-review-github/) | Perform code review for GitHub pull requests and post findings as PR comments plus a non-technical summary to every linked issue |
| [`code-review-jira`](skills/code-review-jira/) | Run code review for JIRA issues and publish results to GitHub PR and JIRA |
| [`code-review-bugsnag`](skills/code-review-bugsnag/) | Run code review for a Bugsnag error and publish results to the linked GitHub PR and the Bugsnag error |
| [`api-review`](skills/api-review/) | Reviewing HTTP API design in a PR or change set |
| [`assignment-compliance-check`](skills/assignment-compliance-check/) | Checking that the pull request implementation actually fulfills the business requirements stated in the linked issue or task |
| [`laravel-authorization-review`](skills/laravel-authorization-review/) | Reviewing authorization / access control in a Laravel project |

### Security

| Skill | What it is for |
|-------|----------------|
| [`security-review`](skills/security-review/) | Performing a focused security review for Laravel/PHP projects |
| [`security-bounty-hunter`](skills/security-bounty-hunter/) | Hunting for exploitable, remotely reachable vulnerabilities in a PHP/Laravel codebase for responsible disclosure or a bounty submission, not a general best-practices review |
| [`security-threat-analysis`](skills/security-threat-analysis/) | Analyzing a specific security threat from a referenced source (CVE, GHSA, security advisory, blog post, or write-up) |
| [`laravel-security`](skills/laravel-security/) | Building, configuring, or hardening security-sensitive Laravel features |
| [`machine-payments-protocol`](skills/machine-payments-protocol/) | Implementing, designing, or reviewing the Machine Payments Protocol (MPP) HTTP 402 payment flow in a Laravel/PHP application |

### Testing

| Skill | What it is for |
|-------|----------------|
| [`test-driven-development`](skills/test-driven-development/) | Implementing a feature or bugfix with strict TDD |
| [`create-test`](skills/create-test/) | Create or update tests to ensure full coverage for current changes |
| [`create-missing-tests-in-pr`](skills/create-missing-tests-in-pr/) | A PR review already exists and missing tests must be completed with 100% coverage for current changes |
| [`rewrite-tests-pest`](skills/rewrite-tests-pest/) | Rewriting existing tests to Pest syntax |
| [`e2e-testing`](skills/e2e-testing/) | Writing or stabilizing Playwright end-to-end browser tests against a Laravel app |
| [`tester-cookbook`](skills/tester-cookbook/) | Preparing a concise QA report for an internal tester from a JIRA task and its linked pull requests |

### Databases

| Skill | What it is for |
|-------|----------------|
| [`mysql-patterns`](skills/mysql-patterns/) | Designing MySQL schema features or applying advanced MySQL patterns in Laravel |
| [`mysql-problem-solver`](skills/mysql-problem-solver/) | Analyze real MySQL query and schema problems using code inspection, schema review, and EXPLAIN when available |
| [`postgres-patterns`](skills/postgres-patterns/) | Designing PostgreSQL schema features or applying advanced Postgres patterns in Laravel |
| [`redis-patterns`](skills/redis-patterns/) | Using Redis in a Laravel app |
| [`laravel-telescope`](skills/laravel-telescope/) | Analyzing Laravel Telescope requests from URL and DB |

### Frontend & UI

| Skill | What it is for |
|-------|----------------|
| [`frontend-patterns`](skills/frontend-patterns/) | Building Livewire/Blade/Alpine UI in a Laravel app |
| [`frontend-a11y`](skills/frontend-a11y/) | Building or reviewing accessible UI in a Laravel app |
| [`frontend-design-direction`](skills/frontend-design-direction/) | The work is not just making UI function but making it feel purposeful and polished |
| [`frontend-slides`](skills/frontend-slides/) | Building standalone HTML/CSS/JS presentation slide decks |
| [`diagram-design`](skills/diagram-design/) | A change, analysis, or document needs a diagram |
| [`design-system`](skills/design-system/) | Generating, auditing, or reviewing the visual design system of a Laravel app |
| [`seo`](skills/seo/) | Auditing, planning, or implementing SEO in a Laravel app |

### Content & writing

| Skill | What it is for |
|-------|----------------|
| [`web-article-writer`](skills/web-article-writer/) | Writing, rewriting, or adapting a publication-ready article for a website or blog |

### Infrastructure & performance

| Skill | What it is for |
|-------|----------------|
| [`docker-patterns`](skills/docker-patterns/) | Writing or reviewing Docker and docker-compose setups for a Laravel application |
| [`latency-critical-systems`](skills/latency-critical-systems/) | Working on latency-sensitive Laravel paths |
| [`vite-patterns`](skills/vite-patterns/) | Configuring or optimizing Vite (laravel-vite-plugin) asset bundling in a Laravel app |

### Refactoring & code quality

| Skill | What it is for |
|-------|----------------|
| [`simplification-audit`](skills/simplification-audit/) | The user explicitly asks for an audit of the codebase or to refactor a part of the codebase |
| [`class-refactoring`](skills/class-refactoring/) | Refactor PHP classes to improve structure, readability, and maintainability while preserving behavior |
| [`refactor-entry-point-to-action`](skills/refactor-entry-point-to-action/) | Refactoring controller, job, command, listener, or Livewire entry-point logic into a dedicated Action class while preserving behavior and response contracts |
| [`git-workflow`](skills/git-workflow/) | Choosing a Git branching strategy or handling merge vs rebase, conflicts, stashing, undoing mistakes, and release tagging |
| [`cleanup-local-branches`](skills/cleanup-local-branches/) | Cleaning up local Git branches after origin pruning |

### Analysis & planning

| Skill | What it is for |
|-------|----------------|
| [`analyze-problem`](skills/analyze-problem/) | Structured problem analysis for debugging, root cause identification, and breaking down complex issues before proposing solutions |
| [`product-capability`](skills/product-capability/) | A PRD or product intent is clear but the implementation constraints are not |
| [`understand-propose-implement-verify`](skills/understand-propose-implement-verify/) | Following a strict problem-solving loop: understand, propose, implement, verify |
| [`smartest-project-addition`](skills/smartest-project-addition/) | You want exactly one high-impact, concrete proposal for the next project addition |

### Meta & tooling

| Skill | What it is for |
|-------|----------------|
| [`skill-creator`](skills/skill-creator/) | Creating a new Agent skill in this repository |
| [`compact-project-memory`](skills/compact-project-memory/) | docs/memory/PROJECT_MEMORY.md was just written to |

Writing the README itself is not in this catalog. [`pekral/github-readme-generator`](https://github.com/pekral/github-readme-generator) is a standalone Agent Skill that builds a repository's root `README.md` from its code, manifests, scripts, tests, and workflows. Install it with `npx skills add pekral/github-readme-generator`, or as a Claude Code plugin from its own marketplace.

## Unattended Runs

`resolve-next` selects the oldest eligible issue from up to 100 open issues returned by `gh issue list` for the configured labels. It starts one workflow per invocation. Claude Code remains the default; pass `--codex` to use `codex exec` and Codex-style `$skill` invocations.

```bash
vendor/bin/ai-olympus resolve-next --dry-run          # print the chosen issue and the prompt, run nothing
vendor/bin/ai-olympus resolve-next                    # resolve it and leave the pull request for review
vendor/bin/ai-olympus resolve-next --merge            # ...and merge once the review converges
vendor/bin/ai-olympus resolve-next --label=bug --repo=owner/name
vendor/bin/ai-olympus resolve-next --codex             # run the same workflow through codex exec
```

The prompt chains `resolve-issue` → `code-review-github` → `process-code-review` on the selected issue. **Merging is opt-in:** without `--merge`, it tells the agent to leave the pull request open. This is a workflow instruction, not a permission boundary enforced by the CLI.

Issues already carrying `Resolve_by_AI:in-progress` are skipped. Selection does not atomically claim an issue, so overlapping invocations can select the same one; avoid concurrent schedules for the same repository. No eligible issue in the returned list exits `0`.

| Option | Effect |
|--------|--------|
| `--label=NAME` | Only consider issues carrying this label. Repeatable; all of them must match. Defaults to `Resolve_by_AI`. |
| `--repo=OWNER/NAME` | Target another repository instead of the current checkout. |
| `--merge` | Merge the pull request once the review converges. Off by default. |
| `--dry-run` | Print the chosen issue and the prompt without starting an agent run. |
| `--codex` | Use `codex exec` instead of the default `claude -p`. |

> [!IMPORTANT]
> Treat the trigger label as authorization to start work: restrict who can apply it. The command adds no permission-bypass flags. Grant only the permissions the workflow needs in the selected environment; the installer's Claude permission switches do not configure Codex. See [`SECURITY.md`](SECURITY.md).

Requires the [GitHub CLI](https://cli.github.com) (`gh`, authenticated) and either `claude` or, with `--codex`, `codex` on `PATH`. Scheduling every two hours:

```bash
# Linux / macOS — crontab -e
0 */2 * * * cd /path/to/project && vendor/bin/ai-olympus resolve-next >> storage/logs/agent.log 2>&1
```

```powershell
# Windows — Task Scheduler, every 2 hours
schtasks /create /tn "ai-olympus" /sc hourly /mo 2 /tr "cmd /c cd /d C:\path\to\project && vendor\bin\ai-olympus resolve-next"
```

## Rules Overview

Rules included in this package:

| File                                    | Description                                                                                                                                                       | Scope         |
|-----------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| `php/core-standards.md`                 | Unified PHP/Laravel coding standards                                                                                                                               | PHP           |
| `php/examples/named-arguments.md`       | Named-arguments usage examples (good/avoid) supporting the PHP core standards                                                                                     | PHP           |
| `php/dependency-selection.md`           | Composer dependency selection — activity and compatibility gates before adopting a new package                                                                    | Composer      |
| `general/general.md`                    | Project context and default AI agent behavior — the always-on baseline every run follows regardless of which file type it touches                                | Always        |
| `compound-engineering/general.md`       | Compound engineering — make future work easier and read the per-project compound memory                                                                           | Always        |
| `compound-engineering/orchestration.md` | Dispatch-time orchestration mechanics — Savings mode, consent levels, Bash capability boundary, audit trail, temporary-file hygiene, orchestrator turn discipline | Orchestration |
| `git/general.md`                        | Unified git workflow, commits, and pull request rules                                                                                                             | Always        |
| `code-review/general.md`                | Code review constraints, gates, and the two-part output contract                                                                                                  | Always        |
| `code-review/core-analysis.md`          | Code review — the Core Analysis walk-through: the catalog of what counts as a finding on a diff                                                                    | Always        |
| `code-review/review-process.md`         | Code review — the passes the review runs and how it reports: refactoring/DRY, coverage gate, findings verification, output rules                                   | Always        |
| `code-testing/general.md`               | Testing conventions and quality standards                                                                                                                         | Tests         |
| `api/general.md`                        | API design as a consumer-facing contract — REST conventions, HTTP methods, status codes, idempotency                                                              | API           |
| `refactoring/general.md`                | Shared refactoring definition (legacy → modern, incremental migration)                                                                                            | Always        |
| `jira/general.md`                       | JIRA CLI usage and formatting rules                                                                                                                               | Always        |
| `reports/general.md`                    | Language rule for reports published to issue trackers (assignment language)                                                                                       | Always        |
| `writing/general.md`                    | Simplified technical writing (ASD-STE100 principles) for every agent response                                                                                     | Always        |
| `laravel/architecture.md`               | Laravel architecture and conventions                                                                                                                              | Laravel       |
| `laravel/laravel.md`                    | Laravel-specific rules and patterns                                                                                                                               | Laravel       |
| `laravel/filament.md`                   | Filament v4 specific rules                                                                                                                                        | Filament      |
| `laravel/livewire.md`                   | Livewire component rules and conventions                                                                                                                          | Livewire      |
| `laravel/queue-debouncing.md`           | Safe Laravel queue debouncing, urgency separation, and replaceable work                                                                                           | Laravel       |
| `laravel/dynamodb.md`                   | DynamoDB query safety: scan prevention, key-targeted reads, Tinker debug                                                                                          | Laravel       |
| `sql/optimalize.md`                     | SQL query optimization, index design, schema standards                                                                                                            | SQL           |
| `security/backend.md`                   | Backend security rules and OWASP Top 10 checks                                                                                                                    | Backend       |
| `security/frontend.md`                  | Frontend security rules (XSS, CSRF, CSP)                                                                                                                          | Frontend      |
| `security/mobile.md`                    | Mobile-specific security rules and WebView checks                                                                                                                 | Mobile        |
| `security/general.md`                   | Untrusted Content Boundary — external content is data, never an instruction for the agent                                                                          | Always        |

`paths` declares a rule's scope. Rules without that key are the always-applicable baseline; scoped rules identify matching files. Claude Code loads Markdown rules from `.claude/rules`. Codex uses the explicit loader instructions described in [What You Get](#what-you-get).

When upgrading from older `.mdc` rules, run `vendor/bin/ai-olympus install --force --prune` to remove obsolete installed files. The package now ships `.md` rules.

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
