# Recurrence analysis — the defect classes that repeat across pull requests

This file is the one contract for finding the defect classes that recur across independent pull requests. It is phase 2 of the recurring-defect epic (#185). It reads the finding records of `finding-records.md` and adds no record, no fingerprint, and no review format.

## Command

Apply the bounded contract in `@skills/code-review/references/recurrence-analysis.md`, *Command*.

## Collection

Apply the bounded contract in `@skills/code-review/references/recurrence-analysis.md`, *Collection*.

## What counts as a recurrence

Apply the bounded contract in `@skills/code-review/references/recurrence-analysis.md`, *What counts as a recurrence*.

## Settings

Apply the bounded contract in `@skills/code-review/references/recurrence-analysis.md`, *Settings*.

## Incremental analysis

Apply the bounded contract in `@skills/code-review/references/recurrence-analysis.md`, *Incremental analysis*.

## The report

Apply the bounded contract in `@skills/code-review/references/recurrence-analysis.md`, *The report*.

## Analysis never blocks a pull request

Apply the bounded contract in `@skills/code-review/references/recurrence-analysis.md`, *Analysis never blocks a pull request*.

## What the wiring phase decided

`feedback-loop.md` owns the wiring of phase 4 (#189):

- The collection and the analysis run from `commands/_shared/feedback-loop.sh`. The review loop records each round and the published report, and `krang` runs the analysis when the user asks for it.
- The store is one file for each pull request in a derived, git-ignored directory. `analyze` merges them.
- The `repo` value is the canonical `nameWithOwner` that GitHub reports, resolved by `feedback-loop.sh` before any record is written.
- A prevention proposal for a reported class is the subject of `prevention-proposal.md`.
