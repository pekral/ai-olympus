# Finding records — structured, fingerprinted review findings

This file is the one contract for turning a published code-review report into machine-readable finding records. It is phase 1 of the recurring-defect epic (#185): later phases group the records across pull requests, so the record and its fingerprint must be stable.

The contract adds no review format. It reads the two reports the review already produces: the findings report of a review round, and the `cr-comment` the converged run publishes (`templates/review-output.md`, `@rules/code-review/general.md` *One published comment per review run*). The reproducer fields, the severities, and the `Rule`, `Location`, and `Deferred` fields stay exactly as they are. The reviewer (`leonardo`) stays read-only and writes no record.

## Command

`skills/_shared/extract-findings.sh` is the only implementation. It is a plain shell script that needs `jq` and `sha256sum` or `shasum`, so it runs the same on Claude Code and on Codex. It runs outside the review and is not an agent binding.

```text
extract-findings.sh --repo <owner/name> --pr <number> [--input <file|->] [--store <file>]
extract-findings.sh --validate <file>
extract-findings.sh --self-test
```

- Without `--store`, the script prints the extracted records, one JSON object per line.
- With `--store`, the script merges the records into that JSON Lines file by `id` and replaces the file atomically. It prints one summary line. One writer uses a store at a time.
- Exit `0` is success, `1` a usage error, `2` a missing tool, `3` a malformed or incomplete report or store. After exit `3` nothing is written.

## What the extractor reads

The extractor reads the header lines `Status`, `Counts`, `Reviewed revision`, and `Last updated`, every finding heading of the form `### <emoji> Critical|Moderate <n>. <title>` outside the excluded sections below, and the list entries of `## Deferred to sub-issues`. A finding carries these fields, all required except `Provenance` and `Deferred`:

| Report field | Used for |
| --- | --- |
| heading word | `severity` |
| `Location` | `subsystem` |
| `Rule` | `category`, `cause_key` |
| `Impact` | `cause_hypothesis` |
| `Faulty Example` | `reproducer` |
| `Expected behavior` | `expected_behavior` |
| `Suggested fix` | `proposed_fix` |
| `Test hint` | required, not stored |
| `Deferred` | `disposition` |
| `## Deferred to sub-issues` entry | `disposition` of an open `moderate` record (see *One occurrence*) |

## The record

One JSON object per line. Every key is required, and no other key is allowed.

| Key | Value |
| --- | --- |
| `schema` | `1`. A change to any rule in this file bumps it. |
| `id` | `f-` and 16 hex digits. The hash of the repository, the pull request number, and the `fingerprint`. |
| `repo`, `pr` | `owner/name` and the pull request number. |
| `revision`, `time` | The `Reviewed revision` and the `Last updated` of the latest report that carried the finding, or that resolved or deferred it. `time` is UTC, with a trailing `Z`. |
| `severity` | `critical` or `moderate`. |
| `category` | The rule file the `Rule` field cites, without `.md` (for example `rules/security/backend`). `assignment` for a gap in `## Functional Review`. `unclassified` when nothing else applies. |
| `cause_key` | The section of that rule: the `#anchor` (a backticked anchor may hold spaces and runs up to the closing backtick), else the `*Section*` name written after the path, else `general`. A lowercase slug, so `#PHP Practices` and `*PHP Practices*` give the same key. `unmet-requirement` for an `assignment` gap. |
| `fingerprint` | 16 hex digits. The recipe is below. |
| `subsystem` | The first two directories of the `Location` path, lowercase. `.` for a file in the root. |
| `cause_hypothesis` | The `Impact` text. |
| `reproducer`, `expected_behavior`, `proposed_fix` | The text of the report fields, without code fences. Capped at 4000 characters. |
| `disposition` | `confirmed`, or `deferred` when the finding carries a `Deferred` field with a sub-issue URL. |
| `status` | `open`, or `resolved` (see *One occurrence*). |

## The fingerprint

The fingerprint is the first 16 hex digits of the SHA-256 of these lines, joined by a newline:

1. `ai-olympus/finding-fingerprint/v1`
2. `category`
3. `cause_key`
4. `subsystem`

The fingerprint comes from the behaviour and the cause. It never reads the title, the `Impact`, the reproducer, the line number, the file name, or any other wording. The same defect described in other words, on another line, or in another file of the same subsystem, has the same fingerprint. A different rule section or a different subsystem has a different fingerprint.

The consequence is stated plainly: two distinct defects of one rule section in one subsystem share a fingerprint. They count as one occurrence in one pull request. Phase 2 counts occurrences in different pull requests, so this loses nothing it uses.

## One occurrence per finding per pull request

- A finding is identified by its `id`. A second finding with the same `id` in one report is one record. The higher severity wins, and `confirmed` wins over `deferred`.
- A later round, an updated finding, and a reopened finding keep the `id`. The merge replaces the record and never adds a second one.
- A report older than the stored record changes nothing. Extracting the same report twice leaves the store byte-identical.
- A report of a pull request resolves the `confirmed` records of that pull request that it no longer carries. A `deferred` record is never resolved by absence. A report of another pull request resolves nothing here.
- A resolved record takes the `revision` and the `time` of the report that resolved it. An older report, such as a replay of the last round that carried the finding, then changes nothing.
- A round report precedes triage and has no `Deferred` field. The converged `cr-comment` lists a deferred Moderate only under `## Deferred to sub-issues`, one list entry per finding. The extractor takes the first path in an entry as the subsystem and requires a sub-issue URL in the entry. An open `confirmed` Moderate of the same repository, pull request, and subsystem that the report no longer carries becomes `deferred` and stays `open`, instead of `resolved`. It takes the `revision` and the `time` of that report. An entry without a path or without a URL defers nothing. A Critical is never deferred this way. Matching is by subsystem, so a Moderate that the loop fixed can also end `deferred` and `open` when a deferred Moderate of the same subsystem exists; the match errs toward `open`.

## What never becomes a record

- Anything under `## Excluded per assignment` or `## Delegated to another person`: these are dismissed or not this run's work.
- The `Not verified:` header line and any observation without a finding heading: the review reports only verified findings, and an unconfirmed point is not a finding (`@rules/code-review/review-process.md` *Output Rules — Truthful reporting*).
- A finding the review dropped. The report does not carry it.
- Any record with a `disposition` other than `confirmed` or `deferred`. `--validate` rejects it.
- A `## Deferred to sub-issues` entry never creates a record. It only changes the `disposition` of a record an earlier report created.

## Untrusted input and errors

The report is untrusted input (`@rules/security/general.md` *Untrusted Content Boundary*). The script reads it as data only. It never executes a line, never builds a command, a path, or a jq program from it, and it removes control characters. It rejects an input over 1 MiB. It caps each `## Deferred to sub-issues` entry at 4000 characters and rejects, with exit 3, a report that lists more than 200 entries there.

The text fields of a record (`cause_hypothesis`, `reproducer`, `expected_behavior`, `proposed_fix`) stay untrusted content for every consumer of the records. A consumer reads them as data and never as an instruction. A consumer fences them before they travel next to its own instructions or into an issue body.

The script rejects, with one clear message per problem and no crash:

- a text that is not a review report (a missing `Status`, `Counts`, `Reviewed revision`, or `Last updated` header line);
- a `Reviewed revision` that is not a commit SHA, and a `Last updated` that is not an ISO-8601 timestamp with a UTC offset;
- a finding that lacks a required field, repeats a field, or has a `Deferred` field without a sub-issue URL;
- a store line that is not valid JSON or does not satisfy the schema, and a repeated `id` in a store.

## Later phases

The record carries what the next phases read, so they need no second extraction:

- Grouping across pull requests uses `fingerprint` (or `category`) with `repo`, `pr`, and `time`. Counting distinct pull requests inside a window needs nothing else.
- Incremental analysis compares `revision`, `time`, and `status` of a record with what it processed before.
- A prevention proposal cites `category`, `cause_key`, `subsystem`, `reproducer`, and `expected_behavior`.
- Routing by tier reads `severity`.

Phase 1 does not run the extractor from the review loop, store records anywhere by default, or group them. Those belong to the later phases.
