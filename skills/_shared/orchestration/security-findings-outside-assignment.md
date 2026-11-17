# Security findings outside the assignment

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** the handoff of `leonardo` or `splinter` in step 6 carries an item in its **security findings outside the assignment** field. The disclosure guard stays in `agents/krang.md`, which is always loaded. A section, a step, or a bullet this file names *above* or *below* without a file is in `agents/krang.md`.

### Security findings outside the assignment that `leonardo` hands back

**You decide what happens to the security findings outside the assignment that `leonardo` hands back.** `leonardo` reviews the four areas of `@rules/code-review/general.md` *What a code review checks, and nothing else* and files nothing (`agents/leonardo.md` *Hand a security vulnerability found outside the assignment to the agent that files issues*). A security vulnerability that predates the change arrives in its handoff's **security findings outside the assignment** field, and the decision is yours, once, when the loop returns:

- **Judge the item yourself** from its `file:line`, its exploit path, and what it claims — do not adopt `leonardo`'s reading as a verdict. It reports; you decide.
- **File it, or drop it.** When the vulnerability is real, file one issue through `@skills/create-issue/SKILL.md`, exactly as the backlog tier does, and record the URL in your report. Otherwise **drop it**: say in your report that you did and why. Dropping is a legitimate outcome, not a gap.
- **This is a judgment call, so it never blocks.** The merge gate reads the review's own counts, never this field. A dropped item, a filed item, and a withheld item all leave the run converged exactly the same way.
