---
name: resolve-issue
description: "Use when resolving an issue from any supported tracker (GitHub, JIRA, Bugsnag). Detects the source automatically from the provided link or ID, implements a safe fix or feature, validates with tests, and creates a pull request."
license: MIT
metadata:
  author: "Petr Král (pekral.cz)"
---

## Constraints
- Apply `@rules/php/core-standards.mdc`
- Apply `@rules/php/dependency-selection.mdc` — whenever the resolution flow needs to add a new Composer dependency (Packagist or a GitHub-hosted VCS repository), run the Activity gate + Compatibility gate from that rule before recommending a package, and embed the selection note in the PR description. When no candidate passes the gates, stop and surface the disqualification table to the user instead of adopting an inactive library.
- Apply `@rules/git/general.mdc`
- Apply `@rules/reports/general.mdc`. The **final technical report** this skill posts on the GitHub PR (code-review and security-review summary block) stays in canonical English per the rule's *Exception — technical CR findings on the GitHub PR*. The **non-technical report** posted on the original issue / JIRA ticket / Bugsnag-linked GitHub issue follows the language of the source assignment. Code identifiers, file paths, severity labels, and CLI commands stay verbatim regardless of the surrounding prose language; never mix two natural languages inside a single comment.
- If the current project uses Laravel, also apply `@rules/laravel/laravel.mdc`, `@rules/laravel/architecture.mdc`, `@rules/laravel/filament.mdc`, and `@rules/laravel/livewire.mdc`
- Follow project architecture and testing rules
- Do not expose sensitive/internal details in user-facing messages
- Preserve existing behavior unless explicitly required otherwise

## Use when
- You are given an issue link, URL, or ID from any supported tracker
- You need to implement a bugfix or feature based on the issue

## Source detection

See `references/source-detection.md` for the detection table and rules.

## Preparation

Before starting the resolution flow:
- Switch to the `main` branch and pull the latest changes so the working tree reflects the current state of the repository before creating the feature branch.

## Required approach
- Fully analyze the issue (description, comments, attachments)
- Clearly define scope before writing code
- Classify the task:
  - **Bug** — incorrect existing behavior or runtime error
  - **Feature** — new behavior
- Prefer minimal, safe, and readable changes
- Keep scope limited unless related fixes are trivial and safe
- When implementing DB work, prefer batch operations over per-row queries inside loops per `@rules/sql/optimalize.mdc` "Batch over per-row operations" — ModelManager `batchUpdate` / `batchInsert`, `whereIn(...)->delete()`, or a single bulk read keyed in memory. Per-row queries are allowed only when iterations have an unavoidable side-effect dependency that is justified in a code comment.

## Execution

1. Verify the issue belongs to the current project before proceeding:
   - **GitHub:** the issue repository must match the current Git remote origin.
   - **JIRA:** the issue project key must match the configured JIRA project for this repository.
   - **Bugsnag:** the error's linked GitHub issue/PR repository (`linkedIssues[]` in the loaded JSON) must match the current Git remote origin. When the error has no linked GitHub issue, confirm the Bugsnag project corresponds to this repository before proceeding.
   - If the issue does not belong to the current project, refuse to process it and inform the user.
   - **The issue must be open / active.** Read the status field off the loaded JSON and refuse to resolve a task that is already closed / resolved / done:
     - **GitHub:** the issue (or PR) `state` must be `OPEN`. Refuse when it is `CLOSED`.
     - **JIRA:** the issue must not sit in a terminal status — anything in the `Done` status category (`Done`, `Closed`, `Resolved`, `Cancelled`, or the project's equivalent). Refuse when it is.
     - **Bugsnag:** the error `status` must be `open`. Refuse when it is `fixed`, `ignored`, or `snoozed`.
   - If the issue is not open / active, **do not resolve it** — stop and inform the user that the task is closed and must be reopened before it can be worked on.
   - **Detect a reopened task.** While verifying the open state, also determine whether the issue was closed and reopened in the past — a reopened task is a **continuation of earlier work**, not a fresh assignment. Read the signals off the JSON already loaded by the deterministic loader:
     - **GitHub:** `stateReason` is `REOPENED` — the authoritative signal. A **merged** PR in `closingPullRequests[]` corroborates it; a closed-unmerged PR alone is an abandoned attempt, not evidence of a reopen.
     - **JIRA:** a comment or the issue changelog (read via the JIRA MCP server — the deterministic loader intentionally does not carry it) records a prior Done / Resolved / Closed status while the issue sits in an active status again. A merged PR in `pullRequests[]` / `devSummary` alone is **not** sufficient — phased tasks merge PRs while staying In Progress.
     - **Bugsnag:** the mirrored GitHub issue in `linkedIssues[]` was reopened, or comments show the error was previously marked fixed and has regressed.
     When any signal matches, mark the run as a **reopened continuation** and apply the *Reopened task (mandatory deep pass)* clause of the comment analysis in step 5 before making any scoping decision.
   - **Claim the issue immediately** (per `@rules/compound-engineering/general.mdc` *Claim a tracker issue before working on it*). Do this before any code change.
     - **GitHub:** re-read the issue via `skills/code-review-github/scripts/load-issue.sh <URL>`. If the label `Resolve_by_AI:in-progress` is already present → another run owns it → **abort** with the message `Issue #<N> already claimed (Resolve_by_AI:in-progress) — another run is working on it`. If absent → apply it: `gh issue edit <N> --add-label "Resolve_by_AI:in-progress"`. Then **re-read and verify** the label actually landed (external writes can be silently blocked in auto-mode; verify against the tracker, not just the command exit code). If it did not land → **abort** rather than proceed unclaimed. Note: the apply-then-verify is not perfectly atomic (GitHub has no CAS on labels), but it collapses the race window to the gap between two loader reads — adequate to stop two long-running agent pipelines from colliding.
     - **JIRA:** run `skills/code-review-jira/scripts/transition-to-in-progress.sh <KEY|URL>`. Exit 0 = claimed (or idempotent no-op for this run). Exit 4 = issue is already past In Progress from another run → **abort** with the message `Issue <KEY> is already past In Progress — another run may be working on it`. Exit 5 = target status name differs for this project — discover the real name via the JIRA MCP server's available-transitions and re-run with it as the `STATUS` argument, or ask a human. Any other non-zero exit → stop and report the failure. This is the second sanctioned status transition (the first is the Code Review transition on PR open); all others remain human-only.
     - **Bugsnag:** no claim step. Bugsnag has no auto-claim mechanism; parallel-collision protection for Bugsnag is a known limitation — rely on the human/linked-issue workflow.
     - **Release on Blocked / abort (before PR):** if this run stops `Blocked` or aborts before a PR is opened, it must release its own GitHub claim label: `gh issue edit <N> --remove-label "Resolve_by_AI:in-progress"`. JIRA does not auto-revert (transitions back are human-only); name the issue key in the Blocked handoff so a human can reset it. If the claim was never applied (e.g. abort happened before the claim step), skip the release.
2. Fetch and analyze the issue from the detected source by running the deterministic loader for that tracker — never call `gh`, `acli`, or REST endpoints directly. Read all required fields off the resulting JSON document.
   - **GitHub:** `skills/code-review-github/scripts/load-issue.sh <URL>` for the structured JSON, or `skills/code-review-github/scripts/gather-issue-context.sh <URL>` for a full Markdown context brief in one pass (issue/PR + comments + changed files + commits + reviews + CI checks + recursively-loaded linked issues/PRs + an inventory of external URLs to follow). Both scripts always take the full GitHub URL (`https://github.com/<owner>/<repo>/issues/<N>`), never a bare number or `#<N>` — the loader rejects bare numbers, so build the URL from the current repo's `origin` remote first when the assignment gives only a number. Read attachment content and the inventoried URLs with your own tools; follow useful links recursively to a sensible depth. If the script is unavailable (missing tool, exit code 2/3), fall back to the GitHub MCP server.
   - **JIRA:** `skills/code-review-jira/scripts/load-issue.sh <KEY|URL>` for the structured JSON, or `skills/code-review-jira/scripts/gather-issue-context.sh <KEY|URL>` for a full Markdown context brief in one pass (issue + comments + attachments + recursively-loaded linked issues + an inventory of external URLs to follow). Read attachment content and the inventoried URLs with your own tools — `acli` cannot fetch them; follow useful links recursively to a sensible depth. If the script is unavailable (missing tool, exit code 2/3), fall back to the JIRA MCP server.
   - **Bugsnag:** `skills/code-review-bugsnag/scripts/load-issue.sh <URL|TRIPLE>` (requires `BUGSNAG_TOKEN`), or `skills/code-review-bugsnag/scripts/gather-issue-context.sh <URL|TRIPLE>` for a full Markdown context brief in one pass (error header + latest event + in-project stacktrace + comments + linked issues + an inventory of external URLs). The JSON carries the error class, message, status, `context`, the in-project `latestEvent.stacktrace` frames (the entry point for the TDD reproduction), `comments[]`, and `linkedIssues[]` (the mirrored GitHub issue/PR). If the script is unavailable (missing tool/token, exit code 2/3), fall back to a Bugsnag MCP server.
3. Define exact requirements and expected behavior.
4. Classify the task (bug or feature).

### Comment analysis

5. Before analyzing the problem, fetch and read **all comments and replies** from the issue tracker (GitHub, JIRA, or Bugsnag). For GitHub, JIRA, and Bugsnag issues, read `comments[]` directly off the JSON loaded in step 2 — do not issue a second listing call:
   - Group comments by conversation thread (e.g., review threads, reply chains).
   - For each thread, determine:
     - **Current requirements** — requests or conditions that are still valid and unfulfilled.
     - **Resolved items** — requirements already addressed by merged PRs or subsequent comments.
     - **Outdated items** — requests superseded by newer comments or decisions.
   - Use only the **current requirements** (combined with the issue description) as input for the next step.

   **Reopened task (mandatory deep pass).** When step 1 marked the run as a reopened continuation, the comment analysis above is blocking and gains these obligations:
   - Read the comments posted **after the most recent close / merge** first — they carry why the task was reopened and what still fails or is missing; wherever they conflict with the original description, the post-reopen comments win.
   - Load every earlier PR linked to the issue (`closingPullRequests[]` for GitHub, `pullRequests[]` / `devSummary` for JIRA, the mirrored issue's PRs for Bugsnag) via the tracker's deterministic loader and record what already landed. Classify that delivered work as **Resolved items** — never reimplement or revert it unless a post-reopen comment explicitly asks for it.
   - Derive the **continuation scope**: current requirements = the delta demanded by the post-reopen comments plus any originally stated requirement that verifiably never landed — not the original assignment from scratch.
   - If no comment or linked activity explains why the task was reopened, stop as **Blocked**: post a question on the tracker asking for the reopen reason and release the claim per step 1 (*Release on Blocked / abort (before PR)*) — never guess the continuation scope.

### Context preparation (mandatory pre-flight)

Run `@skills/prepare-issue-context/SKILL.md` with `MODE=resolve-issue` and the same issue reference. It extracts every scenario from the assignment's *Jak otestovat* / acceptance criteria, maps each scenario to a concrete code path, seeds the development database with the records the scenarios depend on, and runs a one-shot reproduction. Stop immediately and surface the gap list to the user when the skill returns `blocked: <count> open gap(s)` — do **not** continue into problem analysis with incomplete context, because an implementing agent forced to guess at missing data is the most common source of hallucinated fixes. The scenario table the skill produces is the canonical input for the next step.

### Problem analysis

6. **Gate — assignment specificity.** The pre-flight in step 5 already guarantees every scenario is mapped to a concrete code path; this gate only decides how clear the *requirements* are. Pick **specific** or **general** based on the scenario table and the current requirements from comment analysis:
   - **Specific** — expected behavior is unambiguous for every scenario, and the root cause (for bugs) or target behavior (for features) is explicitly stated in the assignment or current requirements. **Skip** `@skills/analyze-problem/SKILL.md` and use the scenario table together with the current requirements as the input for step 7.
   - **General** — requirements are vague, acceptance criteria are missing or open-ended, or the root cause is not identified. When in doubt, treat the assignment as general. **Run** `@skills/analyze-problem/SKILL.md` using the issue description, the scenario table, current requirements, and any available context, and use its output as the input for step 7.
7. Review the input from step 6 and split the identified items into three groups:
   - **In scope** — items that directly match the issue requirements. These will be implemented.
   - **Pre-existing issues** — bugs, project-rule violations, or security vulnerabilities already present in the affected files before this task (see *Pre-existing issue handling* below). These will be fixed in **separate commits** inside the same PR.
   - **Out of scope (deferred)** — valid findings that fall outside the current issue **and** do not qualify as pre-existing issues to fix now (e.g. enhancements, refactors, future features). These will be added to the PR summary as a `## TODO` list for future tasks **and each will be filed as a follow-up issue in the originating tracker** per `@rules/compound-engineering/general.mdc` *File deferred points as follow-up tracker issues* (see *Deferred-item follow-up issues* below).

### Read, Map & Verify before implementing (mandatory pre-flight)

Reading, mapping, and verifying come first; implementing comes last. This pre-flight is **blocking** — do not add or modify a single line of production code until all three steps pass, and never act on an assumption you have not confirmed by reading the code. (The context preparation above maps scenarios to code paths; this gate grounds the actual implementation in the real files you are about to change.)

1. **Read** — open and read the actual files you will change and the code they depend on (callers, called methods, related tests, configuration, migrations). Confirm what the code does by reading it, not by guessing from names or the issue description.
2. **Map** — map the change's blast radius: every call site, caller, data-flow path, and existing test that the in-scope change touches, plus the conventions, helpers, Services, and Actions already in the codebase to reuse instead of reinventing.
3. **Verify** — check your assumptions against the real code and its observed behavior (for bugs, reproduce the failure; for features, confirm the integration points exist as assumed). If reading and mapping contradict the issue framing or the scenario table, stop and surface the discrepancy instead of implementing on a wrong premise.

Only after Read, Map, and Verify are complete may phase planning and implementation begin.

### Phase planning (commit plan)

Before writing any code, decide how the in-scope work will be split into commits within the PR, applying the **one phase = one commit** rule from `@rules/git/general.mdc` *Git Rules*.

1. **Detect existing phases** in the issue description and the kept comments. Phase markers include explicit headings such as `Phase 1`, numbered milestones, ordered acceptance-criteria blocks, or a step-by-step plan written by the reporter.
2. **If phases exist:** treat each phase as exactly **one commit**. Keep the original phase order as commit order. Do not merge, reorder, or re-scope phases.
3. **If no phases exist but the assignment is long or covers multiple distinct concerns:** propose a phased breakdown — each phase must be independently reviewable and yield a working state — then map **one phase per commit**.
4. **If the assignment is small and atomic:** keep it as a single commit. Do not invent artificial phases.
5. Record the planned phases as a numbered list (one line per commit, with the intended commit message in `type(scope): description` form per `@rules/git/general.mdc`) **before** starting implementation. This list is the commit plan for step 11.
6. During implementation, commit at the end of each phase. Run pre-push fixers and tests on the changes belonging to that phase before moving on.

### Pre-existing issue handling

While reading and modifying the files required for the in-scope work, you may encounter problems that are **unrelated to the current assignment** but were already present in those files. The following categories qualify as pre-existing issues that must be fixed in this PR:

- **Bugs** — incorrect logic, broken edge cases, null-dereference risks, race conditions, or runtime errors that exist before this task.
- **Project-rule violations** — code that contradicts any rule listed in this skill's *Constraints* block (`@rules/php/core-standards.mdc`, `@rules/laravel/*`, `@rules/sql/optimalize.mdc`, etc.) or any other rule under `.claude/rules/`.
- **Security vulnerabilities** — anything `@rules/security/backend.md`, `@rules/security/frontend.md`, or `@rules/security/mobile.md` would flag (injection, missing authn/authz, unsafe deserialization, sensitive-data exposure, …).

Rules:

1. **Do not silently ignore** a pre-existing issue you encountered in a file you had to read for the in-scope work — fix it in this PR.
2. **Do not expand scope** by actively scanning unrelated files for additional pre-existing issues. Limit attention to files already touched by the in-scope changes (or their direct dependencies you must read to understand the change).
3. Land each pre-existing fix in its **own separate commit** inside the same PR:
   - Use a Conventional Commits subject per `@rules/git/general.mdc`: `fix(<scope>): pre-existing — <description>` for bugs and security, `refactor(<scope>): pre-existing — <description>` for rule violations without behavior change.
   - The `pre-existing — ` prefix is mandatory so reviewers can identify these commits at a glance (e.g. `fix(user): pre-existing — null check before dispatching welcome mail`).
   - **Test coverage workflow depends on the commit type:**
     - `fix(<scope>): pre-existing — …` (bug, security) — add the regression test in the **same commit** as the fix; the test must fail before the fix lands and pass after.
     - `refactor(<scope>): pre-existing — …` (project-rule violation, behavior-preserving) — apply `@rules/refactoring/general.mdc` *Test Coverage Contract*: when the target lines are below 100% coverage, author a dedicated `test(<scope>): cover <area> before pre-existing refactor` commit **before** the refactor commit, and do **not** modify pre-existing tests inside the refactor commit (mechanical renames forced by the refactor itself stay exempt and must be flagged in the commit body).
   - Either way, pre-existing fixes follow the same 100% coverage rule on changed lines as in-scope changes (step 16).
4. Order pre-existing fix commits **before** the in-scope commits in the commit plan from the previous section, so they form an independently revertable base. Update the recorded commit plan to include them before starting implementation.
5. If a pre-existing issue is **non-trivial** (would significantly expand the PR, requires architectural decisions, or affects shared infrastructure beyond the touched files), do **not** fix it inline. Move it to the *Out of scope (deferred)* group from step 7 and surface it under the PR's `## TODO` section with a one-line reason for deferral.

### If bug

**Mandatory: strict TDD — failing test first, blocking.**

Run `@skills/test-driven-development/SKILL.md` as the governing cycle for every bug fix. The Iron Law (`NO PRODUCTION CODE WITHOUT A FAILING TEST FIRST`) applies without exception:

8. Write or update a test that reproduces the bug (the failing test). Follow the RED step in `@skills/test-driven-development/SKILL.md`.
9. **Verify RED** — run the test and confirm it fails for the expected reason, not because of a syntax, setup, or typo issue. **Do not proceed to the fix until the test is observed failing.** This step is mandatory and blocking.
10. Apply the fix (GREEN step) — write the smallest production change that makes the test pass, then verify all relevant tests pass.

### If feature
8. Design a minimal implementation aligned with project architecture.

### Continue
11. Implement the solution for all **in-scope** items identified in step 7.
12. Ensure no sensitive data is exposed in error/validation messages. Apply `@rules/security/backend.md` *Safe Validation & Error Messages* (and `@rules/security/frontend.md` / `@rules/security/mobile.md` for the equivalent client surfaces) to every user-facing string the change touches, **including every locale shipped by the project** — auth, password-reset, sign-up, and account-lookup flows must return one generic message with one response shape so the wording cannot be used for identity enumeration, authorization-denied responses must not confirm the resource exists, and no stack traces / file paths / framework versions / DB or queue / cache identifiers / verbatim attacker input reach the response body.
    Apply `@rules/security/backend.md` *Malicious Code & Supply-Chain Indicators* (issue #549) to every line the change adds in application code, shell / deploy / CI scripts, and installer hooks — never introduce a silent `curl -s … | sh`, disabled TLS validation (`curl -k`, `CURLOPT_SSL_VERIFYPEER => false`, `NODE_TLS_REJECT_UNAUTHORIZED=0`), suppressed error output on a security-relevant command, or a hidden `/tmp` file paired with a detached background process; route downloads through allow-listed checksum-verified HTTPS and background work through the project's queue / scheduler.
13. If the implementation introduced new database migrations, run them (`php artisan migrate` for Laravel projects, or the project-specific equivalent) before executing the affected tests or creating the pull request.
14. Run tests for affected areas and confirm correctness.
15. Add or update tests to cover the new or fixed behavior.
16. Verify 100% code coverage for all changed or added code paths — if coverage tooling exists, run it and confirm the result before proceeding.

## Pre-push quality gates

Follow the workflow defined in `references/quality-gates.md`.

## Code quality self-check (single pass)

After implementation and pre-push quality gates pass, and **before creating the pull request**, run one self-check pass on the local changes:

1. **Run the review inline.** Invoke `@skills/code-review/SKILL.md` directly in this skill's context, passing the current branch / diff context plus the instruction "run `@skills/code-review/SKILL.md` on the local changes and return the Critical / Moderate / Minor findings with their reproducer fields (Faulty Example, Expected Behavior, Test Hint, Suggested Fix)". Do not dispatch the review as a subagent — run it sequentially in the current context.
2. If **Critical** or **Moderate** findings exist:
   - Apply the **Suggested Fix** snippet from each finding directly to the working tree
   - Add or update a reproducer test for each finding using its **Faulty Example**, **Expected Behavior**, and **Test Hint**
   - Re-run the pre-push quality gates on touched files
3. **Do not iterate the review to convergence.** This self-check runs exactly once: one review pass, one fix round for the findings it surfaced, one quality-gates re-run. Convergence to 0 Critical + 0 Moderate is owned exclusively by the authoritative post-PR review loop (`code-review-github` / `process-code-review` — the `argos` ‖ `athena` ↔ `talos` loop), which reviews the full diff again after the PR exists; duplicating that convergence here doubles the review cost without raising the quality bar of the merged result.

PR-comment processing via `@skills/process-code-review/SKILL.md` remains the path used **after** a PR exists; it is not part of this pre-PR self-check because it requires an open PR to operate on.

## Testing

After the code quality self-check pass, and **still before creating the pull request**, validate the change:

1. **Run the security review inline.** Invoke `@skills/security-review/SKILL.md` directly in this skill's context, passing the current diff context plus the instruction "run `@skills/security-review/SKILL.md` on the local changes and return the Critical / Moderate / Minor findings". Do not dispatch the review as a subagent — run it sequentially in the current context.

Apply the **Suggested Fix** for any **Critical** or **Moderate** finding from the security review and re-run the pre-push quality gates on touched files. Like the code quality self-check, this is a single pass — do not re-enter a review loop; the authoritative post-PR convergence loop re-validates the full diff.

## Pull request

**Creating the pull request is the default, mandatory final step.** Once review and testing are clean, open the PR automatically — applying the valid git rules and PR definitions in this section — **without asking the user for confirmation**. The skill is not finished until the PR exists.

**Opt-out — the user must explicitly ask to skip the PR.** Only when the user's request explicitly states that no pull request should be created (e.g. "don't open a PR", "no PR", "just implement locally", "leave it on the branch") do you skip PR creation. A silent or ambiguous request is **not** an opt-out — when in doubt, create the PR. When the user did opt out:
- Still run the full flow through implementation, the code quality self-check, and the security review — only the PR creation and **every step that depends on an open PR** are skipped: the technical report on the PR, the non-technical report on the original tracker, the *Deferred-item follow-up issues* step, the JIRA Code-Review transition, the GitHub `ready for review` label, and the compound-memory step (`@skills/record-project-memory/SKILL.md`). None of them run without a PR — report the deferred items in the handoff instead so they are filed when the PR opens.
- Commit the changes on the local feature branch (do **not** push or open the PR) and leave the working tree on that branch.
- Release the tracker claim the same way the before-PR release does (*Release on Blocked / abort (before PR)* in step 1) — this is a deliberate stop, not a failure, but no PR will own the claim, so removing the `Resolve_by_AI:in-progress` label lets a human pick the issue up. Name the issue / key in the handoff.
- Report what was implemented, the review/security outcome, and the exact `gh pr create --draft …` command the user can run later to open the PR.

Once review and testing are clean and the user has **not** opted out:

- Create a branch (name always in English, regardless of the assignment language) and commit changes following `@rules/git/general.mdc`
- **Open the pull request as a Draft** (`gh pr create --draft …`) per `@rules/git/general.mdc` *Draft pull requests*. The inline self-check above is the implementer's single-pass pre-PR self-check, **not** the authoritative code review — the authoritative `code-review-github` / `process-code-review` (the `argos` / `athena` ↔ `talos` convergence loop) still runs **after** the PR exists, so at creation time the PR is not yet ready to merge and agents will keep working on it. It is promoted out of Draft (`gh pr ready`) by `@skills/process-code-review/SKILL.md` once that review converges to 0 Critical + 0 Moderate.
- Create the pull request with:
  - clear description of the change
  - reference to the original issue
  - testing instructions
  - **Summary** — concise overview of what changed and why
  - **Pre-existing fixes** — if any pre-existing issues were fixed per *Pre-existing issue handling*, list each fix commit under a `## Pre-existing fixes` section with a one-line rationale so reviewers can review them independently of the assignment
  - **TODO list** — if any **out-of-scope (deferred)** items were identified in step 7 (or non-trivial pre-existing issues were deferred), include them under a `## TODO` section as a checklist of potential follow-up tasks; each entry is then cross-linked to its follow-up tracker issue by the *Deferred-item follow-up issues* step below

### Deferred-item follow-up issues

Every item the run knowingly deferred — the *Out of scope (deferred)* group from step 7 and every non-trivial pre-existing issue deferred per *Pre-existing issue handling* rule 5 — must be registered as a **new issue in the originating tracker** per `@rules/compound-engineering/general.mdc` *File deferred points as follow-up tracker issues*. A PR `## TODO` checklist alone is not durable: the PR gets merged and the list is forgotten. Run this step right after the PR exists (so the new issue can link to it) and before the final report:

1. **Deduplicate** — search the tracker for an existing open issue covering the point first (`gh issue list --search "<keywords>" --state open` for GitHub; a JQL search via `acli` for JIRA). When one exists, reference that issue in the `## TODO` entry instead of filing a duplicate.
2. **File** — create the issue in the originating tracker, carrying the deferred point verbatim, the deferral reason, and links to the source task and this PR (follow the create-without-rewriting convention of `@skills/create-issue/SKILL.md`):
   - **GitHub:** `gh issue create --title "<short point>" --body "<verbatim point + reason + source/PR links>"` in the source repository. When the repository uses a backlog label for agent-resolvable work (e.g. `Resolve_by_AI`), apply it so the selection flow can pick the issue up; skip when no such label exists. Also select and apply the single most relevant existing content label per `@rules/compound-engineering/general.mdc` *Label newly created tracker issues*.
   - **JIRA:** create the issue in the source project via `acli` (fall back to the JIRA MCP server when `acli` is unavailable). Also select and apply the single most relevant existing label per `@rules/compound-engineering/general.mdc` *Label newly created tracker issues*.
   - **Bugsnag:** file a GitHub issue in the repository of the error's `linkedIssues[]` (inherits the GitHub label-selection mechanics per `@rules/compound-engineering/general.mdc` *Label newly created tracker issues*).
3. **Verify** — re-read the created issue via the tracker's deterministic loader and confirm it exists; external writes can be silently blocked in auto-mode. When filing fails or is blocked, list the unfiled point in the final report as a blocker for a human to file manually — never report the deferral as handled without a live issue URL.
4. **Cross-link** — update the PR `## TODO` entry with the created issue URL (`- [ ] <point> — filed as <issue URL>`).

## Final report

Reporting is split by audience and destination:

### Technical report → codebase tracker (GitHub PR)

Post the technical report as a comment on the GitHub PR, since that is where the codebase and testing state live. It must contain:

- **Code review summary** — outcome of `@skills/code-review/SKILL.md` (findings addressed during the loop and the final clean state)
- **Security review summary** — outcome of `@skills/security-review/SKILL.md`

### Non-technical report → original task tracker

Post the non-technical report on the issue tracker where the task with the assignment was created (the original tracker, regardless of where the PR lives):

- **GitHub** (task filed as a GitHub issue): post as a comment on the original issue
- **JIRA** (task filed in JIRA): post as a JIRA comment formatted with JIRA Wiki Markup per `@rules/jira/general.mdc` (no Markdown headings, fenced code blocks, or tables)
- **Bugsnag** (task originated from a Bugsnag error): post the non-technical report as a comment directly on the Bugsnag error via `skills/code-review-bugsnag/scripts/upsert-comment.sh <URL|TRIPLE> -` (requires `BUGSNAG_TOKEN`; falls back to a Bugsnag MCP server when the script is unavailable). Also mirror it as a comment on the linked GitHub issue from `linkedIssues[]` when one exists.

The non-technical report must be understandable by non-technical testers and product managers and contain:

- **What changed:** a brief, plain-language summary of the fix or feature
- **How to test:** step-by-step instructions a tester can follow to verify the change works correctly
- **Risk areas and edge cases:** specific scenarios the tester should focus on to catch potential regressions or unexpected behavior
- **Pre-existing fixes also covered by this PR (when any):** plain-language one-line summary per pre-existing fix commit produced by *Pre-existing issue handling*, plus a one-line "what to re-verify" hint per fix so the tester knows the additional regression surface to validate. Omit the bullet entirely when no pre-existing fix landed.

### Compound memory (record durable lessons)

After the reviews converged (no Critical / Moderate) and the reports are posted, run `@skills/record-project-memory/SKILL.md` with the converged task context and the PR link. It writes to the project memory file (`docs/memory/PROJECT_MEMORY.md`) **only** the lessons that clear the promotion bar in `@rules/compound-engineering/general.mdc` *Compound Memory (per project)* — a trivial task records nothing. This is how a review finding or a non-obvious decision from this PR stops recurring on the next task.

### GitHub-specific follow-up
- If the original repository uses a `ready for review` (or equivalent) label, apply it to the source issue once the PR is open to signal it is ready for reviewers. Skip this step when the project does not use such labels.

### JIRA-specific follow-up
- Link the created PR back to the JIRA issue.
- Once the PR is open, move the issue to the project's Code Review status by running `skills/code-review-jira/scripts/transition-to-code-review.sh <KEY|URL>`. This is the second sanctioned status transition (the first is the In Progress claim at the start of work via `skills/code-review-jira/scripts/transition-to-in-progress.sh`; per `@rules/jira/general.mdc`); the helper refuses any non-review target and only reports success after confirming the issue actually reached the review column. When it exits 5 — the review-status name differs for this project and could not be auto-resolved — discover the real name via the JIRA MCP server's available-transitions and re-run with it as the `STATUS` argument, or ask a human. Perform no other status transition; all others remain human-only.

### Bugsnag-specific follow-up
- The created PR is linked through the Bugsnag error's existing GitHub integration (`linkedIssues[]`); do not invent a second link.
- Do not change the Bugsnag error status (fixed / ignored / snoozed) automatically — like JIRA transitions, marking an error fixed is left to a human after the fix is verified in production.

## References

- references/source-detection.md
- references/quality-gates.md

## Done when
- The issue is fully addressed
- Behavior is correct and stable
- Tests cover affected logic with 100% coverage and pass
- Pre-push fixers and checkers ran clean on all changed files
- No sensitive data is exposed
- Code quality self-check pass ran and its Critical / Moderate findings were addressed **before the PR was created**
- Security review completed **before the PR was created**
- A clean pull request is created with a summary **by default** — skipped only when the user explicitly opted out of PR creation (see *Pull request*), in which case the committed local branch and the ready-to-run `gh pr create --draft …` command are reported instead
- Technical report posted on the GitHub PR (skipped on PR opt-out)
- Non-technical report posted on the original issue tracker (skipped on PR opt-out)
- Every deferred `## TODO` item has a follow-up tracker issue cross-linked per *Deferred-item follow-up issues*, or is listed in the final report as a blocker for manual filing (skipped on PR opt-out)
- For JIRA issues: PR is linked back and a summary comment is posted (skipped on PR opt-out)
- Durable lessons (if any cleared the promotion bar) were recorded into the project memory file via `@skills/record-project-memory/SKILL.md` (skipped on PR opt-out)

## Output Humanization
- Use [blader/humanizer](https://github.com/blader/humanizer) for all skill outputs to keep the text natural and human-friendly.
