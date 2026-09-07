---
description: Compatibility alias for preparing one GitHub issue pull request for merge without merging
argument-hint: [GitHub issue or pull request URL]
---

# Compatibility alias

Use `/prepare-issue-for-merge $ARGUMENTS`.

The canonical workflow now lives in `@skills/prepare-issue-for-merge/SKILL.md`, so Claude Code and
Codex apply the same review-fingerprint, merge-readiness, acceptance-criteria, reporting, and safe
comment-cleanup contract. This alias never merges the pull request.
