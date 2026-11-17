# The `FAST` run — step 6 without a review, and its closing

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** the final tier of the run is `FAST` (step 6 and *Closing a `FAST` run*). A section, a step, or a bullet this file names *above* or *below* without a file is in `agents/krang.md`.

### The `FAST` path of step 6

**On a final tier of `FAST` the CR dispatch and its convergence loop are skipped, and nothing else in this step is**: record `stage|skipped|leonardo|tier FAST — <score and signals>` in the routing ledger, then continue at the *Post-convergence scoped pass* bullet below — which runs, unconditionally on this tier, because it is the deterministic validation the `FAST` pipeline rests on and there was no CR round whose head it could already have validated. `raphael` then runs alone after that pass, under the same skip question as in a CR round, and a failed scenario is a residual finding you dispatch `donatello` on; the step-6a reporting dispatch applies exactly as on any other tier, and *Closing a `FAST` run*
(*Adaptive routing* above) promotes the pull request in place of the convergence loop that normally would. The skip rests on the **final** tier, never the initial one, so a task that grew into an authorization change is reviewed exactly like one that started as one.

### Closing a `FAST` run

A `FAST` run dispatches no `leonardo`, so nothing in it runs `@skills/process-code-review/SKILL.md` — and that skill is what normally promotes the pull request out of Draft and labels it `ready to merge`. Left unhandled, every `FAST` run would end with a Draft PR nobody can merge, which is the saving turning into a stall.

A pull request whose body carries the marker `<!-- ai-olympus:contribution-policy=human-description -->` is the one exception: it stays a Draft for its author, and none of the steps below runs (`@rules/git/pull-requests.md` *Foreign repository with a contribution policy — a human writes the description*).

You close it instead, and only in this exact shape:

1. The post-implementation classification must still read `tier=FAST` on the final diff. A tier that rose is not a `FAST` run any more — dispatch `leonardo` and let the loop close it as usual.
2. `donatello`'s scoped validation must have returned `Tests done (scoped)` with every acceptance criterion satisfied. A `Blocked` scoped pass stops the run exactly as on any other tier.
3. Then, and only then, promote the PR: `gh pr ready <PR>`, label it `gh pr edit <PR> --add-label "ready to merge"` (create the label first when the repository lacks it, per `@skills/process-code-review/references/ready-to-merge-signal.md` *Pull-request label*). Run both commands through `skills/_shared/audit-trail.sh run --`, which records their lines. Write no status on the source tracker item (`@rules/compound-engineering/tracker.md` *No status write once the code review has started*).
4. Record `stage|skipped|leonardo|tier FAST — <the classifier's score and signals>` in the routing ledger, so the skipped review is visible in the record rather than inferred from its absence.

The pull request still carries no code-review report, which is why `@skills/merge-github-pr/SKILL.md` has its own, independently verified `FAST`-tier exemption: it re-runs the classifier over the PR's own changed files rather than trusting anything this run wrote. Your promotion is not evidence for that gate, and it was never meant to be.
