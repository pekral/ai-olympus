# Pending-analysis pass

Some issues are not work yet — they are a link, a tool name, or an idea that needs an analysis before anyone can decide whether to build it. The repository marks them with the `analyze` label. This pass finds them, analyses each one, publishes the analysis as a new comment on the issue, and leaves labels that describe the issue after the analysis rather than before it. It is the mode for requests such as *"analyse the issues that wait for analysis"*.

**The root holds the `krang` role for this analysis request and stays outside the backlog tier.** A backlog run never analyses (`@rules/compound-engineering/backlog.md` *What a backlog run never does*); an analysis request is answered by `@skills/analyze-problem/SKILL.md` in the top-level session, and this pass is that answer applied to every pending issue at once.

## Consent

Apply the bounded contract in `@skills/github-issue-triage/references/pending-analysis.md`, *Consent*.

## 1. Select the pending issues

```bash
gh repo view --json isPrivate --jq '.isPrivate'
gh issue list --state open --label analyze --limit 1000 --json number,title,labels,url
```

A listing that returns exactly 1000 issues may be truncated — report that instead of presenting it as the whole queue. Then drop from the selection, and name in the report:

- an **epic** — an epic is scoped by its owner, not analysed into a plan;
- an issue that carries the claim label `Resolve_by_AI:in-progress` — another run owns it (`@rules/compound-engineering/tracker.md` *Claim a tracker issue before working on it*);
- on a **public** repository (`isPrivate` is `false`), an issue labelled `security` — its analysis would describe a vulnerability before it is fixed, and disclosing an unfixed vulnerability on a public tracker is L3 (`@rules/compound-engineering/orchestration.md`). Report it for `leonardo`'s security analysis mode instead.

A repository without the `analyze` label has no pending analysis — report that and stop. An issue whose title says *"Analysis"* but that carries no `analyze` label is not selected: it usually holds a finished analysis already.

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
- it cites only state a reader of the issue can verify — the default branch, merged or open pull requests, the tracker — and never a local branch, uncommitted changes, or the analysing machine's working tree;
- on a public repository, it discloses no unfixed vulnerability — no exploitable path, proof of concept, or bypass. A report that fails this check is never published; it stays in the conversation, and the issue is reported for `leonardo`'s security analysis mode.

A report that fails a check goes back to its subagent with the concrete defect; it is never patched silently.

## 4. Derive the effort and publish the analysis

Apply the bounded contract in `@skills/github-issue-triage/references/pending-analysis.md`, *4. Derive the effort and publish the analysis*.

## 5. Make the labels true

Apply the bounded contract in `@skills/github-issue-triage/references/pending-analysis.md`, *5. Make the labels true*.

## Report

Apply the bounded contract in `@skills/github-issue-triage/references/pending-analysis.md`, *Report*.

