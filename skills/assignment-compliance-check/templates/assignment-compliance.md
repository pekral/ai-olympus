## Assignment Compliance

- **Linked task:** <issue / JIRA / Bugsnag URL>
- **Pull request:** <PR URL>
- **Verdict:** <the verdict line of the verdict that applies>

### Critical gaps

#### 1. <short title in everyday language>
- **What the task asked for:** <one sentence quoting or paraphrasing the requirement, with the source comment URL or "issue description">
- **What the pull request does instead:** <one sentence describing the actual behavior implied by the diff>
- **Example a tester would see:** <concrete input, expected output versus actual output; when the change is reachable only behind a test parameter, start by enabling the gating toggle>

=== END OF COMMENT BODY — NOTHING BELOW THIS LINE IS EVER PUBLISHED ===

TEMPLATE GUIDANCE. The published text ends at the line above. The intermediate source of JIRA and the plain text of Bugsnag have no comment syntax, so this guidance sits below the marker instead of inside the body.

- The header lines are identical in all three cases. The block carries no file path, line number, or code snippet.
- Verdict 1, every acceptance criterion is met. One sentence, nothing behind it: "- **Verdict:** All stated acceptance criteria are met." Drop the Critical gaps heading and its blocks.
- Verdict 2, a criterion is not met. The verdict line is "- **Verdict:** Critical gaps found: N". Repeat the numbered block under Critical gaps for every Critical gap.
- Verdict 3, the assignment states no explicit acceptance criteria. Drop the Critical gaps heading and its blocks, and replace the verdict line with three lines: "- **Verdict:** The assignment states no explicit acceptance criteria.", "- **Judged against:** <the basis the check used instead, and the sources read>", and "- **Result:** <one sentence: the implementation matches that basis, or the one place where it does not>".
- Convert the block to the JIRA intermediate source for a JIRA target and to plain text for a Bugsnag target. Translate the verdict sentences and the field labels into the assignment language.
- The caller embeds only the part above the marker line.
