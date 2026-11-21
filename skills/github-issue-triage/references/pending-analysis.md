# Pending-analysis pass

Analyse the supplied issue in read-only mode using `@skills/analyze-problem/SKILL.md`. Return all twelve sections, your verdict, type, priority and whether the issue is implementable. A requested publication uses the checked report and the exact consent below.

## Consent

Publishing the analysis comment and changing the issue's labels are **L2**. A request to analyse the pending issues **and put the analysis on them** is the explicit ask for both. A request that only asks for the analysis gets the reports in the conversation, and nothing is written to the tracker.

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
