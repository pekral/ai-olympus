# Pending-analysis pass

Some issues are not work yet — they are a link, a tool name, or an idea that needs an analysis before anyone can decide whether to build it. The repository marks them with the `analyze` label. This pass finds them, analyses each one, publishes the analysis as a new comment on the issue, and leaves labels that describe the issue after the analysis rather than before it. It is the mode for requests such as *"analyse the issues that wait for analysis"*.

**It runs in the top-level session, never inside `krang`'s backlog tier.** A backlog run never analyses (`@rules/compound-engineering/backlog.md` *What a backlog run never does*); an analysis request is answered by `@skills/analyze-problem/SKILL.md` in the top-level session, and this pass is that answer applied to every pending issue at once.

## Consent

Publishing the analysis comment and changing the issue's labels are **L2**. A request to analyse the pending issues **and put the analysis on them** is the explicit ask for both. A request that only asks for the analysis gets the reports in the conversation, and nothing is written to the tracker.

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

Independent issues may be analysed **in parallel**, one subagent per issue, unless the project forbids helper agents: then the session analyses them one after another itself (`skills/_shared/orchestration/helper-agents.md`). Each subagent:

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

Derive the effort label of each report before you publish it. The helper owns the method and every threshold (`SKILL.md` *Effort derivation*), so never state a size or a time yourself. Derive it from the assignment text this pass produced for the issue, when it produced one: save that text to a file outside the repository. Only when the pass produced no assignment text, save the issue body instead. An issue that is only a link then gets its size from the assignment the analysis settled, never from the link.

```bash
skills/github-issue-triage/scripts/derive-effort.sh --assignment <assignment-file>
```

Add an **Effort:** field after the **Priority:** field of section 11 of the report. The field names the `label=` line, the count with its method (`files-and-criteria`: the `files` and `criteria` values), the `tier` and its source, and, for `effort: large`, that the issue is a candidate for splitting. It states no hours or days. When the helper exits non-zero, publish the report without the field, name the reason in the report to the user, and leave the effort labels of the issue unchanged.

The report is the one `skills/analyze-problem/templates/analysis-report.md` renders. The prose of the report follows `@skills/agent-voice/SKILL.md`. Every marker, heading, field, identifier, and link stays as this skill defines it.

```bash
skills/code-review-github/scripts/upsert-comment.sh <URL> <report-file> agent-note
```

`agent-note` is create-only: the analysis always lands as a new comment, and no earlier comment is overwritten. Never paste the analysis into the issue body.

## 5. Make the labels true

In one `gh issue edit` per issue, so the issue is never left half-relabelled:

- **remove `analyze`** — the issue no longer waits for an analysis;
- **set the primary type label** the analysis settled (`enhancement`, `chore`, …), and remove the type label it replaces;
- **set the priority** from the analysis *Priority* field, and remove the priority label it replaces — with two exceptions that keep the current priority and report the difference: a person set it after the last triage (read the label history as `references/backlog-sweep.md` step 5 shows), or it is `priority: critical`. The pass never assigns `priority: critical`, and never removes or lowers it;
- **set the effort label** that `scripts/derive-effort.sh` printed (`effort: small`, `effort: medium`, or `effort: large`) when it printed one, and remove the other effort labels the issue carries. An issue carries one effort label at most, so a new analysis replaces the old one. Run `scripts/seed-labels.sh` first when the repository lacks the label, because `gh issue edit --add-label` fails on a missing label;
- **add `question`** when the verdict leaves a decision to the owner.

The pass **never adds or removes `Resolve_by_AI`**. It is a workflow label that routes an issue to autonomous resolution (`@rules/compound-engineering/tracker.md` *Label tracker issues, and keep the labels true*), and routing is the owner's decision. When the verdict is *do not adopt* or *defer* and the issue still carries it, the report asks the owner to remove it; when the analysis produced an assignment an agent could implement, the report recommends adding it.

```bash
gh issue edit <N> --remove-label analyze \
  --remove-label "<replaced type>" --add-label "<type>" \
  --remove-label "<replaced priority>" --add-label "priority: <level>" \
  --remove-label "<replaced effort>" --add-label "<effort label>"
```

Leave out a `--remove-label` / `--add-label` pair when the label does not change.

Re-read the issue afterwards (`gh issue view <N> --json labels,comments`) and confirm that both the comment and the labels landed.

## Report

Close with one report to the user, in their language: per issue, the verdict in one sentence, the comment URL, the label changes including the effort label, and the recommended next step. An issue labelled `effort: large` is named as a split candidate; the pass never splits it. Batch every decision the analyses left open — adopting a dependency, adding or removing `Resolve_by_AI`, closing a rejected idea — and every issue the selection dropped into one round of questions.

**Handoff status:** `Analyses published` + the count + the comment URLs; or `Blocked` with the reason.
