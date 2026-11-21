# Test assignment

The root session holds the `krang` role. Validate the source, take the working-tree write-lock in both modes, and apply the input map and test matrix in `@skills/test-assignment/SKILL.md`, steps 1–3. Keep the mode and optional test data with the matrix.

### 4. Automated coverage — `donatello`

- `report` mode: dispatch `donatello` to map the matrix to the existing tests and to list every case that no test covers. It writes no code.
- `fix` mode: dispatch `donatello` to add a test for every uncovered case, in the test file that owns the input.
  - A test for an unmet criterion fails against the unfixed code first, then the fix follows (`@skills/test-driven-development/SKILL.md`). The failure evidence goes into the pull-request description. The test and the fix land in one commit.
  - A test that protects behaviour the change already meets is proved by a mutation check in the working tree. The mutation is never committed.

### 5. Prove that nothing else broke — `donatello`

- Dispatch `donatello` on the final head for the whole test suite and the project gate (`@skills/resolve-issue/references/quality-gates.md`). Every suite run, the re-run of step 5 included, goes through `commands/_shared/run-gate.sh`. Nothing else may use the test databases or the local services during the run.
  - `fix` mode runs the full gate.
  - `report` mode runs the checkers and the whole suite without the fixers, and leaves the working tree unchanged.
- Classify every failure once:
  - caused by this pull request;
  - pre-existing — the same failure on the base branch;
  - flaky — proved by a deterministic reproduction or by a repeated run.
- In `fix` mode, a failure caused by this pull request is fixed and the whole suite runs again. In `report` mode it is a finding.
- Read the CI state on the exact head once. When CI fails, reproduce the failure locally the way CI runs it. Never report a CI failure as understood when its log was not read and its reproduction failed.

### 6. Exercise the running application — `raphael`

- When the test suite resets local services that the application shares, run this step after step 5. Before the walkthrough, confirm that the development data still matches the seeds.
- Dispatch `raphael` with the matrix, the input map, and the instruction to run a base-branch comparison. It follows `@skills/interactive-testing/SKILL.md` and the project's own testing guidelines.
- `raphael` serves the branch under test from the main checkout. It exercises every matrix row through the real interface — the API with a real HTTP client, the UI in a browser — and records the exact request and response.
- It sends the same request set to the base branch. The results may differ only where the assignment changes behaviour. Every other difference is a finding.
- It checks the application log for new errors. It prepares test data as `@skills/interactive-testing/SKILL.md` *Missing test data* states, removes or restores that data afterwards through `@skills/interactive-testing/SKILL.md` *Scripted cleanup*, closes the browser tabs it opened as `@skills/interactive-testing/SKILL.md` *Browser tabs* states, and lists what it could not.
- A row it cannot exercise is `Blocked`, with the reason and with what would unblock it.

### 7. Finish by mode

- `report` mode: dispatch `april` in *Test report mode* (`agents/april.md`). It publishes the technical report on the pull request, rendered from `skills/test-assignment/templates/test-report-pr.md`, and the plain-language summary on the source issue through `@skills/pr-summary/SKILL.md`.
- `fix` mode: send every `Not met` row and every failure caused by this pull request to `donatello`. Then continue with `commands/_shared/orchestration/verify-merge-readiness.inc` from its step 2. The review loop, the exact-head gate, and the source-issue TL;DR run there, with the evidence of steps 2 to 6 in the brief. Stop before merge.
- Run *Run cleanup* (`commands/_shared/krang-workflow.inc`) on every terminating path.


Use the report template and completion checks in `@skills/test-assignment/SKILL.md`. Release the lock on every exit. Neither mode merges.
