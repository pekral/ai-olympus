# Feedback loop — from a repeated review finding to an enforced safeguard

This reference defines the local store, repository identity and deterministic limits for recorded findings.

## The store

`commands/_shared/feedback-loop.sh` is the only code that knows the layout of the records, and `commands/_shared/promote-learning.sh` is the only code that writes the promotion history (`learning-promotion.md`). Every command takes the directory with `--dir`. The directory is `.claude/run/feedback`, and `.codex/run/feedback` on Codex (the existing mapping of `.claude/run/`). It is git-ignored with the rest of the run directory.

| File | Content |
| --- | --- |
| `pr-<owner>--<name>--<number>.jsonl` | The records of one pull request, in lower case. One pull request has one writer at a time, and two pull requests never share a file. |
| `merged.jsonl` | The files above, joined by `analyze`. |
| `report.json` | The report of the latest successful analysis. |
| `state.json` | The state file of the analysis (`recurrence-analysis.md` *Incremental analysis*). |
| `analysis-<n>.json`, `proposal-<n>.md` | Scratch files of a feedback-analysis run. |
| `promotion.jsonl` | The promotion history: candidates, decisions, and promotions (`learning-promotion.md` *The promotion history*). |

- **Every file is derived.** Delete the directory at any time. The next review rounds fill it again, and `collect` reads the published report of any pull request.
- **The promotion history is the one exception.** A deleted `promotion.jsonl` loses the recorded decisions, so a human decides those candidates again.
- **A deleted store loses the round reports.** `collect` reads only the report that GitHub still holds, which is the converged one. A finding that the loop fixed in an earlier round survives only in the store.
- **The records hold code.** A record quotes the faulty example of a finding. The directory is owner-only, and nothing in it is committed, published, or attached to a tracker. A proposal quotes at most the first three records, as data, under `prevention-proposal.md` *Untrusted text*.
- **No second database.** The store is plain files. It replaces no tracker and no report.

## One spelling of the repository

`record` and `collect` resolve the repository of the URL with `gh repo view --json nameWithOwner` and write that value in every record. The URL can use another case or the old name of a renamed repository. The records, the store name, and the dedupe key use the canonical value, so one defect class never splits into two. The repository that cannot be resolved gives exit `3` and writes nothing.

`propose-prevention.sh --find` accepts a search result only when its issue URL belongs to the repository of the key, compared without regard to case. A result of another owner or repository is not the proposal of the class.

## Configuration and limits

- The configuration is the two manifest keys of `recurrence-analysis.md` *Settings*, the three keys of `learning-promotion.md` *Settings*, and the store directory. There is no other setting.
- **GitHub only.** The loop records pull requests on GitHub. A review on another tracker records nothing.
- **One checkout.** The store lives in the run directory of one checkout. Two machines hold two stores.
- **No automatic trigger.** The analysis runs when the user asks for it.
- **The state file advances with the analysis.** A class whose filing failed is reported again when a new occurrence arrives. Delete `state.json` to process every record again.
- **The search reads at most 1000 results.** A repository with more matches for `prevention-proposal` makes `lookup` exit `3`, and the run files nothing.
- **The freshness of the issue search is not verified.** The create-then-search check of step 7 detects a search that lags, and it files nothing more after it.
- **A rule is not changed during a running implementation.** A safeguard takes effect only after its pull request is reviewed and merged. Until then the default branch, and every other running task, uses the rules it had.
