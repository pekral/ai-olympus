---
name: create-issue
description: Use when create a single issue from provided text without modifying its content
license: MIT
metadata:
  author: Petr Král (pekral.cz)
---

## Constraints
- Preserve original text exactly (no rewriting or summarizing)
- Improve formatting only (headings, lists, spacing)
- Assign issue to current user
- Use available CLI tools

---

## Execution

### 1. Prepare Content
- Use original text as-is
- Apply readable formatting (headings, lists)

### 2. Generate Title
- Use first line of input
- Remove formatting only

### 3. Create Issue
- Before the issue is created, apply `@skills/create-issues-from-text/SKILL.md` *Guard for a possible vulnerability*; a withheld text gets no issue and is reported to the operator with the reason and the private routes
- Use available CLI tool
- Set title and formatted description
- Assign to current user
- Select and apply the single most relevant existing label per `@rules/compound-engineering/tracker.md` *Label tracker issues, and keep the labels true* (never create a new label; leave the issue unlabeled when none fits)

### 4. Output
- Return direct link to created issue, or the withheld report of the guard when it created none

---

## Output Format

Render the issue body from `skills/create-issue/templates/issue-body.md`.
