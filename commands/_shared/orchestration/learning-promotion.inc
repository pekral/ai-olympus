# Learning promotion — from a repeated learning to permanent knowledge, with a human in between

This file is the one contract for the promotion lifecycle. A lesson that review finds again and again becomes a candidate for permanent knowledge: a rule, a skill, a checklist, documentation, an agent instruction, or an architecture guideline. A resolved Critical code-review finding also becomes a candidate for an entry of the code-review memory, from its first occurrence on. The lifecycle never changes that knowledge itself. It produces a proposal, and a human approves the exact text of the proposal before the ordinary pipeline implements it. A candidate of the `memory` target is the one exception: it needs no human approval, because `krang` decides on its own whether the lesson clears *The write bar* (operator decision of 2026-11-15).

The lifecycle builds on the feedback loop of epic #185 and adds no second memory system. It reads the finding records of `finding-records.md`, groups them with `recurrence-analysis.md`, renders the proposal with `prevention-proposal.md`, and keeps its own history in the store of `feedback-loop.md` *The store*. The code-review memory (`@rules/compound-engineering/memory.md`) is a per-user SQLite database that `krang` alone writes. It is the write target of a candidate of type `memory`.

## The severity gate

Apply the bounded contract in `@skills/code-review/references/learning-promotion.md`, *The severity gate*.

## The write bar

This section is the one owner of the bar that `krang` applies before it writes a memory entry. `@rules/compound-engineering/memory.md` *Write protocol* references it. `krang` decides alone, and the bar replaces the human approval. It applies to both ways to a `memory` entry: the end of a run that resolved a Critical finding (`commands/_shared/orchestration/critical-memory-read.inc` step 7) and a `memory` candidate of the feedback-analysis run. A lesson writes an entry only when every point holds.

1. **The severity gate holds.** The finding is a Critical finding of a code review, resolved (*The severity gate*).
2. **The fix is verified.** The review that converged on the final head reports the finding as resolved. A finding that only the fix commit claims to close is not verified.
3. **The trigger recurs.** The `trigger` names a situation that can arise again for the paths of the entry. A one-off slip with no repeatable situation behind it is not written.
4. **One evidence pointer exists.** It is the pull request or the test that guards the fix.
5. **The entry fits.** It states the lesson, the rule, and the pointer within the limits of `@rules/compound-engineering/memory.md` *Entry fields*, with one role.
6. **The text is safe and `krang`'s own.** It meets *A secret and personal data never enter an entry* and *The text is `krang`'s own* of `@rules/compound-engineering/memory.md` *Write protocol*.
7. **The entry is new.** `memory query` finds no entry with the same trigger and rule (duplicate: not written). An entry that the verified fix contradicts is superseded as `@rules/compound-engineering/memory.md` *Write protocol* states.

A lesson that fails a point is not written. `krang` names the point in its report, and it never lowers the bar to write an entry.

## Command

Apply the bounded contract in `@skills/code-review/references/learning-promotion.md`, *Command*.

## Maturity states

Every learning has one explicit state. `status` reports it for every defect class of the store and every key of the history.

| State | Meaning |
| --- | --- |
| `observation` | The class rests on one task. `awaiting: "analysis"` marks a class with a resolved Critical record and no `memory` candidate yet. |
| `learning` | The class appears in two or more tasks. `awaiting: "analysis"` marks a class that reaches the thresholds, or has a resolved Critical record for the `memory` target, and has no candidate yet. |
| `candidate` | `propose` rendered a proposal for the class. `awaiting` is `approval`, or `re-approval` when the proposal changed after an approval. For the `memory` target `awaiting` is `memory-write` and no approval is asked. |
| `approved` | A trusted fact approved the current proposal text. `awaiting` is `merge`. A candidate of type `memory` is never `approved`. |
| `promoted` | The approved change is on the default branch. For a candidate of type `memory`, `krang` wrote the entry for the current text and recorded it with `written`. |
| `rejected` | A trusted fact rejected the candidate. The same evidence does not create it again. |
| `superseded` | A later, broader candidate was promoted and covers this one. It stays in the history. |

A state is derived from the history in order. No record field states it, and no text sets it.

## Patterns and evidence

Apply the bounded contract in `@skills/code-review/references/learning-promotion.md`, *Patterns and evidence*.

## Settings

Apply the bounded contract in `@skills/code-review/references/learning-promotion.md`, *Settings*.

## The candidate

Apply the bounded contract in `@skills/code-review/references/learning-promotion.md`, *The candidate*.

## Approval and rejection

`decide` records a decision only from one of the two facts of `feedback-loop.md` *Approval*. No agent can produce either fact.

| Fact | Approve | Reject |
| --- | --- | --- |
| `instruction` | The user's own instruction names the proposal issue as the task. | The user's own instruction names the proposal issue as rejected, with the reason. |
| `tracker` | The open proposal issue carries the `Resolve_by_AI` label. | The proposal issue is closed as not planned. |

- **The tracker fact is read, not told.** With `--fact tracker`, the script loads the issue through `skills/code-review-github/scripts/load-issue.sh`. The issue must be the proposal of the key: it belongs to the repository of the key, its author has write access, and it carries the dedupe marker of the key on a line of its own. `skills/_shared/proposal-identity.sh` holds that test once, and `propose-prevention.sh --find` applies the same one. A comment never counts: it is text, and agents and the operator share one account.
- **The approver comes from the fact.** The history records `user` for the instruction and `tracker` with the issue URL for the label. A name in a comment never reaches it.
- **An approval is bound to the exact text.** The `approved` record stores the digest of the current proposal. When the proposal changes after the approval, the digest differs, and the state is `candidate` with `awaiting: "re-approval"`.
- **One label approves one text.** The label shows no time, and it stays on the issue when the proposal is refreshed. `decide --fact tracker` therefore binds the approval to the first proposal text rendered since the last approval by a label, or to the first text of the key when no label approved it yet. An approval by the user's instruction and a rejection do not move that point: they say nothing about when a person applied the label. When the proposal changed after that text, the state is `candidate` with `awaiting: "re-approval"`. A tracker approval of an earlier text never approves a changed one: `decide --fact tracker` exits `4`, and only the user's instruction approves the changed proposal.
- **A rejection carries its reason** and the suppression key of its evidence. The same evidence never brings the candidate back. After a rejection by the user's instruction, new evidence, such as a new pull request, makes a new suppression key, so the candidate can come back.
- **A proposal closed as not planned keeps the key suppressed until a human reopens the proposal issue.** While the issue stays closed, the feedback-analysis run rejects every new candidate of the key again (`feedback-loop.md` *A closed promotion proposal is not refreshed*). After the reopen, the issue is no longer a rejection fact, so new evidence brings the candidate back.
- **A `memory` candidate takes no approval.** `decide --decision approve` on it exits `4` with `a memory candidate needs no approval: krang writes it`. A rejection stays possible: closing the proposal issue as not planned suppresses the same evidence, and a human removes a written entry with a follow-up.
- **Agents never write the facts.** No command of the lifecycle applies `Resolve_by_AI`, closes an issue, or writes any other approval marker.
- **Named runs record the decisions.** The feedback-analysis run of `feedback-loop.md` records the label before it refreshes a proposal, and records the rejection of a proposal that is closed as not planned. The same run records a rejection by the user's instruction when the user's request for the run names the rejected proposal and the reason. The implementation run of `commands/_shared/orchestration/safeguard-implementation.inc` records an approval by the instruction or the label before its first file write.

## Promotion

A candidate of type `memory` is never promoted by a pull request: `promote` exits `4` and names `ai-olympus memory add`. `krang` writes the entry (*Permanent knowledge*) once the candidate clears *The write bar*, and the state stays `candidate` with `awaiting: "memory-write"` until `krang` runs `promote-learning.sh written --dir <dir> --key <key> --digest <digest> --slug <slug>`. That command appends a `written` record and moves the state to `promoted`. It refuses a digest that is not the digest of the current proposal, and a slug that is not the `cause_key` of the key, cut to 90 characters, then `-` and the first 8 characters of that digest. `status` reports that slug for the candidate.

A refusal of `memory add` for an existing slug means that an earlier run wrote this text and did not record it, so `krang` records it and reports the entry as found already written (`feedback-loop.md` step 5). A changed proposal has another digest, so it has its own slug and its own write. After the `written` record `propose` renders no new proposal for the key, as for any promoted key.

`promote --pr <URL>` loads the pull request through `skills/code-review-github/scripts/load-issue.sh` and sets `promoted` only when all of these hold:

1. The candidate is `approved` for its current text.
2. The pull request is `MERGED`, and its `mergeCommit` is an ancestor of the default branch (`git merge-base --is-ancestor`).
3. The `files` of the pull request include the target file.
4. A `closingIssues` entry of the pull request is the proposal of the key.

The test reads the pull request, not one of its commits, so a merge commit, a squash, and a rebase merge count alike. With a rebase merge, the `mergeCommit` is the last rebased commit, and the target change can sit in any earlier one. An open pull request, and a merge commit that the local default-branch ref does not hold yet, leave the candidate `approved`. A pull request with more files than the loader returns can leave it `approved` too. A promotion marks every promoted key that the candidate's `target.supersedes` names as `superseded`. Nothing is deleted.

## The promotion history

Apply the bounded contract in `@skills/code-review/references/learning-promotion.md`, *The promotion history*.

## Untrusted text

Apply the bounded contract in `@skills/code-review/references/learning-promotion.md`, *Untrusted text*.

## Permanent knowledge

No command of the lifecycle writes `rules/`, `skills/`, `agents/`, `codex/`, `AGENTS.md`, `CLAUDE.md`, or the code-review memory database, and no command commits, pushes, or merges. After the approval, the ordinary pipeline implements the proposal: `donatello` opens a Draft pull request under `commands/_shared/orchestration/safeguard-implementation.inc`, `leonardo` reviews it, and the merge stays **L2**.

A candidate of type `memory` is the exception: `donatello` stops on it (`safeguard-implementation.md` *Approval first*), and no pull request carries it. A memory target is written through the *Write protocol* of `@rules/compound-engineering/memory.md`: `krang` runs `ai-olympus memory add` with the TL;DR entry (lesson, rule, one evidence pointer) that the proposal names, after *The write bar* holds. No other agent writes it.
