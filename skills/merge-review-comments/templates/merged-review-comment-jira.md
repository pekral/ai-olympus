h2. Current state

[The newest verdict and every point that still holds, in plain prose for the person who owns the ticket. State what is resolved and what is open. Every number and every verdict of the merged comments stays, with the round or the date when the rounds differ.]

h2. Open questions

* [One question that no later round and no linked source answered, with the round or the date it was asked.]
* [One question that a later round or a linked source answered: the question, then the answer and its source.]

h2. Earlier rounds

h3. Round [n or date]

* [A verdict, a finding, or a number of that round that the current state does not already carry, and what changed after it.]

[Closing links line: the links the merged comments carried, in the form [label|url], separated by " · ". Omit the line when they carried none.]

=== END OF COMMENT BODY — NOTHING BELOW THIS LINE IS EVER PUBLISHED ===

TEMPLATE GUIDANCE. The published comment ends at the line above. The source
format has no comment syntax, so the boundary is this marker line.

Who reads this comment
  The person who owns the ticket, not a developer. The section "A JIRA comment
  is written for a non-technical reader" of @rules/reports/general.md binds every
  line above the marker: no class, method, or file names, no SHA or fingerprint,
  no gate or CI result, no severity label, no code block, and the 3 000-character
  cap. The comment keeps every verdict, finding, open question, and number of the
  merged comments. When that does not fit the cap, the skill stops and reports it.

Section order is binding: Current state, Open questions, Earlier rounds. Omit a
section with nothing to say. A round that differs from the newer rounds carries
its round number or its date. The publisher appends the review marker: write none.

An open question that a later round or a linked source answered shows the answer
and its source. Add data that the rounds verified but did not publish only when the
plan says unpublishedDataAllowed is true, never a secret or personal data.

Language and voice: the assignment language (@rules/reports/general.md) and the
voice of @skills/agent-voice/SKILL.md. Headings are translated with the prose.
@skills/merge-review-comments/SKILL.md owns every rule of this shape.
