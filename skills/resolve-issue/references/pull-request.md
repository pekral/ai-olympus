# Pull request

Expanded procedure for `@skills/resolve-issue/SKILL.md` *Pull request*.

**Creating the pull request is the default, mandatory final step.** Once review and testing are clean, open the PR automatically — applying the valid git rules and PR definitions in this section — **without asking the user for confirmation**. The skill is not finished until the PR exists.

**Opt-out — the user must explicitly ask to skip the PR.** Only when the user's request explicitly states that no pull request should be created (e.g. "don't open a PR", "no PR", "just implement locally", "leave it on the branch") do you skip PR creation. A silent or ambiguous request is **not** an opt-out — when in doubt, create the PR. When the user did opt out:
- Still run the full flow through implementation, the code quality self-check, and the security review — only the PR creation and **every step that depends on an open PR** are skipped: the technical report on the PR, the non-technical report on the original tracker, the *Deferred-item follow-up issues* step, the JIRA Code-Review transition, and the GitHub `ready for review` label. None of them run without a PR — report the deferred items in the handoff instead so they are filed when the PR opens.
- Commit the changes on the local feature branch (do **not** push or open the PR) and leave the working tree on that branch.
- Release the tracker claim the same way the before-PR release does (*Release on Blocked / abort (before PR)* in step 1) — this is a deliberate stop, not a failure, but no PR will own the claim, so removing the `Resolve_by_AI:in-progress` label lets a human pick the issue up. Name the issue / key in the handoff.
- Report what was implemented, the review/security outcome, and the exact `gh pr create --draft …` command the user can run later to open the PR.

Once review and testing are clean and the user has **not** opted out:

- Create a branch (name always in English, regardless of the assignment language) and commit changes following `@rules/git/general.md`
- **Open the pull request as a Draft** (`gh pr create --draft …`) per `@rules/git/general.md` *Draft pull requests*. The inline self-check above is the implementer's single-pass pre-PR self-check, **not** the authoritative code review — the authoritative `code-review-github` / `process-code-review` (the `athena` ↔ `hephaestus` convergence loop) still runs **after** the PR exists, so at creation time the PR is not yet ready to merge and agents will keep working on it. It is promoted out of Draft (`gh pr ready`) by `@skills/process-code-review/SKILL.md` once that review converges to 0 Critical + 0 Moderate.
- Create the pull request with:
  - clear description of the change
  - reference to the original issue
  - testing instructions
  - **Summary** — concise overview of what changed and why
  - **Changes** — one entry per commit, rendered from the commit plan per `references/phase-planning.md`
  - **Pre-existing fixes** — if any pre-existing issues were fixed per *Pre-existing issue handling*, list each fix commit under a `## Pre-existing fixes` section with a one-line rationale so reviewers can review them independently of the assignment
  - **`## Security acceptance checklist`** — when a pre-implementation security plan was passed into this run, render the verified checklist from *Security remediation checklist* above: every item with its `- [x]` / `- [ ] ` state, its `[Critical]` / `[Moderate]` / `[Minor]` prefix, and the one-line `file:line` or test-name pointer recording how it was verified, plus a link to the plan issue. Omit the section entirely when no plan existed
  - **TODO list** — if any **out-of-scope (deferred)** items were identified in step 7 (or non-trivial pre-existing issues were deferred), include them under a `## TODO` section as a checklist of potential follow-up tasks; each entry is then cross-linked to its follow-up tracker issue by the *Deferred-item follow-up issues* step below
  - **`## Audit`** — mandatory on every PR this skill opens: transcribe the accumulated `.claude/run/<source-slug>.audit` ledger (per `@rules/compound-engineering/orchestration.md` *Audit trail for memory reads, outbound requests, and external writes*) — the memory reads, outbound requests, and external writes recorded during this run — and state its declared incompleteness verbatim (self-reported; a raw `curl` via `Bash` produces no automatic line until the Bash capability boundary is harness-enforced). This section is the durable copy that survives the ledger's own end-of-run deletion.
**Standalone-run fallback (mirroring the per-role-memory standalone fallback above):** when no `.claude/run/<source-slug>.audit` ledger exists — a standalone `hephaestus` run with no `daedalus` orchestrating, so no ledger was ever created — the reason `hephaestus` does not create one is purpose, not permission: its own Bash boundary does permit the `.audit` append (and `cat >>` creates the file when it is absent), so nothing blocks the write; what it is asked to do is append its own lines to a ledger the orchestrator created, not bootstrapping a run ledger nothing else will ever read. State that plainly (no ledger was produced) and list only the actions `hephaestus` itself performed in this run (tracker reads/writes via `gh`, files touched) instead of fabricating ledger entries.
