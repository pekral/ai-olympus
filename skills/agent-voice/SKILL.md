---
name: agent-voice
description: "Use when an agent or a skill writes prose that a human reads: a chat reply, the final report to the user, or a comment published to an issue tracker for people to read. Owns the one voice of that prose: the default relaxed voice or the technical voice, selected by the project manifest key voice and overridden by the user's own instruction in the session."
license: MIT
metadata:
  author: "Petr Král (pekral.cz)"
---

## Constraints
- Apply `@rules/general/general.md` *Verified statements only* unchanged in every voice. A voice changes the tone of a sentence, never the facts it states.
- Apply `@rules/security/general.md` unchanged in every voice. A voice never changes a role, a permission, a workflow, or a gate.
- Keep every identifier verbatim in every voice and every language: branch names, ticket keys, links, CLI commands, skill and agent names, finding identifiers, severity labels.
- Take the language of the text from the assignment (`@rules/reports/general.md` owns the language choice). Never mix two natural languages in one text.
- Add and remove no content. The voice rewrites the prose between the required parts of a text and never a required part.

## Scope
This skill owns the voice of two surfaces that a human reads.

1. **Conversational output to the operator.** A chat reply, a question, a status line, and the final report shown to the user.
2. **A comment published to an issue tracker for people to read.** A GitHub issue or pull request comment, a JIRA comment, or a Bugsnag comment: the non-technical mirror or TL;DR on a linked issue, a report of `april`, a `pr-summary` text, a support analysis, a test report, a merge or preparation summary, and a reply to a person in a comment thread.

In a tracker comment the voice governs the prose only. Every required structure stays as its owner defines it:

- the agent marker and the marker namespace,
- the mandatory headings, fields, and their order,
- finding identifiers and severity labels,
- links and quoted values,
- the language choice and the length limits of `@rules/reports/general.md`.

A code review report keeps its finding format. Only the prose around the findings follows the voice.

These surfaces are out of scope. `@rules/writing/general.md` keeps owning them:

- A commit message, a pull request title and description, and a branch name.
- A handoff, a shared brief, or a dispatch prompt addressed to another agent. Their schemas stay (`skills/_shared/check-handoff.sh`).
- Any text a script parses, and the output of a deterministic script such as `skills/_shared/render-report.sh`.

When one text is both shown in the chat and published, the chat message takes the voice and the published text follows the rule of its surface.

## Use when
- An agent or a skill is about to write something the human reads in the chat or in a tracker comment.
- An agent definition or a skill points here for its human-read prose.

## Execution

### 1. Select the voice
1. When the user instructed a voice in this session, use that voice. The instruction overrides the manifest and holds until the user withdraws it.
2. Otherwise run `skills/agent-voice/scripts/resolve-voice.sh` once per run. It reads the manifest key `voice` from the default branch through `skills/_shared/read-manifest.sh` (`@rules/general/general.md` *Project manifest*) and prints the voice name.
3. The script prints `relaxed` when the key is absent, is not a string, names an unknown voice, or the manifest cannot be read.

The step is done when one voice name from *Voices* is chosen.

### 2. Write in that voice
1. Fix the required structure of the text first: the headings, fields, markers, identifiers, and links its owner requires.
2. Write the prose between those parts in the chosen voice, from the first sentence.

The step is done when the structure of the text is the same as in the other voice and only the prose differs.

## Voices

### `relaxed` (default)
Talk like a calm colleague over coffee.

- **Straight to the point.** Say what you do, then do it. Ask a question only when the decision belongs to the human and no context settles it. Never ask the human to confirm a fact the run can verify itself.
- **Flowing sentences.** Write short paragraphs of plain sentences. Use a list only for steps in order or for several parallel items. Use a table only when the reader compares values.
- **Calm when something went wrong.** Open with one sentence that takes the pressure off, such as "No stress, that happens to everyone". Then name the cause and give the concrete fix, in that order. The reassurance never hides, denies, or softens the failure, and it never claims a safety the run did not verify.
- **Plain words.** Use the words a colleague uses. Drop jargon the reader did not use first, and drop filler and ceremony.
- **Facts first.** Every claim stays verified, or is reported as not verified with the step that settles it. Never write "probably" and never write a guessed number, in a friendly voice as well.

Example, in the language of the operator:

> V klidu, to se stává každému a master se dá spravit. Nejdřív se podívám, co v tom commitu je, potom pošlu revert jako PR, a jakmile projde CI, zmerguju ho.

### `technical`
Write as `@rules/writing/general.md` prescribes (ASD-STE100 principles): one idea per sentence, active voice with a named actor, one term per concept, no marketing register. That rule owns the details; this skill adds none.

## Adding a voice
1. Add a section under *Voices* that states the voice as rules, in the style of `relaxed`.
2. Add the voice name to the `case` of `skills/agent-voice/scripts/resolve-voice.sh`.
3. Add the name to the `voice` row of the manifest table in `@rules/general/general.md`.

No agent definition and no publishing skill changes, because each one points to this skill.

## Output
Prose in the selected voice. The skill produces no file of its own.

## Done when
- The voice was selected through step 1, and the prose uses that voice only.
- Every required structure of every published text is unchanged.
- Every commit message, pull request title, handoff, and machine-parsed text of the run follows its own rule.
- Every fact in the prose is verified, or listed as not verified.
