---
description: Code Review — an issue with several pull requests: every remark and finding split per pull request, on the pull request and in the tracker comment, and the one JIRA comment of a stack. Companion to code-review/general.md, read on demand.
paths:
  - ".claude/rules/code-review/several-pull-requests.md"
---

# Code Review — an issue with several pull requests

Moved out of `@rules/code-review/general.md` so a review that does not need it does not carry it. **Loading trigger:** the reviewed issue links more than one pull request. A section this file names *above* or *below* without a file is in this file or in `@rules/code-review/general.md`.

## Remarks and findings split per pull request

When the reviewed issue links more than one pull request, every remark and every finding belongs to exactly one of them, and the report says which one. A reader of one merged list cannot tell which pull request to fix, to review again, or to merge.

- **The technical report stays on its own pull request.** Each pull request carries its own code-review report (`cr-comment` namespace) with its own findings, counts, reviewed revision, and diff fingerprint. A finding is never published on a sibling pull request.
- **The tracker comment groups per pull request.** On the issue — the source tracker comment and the linked-GitHub-issue mirror — each review finding, each reviewer remark, and each question that closes a finding sits under the pull request it concerns: one group per pull request, in pull-request number order. `@skills/pr-summary/references/several-pull-requests.md` owns the rendering per tracker.
- **The issue-level verdict stays one.** The acceptance criteria belong to the issue, so the assignment verdict is rendered once, above the groups. An unmet criterion names the pull request that is meant to deliver it.
- **A remark that concerns the issue as a whole stays outside the groups.** A question about the assignment that no pull request owns closes its criterion bullet, as on a single-PR issue.
- **A run that reviews one of the pull requests renders only its own group.** Every run posts a new comment, so the groups of the other pull requests stay in the earlier comments that carried them. The review of the second pull request never erases the findings of the first. A JIRA task delivered as a stack follows *A stack of pull requests* below instead.
- **One pull request, no groups.** The comment keeps its usual shape.

## A stack of pull requests — one JIRA comment for the whole stack

A task in JIRA is sometimes delivered as a stack: several open pull requests of the task, where the base branch of one is the head branch of another (`baseRefName` and `headRefName` from `skills/code-review-github/scripts/load-issue.sh`). Each pull request is one part of the stack. Without this section, every review of a part adds its own comment, and the owner of the task has to read all of them. This section is the one owner of the rule.

- **The code review on GitHub stays as it is.** Every part gets its own review and its own `cr-comment` report, exactly as for any pull request.
- **The JIRA task gets one comment for the stack, per run.** The comment carries all that the code review reports of the stack say for the owner: the verdict on the acceptance criteria for the task as a whole, what has to be fixed in each part, and the open questions. The owner reads it and knows what is left on the whole task without opening a pull request.
- **It is written once, after every part finished its review in the run.** Each review of the run publishes its GitHub report only (`tracker-report: deferred`), and one report goes to JIRA when the last part has finished. A part that the run did not review, or could not review, is named in the comment with the reason, and the comment never waits for it.
- **Each point names its part.** A point sits in the group of the part it belongs to (`@skills/pr-summary/references/several-pull-requests.md` *A stack on JIRA*). A point that several parts share appears once, above the groups, and names the parts it concerns.
- **The comment follows the JIRA comment rules of today.** The language, the voice, the banned content, and the 3 000-character cap of `@rules/reports/general.md` *A JIRA comment is written for a non-technical reader* apply unchanged.
- **Another run adds a comment; it never replaces one.** When a later run reviews the same stack, its comment is added and the earlier comments stay. This is the default until the owner decides otherwise.
- **A pull request that is not part of a stack keeps the behaviour of *Remarks and findings split per pull request* above.** The linked-GitHub-issue mirror and the Bugsnag report stay as they are: the stack rule covers JIRA only.
