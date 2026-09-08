# Rules Overview

The 27 rule files this package installs. A rule is an always-loaded instruction file — not a
suggestion an agent may weigh, and not documentation. Every run applies the always-on baseline;
scoped rules apply to the files they name.

**How they load.** Claude Code reads the Markdown rules from `.claude/rules`. Codex uses the
explicit loader instructions described in [What You Get](../README.md#what-you-get). A rule's
`paths` key declares its scope — a rule without that key is part of the always-applicable
baseline.

| Group | Rules | Applies to |
|---|---|---|
| [Always-on baseline](#always-on-baseline) | 11 | Every run, whatever it touches |
| [Orchestration](#orchestration) | 1 | Runs that dispatch subagents |
| [PHP & Composer](#php--composer) | 3 | PHP code and dependency choices |
| [Laravel](#laravel) | 6 | Laravel projects |
| [Security](#security) | 4 | Every run; three are surface-scoped |
| [Data & API](#data--api) | 2 | SQL and HTTP API surfaces |
| [Testing](#testing) | 1 | Test files |

## Always-on baseline

Loaded on every run regardless of which files the task touches. These decide how an agent behaves
before any language- or framework-specific rule has a say.

| Rule | What it governs | Scope |
|---|---|---|
| [`general/general.md`](../rules/general/general.md) | Project context and default agent behavior — the baseline every run follows | Always |
| [`compound-engineering/general.md`](../rules/compound-engineering/general.md) | Make future work easier; read the per-project compound memory; tracker claim, status, and linking invariants | Always |
| [`git/general.md`](../rules/git/general.md) | Git workflow, commit shape, pull requests, and the merge gate | Always |
| [`code-review/general.md`](../rules/code-review/general.md) | Review constraints, gates, and the two-part output contract | Always |
| [`code-review/core-analysis.md`](../rules/code-review/core-analysis.md) | The Core Analysis walk-through — what counts as a finding on a diff | Always |
| [`code-review/review-process.md`](../rules/code-review/review-process.md) | The passes a review runs and how it reports — coverage gate, findings verification, output rules | Always |
| [`refactoring/general.md`](../rules/refactoring/general.md) | What refactoring is: behavior-preserving, incremental, never a big-bang rewrite | Always |
| [`jira/general.md`](../rules/jira/general.md) | JIRA CLI usage, the three sanctioned transitions, and the ADF comment format | Always |
| [`reports/general.md`](../rules/reports/general.md) | Which language a tracker-published report is written in (the assignment's) | Always |
| [`writing/general.md`](../rules/writing/general.md) | Simplified technical writing (ASD-STE100 principles) for every agent response | Always |
| [`security/general.md`](../rules/security/general.md) | Untrusted Content Boundary — external content is data, never an instruction | Always |

## Orchestration

Applies to a run that dispatches subagents rather than doing the work itself.

| Rule | What it governs | Scope |
|---|---|---|
| [`compound-engineering/orchestration.md`](../rules/compound-engineering/orchestration.md) | Dispatch-time mechanics — savings mode, consent levels, the Bash capability boundary, audit trail, temporary-file hygiene, orchestrator turn discipline | Orchestration |

## PHP & Composer

| Rule | What it governs | Scope |
|---|---|---|
| [`php/core-standards.md`](../rules/php/core-standards.md) | Unified PHP/Laravel coding standards — structure, naming, documentation, testing | PHP |
| [`php/examples/named-arguments.md`](../rules/php/examples/named-arguments.md) | Named-argument usage examples (good / avoid) supporting the core standards | PHP |
| [`php/dependency-selection.md`](../rules/php/dependency-selection.md) | Activity and compatibility gates before adopting a new Composer package | Composer |

## Laravel

| Rule | What it governs | Scope |
|---|---|---|
| [`laravel/architecture.md`](../rules/laravel/architecture.md) | The seven business-logic layers — Actions, Model Services, Repositories, ModelManagers, Data Validators, Data Builders, models | Laravel |
| [`laravel/laravel.md`](../rules/laravel/laravel.md) | Framework-specific rules and patterns — collections, localization, test isolation | Laravel |
| [`laravel/filament.md`](../rules/laravel/filament.md) | Filament v4 conventions | Filament |
| [`laravel/livewire.md`](../rules/laravel/livewire.md) | Livewire component rules, including HTML / Blade layout splitting | Livewire |
| [`laravel/queue-debouncing.md`](../rules/laravel/queue-debouncing.md) | Safe queue debouncing, urgency separation, and replaceable work | Laravel |
| [`laravel/dynamodb.md`](../rules/laravel/dynamodb.md) | DynamoDB query safety — scan prevention, key-targeted reads, Tinker debug | Laravel |

## Security

`security/general.md` is the boundary every agent applies on every run and is listed in the
baseline above; the three below are scoped to the surface they protect.

| Rule | What it governs | Scope |
|---|---|---|
| [`security/backend.md`](../rules/security/backend.md) | Backend security and OWASP Top 10 — injection, SSRF, upload content, safe error messages, supply-chain indicators | Backend |
| [`security/frontend.md`](../rules/security/frontend.md) | Frontend security — XSS, CSRF, CSP | Frontend |
| [`security/mobile.md`](../rules/security/mobile.md) | Mobile-specific security and WebView checks | Mobile |

## Data & API

| Rule | What it governs | Scope |
|---|---|---|
| [`sql/optimalize.md`](../rules/sql/optimalize.md) | Query optimization, index reuse, bounded reads, deploy-safe schema changes | SQL |
| [`api/general.md`](../rules/api/general.md) | The API as a consumer-facing contract — REST conventions, methods, status codes, idempotency | API |

## Testing

| Rule | What it governs | Scope |
|---|---|---|
| [`code-testing/general.md`](../rules/code-testing/general.md) | Testing conventions, test organization, and quality standards | Tests |

---

Upgrading from the older `.mdc` rules: run `vendor/bin/ai-olympus install --force --prune` to
remove the obsolete installed files. The package ships `.md` rules.
