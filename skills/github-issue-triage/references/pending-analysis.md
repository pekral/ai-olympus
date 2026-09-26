# Pending-analysis pass

Some issues are not work yet — they are a link, a tool name, or an idea that needs an analysis before anyone can decide whether to build it. The repository marks them with the `analyze` label. This pass finds them, analyses each one, publishes the analysis as a new comment on the issue, and leaves labels that describe the issue after the analysis rather than before it. It is the mode for requests such as *"analyse the issues that wait for analysis"*.

**It runs in the top-level session, never inside `splinter`'s backlog tier.** A backlog run never analyses (`@rules/compound-engineering/backlog.md` *What a backlog run never does*); an analysis request is answered by `@skills/analyze-problem/SKILL.md` in the top-level session, and this pass is that answer applied to every pending issue at once.

## Consent

Publishing the analysis comment and changing the issue's labels are **L2**. A request to analyse the pending issues **and put the analysis on them** is the explicit ask for both. A request that only asks for the analysis gets the reports in the conversation, and nothing is written to the tracker.

## 1. Select the pending issues

```bash
gh issue list --state open --label analyze --json number,title,labels,url
```

Skip an epic that carries the label: an epic is scoped by its owner, not analysed into a plan. A repository without the `analyze` label has no pending analysis — report that and stop. An issue whose title says *"Analysis"* but that carries no `analyze` label is not selected: it usually holds a finished analysis already.

## 2. Analyse each issue

Run `@skills/analyze-problem/SKILL.md` against the issue URL, **read-only**, and let that skill own everything about the analysis — the pre-flight, the four phases, the twelve-section report, and the plan artifact, which in a read-only run is returned inline inside the report.

Independent issues may be analysed **in parallel**, one subagent per issue. Each subagent:

- receives the issue URL, the assignment language, and the environment facts it cannot discover itself;
- returns its report **as text** — a harness may refuse a subagent's file writes, and the session that publishes saves the report to a file outside the repository;
- returns a verdict (adopt, adopt partially, do not adopt, defer), a primary type label, a priority with its reason, and whether the issue now holds an assignment an agent could implement;
- never publishes, labels, or edits anything.

Publishing stays with the session that holds the consent, so a subagent's report is read before it reaches the tracker.

## 3. Review each report before publishing

Read every report in full. Publish it only when:

- all twelve sections are filled and the *Sources* section lists what the analysis actually read;
- it is written in the assignment language only (`@rules/reports/general.md`);
- it quotes no secret, token, or personal data from the tracker or the environment;
- its facts about this repository hold — spot-check the claims the verdict rests on against the default branch;
- it cites only state a reader of the issue can verify — the default branch, merged or open pull requests, the tracker — and never a local branch, uncommitted changes, or the analysing machine's working tree.

A report that fails a check goes back to its subagent with the concrete defect; it is never patched silently.

## 4. Publish the analysis

```bash
skills/code-review-github/scripts/upsert-comment.sh <URL> <report-file> agent-note
```

`agent-note` is create-only: the analysis always lands as a new comment, and no earlier comment is overwritten. Never paste the analysis into the issue body.

## 5. Make the labels true

In one `gh issue edit` per issue, so the issue is never left half-relabelled:

- **remove `analyze`** — the issue no longer waits for an analysis;
- **set the primary type label** the analysis settled (`enhancement`, `chore`, …), replacing a type label the analysis proved wrong;
- **set the priority** from the analysis *Priority* field, unless a person set the current priority after the last triage — then keep it and report the difference;
- **remove `Resolve_by_AI`** when the verdict is *do not adopt* or *defer*, so an automated selection cannot pick up work nobody decided to do. **Never add it**: routing an issue to autonomous resolution is the owner's decision, and the report recommends it instead;
- **add `question`** when the verdict leaves a decision to the owner.

```bash
gh issue edit <N> --remove-label analyze --add-label "<type>" --add-label "priority: <level>"
```

Re-read the issue afterwards (`gh issue view <N> --json labels,comments`) and confirm that both the comment and the labels landed.

## Report

Close with one report to the user, in their language: per issue, the verdict in one sentence, the comment URL, the label changes, and the recommended next step. Batch every decision the analyses left open — adopting a dependency, routing an issue to `Resolve_by_AI`, closing a rejected idea — into one round of questions.

**Handoff status:** `Analyses published` + the count + the comment URLs; or `Blocked` with the reason.
