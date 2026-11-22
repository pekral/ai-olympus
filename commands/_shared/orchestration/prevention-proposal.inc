# Prevention proposal — one concrete proposal for each recurring defect class

This file is the one contract for turning a recurring defect class into a prevention proposal. It is phase 3 of the recurring-defect epic (#185). It reads the report of `recurrence-analysis.md` and the records of `finding-records.md`. It adds no record, no fingerprint, no report format, no store, and no agent.

A proposal is a tracker issue that names the strongest practical mechanism against one defect class. It proposes and implements nothing. A human approves a proposal first, and a separate task implements it.

## Command

Apply the bounded contract in `@skills/code-review/references/prevention-proposal.md`, *Command*.

## Who writes what

The script decides what can be decided, and the caller writes only the judgment.

| Part | Source |
| --- | --- |
| The defect class and the window | The report, from schema-restricted fields |
| Every occurrence: the link, the fingerprint, the subsystem, the severity, the status, the date | The store record of the occurrence, validated by `extract-findings.sh --validate`. The report names the record, and the record must belong to the class and the pull request that the report names |
| The record text of the first three occurrences | The store, quoted as data |
| The root cause, the safeguards checked, the mechanism, the files, the steps, the verification, the false positives, the impact, the limits | The analysis file, written by the caller |
| The section layout, the level names, the approval statement, the dedupe marker | The script |

## What gets a proposal

Apply the bounded contract in `@skills/code-review/references/prevention-proposal.md`, *What gets a proposal*.

## The analysis file

Apply the bounded contract in `@skills/code-review/references/prevention-proposal.md`, *The analysis file*.

## One defect class, one proposal

Apply the bounded contract in `@skills/code-review/references/prevention-proposal.md`, *One defect class, one proposal*.

## Filing a proposal

Filing a tracker issue is a write that other people see. The steps below run only when the user asks for them or when the wiring phase (#189) assigns them to a role. The run that files follows `@rules/compound-engineering/tracker.md`.

1. Run `skills/_shared/assert-current-repo.sh <class.repo>`. Stop on any non-zero exit, and file nothing: steps 3 to 5 act on the current checkout, so they must not run in a checkout of another repository. Exit `4` is a different repository and exit `5` is an ownership that cannot be proven.
2. Run `propose-prevention.sh` with `--out` set to a file in the scratch directory. Stop when the status is `none`. File nothing and say the reason.
3. Run `commands/_shared/feedback-loop.sh lookup --key <key>`. It searches the tracker of `class.repo` for the marker words (`repo:<owner>/<name> is:issue in:body "prevention-proposal"`), reads every page of the result, and runs `propose-prevention.sh --find` on it. The number of proposals in the repository therefore never blocks the lookup. A search that GitHub cuts at 1000 results is refused with exit `3`.
4. When the action is `create`, file the body file unchanged through `@skills/create-issue/SKILL.md`. The first line of the file is the title. That skill assigns the issue to the current user and selects one existing label under `@rules/compound-engineering/tracker.md` *Label tracker issues, and keep the labels true*. Phase 3 adds no label and no second issue-creation path.
5. When the action is `update`, publish the body file with `skills/code-review-github/scripts/upsert-comment.sh <url> <body-file> prevention-proposal`, where `<url>` is the `url` that the `--find` line prints. A bare number resolves against the current git remote, so it never replaces the `url`. The helper replaces this actor's earlier comment of that namespace on the issue. The issue body, its labels, and its state stay as a human left them. A closed proposal is reported to the human and is never reopened by the run.
6. Read the issue or the comment back through the tracker loader and confirm it landed. Delete the scratch files.

## Untrusted text

Apply the bounded contract in `@skills/code-review/references/prevention-proposal.md`, *Untrusted text*.

## Approval

Without an approval nothing is implemented. The proposal states it. The script writes the proposal file and nothing else. No step of this contract changes a rule, a configuration, an analyser setting, a test, or a CI check of any project.

## What this phase leaves to later phases

- Phase 4 (#189) assigned the roles in `feedback-loop.md`: `krang` runs the analysis and files the proposal, `leonardo` writes the analysis file, and the filing is **L1** for `krang` in a feedback-analysis run that the user asked for (`@rules/compound-engineering/orchestration.md` *Externally-visible actions & consent levels*).
- A canonical `repo` value is the `nameWithOwner` that `commands/_shared/feedback-loop.sh` resolves before it writes a record.
- Only `commands/_shared/feedback-loop.sh` and the feedback-analysis run of `krang` call the proposal. A missing `jq`, a failed proposal, or a refused analysis changes nothing for a pull request.
