---
description: Code Review — an issue with several pull requests: every remark and finding split per pull request, on the pull request and in the tracker comment. Companion to code-review/general.md, read on demand.
paths:
  - ".claude/rules/code-review/several-pull-requests.md"
---

# Code Review — an issue with several pull requests

Moved out of `@rules/code-review/general.md` so a review that does not need it does not carry it. **Loading trigger:** the reviewed issue links more than one pull request. A section this file names *above* or *below* without a file is in this file or in `@rules/code-review/general.md`.

## Remarks and findings split per pull request

When the reviewed issue links more than one pull request, every remark and every finding belongs to exactly one of them, and the report says which one. A reader of one merged list cannot tell which pull request to fix, to review again, or to merge.

- **The technical report stays on its own pull request.** Each pull request carries its own code-review report (`cr-comment` namespace) with its own findings, counts, reviewed revision, and diff fingerprint. A finding is never published on a sibling pull request.
- **The tracker comment groups per pull request.** On the issue — the source tracker comment and the linked-GitHub-issue mirror — each review finding, each reviewer remark, and each question that closes a finding sits under the pull request it concerns: one group per pull request, in pull-request number order. `@skills/pr-summary/references/several-pull-requests.md` owns the rendering per tracker.
- **The issue-level verdict stays one.** The acceptance criteria belong to the issue, so the status line and the assignment verdict are rendered once, above the groups. An unmet criterion names the pull request that is meant to deliver it.
- **A remark that concerns the issue as a whole stays outside the groups.** A question about the assignment that no pull request owns closes its criterion bullet, as on a single-PR issue.
- **A run that reviews one of the pull requests renders only its own group.** Every run posts a new comment, so the groups of the other pull requests stay in the earlier comments that carried them. The review of the second pull request never erases the findings of the first.
- **One pull request, no groups.** The comment keeps its usual shape.
