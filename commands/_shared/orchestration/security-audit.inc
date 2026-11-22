# Defensive security audit

Use the repository, subsystem or diff supplied by the caller. For an ordinary review, limit the assessment to changed code and its affected control paths. Use source evidence or a local, side-effect-free observation of expected control behaviour with benign inputs. Keep any local observation sequential. Do not use external targets, run live exploits, write project files, or run a test suite, fixer, analyser or build.

Apply the bounded `@skills/security-audit/SKILL.md` contract. Commission only source-based examination of access controls, validation, isolation and data-handling policy in the supplied local scope. Every brief below inherits these limits. For benign local checks use the sequential pass. If confirmation would require executing an exploit or constructing a vulnerability reproduction, mark the finding `needs_validation`.

## Diff mode

**Workers.** The reviewer runs every pass itself, in sequence, in its own context. It never dispatches a subagent for a hunter, a verifier, or a coverage critic, so the verifier of a candidate is a separate pass, never the hunting pass.

### Verification and coverage by profile

Use the user's requested profile and the numeric budget from `@skills/security-audit/references/profiles-and-budget.md`. Follow the read-only boundaries above for every pass.

| Profile | Verification | Coverage critic |
| --- | --- | --- |
| `quick` | Every candidate whose boundary is authentication, authorization, or tenant isolation first, then the others while the verifier reserve lasts. A candidate no verifier reached stays `needs_validation` with severity `unrated`. | A fresh critic after each wave; at most one follow-up wave for the omissions it found. |
| `standard` | Every candidate. | A fresh critic after each wave; the omissions it found are hunted in the next wave. |
| `deep` | Every candidate; a finding a critic reports as `assumption_based_finding` goes to a second new verifier, whose ID replaces the first. | A fresh critic after each wave; waves repeat while a critic still finds an omission and budget remains. |

## Execution

### 1. Set the budget and open the run
1. Run `skills/security-audit/scripts/audit-artifacts.sh budget --profile <profile> [--total <passes>]`. Its output is the run's budget. Never compute or adjust it by hand.
2. Record the commit audited and the pre-audit `git status --short` output.
3. Run `skills/security-audit/scripts/audit-artifacts.sh snapshot` in the audited project. Its output is the working-tree baseline. Record it as `worktree_baseline` in `metadata.json`.
4. Create the run directory beside the earlier runs.

### 2. Reconnaissance and the coverage inventory
The inventory exists before hunting. No hunter opens before this step ends.
1. **Reconnaissance.** With read-only inspection commands, map every trust boundary in scope and every surface that crosses one: HTTP entrypoints, authentication, authorization, background jobs, queues, webhooks, file handling, external APIs, data isolation, privileged actions, AI tools, MCP integrations, and agent-triggered side effects. Include frontend, configuration, deployment and CI files, and dependencies wherever the scope reaches them.
2. **Coverage units.** Write one coverage unit for each attack class that applies to a surface of a subsystem, with the fields in `@skills/security-audit/references/roles-and-lifecycle.md` *Coverage units*. The attack classes are the *Hunting areas* of the same file. Split a broad unit until a hunter can hold it in one pass. A surface outside the scope the user named is a unit with status `out_of_scope` and a reason. Every AI tool, MCP integration, and agent-triggered side effect gets an `ai_tools` unit, whatever other units its surface has.
3. Write the inventory as `coverage-ledger.json`: `base_run` set to `null`, every other unit `queued`, `agent` set to `null`, and no wave. **In an incremental audit**, run `skills/security-audit/scripts/audit-artifacts.sh impact <base-run-dir>` first and write the inventory from its result, as `@skills/security-audit/references/incremental-audit.md` *The inventory* states: `base_run` names the base run, every unit the impact marks `reuse` is copied unchanged with `reused_from`, and every other base unit is queued again under its `coverage_id`. Reconnaissance walks every changed path the impact lists, not only the new ones.
4. Run `skills/security-audit/scripts/audit-artifacts.sh inventory <run-dir>`. Hunting starts only after it exits `0`.

### 3. Hunt in waves (bounded by `hunting_max`)
- A wave is a set of hunters you open together. Give each hunter exactly one queued unit, a new worker ID, and the brief in `commands/_shared/orchestration/security-audit-briefs.inc` *Hunter brief*, verbatim. Record the hunter's ID as the unit's `agent`.
- **Parallel hunting stays read-only.** Run the hunters of one wave in parallel only when all of these hold:
  1. Every worker is fresh and read-only, and receives nothing but its verbatim brief.
  2. No worker writes a file anywhere. A worker returns its result in its reply. You alone write the artefacts, after the wave.
  3. No unit of the wave needs a local reproduction. A unit that does is hunted alone, in a sequential pass.
  4. The host gives you parallel workers that hold reading tools only. On Claude Code a worker is a fresh subagent dispatched through the `Task` tool, with a subagent type whose tool list carries only reading tools (`Read`, `Grep`, `Glob`): no `Write`, `Edit`, `NotebookEdit`, or `Bash`. On Codex it is a fresh sub-agent that runs in the `read-only` sandbox. Where the host has neither, run the wave's passes one after another in this context, one unit per pass.
- Keep concurrency to the number of lanes you can coordinate.
- After every wave, run `skills/security-audit/scripts/audit-artifacts.sh snapshot` again and record its output as the wave's `worktree`. When it differs from `worktree_baseline`, a worker wrote to the working tree. Open no further parallel wave, report the write, and leave the change for the user to inspect. `validate` refuses a run whose waves changed the working tree.
- **The snapshot has a limit.** It covers HEAD, the index, and the bytes of every tracked file and every untracked file that is not git-ignored. A write to a git-ignored path, outside the work tree, or inside a submodule or a nested git repository does not change it, so an unchanged snapshot is no proof that a worker stayed read-only. Condition 4 above, a worker that holds reading tools only, is what keeps a worker from writing; the snapshot only catches a write it can see.
- Set a hunted unit to `candidate` when its hunter returned a candidate, and to `covered` when it returned `none`. A unit the hunter could not analyse is `blocked` with the reason.
- Record on the unit what its hunter returned besides candidates: the security inputs on its path (`inputs`, `@skills/security-audit/references/roles-and-lifecycle.md` *Security inputs*), the ten AI and agent threat classes for an `ai_tools` unit (`threats`, *AI and agent threat model*), and the five action dimensions for an action surface (`action_binding`, *Action binding*). `validate` refuses a covered unit without them.
- Every hunter pass spends one pass of the budget. Stop opening hunters when `spent.hunting` equals `hunting_max`. A unit that did not get its pass becomes `deferred` with the reason `hunting budget spent`; it never stays `queued` and never counts as covered.
- The reserves for verifiers and coverage critics are not available to hunting under any circumstance.

### 4. Verify every candidate (the reserve)
- Compute each candidate's fingerprint with `skills/security-audit/scripts/audit-artifacts.sh fingerprint` from its fingerprint basis, as described in `@skills/security-audit/references/roles-and-lifecycle.md` *Fingerprint*. Merge candidates with the same fingerprint into one finding before verification, so one verifier settles one root cause. The merged finding lists every hunter that proposed it. A candidate with the fingerprint of a finding an earlier wave verified joins that finding and is not verified again. Never merge on similar wording alone.
- Give each candidate to a new verifier: a fresh worker with a new ID, which hunted nothing and verifies no other candidate, with the brief in `commands/_shared/orchestration/security-audit-briefs.inc` *Verifier brief*. **A hunter never verifies or confirms its own candidate.** The verifier tries to refute the candidate and traces the full path from source to sink through middleware, policies, framework protections, validation, sanitization, and configuration.
- The verifier sets the verdict by the lifecycle rules in `@skills/security-audit/references/roles-and-lifecycle.md` *Finding lifecycle*. Only a validated finding reaches `confirmed`. A control that stops the flow makes the finding `rejected`, and a control whose effect stays unclear makes it `needs_validation`. A finding without a demonstrated failure of a security boundary is `needs_validation` with severity `unrated`.
- Re-open the cited `file:line` yourself before you accept a verdict. A finding you cannot confirm in the current bytes does not enter `findings.json` as `confirmed`.
- Set every unit whose candidates all have a verdict from `candidate` to `covered`.
- **Seal every covered unit.** Add every file a finding cites to the `inputs` of every unit that lists the finding. Then run `skills/security-audit/scripts/audit-artifacts.sh digest <path>...` in the audited project with the unit's `paths` and input paths, and record the output as the unit's `inputs_digest`. The digest is the proof a later incremental run relies on. `validate` refuses a digest that does not match the working tree, an input that git ignores, a path that is or passes through a symbolic link, a symbolic link whose target the unit does not record, a path that matches no file, and a finding that cites a file outside the paths and inputs of a unit that lists it.

### 5. Run a fresh coverage critic after every wave (the reserve)
- After the candidates of a wave are verified, open a new coverage critic: a fresh read-only worker with a new ID that reviewed no earlier wave, with the brief in `commands/_shared/orchestration/security-audit-briefs.inc` *Coverage critic brief*. It reports missing or weak coverage: surfaces and attack classes no unit holds, uncovered entrypoints, unanalysed trust boundaries, shallow review, and findings that rest on an assumption.
- Record the wave in `coverage-ledger.json`: its number, its hunters, its critic, its `worktree`, and every gap the critic reported, with the gap's kind and how it was closed.
- A real omission becomes a new `queued` unit, hunted and verified in the next wave within the remaining budget. A finding that rests on an assumption goes to a new verifier, or becomes `needs_validation`. Never hide an omission by widening a unit already marked complete.
- Open the next wave while a unit is queued, hunting budget remains, and the coverage-critic reserve still holds a pass for the wave's critic. The profile decides how many waves follow (`@skills/security-audit/references/profiles-and-budget.md`).

### 6. Write the artefacts and validate
1. Write `findings.json`, finalise `coverage-ledger.json`, and write `metadata.json` with the spent passes and the counts per verdict. No unit stays `queued` or `candidate`.
2. Write `REPORT.md` in the shape of `@skills/security-audit/templates/REPORT.md`. In an incremental run, *Incremental basis* lists the base run, the changed paths, the reused and the invalidated units, and every invalidated finding with the verdict this run gave it.
3. Run `skills/security-audit/scripts/audit-artifacts.sh validate <run-dir>`. A non-zero exit lists the problems. Fix the artefacts and run it again. A run is not finished until it exits `0`.

---


## Done when
- The coverage inventory existed before the first hunter opened, and `skills/security-audit/scripts/audit-artifacts.sh inventory` exited `0` on it.
- Every coverage unit has a final status: `covered`, or `blocked`, `deferred`, or `out_of_scope` with a reason.
- A fresh coverage critic reviewed every wave, and the working-tree snapshot after every wave equals the baseline, within the limit step 3 states.
- `spent.hunting` is not above `hunting_max`, and both reserves were available to the stages they belong to.
- Every finding is `confirmed`, `needs_validation`, or `rejected`, every verdict comes from a verifier that did not hunt the finding, and no `needs_validation` finding carries a definitive severity without a demonstrated boundary failure.
- Every covered unit records its security inputs and an `inputs_digest` that equals the digest of its paths and inputs, every covered `ai_tools` unit records the ten threat classes, and every covered action surface records the five action dimensions.
- In an incremental run, every reused unit and finding is an unchanged copy of its base record, whose security path is unchanged, and every other base unit was inventoried again under its `coverage_id`.
- `metadata.json`, `coverage-ledger.json`, `findings.json`, and `REPORT.md` exist, and `skills/security-audit/scripts/audit-artifacts.sh validate` exits `0`.
- The audited project is unchanged: `git status --short` matches the baseline recorded before the audit, and `skills/security-audit/scripts/audit-artifacts.sh snapshot` prints `worktree_baseline`.

---
