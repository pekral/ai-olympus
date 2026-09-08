# Plan: bash scripts for an AI agent working with JIRA

Status: **implemented and verified** against a real JIRA ticket (build green, 212 tests). It came out of
`/analyze-problem`, and the user then widened the scope with a full context loader.

What was actually delivered (`gather-issue-context.sh` is the addition to the original proposal below):

| Operation | Script | Status |
|---|---|---|
| Reading an issue | `load-issue.sh` (already existed) | unchanged |
| Adding / editing a comment | `upsert-comment.sh` (already existed) | unchanged |
| Parsing comments | `parse-comments.sh` (new) | done |
| Full context (issue, comments, attachments, recursively linked issues, and a URL inventory rendered as a Markdown brief) | `gather-issue-context.sh` (new) | done |
| Status change to Code Review | `transition-to-code-review.sh` (new) | done |

Wiring into the skills: `rules/jira/general.md` (the transition exception), `code-review-jira` (the script
catalog), `resolve-issue` (gather, plus the transition after the PR opens), `prepare-issue-context` (gather),
and `tester-cookbook` (gather).

---

The original proposal, for context:

## Goal

The AI agent needs a set of deterministic bash scripts for four JIRA operations: (1) reading an issue,
(2) adding or editing a comment, (3) parsing the existing comments into a structured shape, and (4) the one
permitted status change — the transition to "Code Review". Once they exist, the agent performs all four with
a single script call, with no ad-hoc `acli` commands and no risk of moving the issue into any state other
than review.

## Architecture

Everything lives in the **existing** home of the JIRA tooling, `skills/code-review-jira/scripts/`, beside
`load-issue.sh` and `upsert-comment.sh`. No new directory and no new abstraction — this builds on what the
repository already has (`acli` as the primary tool, the JIRA MCP as the fallback; see
`rules/jira/general.md`).

Operation to script:

| Operation | Solution | New code? |
|---|---|---|
| Reading an issue | `load-issue.sh <KEY\|URL>` (already exists) | no |
| Adding / editing a comment | `upsert-comment.sh <KEY\|URL> <BODY\|-> [MARKER]` (already exists) | no |
| Parsing comments | `parse-comments.sh <KEY\|URL>` (new, a thin layer over `load-issue.sh`) | yes |
| Status change to Code Review | `transition-to-code-review.sh <KEY\|URL> [STATUS]` (new) | yes |

Why here rather than a new abstraction: parsing comments is a pure projection of `load-issue.sh`'s output,
and the transition is a thin, security-constrained wrapper over `acli jira workitem transition`. Both share
the KEY/URL normalisation the existing scripts already perform — see "Known debt" below.

## Implementation steps

1. **Edit the `rules/jira/general.md` rule (governance; requires a human's consent).**
   Line 9 currently reads "Never change JIRA issue status." Replace it with a wording that carries one
   exception:
   > Never change JIRA issue status, with one exception: a single allowed transition to the
   > project's Code Review status, performed only via
   > `skills/code-review-jira/scripts/transition-to-code-review.sh`. Every other transition
   > (Done, In Progress, Closed, …) stays human-only.
   This changes a **shared** rule, so it reaches every skill that imports it (`resolve-issue`,
   `tester-cookbook`, `code-review-jira`, `process-code-review`). That is why it is step 1 and why it needs
   explicit consent.

2. **`parse-comments.sh <KEY|URL>`** — comment extraction.
   - Calls `load-issue.sh "$1"` and projects `.comments[]` through `jq` into an array of objects:
     `{ index, author, created, visibility, body, charCount, lineCount }`.
   - `index` is the 0-based position. `charCount` and `lineCount` let the agent decide whether to read a
     comment whole or in parts.
   - Output: one JSON array on stdout (deterministic, slurpable with `jq`). No `--text` mode — the agent
     formats for a human itself (YAGNI; it gets added when a real caller needs it).
   - Propagates the exit codes of `load-issue.sh` (2 = missing tool, 3 = fetch failed).

3. **`transition-to-code-review.sh <KEY|URL> [STATUS]`** — the one permitted transition.
   - Normalises the KEY from the argument, exactly as the existing scripts do.
   - Target state: the `STATUS` argument, otherwise the `JIRA_CODE_REVIEW_STATUS` env var, otherwise the
     default `"Code Review"`.
   - **Whitelist guard:** the target state must match (case-insensitively) the list of review-state synonyms
     (`JIRA_CODE_REVIEW_SYNONYMS`, default `Code Review,In Review,Review,Ready for Review,CR`). Anything
     outside the list exits 1 ("refused: only the Code Review transition is allowed"). The script is
     therefore **structurally unable** to move an issue to Done or anywhere similar.
   - Idempotence: it reads the current `status` through `load-issue.sh`; if the issue is already in the
     target state, it is a no-op and exits 0.
   - It then runs `acli jira workitem transition --key "$KEY" --status "$TARGET" --yes --json`.
   - **Discovery and asking (the user's requirement):** `acli` cannot list the available transitions (see
     `load-issue.sh`, Known limitations). When a transition fails because the target state does not exist in
     that project, or is not reachable from the current state, the script exits with the reserved code 5 and
     an instruction: the agent resolves the project's real review-state name through the JIRA MCP (available
     next transitions), checks it against the whitelist, and re-runs the script with the correct `STATUS`;
     when that cannot be settled unambiguously, it **asks a human**. Other API failures exit 3, and a
     missing `acli` exits 2.

4. **Documentation in `skills/code-review-jira/SKILL.md`** — add a short paragraph to the scripts section
   with call patterns for all four operations (including the two that already exist), so the agent finds
   them in one place.

5. **Build gate.** Run `composer build` (and `composer skill-check`) before pushing, and fix everything it
   reports. See CLAUDE.md.

### Known debt (record it in the project's compound memory)

The KEY/URL normalisation is duplicated across `load-issue.sh` and `upsert-comment.sh` today, and the two new
scripts make four copies. Extract a shared `scripts/lib-jira-key.sh` when a fifth copy is needed. Not now —
refactoring existing code is outside this assignment (CLAUDE.md §3).

## Sources

- `skills/code-review-jira/scripts/load-issue.sh` — reads the issue and its comments (`acli jira workitem view --json`, `comment list --json --paginate`), with a stable JSON shape including `.comments[]`.
- `skills/code-review-jira/scripts/upsert-comment.sh` — idempotent comment upsert (anchored per actor).
- `rules/jira/general.md:9` — the ban on status changes (step 1 changes it); lines 16–31 — the mandatory Wiki Markup for comments.
- `acli jira workitem transition --help` — `--key`, `--status "<name>"`, `--yes`, `--json`; the transition targets the state's **name**.
- `acli jira workitem comment list --help` — `--json --paginate`.
- The user's requirement: the status may move **only to "Code Review"**, the name differs per project, so find it or ask.

## Success criteria

- `parse-comments.sh <KEY>` returns a valid JSON array of comments carrying `index, author, created, visibility, body, charCount, lineCount`; an issue with no comments returns `[]`.
- `transition-to-code-review.sh <KEY>` moves the issue into the review state and is idempotent (a second run is a no-op, exit 0).
- `transition-to-code-review.sh <KEY> "Done"` exits 1 and leaves the issue unchanged (the whitelist guard).
- An unknown review-state name exits 5 with the instruction for MCP discovery or asking, and never guesses at a transition.
- A missing `acli` exits 2; an API failure exits 3 (consistent with the existing scripts).
- `composer build` and `composer skill-check` pass.
- `rules/jira/general.md` carries an exception limited to one script and one target state.
