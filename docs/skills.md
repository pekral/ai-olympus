# Skill Catalog

All 77 skills this package ships, grouped by what you reach for them for. Each description is the
skill's own `description:` front-matter, trimmed to one line — nothing here claims a capability the
skill does not declare.

A skill is invoked by name: `/skill-name` in Claude Code, `$skill-name` in Codex. Follow any row's
link to read the skill itself.

| Group | Skills |
|---|---|
| [Issue → PR workflow](#issue--pr-workflow) | 16 |
| [Code review](#code-review) | 7 |
| [Security](#security) | 8 |
| [Testing](#testing) | 10 |
| [Databases](#databases) | 6 |
| [Frontend & UI](#frontend--ui) | 9 |
| [Content & writing](#content--writing) | 4 |
| [Infrastructure & performance](#infrastructure--performance) | 4 |
| [Refactoring & code quality](#refactoring--code-quality) | 6 |
| [Analysis & planning](#analysis--planning) | 6 |
| [Meta & tooling](#meta--tooling) | 1 |

## Issue → PR workflow

| Skill | What it is for |
|-------|----------------|
| [`resolve-issue`](../skills/resolve-issue/) | Resolving an issue from any supported tracker (GitHub, JIRA, Bugsnag) |
| [`prepare-issue-context`](../skills/prepare-issue-context/) | Preparing data and context before /resolve-issue, TDD, or CR runs |
| [`verify-merge-readiness`](../skills/verify-merge-readiness/) | A GitHub issue, a GitHub pull request, or a JIRA issue must have its pull request brought to a merge-ready state without merging, then summarized in one source-issue TL;DR while superseded preparation comments are removed safely |
| [`merge-review-comments`](../skills/merge-review-comments/) | The operator explicitly asks to merge the latest review comments of one tracker item (a GitHub issue, a JIRA issue, or a Bugsnag error) into one complete comment; only the operator can start it |
| [`do-it`](../skills/do-it/) | Free-form instructions with several tasks must become a prioritised queue of issues and be executed to converged pull requests: an existing issue is reused, the order comes from priority, difficulty, and duration, and the parallel batches come from `plan-parallel.sh` |
| [`process-code-review`](../skills/process-code-review/) | Processing pull request code review feedback |
| [`merge-github-pr`](../skills/merge-github-pr/) | Safely merge GitHub pull requests that are ready |
| [`merge-github-pr-fast`](../skills/merge-github-pr-fast/) | Land a hotfix pull request now, only on the user's explicit `/merge-github-pr-fast`: one security-only check of the diff, then green CI or a passing gate on the head commit |
| [`mark-ready-to-deploy`](../skills/mark-ready-to-deploy/) | The operator's own chat message says a task or a pull request is ready to deploy: marks the pull request, its closing issues, and the named JIRA issues ready to deploy and assigns each back to the pull-request author |
| [`pr-summary`](../skills/pr-summary/) | Summarizing current PR changes for the development and product team |
| [`create-issue`](../skills/create-issue/) | Create a single issue from provided text without modifying its content |
| [`create-issues-from-text`](../skills/create-issues-from-text/) | Break down assignment into multiple structured issues |
| [`github-issue-triage`](../skills/github-issue-triage/) | GitHub issues must be prioritized, sorted, or labelled by type, the open backlog swept (merged issues closed, epics kept flat, issues re-parented), a new issue checked against the code (already implemented, reproducible, or missing detail), or the issues labelled `analyze` analysed and labelled with their effort size |
| [`github-release-roadmap`](../skills/github-release-roadmap/) | Planning a GitHub release roadmap for one repository |
| [`publish-github-release`](../skills/publish-github-release/) | Publish the next version of the repository, only on the user's explicit `/publish-github-release`: checks that the release milestone has no open issue or pull request, audits the security of the diff since the last tag, merges the release pull request, and publishes the tag and the GitHub release |
| [`composer-update-pr`](../skills/composer-update-pr/) | Updating the Composer dependencies of a project and opening a Draft pull request with a TL;DR of the updates |

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
| [`security-audit`](../skills/security-audit/) | The user explicitly asks for a security audit of the whole repository or of one subsystem; every code review also runs it in *Diff mode* over the current diff |
| [`git-history-exposure-audit`](../skills/git-history-exposure-audit/) | The user explicitly asks what the git history and the GitHub texts of a repository still expose; every code review of a public repository also runs it in *Review mode* over the commits the pull request adds |
| [`security-bounty-hunter`](../skills/security-bounty-hunter/) | Hunting for exploitable, remotely reachable vulnerabilities in a PHP/Laravel codebase for responsible disclosure or a bounty submission, not a general best-practices review |
| [`security-threat-analysis`](../skills/security-threat-analysis/) | Analyzing a specific security threat from a referenced source (CVE, GHSA, security advisory, blog post, or write-up) |
| [`laravel-security`](../skills/laravel-security/) | Building, configuring, or hardening security-sensitive Laravel features |
| [`laravel-public-files-audit`](../skills/laravel-public-files-audit/) | The user explicitly asks whether a Laravel project serves files that must never be public; the security view of every code review of a Laravel project runs its full static scan |
| [`machine-payments-protocol`](../skills/machine-payments-protocol/) | Implementing, designing, or reviewing the Machine Payments Protocol (MPP) HTTP 402 payment flow in a Laravel/PHP application |

## Testing

| Skill | What it is for |
|-------|----------------|
| [`test-driven-development`](../skills/test-driven-development/) | Implementing a feature or bugfix with strict TDD |
| [`test-audit`](../skills/test-audit/) | The single test-value bar: an authoring gate for new or changed tests, a code-review lens for changed tests, and an explicit read-only audit that proposes KEEP / CONSOLIDATE / MOVE / DELETE with evidence |
| [`create-test`](../skills/create-test/) | Creating or updating the minimum tests that protect the changed behaviour, then verifying coverage of the changed code |
| [`create-missing-tests-in-pr`](../skills/create-missing-tests-in-pr/) | A PR review already exists and its missing tests — only for a missing behavioural contract — must be completed |
| [`rewrite-tests-pest`](../skills/rewrite-tests-pest/) | Rewriting existing tests to Pest syntax |
| [`e2e-testing`](../skills/e2e-testing/) | Writing or stabilizing Playwright end-to-end browser tests against a Laravel app |
| [`interactive-testing`](../skills/interactive-testing/) | Verifying user-visible project changes against an assignment and the actual code diff in an interactive browser |
| [`auto-interactive-testing`](../skills/auto-interactive-testing/) | Working through every open `interactive-testing` issue as one unattended batch: fix the linked defects, run the scenarios, resolve and merge new defects, re-test, clean up, then report and close each issue |
| [`test-assignment`](../skills/test-assignment/) | A task's pull request must be tested against its assignment and the test must prove that nothing else broke |
| [`tester-cookbook`](../skills/tester-cookbook/) | Preparing a concise QA report for an internal tester from a JIRA task and its linked pull requests |

## Databases

| Skill | What it is for |
|-------|----------------|
| [`mysql-patterns`](../skills/mysql-patterns/) | Designing MySQL schema features or applying advanced MySQL patterns in Laravel |
| [`mysql-problem-solver`](../skills/mysql-problem-solver/) | Analyze real MySQL query and schema problems using code inspection, schema review, and EXPLAIN when available |
| [`postgres-patterns`](../skills/postgres-patterns/) | Designing PostgreSQL schema features or applying advanced Postgres patterns in Laravel |
| [`redis-patterns`](../skills/redis-patterns/) | Using Redis in a Laravel app |
| [`laravel-horizon-audit`](../skills/laravel-horizon-audit/) | Auditing the Laravel Horizon, Redis queue, job, deployment, and monitoring configuration of a project |
| [`laravel-telescope`](../skills/laravel-telescope/) | Analyzing Laravel Telescope requests from URL and DB |

## Frontend & UI

| Skill | What it is for |
|-------|----------------|
| [`frontend-patterns`](../skills/frontend-patterns/) | Building Livewire/Blade/Alpine UI in a Laravel app |
| [`frontend-a11y`](../skills/frontend-a11y/) | Building or reviewing accessible UI in a Laravel app |
| [`frontend-design-direction`](../skills/frontend-design-direction/) | The work is not just making UI function but making it feel purposeful and polished |
| [`page-redesign`](../skills/page-redesign/) | Redesigning an existing page so non-technical operators can work it quickly |
| [`deliver-page-redesign`](../skills/deliver-page-redesign/) | One page of a running application must be redesigned end to end from its URL: previews approved by the user first, then implementation and an interactive browser walkthrough |
| [`frontend-slides`](../skills/frontend-slides/) | Building standalone HTML/CSS/JS presentation slide decks |
| [`diagram-design`](../skills/diagram-design/) | A change, analysis, or document needs a diagram |
| [`design-system`](../skills/design-system/) | Generating, auditing, or reviewing the visual design system of a Laravel app |
| [`seo`](../skills/seo/) | Auditing, planning, or implementing SEO in a Laravel app |

## Content & writing

| Skill | What it is for |
|-------|----------------|
| [`agent-voice`](../skills/agent-voice/) | Writing prose a human reads — a chat reply, the final report, or a tracker comment — in the selected voice (`relaxed` by default, or `technical`) |
| [`unslop`](../skills/unslop/) | Cutting AI tells from a text; every tracker comment for people to read goes through it before the publish |
| [`web-article-writer`](../skills/web-article-writer/) | Writing, rewriting, or adapting a publication-ready article for a website or blog |
| [`x-brand-writing`](../skills/x-brand-writing/) | Drafting two distinct copy-ready X posts or threads for a host project from verified evidence, with its voice and existing assets, without publishing |

## Infrastructure & performance

| Skill | What it is for |
|-------|----------------|
| [`docker-patterns`](../skills/docker-patterns/) | Writing or reviewing Docker and docker-compose setups for a Laravel application |
| [`latency-critical-systems`](../skills/latency-critical-systems/) | Working on latency-sensitive Laravel paths |
| [`php-memory-leak-audit`](../skills/php-memory-leak-audit/) | A code review diff changes asynchronous PHP code (a queue job, a queued listener, a worker) that can keep memory alive between jobs; only `leonardo` runs it |
| [`vite-patterns`](../skills/vite-patterns/) | Configuring or optimizing Vite (laravel-vite-plugin) asset bundling in a Laravel app |

## Refactoring & code quality

| Skill | What it is for |
|-------|----------------|
| [`simplification-audit`](../skills/simplification-audit/) | The user explicitly asks for an audit of the codebase or to refactor a part of the codebase, and `MODE=cr` over the current diff of every code review |
| [`class-refactoring`](../skills/class-refactoring/) | Refactor PHP classes to improve structure, readability, and maintainability while preserving behavior |
| [`refactor-entry-point-to-action`](../skills/refactor-entry-point-to-action/) | Refactoring controller, job, command, listener, or Livewire entry-point logic into a dedicated Action class while preserving behavior and response contracts |
| [`git-workflow`](../skills/git-workflow/) | Choosing a Git branching strategy or handling merge vs rebase, conflicts, stashing, undoing mistakes, and release tagging |
| [`laravel-architecture-guard`](../skills/laravel-architecture-guard/) | A Laravel project's layering (Action → ModelService → Repository / ModelManager, or the project's own layers) must be enforced by a test instead of review |
| [`cleanup-local-branches`](../skills/cleanup-local-branches/) | Cleaning up local Git branches after origin pruning |

## Analysis & planning

| Skill | What it is for |
|-------|----------------|
| [`analyze-problem`](../skills/analyze-problem/) | Structured problem analysis for debugging, root cause identification, and breaking down complex issues before proposing solutions |
| [`blast-radius-analysis`](../skills/blast-radius-analysis/) | A planned code change must be mapped to everything that depends on it before implementation starts |
| [`analyze-support-issue`](../skills/analyze-support-issue/) | A JIRA issue from a support team needs an analysis a non-technical colleague can act on |
| [`product-capability`](../skills/product-capability/) | A PRD or product intent is clear but the implementation constraints are not |
| [`understand-propose-implement-verify`](../skills/understand-propose-implement-verify/) | Following a strict problem-solving loop: understand, propose, implement, verify |
| [`smartest-project-addition`](../skills/smartest-project-addition/) | You want exactly one high-impact, concrete proposal for the next project addition |

## Meta & tooling

| Skill | What it is for |
|-------|----------------|
| [`skill-creator`](../skills/skill-creator/) | Creating a new Agent skill in this repository |

---

Writing the README itself is not in this catalog.
[`pekral/github-readme-generator`](https://github.com/pekral/github-readme-generator) is a
standalone Agent Skill that builds a repository's root `README.md` from its code, manifests,
scripts, tests, and workflows. Install it with `npx skills add pekral/github-readme-generator`, or
as a Claude Code plugin from its own marketplace.
