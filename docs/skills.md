# Skill Catalog

All 55 skills this package ships, grouped by what you reach for them for. Each description is the
skill's own `description:` front-matter, trimmed to one line — nothing here claims a capability the
skill does not declare.

A skill is invoked by name: `/skill-name` in Claude Code, `$skill-name` in Codex. Follow any row's
link to read the skill itself.

| Group | Skills |
|---|---|
| [Issue → PR workflow](#issue--pr-workflow) | 10 |
| [Code review](#code-review) | 7 |
| [Security](#security) | 5 |
| [Testing](#testing) | 6 |
| [Databases](#databases) | 5 |
| [Frontend & UI](#frontend--ui) | 7 |
| [Content & writing](#content--writing) | 1 |
| [Infrastructure & performance](#infrastructure--performance) | 3 |
| [Refactoring & code quality](#refactoring--code-quality) | 5 |
| [Analysis & planning](#analysis--planning) | 4 |
| [Meta & tooling](#meta--tooling) | 2 |

## Issue → PR workflow

| Skill | What it is for |
|-------|----------------|
| [`resolve-issue`](../skills/resolve-issue/) | Resolving an issue from any supported tracker (GitHub, JIRA, Bugsnag) |
| [`prepare-issue-context`](../skills/prepare-issue-context/) | Preparing data and context before /resolve-issue, TDD, or CR runs |
| [`verify-merge-readiness`](../skills/verify-merge-readiness/) | A GitHub issue or pull request must be brought to a merge-ready state without merging, then summarized in one source-issue TL;DR while superseded preparation comments are removed safely |
| [`process-code-review`](../skills/process-code-review/) | Processing pull request code review feedback |
| [`merge-github-pr`](../skills/merge-github-pr/) | Safely merge GitHub pull requests that are ready |
| [`pr-summary`](../skills/pr-summary/) | Summarizing current PR changes for the development and product team |
| [`create-issue`](../skills/create-issue/) | Create a single issue from provided text without modifying its content |
| [`create-issues-from-text`](../skills/create-issues-from-text/) | Break down assignment into multiple structured issues |
| [`github-issue-triage`](../skills/github-issue-triage/) | GitHub issues must be prioritized, sorted, or labelled by type |
| [`github-release-roadmap`](../skills/github-release-roadmap/) | Planning a GitHub release roadmap for one repository |

## Code review

| Skill | What it is for |
|-------|----------------|
| [`code-review`](../skills/code-review/) | Senior PHP code review focused on architecture, business logic, and risk detection |
| [`code-review-github`](../skills/code-review-github/) | Perform code review for GitHub pull requests and post findings as PR comments plus a non-technical summary to every linked issue |
| [`code-review-jira`](../skills/code-review-jira/) | Run code review for JIRA issues and publish results to GitHub PR and JIRA |
| [`code-review-bugsnag`](../skills/code-review-bugsnag/) | Run code review for a Bugsnag error and publish results to the linked GitHub PR and the Bugsnag error |
| [`api-review`](../skills/api-review/) | Reviewing HTTP API design in a PR or change set |
| [`assignment-compliance-check`](../skills/assignment-compliance-check/) | Checking that the pull request implementation actually fulfills the business requirements stated in the linked issue or task |
| [`laravel-authorization-review`](../skills/laravel-authorization-review/) | Reviewing authorization / access control in a Laravel project |

## Security

| Skill | What it is for |
|-------|----------------|
| [`security-review`](../skills/security-review/) | Performing a focused security review for Laravel/PHP projects |
| [`security-bounty-hunter`](../skills/security-bounty-hunter/) | Hunting for exploitable, remotely reachable vulnerabilities in a PHP/Laravel codebase for responsible disclosure or a bounty submission, not a general best-practices review |
| [`security-threat-analysis`](../skills/security-threat-analysis/) | Analyzing a specific security threat from a referenced source (CVE, GHSA, security advisory, blog post, or write-up) |
| [`laravel-security`](../skills/laravel-security/) | Building, configuring, or hardening security-sensitive Laravel features |
| [`machine-payments-protocol`](../skills/machine-payments-protocol/) | Implementing, designing, or reviewing the Machine Payments Protocol (MPP) HTTP 402 payment flow in a Laravel/PHP application |

## Testing

| Skill | What it is for |
|-------|----------------|
| [`test-driven-development`](../skills/test-driven-development/) | Implementing a feature or bugfix with strict TDD |
| [`create-test`](../skills/create-test/) | Create or update tests to ensure full coverage for current changes |
| [`create-missing-tests-in-pr`](../skills/create-missing-tests-in-pr/) | A PR review already exists and missing tests must be completed with 100% coverage for current changes |
| [`rewrite-tests-pest`](../skills/rewrite-tests-pest/) | Rewriting existing tests to Pest syntax |
| [`e2e-testing`](../skills/e2e-testing/) | Writing or stabilizing Playwright end-to-end browser tests against a Laravel app |
| [`tester-cookbook`](../skills/tester-cookbook/) | Preparing a concise QA report for an internal tester from a JIRA task and its linked pull requests |

## Databases

| Skill | What it is for |
|-------|----------------|
| [`mysql-patterns`](../skills/mysql-patterns/) | Designing MySQL schema features or applying advanced MySQL patterns in Laravel |
| [`mysql-problem-solver`](../skills/mysql-problem-solver/) | Analyze real MySQL query and schema problems using code inspection, schema review, and EXPLAIN when available |
| [`postgres-patterns`](../skills/postgres-patterns/) | Designing PostgreSQL schema features or applying advanced Postgres patterns in Laravel |
| [`redis-patterns`](../skills/redis-patterns/) | Using Redis in a Laravel app |
| [`laravel-telescope`](../skills/laravel-telescope/) | Analyzing Laravel Telescope requests from URL and DB |

## Frontend & UI

| Skill | What it is for |
|-------|----------------|
| [`frontend-patterns`](../skills/frontend-patterns/) | Building Livewire/Blade/Alpine UI in a Laravel app |
| [`frontend-a11y`](../skills/frontend-a11y/) | Building or reviewing accessible UI in a Laravel app |
| [`frontend-design-direction`](../skills/frontend-design-direction/) | The work is not just making UI function but making it feel purposeful and polished |
| [`frontend-slides`](../skills/frontend-slides/) | Building standalone HTML/CSS/JS presentation slide decks |
| [`diagram-design`](../skills/diagram-design/) | A change, analysis, or document needs a diagram |
| [`design-system`](../skills/design-system/) | Generating, auditing, or reviewing the visual design system of a Laravel app |
| [`seo`](../skills/seo/) | Auditing, planning, or implementing SEO in a Laravel app |

## Content & writing

| Skill | What it is for |
|-------|----------------|
| [`web-article-writer`](../skills/web-article-writer/) | Writing, rewriting, or adapting a publication-ready article for a website or blog |

## Infrastructure & performance

| Skill | What it is for |
|-------|----------------|
| [`docker-patterns`](../skills/docker-patterns/) | Writing or reviewing Docker and docker-compose setups for a Laravel application |
| [`latency-critical-systems`](../skills/latency-critical-systems/) | Working on latency-sensitive Laravel paths |
| [`vite-patterns`](../skills/vite-patterns/) | Configuring or optimizing Vite (laravel-vite-plugin) asset bundling in a Laravel app |

## Refactoring & code quality

| Skill | What it is for |
|-------|----------------|
| [`simplification-audit`](../skills/simplification-audit/) | The user explicitly asks for an audit of the codebase or to refactor a part of the codebase |
| [`class-refactoring`](../skills/class-refactoring/) | Refactor PHP classes to improve structure, readability, and maintainability while preserving behavior |
| [`refactor-entry-point-to-action`](../skills/refactor-entry-point-to-action/) | Refactoring controller, job, command, listener, or Livewire entry-point logic into a dedicated Action class while preserving behavior and response contracts |
| [`git-workflow`](../skills/git-workflow/) | Choosing a Git branching strategy or handling merge vs rebase, conflicts, stashing, undoing mistakes, and release tagging |
| [`cleanup-local-branches`](../skills/cleanup-local-branches/) | Cleaning up local Git branches after origin pruning |

## Analysis & planning

| Skill | What it is for |
|-------|----------------|
| [`analyze-problem`](../skills/analyze-problem/) | Structured problem analysis for debugging, root cause identification, and breaking down complex issues before proposing solutions |
| [`product-capability`](../skills/product-capability/) | A PRD or product intent is clear but the implementation constraints are not |
| [`understand-propose-implement-verify`](../skills/understand-propose-implement-verify/) | Following a strict problem-solving loop: understand, propose, implement, verify |
| [`smartest-project-addition`](../skills/smartest-project-addition/) | You want exactly one high-impact, concrete proposal for the next project addition |

## Meta & tooling

| Skill | What it is for |
|-------|----------------|
| [`skill-creator`](../skills/skill-creator/) | Creating a new Agent skill in this repository |
| [`compact-project-memory`](../skills/compact-project-memory/) | docs/memory/PROJECT_MEMORY.md was just written to |

---

Writing the README itself is not in this catalog.
[`pekral/github-readme-generator`](https://github.com/pekral/github-readme-generator) is a
standalone Agent Skill that builds a repository's root `README.md` from its code, manifests,
scripts, tests, and workflows. Install it with `npx skills add pekral/github-readme-generator`, or
as a Claude Code plugin from its own marketplace.
