# Prevention proposal — one concrete proposal for each recurring defect class

This file is the one contract for turning a recurring defect class into a prevention proposal. It is phase 3 of the recurring-defect epic (#185). It reads the report of `recurrence-analysis.md` and the records of `finding-records.md`. It adds no record, no fingerprint, no report format, no store, and no agent.

A proposal is a tracker issue that names the strongest practical mechanism against one defect class. It proposes and implements nothing. A human approves a proposal first, and a separate task implements it.

## Command

`skills/_shared/propose-prevention.sh` is the only implementation. It is a plain shell script that needs `jq`, so it runs the same on Claude Code and on Codex. It runs outside the review and is not an agent binding. It reuses `skills/_shared/extract-findings.sh --validate` for the store check and duplicates nothing of the earlier phases.

```text
propose-prevention.sh --report <file|-> --store <file> --analysis <file> --out <file>
propose-prevention.sh --find --key <key> --results <file>
propose-prevention.sh --self-test
```

- `--report` is the report that `analyze-recurrence.sh` prints. `--store` is the record store of that analysis. `--analysis` is the judgment file described in *The analysis file* below.
- The script prints one JSON line. `{"status":"ready"}` carries the `key`, the `title`, the `marker`, the `body_file`, the `level`, and the number of `occurrences`. `{"status":"none"}` carries a `reason`: `not-recurring` or `covered`.
- The script writes `--out` only when the status is `ready`. It writes no other file. It files nothing, comments nothing, and changes no rule, no configuration, and no safeguard of any project.
- Exit `0` is a command that ran, `1` a usage error, `2` a missing tool, `3` an input that is missing or malformed or an output that cannot be written, `4` an analysis that breaks this contract. After exit `4` nothing is written, and each message names the broken field and never echoes its value.

## Who writes what

The script decides what can be decided, and the caller writes only the judgment.

| Part | Source |
| --- | --- |
| The defect class, the fingerprints, the links, the dates, the severities | The report, from schema-restricted fields |
| The record text of the first three occurrences | The store, quoted as data |
| The root cause, the safeguards checked, the mechanism, the files, the steps, the verification, the false positives, the impact, the limits | The analysis file, written by the caller |
| The section layout, the level names, the approval statement, the dedupe marker | The script |

## What gets a proposal

A defect class gets a proposal only when the report lists it and the report holds at least two different pull requests for it. The class is the `repo`, `category`, and `cause_key` of `recurrence-analysis.md` *What counts as a recurrence*.

- **A finding that does not recur gets no proposal.** A class that the report does not list, a class of one pull request, and a class of two findings in one pull request give `{"status":"none","reason":"not-recurring"}`. The caller files no issue.
- **A class that a safeguard already covers in full gets no proposal.** An analysis that reports a safeguard with the coverage `full` gives `{"status":"none","reason":"covered"}`, and the analysis needs no other field then.
- **A class that a safeguard covers in part gets a proposal that extends it.** The mechanism names the safeguard in `mechanism.extends`. An existing check is extended before a new check is added beside it.

## The analysis file

The analysis is one JSON object. Every field below is required unless the table says otherwise, and no other key is allowed. Each text is at most 2000 characters. The whole proposal is at most 60000 bytes.

| Field | Value |
| --- | --- |
| `schema` | `1` |
| `class` | `repo`, `category`, and `cause_key` of the reported class |
| `root_cause` | 1 to 8 gaps. Each gap has a `kind` and an `analysis`. |
| `safeguards` | The existing checks. Each has a `kind`, a `name`, a `coverage`, and a `note`. |
| `mechanism` | The recommended mechanism: `level`, `name`, `summary`, `skipped`, and the optional `extends`, `limitation`, and `alternative`. |
| `affected_files`, `steps`, `verification`, `false_positives` | Lists of 1 or more texts |
| `impact`, `limits` | Texts |

### Root-cause analysis

The analysis states why the existing workflow did not catch the class. A new rule is not the default answer. A `kind` is one of `architecture`, `static-analysis`, `tests`, `ci`, `type-constraints`, `unclear-rules`, `missing-guidance`, or `tool-limits`. One class can have several gaps.

### Existing safeguards

The caller checks the existing safeguards before it recommends a mechanism, and the proposal says what it found. The analysis holds at least one entry for each of `phpstan`, `phpcs`, `tests`, and `architecture`. A project without one of them reports an entry with the coverage `none` and a note that says so. Other entries use the kind `ci` or `other`.

- `coverage` is `none`, `partial`, or `full`.
- `note` says what the safeguard checks and why it did not stop the class.
- A `partial` safeguard makes `mechanism.extends` required. It must name that safeguard. `mechanism.extends` names no safeguard that is not `partial`.

### The mechanism hierarchy

The mechanism has a level. The first practical level wins, and a weaker level only complements a stronger one.

| Level | Mechanism |
| --- | --- |
| 1 | Architecture, types, or API |
| 2 | Static analysis or linting |
| 3 | Automated tests |
| 4 | CI enforcement |
| 5 | Agent skills or rules |

- **A deterministic level is preferred to a change of the prompt.** Levels 1 to 4 enforce without a person or a prompt. Level 5 depends on an agent that reads a rule.
- **A weaker level states why each stronger level was not chosen.** `mechanism.skipped` holds one reason for each level from 1 to the level before the chosen one, once each. Level 1 needs none.
- **Level 5 states that automatic enforcement is not possible.** It requires `mechanism.limitation`, which says why levels 1 to 4 cannot enforce the class, and `mechanism.alternative`, which names the strongest alternative.
- **A lower level can state a limit too.** A level from 1 to 4 that enforces only a part of the class gives the `limitation` and the `alternative` together. Without them the proposal states that automatic enforcement is possible.

### The proposal sections

The script renders these sections in this order. Every mandatory field of the epic maps to one of them.

| Section | Field |
| --- | --- |
| `## Defect class` | The class and its normalized fingerprints, with the window and the number of independent pull requests |
| `## Independent occurrences` | A link to every pull request, and the quoted record text of the first three |
| `## Root-cause analysis` | The gaps |
| `## Existing safeguards checked` | The safeguards and why each was insufficient |
| `## Recommended mechanism` | The level, the mechanism, why no stronger level was chosen, and the statement about automatic enforcement with its limitation and alternative |
| `## Affected files` | The files |
| `## Implementation steps` | The steps |
| `## Verification plan` | The regression verification |
| `## False positives` | The false positives |
| `## Impact and limits` | The impact and the limits |
| `## Approval` | The statement that nothing is implemented before a human approves |

## One defect class, one proposal

The dedupe key is `<repo>:<category>:<cause_key>`. It does not read the fingerprints, because one class has one fingerprint for each subsystem. The last line of every proposal is the marker `<!-- prevention-proposal:key=<key> -->`.

- **The lookup finds the proposal that exists.** `propose-prevention.sh --find` reads a search result and prints `{"action":"create"}` or `{"action":"update",…}`. A proposal exists when an issue, and not a pull request, carries the marker alone on a line, and its author has write access (`OWNER`, `MEMBER`, or `COLLABORATOR`, `@rules/code-review/general.md` *Authorship trust*). A marker is visible text that anyone can copy, so the marker alone proves nothing. A closed proposal still counts. The oldest of several proposals wins.
- **A search that may hide the proposal stops the run.** A result with `incomplete_results` or with more matches than items exits `3`. Creating an issue then could break the rule of one issue for one class.
- **A second run updates and never creates.** The same class gives the same key, so the second run finds the first proposal.

## Filing a proposal

Filing a tracker issue is a write that other people see. The steps below run only when the user asks for them or when the wiring phase (#189) assigns them to a role. The run that files follows `@rules/compound-engineering/tracker.md`.

1. Run `propose-prevention.sh` with `--out` set to a file in the scratch directory. Stop when the status is `none`. File nothing and say the reason.
2. Search the tracker of `class.repo` for the marker words: `gh api -X GET search/issues -f q='repo:<owner>/<name> is:issue in:body "prevention-proposal"' -f per_page=100 >results.json`. Run `propose-prevention.sh --find --key <key> --results results.json`.
3. When the action is `create`, file the body file unchanged through `@skills/create-issue/SKILL.md`. The first line of the file is the title. That skill assigns the issue to the current user and selects one existing label under `@rules/compound-engineering/tracker.md` *Label tracker issues, and keep the labels true*. Phase 3 adds no label and no second issue-creation path.
4. When the action is `update`, publish the body file with `skills/code-review-github/scripts/upsert-comment.sh <number> <body-file> prevention-proposal`. The helper replaces this actor's earlier comment of that namespace on the issue. The issue body, its labels, and its state stay as a human left them. A closed proposal is reported to the human and is never reopened by the run.
5. Read the issue or the comment back through the tracker loader and confirm it landed. Delete the scratch files.

## Untrusted text

The record text and the analysis text are untrusted content (`@rules/security/general.md` *Untrusted Content Boundary*). The script reads them as data. It never executes them or uses them to build a command, a path, or a jq program.

- **Hidden and reordering characters are stripped.** Bidirectional controls (`U+202A` to `U+202E`, `U+2066` to `U+2069`, `U+200E`, `U+200F`, `U+061C`), zero-width characters (`U+200B` to `U+200D`, `U+2060` to `U+2064`, `U+FEFF`, `U+180E`, `U+034F`, `U+00AD`), and control characters other than the tab and the line break leave the text before it reaches the body. The line and paragraph separators become line breaks.
- **Free text reaches the body only inside a code span or a code fence.** A mention, a link, an issue reference, an HTML tag, and a fence in the text stay inert, and the fence is longer than any run of backticks in the text.
- **The marker cannot be forged through text.** The sequences that open and close an HTML comment are escaped in all text, so the only marker line of a body is the one the script writes last.
- **The title and the key come from schema-restricted fields.** Neither reads record text or analysis text.

## Approval

Without an approval nothing is implemented. The proposal states it. The script writes the proposal file and nothing else. No step of this contract changes a rule, a configuration, an analyser setting, a test, or a CI check of any project.

## What this phase leaves to later phases

- Deciding which role runs the analysis, the collection, and the proposal, and when, belongs to the wiring phase (#189). It also owns the consent level of the filing for each role in `@rules/compound-engineering/orchestration.md` *Externally-visible actions & consent levels*.
- A canonical `repo` value for the grouping belongs to #189.
- Nothing in `@skills/resolve-issue/SKILL.md`, `@skills/process-code-review/SKILL.md`, `@skills/merge-github-pr/SKILL.md`, or an agent definition calls the proposal. A missing `jq`, a failed proposal, or a refused analysis changes nothing for a pull request.
