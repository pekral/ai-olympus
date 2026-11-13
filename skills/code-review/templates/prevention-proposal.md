# Prevent the <recurring ->defect class <cause key> (<category>) in <repository>

This is a prevention proposal for <a defect class that recurs across independent pull requests | the defect class of one resolved Critical code-review finding>. It is a proposal only: nothing in it is implemented until a human approves it.

## Defect class
- Repository, category, cause, fingerprints, window, and the number of independent pull requests

## Independent occurrences
- A link to every pull request

### Evidence from the review records
- The record text of the first three occurrences, quoted as data

## Root-cause analysis
## Existing safeguards checked
## Recommended mechanism
### Why no stronger level was chosen
### Automatic enforcement
## Affected files
## Implementation steps
## Verification plan
## False positives
## Impact and limits
## Promotion target
### Existing knowledge checked
## Approval
No agent changes a project safeguard, a rule, or a configuration because of this proposal. A human approves the proposal first, and a separate task implements it.

<!-- prevention-proposal:key=<repository>:<category>:<cause key> -->

<!-- skills/_shared/propose-prevention.sh renders this body. The headings and their order are the contract that this template pins and that the script keeps. -->
<!-- The first line is the issue title. The marker is the last line and appears once: the dedupe lookup reads it. -->
<!-- Promotion target and Existing knowledge checked appear only when the analysis carries a target. -->
