---
name: interactive-testing
description: "Use when verifying user-visible project changes against an assignment and the actual code diff. Requires the testing agent's own interactive browser, checks security risks, verifies suspected vulnerabilities, and reports confirmed threats, which it repairs and re-tests only when the caller explicitly asks for repairs."
license: MIT
metadata:
  author: "Petr Král (pekral.cz)"
---

## Constraints

- Apply `@rules/security/general.md` for the untrusted-content boundary.
- The testing agent MUST use its own interactive browser session. Every interactive-testing run drives the web browser available to the session. In Claude Code, use Claude in Chrome (the `mcp__claude-in-chrome__*` tools). When Claude in Chrome is not connected, use the desktop app's built-in browser (the `mcp__Claude_Browser__*` tools). In Codex, use the provided `cua_repl` browser tools and their documented browser APIs. A delegated agent must operate the browser itself; another agent's screenshots or test summary do not fulfil this requirement.
- Shell-driven Playwright, headless scripts, HTTP requests, unit tests, source inspection, and generated screenshots never replace this walkthrough. They may supply supplementary evidence only. If no interactive browser tool is available, return `Blocked` and name the remediation, for example *connect the Claude in Chrome extension*.
- Use the project URL supplied by the user or recorded in the consuming project's instructions or memory. If neither identifies the target, ask for it. Do not silently switch hosts, environments, or checkouts.
- Sign in as *Sign-in and test accounts* below states. A missing login never ends the UI check, and it never makes you wait for the user.
- Test the running application without changing its implementation, configuration, permissions, credentials, or database directly. The exceptions are the local preparation that *Local readiness* allows, a local test account that *Sign-in and test accounts* allows, a local data change that *Missing test data* allows, and confirmed security fixes through *Check security and repair confirmed threats*. Other fixes, publishing to a tracker, deploying, or merging require a separate assignment.
- Respect the browser tool's confirmation rules. Use reversible, clearly identified test data within the authorized workspace. Do not trigger AI processing, recurring jobs, external imports, emails, tracker comments, or destructive actions unless that specific effect is authorized for the scenario. Opening a menu is not evidence that its actions work.

## Use when

- A developer asks for a browser acceptance check after a feature, fix, revert, or UI change.
- A new QA agent receives an assignment plus a branch, commit range, PR, or uncommitted diff.
- Automated checks passed but the change still needs verification through real user interactions.
- A change alters what a user can see or do in the application's UI. Tests and HTTP requests never replace the UI check for such a change.

## Inputs

- Assignment and latest user corrections, including behavior that must remain unchanged.
- Repository and the exact diff to test: base/head revisions plus any intended working-tree changes.
- Project URL, account/role, workspace, available test data, and authorized side effects.

Infer routine choices from the current task. Ask only for missing information that blocks a concrete scenario, while continuing independent scenarios.

## Local readiness

This section owns how a testing agent prepares the local environment before the walkthrough. Other skills and agents reference it and do not restate it.

Check every item before the first scenario. When an item is not ready, prepare it yourself with the project's own documented command, then check it again. A preparation step never makes you wait for the user.

1. **The checkout is the revision under test.** The working tree is on the head of the requested change. When it is on another revision and the tree is clean, switch to the head (`gh pr checkout <PR>` or `git switch <branch>`) and pull it with `git pull --ff-only`. Record the branch the run started on.
2. **The dependencies match the lock files.** When `vendor/` or `node_modules/` does not match `composer.lock` or the JavaScript lock file, run the install through `skills/_shared/run-gate.sh --exclusive --command "composer install"`, or through the same wrapper with the project's install command (for example `--command "npm ci"`), so it never overlaps a gate or an install of another project. Install only from the committed lock files: never run `composer update`, `composer require`, or `npm install`. The package-registry download is an outbound request: append one `outbound-request` audit line for each install, per `@rules/compound-engineering/orchestration.md` *Audit trail for memory reads, outbound requests, and external writes*.
3. **The environment file exists.** When `.env` is missing, create it through the project's documented setup step, for example a copy of `.env.example` and `php artisan key:generate`. Never edit an existing `.env`.
4. **The local database is migrated.** When `php artisan migrate:status` (or the framework's equivalent) shows a pending migration, run the migrations. Never run `migrate:fresh`, a rollback, or a wipe.
5. **The application caches are current.** Clear a stale configuration, route, or view cache, for example with `php artisan optimize:clear`.
6. **The front-end assets match the head commit.** Build them with the project's build command, or start its dev server. A stale dev-server hot file must not send the assets to a server the browser cannot reach.
7. **The application answers on the project URL.** When it does not, start it with the project's documented serve or container command. Start the processes a scenario needs, such as queue workers or a websocket server. Restart a process that still runs older code.

Preparing the environment is allowed only inside these limits:

- **Local target only.** The same rule as *Sign-in and test accounts*: never prepare, migrate, or restart anything on a production, staging, or other shared remote environment.
- **The project's own commands.** Use the commands the project documents in its instructions, README, `composer.json` scripts, `package.json` scripts, or container files. Never invent a bootstrap the project does not have.
- **Tracked files stay untouched.** Never edit, commit, or revert a tracked file. Never stash, reset, or discard uncommitted changes. When the working tree carries changes that do not belong to the requested change, do not switch the checkout: the walkthrough is `Blocked`, and the report names the files.
- **Traceable.** Report every preparation step under **Scope**: the item, the command, and its result. At the end, stop the processes this run started, switch back to the branch the run started on, and delete a local branch this run created, unless the user needs them to reproduce a failure; then list them under **Cleanup**. Leave a process this run restarted running, and list it under **Cleanup**.

When an item cannot be made ready inside these limits, every scenario that depends on it is `Blocked`. State the failing command, its error, and the exact step that unblocks it. Continue the scenarios that do not depend on it.

## Sign-in and test accounts

This section owns how a testing agent signs in to the application under test. Other skills and agents reference it and do not restate it.

Try these sources in order. Stop at the first one that signs in:

1. **A valid session.** Reuse a session the browser already holds for the target.
2. **A documented test account.** Use the test accounts the project documents: its `CLAUDE.md` or `AGENTS.md`, its README, a test-account helper class (for example `TestUserAccounts`), or the seed migration or seeder that creates them. Sign in through the normal sign-in form.
3. **A test account you create.** When no documented account signs in, create one through the project's own mechanism. Prefer, in this order: the project's documented seed command or seed migration, a console command the project ships for creating users, or the application's user factory or user model through `tinker` (or the framework's equivalent console), with the role assigned through the application's own role API. Then sign in through the normal sign-in form.

Creating a test account is allowed only inside these limits:

- **Local target only.** The target is `localhost`, `127.0.0.1`, a `*.test` host, or a URL the project documents as its local development or test instance. Never create an account on a production, staging, or other shared remote environment, even when its URL is documented.
- **No authentication bypass.** Sign in only through the application's sign-in form. Never forge a session cookie, write a session row, or disable authentication, verification, or two-factor middleware in code or configuration.
- **Reserved test identity.** Use the e-mail pattern the project documents. Without one, use an address in a reserved test domain (RFC 2606), for example `qa-<role>@<project>.test`.
- **Test password.** Use the project-documented test password when one exists. Otherwise, generate a random password for this run. Never print a real user's credentials.
- **Real accounts stay untouched.** Never reuse, re-password, re-role, or delete an account that is not a test account.
- **Least role.** Give the account only the role the scenario needs.
- **Two-factor authentication.** When the account must pass a second factor, use the test path the project documents. Without one, the scenario is `Blocked`.
- **Traceable.** Report every account you created under **Cleanup**: the e-mail, the role, and the mechanism. Report the password only when it is the project-documented test password.

When no source signs in, the scenario is `Blocked`. State the reason and the exact step that unblocks it — for example a non-local target, mandatory two-factor authentication without a test path, or a sandbox that refused the command. Close the tabs this run opened, as *Browser tabs* states, and name the URL in the report so the user can sign in there. Never ask for passwords or one-time codes in chat. Never treat elapsed time as a successful sign-in. Never skip the UI check silently.

## Missing test data

This section owns how a testing agent gets the data a scenario needs. Other skills and agents reference it and do not restate it.

A missing record, relation, or record state never ends the walkthrough, and it never makes you wait for the user. Create the data yourself, or temporarily change it, and finish the scenario.

Try these sources in order. Stop at the first one that produces the data:

1. **Existing test data.** Use a record that already meets the scenario's prerequisites and that the project documents as test data or that this run created.
2. **The application's UI.** Create the record through the same screens a user would use, when those screens exist and work.
3. **The project's seed mechanism.** Run the project's documented seed command, seeder, or a console command the project ships for creating the data.
4. **The application's own code.** Use the application's factories, actions, or services through `tinker` (or the framework's equivalent console), so the record carries the related records and derived values the application itself would produce.
5. **A temporary change of an existing record.** Use this source only when sources 1 to 4 cannot produce the state, for example the creation date of an account that must be younger than a limit. Prefer a record the project documents as test data, or the test account in use, before any other record. Change the record through the application's own code, as source 4 states.

Creating, changing, or restoring test data is allowed only inside these limits:

- **Local target only.** The same rule as *Sign-in and test accounts*: never create or change data on a production, staging, or other shared remote environment.
- **The database is shown to be a development or test database.** Before an existing record changes, show this as `@rules/security/general.md` *Production is never touched* requires: take the evidence from the dispatch, the project's documented development setup, or a database this run started. A local URL alone is not that evidence. Never read a `.env*` file or an environment value to get it (`@rules/security/general.md` *Environment values are never read*). When the database cannot be shown, no existing record changes, and the scenario that needs the change is `Blocked`.
- **Only through the application's code.** Only the databases of the project under test change, and only through that project's own code. Use no database client and no raw SQL statement that writes.
- **A state the application can produce.** Reach a record state through a factory state or an application action. Never write a raw SQL row, and never set a status, a flag, or a computed value directly to force a pass. A change prepares a precondition that the criterion reads. It never sets the result that the criterion checks.
- **Access stays untouched.** Never change the credentials, role, permissions, membership, owner, tenant, verification, two-factor, session, or token state of an existing record. Accounts stay as *Sign-in and test accounts* states. Never delete or re-assign an existing record.
- **Restore every change.** Before the change, record the original value of each changed attribute. Restore it when the scenario that needed it ends, and at the latest at the end of the run. Restore only an attribute that still holds the value this run set; otherwise leave it and list it as remaining. Read the restored value back through the application's code, and in the UI when the value is visible there.
- **A change prepares the state only.** The scenario still runs as *Constraints* states, in the application and repository under test. A data change never replaces the walkthrough, and it never moves the test to another application, another repository, or an external service.
- **Identifiable.** Mark every created record so it is recognisable as test data, for example a `QA` prefix in its name and a reserved test domain (RFC 2606) in any e-mail address.
- **No unauthorized side effects.** The *Constraints* above still apply: creating, changing, or restoring data never sends e-mails, calls external services, or starts imports or jobs that the scenario does not authorize. Use a factory or a console flag that suppresses such effects when the project provides one.
- **Traceable.** Report every record you created or changed under **Cleanup**: its type, its identifier, and the mechanism. For a changed record, also report the attribute names and the restore result. Never report the value of a personal-data or secret attribute. Keep the record that *Scripted cleanup* requires as you work. Delete the created records at the end of the run through *Scripted cleanup*, unless the user needs them to reproduce a failure; then list them as remaining.

When no source can produce the data inside these limits, the scenario is `Blocked`. State the reason and the exact step that unblocks it. Continue the other scenarios.

## Browser tabs

This section owns which browser tabs a testing agent opens and closes. Other skills and agents reference it and do not restate it.

- **Open a tab of your own.** Open a new tab for the run through the browser tool (`tabs_create_mcp` in Claude in Chrome) and record its identifier. Never navigate, reload, or reuse a tab this run did not open.
- **Close every tab you opened when testing ends.** Close the tab after the last screenshot, console read, and UI read-back, and before the report is written. This applies on every terminating path: `Passed`, `Failed`, `Partial`, `Blocked`, and an aborted run. A failure or a blocked sign-in never keeps the tab open. The report carries the evidence and the URL instead.
- **Close tabs through the browser tool only.** In Claude Code, use `tabs_close_mcp` of Claude in Chrome, or the tab-close tool of the desktop app's built-in browser. In Codex, use the documented tab-close API of the `cua_repl` browser tools. Never close the window, never end the browser process, and never use a shell command for this.
- **Never close a tab you did not open.** Tabs that were open before the run, including the tab of the user, stay open. Before you close a tab, list the open tabs (`tabs_context_mcp`) and close only an identifier in your record. A tab you cannot identify as yours stays open.
- **A tab that will not close is reported.** List it under **Cleanup** with the reason. Do not retry through another mechanism.

## Scripted cleanup

This section owns how a testing agent removes the test data of a run. Other skills and agents reference it and do not restate it. Cleanup runs through a script wherever a script can do it, so it is as fast as possible. Every limit of *Sign-in and test accounts* and *Missing test data* applies to a script as it applies to a manual step.

**Keep a record as you work.** For each test account or record this run creates, write down its type, its identifier, and the mechanism. For each record this run changes, write down the attribute names and the original value of each attribute. Cleanup acts on this record only. A record or an attribute that is not in it is never touched.

Try these mechanisms in order. Stop at the first one that works:

1. **The project's own cleanup mechanism.** Use a seeder, a console command, or a factory teardown that the project documents for test data. Use it only when it removes or restores no more than the record lists. A mechanism that wipes, resets, or truncates the database is never used.
2. **One scratch script.** Generate one script and one JSON file from the record. The script deletes every record the run created and restores every attribute the run changed, in one pass, through the application's own code, run in its console (for example `tinker`).
3. **The browser.** Delete and restore through the application's UI. Use this when no script path exists or when the script failed, for example when the console is unavailable or the database cannot be shown to be a development or test database. The report states that the browser fallback ran and why.

A scratch script stays inside these limits:

- **Same limits as the data change.** The script obeys these limits of *Missing test data*: *Local target only*, *The database is shown to be a development or test database*, *Only through the application's code*, *Access stays untouched*, *Restore every change*, and *No unauthorized side effects*.
- **Values are data, never code.** The script reads the record from the JSON file with `json_decode(file_get_contents(...), true)`. It never writes a value from the record into its own source. Create both files owner-only (for example under `umask 077`) and delete both afterwards, also when the script failed.
- **The record decides the scope.** The script acts on the identifiers in the record and nothing else. Never widen the script after it is generated. A script that fails leaves the rest to the browser fallback.
- **Outside tracked files.** Write the script and the JSON file as new files with unique names in the session's scratch directory or the operating system's temporary directory, never over an existing file and never inside the repository. Run the script once.
- **Read back.** Read the result of the run. Then read the deleted records back through the application's code, and in the UI where they are visible.
- **Traceable.** Report each deleted record and each restored attribute under **Cleanup**, with the mechanism that ran (project mechanism, scratch script, or browser) and the read-back result.

## Execution

### 1. Derive scenarios from the assignment and diff

1. Read repository instructions and relevant project memory. Inspect `git status`, current revision, and the requested diff. Do not modify files; switch the checkout only as *Local readiness* allows.
2. For a merged change, compare its recorded base and head; an empty working-tree diff does not mean there is nothing to test. Include all commits in the requested change, not just the last styling commit.
3. Turn each requirement and meaningful changed behavior into a row: preconditions, user actions, expected visible result, persistence check, and relevant viewport/role.
4. Include the main successful flow, validation/empty states, navigation, save/reload behavior, and immediate regressions implied by the diff. Distinguish layout changes from processing or integration behavior outside the assignment.
5. Record the revision and browser target. If the served checkout/version cannot be established, state that limitation instead of assuming it matches the diff.

### 2. Prepare the local environment

Check and prepare the local environment as *Local readiness* states, before the first scenario.

### 3. Open the interactive browser and establish prerequisites

1. Read the browser tool documentation and open the project using its supported interactive browser API. Sign in as *Sign-in and test accounts* states.
2. Observe the rendered page before interacting. Verify the signed-in identity, selected workspace, record state, and relevant permissions from visible UI.
3. Check data readiness from the record's visible state, never from its name or from admin access. A record named "draft" can carry another status, and an action can depend on a prerequisite the page does not show.
4. When the scenario's workspace, record, or record state is missing, create it as *Missing test data* states. Ask for the correct workspace or account only when that section leaves the scenario `Blocked`. Never invent membership on an existing workspace. Change an existing record only as that section states.
5. Record a time boundary for browser console evidence so old errors are not attributed to the current scenario.

### 4. Exercise the actual user flow

1. Navigate through visible links and menus. Use accessible roles/names or observed test IDs; never guess selectors, use hidden application state, or call backend actions to simulate clicks.
2. After each meaningful action, inspect fresh browser state before choosing the next action. Wait for the specific result, enabled control, selected tab, or loaded form. A deferred panel's heading alone does not prove its form finished loading.
3. For saves, verify the visible success state, then reload or navigate away and back to prove persistence. A changed textbox value alone is insufficient.
4. Exercise keyboard interactions where relevant: focus, tab navigation, arrow keys, Enter, Escape, and modal dismissal. Verify the resulting focus or selection.
5. Test layouts on desktop and at a representative narrow viewport when the diff changes layout. Use the interactive browser's viewport capability. Report width/height and do not claim real-device or touch emulation when only viewport size changed.
6. Capture and actually inspect rendered screenshots for relevant states, especially failures. Check readability, wrapping, clipping, scrolling, control reachability, and correspondence between icons and actions. Restore temporary viewport overrides afterward.
7. Read recent console errors through the browser tool. Reproduce suspicious behavior once with a stable, fully loaded page. Separate reproducible failures, transient observations, environment blockers, and unproven attribution to the diff.
8. For side-effecting flows without authorization, or whose prerequisites *Missing test data* cannot produce, stop before the effect and mark that portion `Blocked`; do not mark the entire action passed because its button rendered.

### 5. Check security and repair confirmed threats

The goal is to protect the application against compromise. Run this check on every walkthrough, even when the assignment names no security criterion.

1. Apply `@rules/security/backend.md` and `@rules/security/frontend.md` where the exercised surface uses them. Derive security scenarios from the flows, inputs, endpoints, and trust boundaries reached during the walkthrough. Include authentication, authorization and tenant isolation, input injection and output escaping, CSRF on state-changing requests, uploads and downloads, redirects and outbound URLs, and exposed secrets or sensitive data where applicable. Record why a check is not applicable or cannot run. This focused check is not a whole-repository security audit.
2. Probe only the authorized local development or test instance, using this run's test accounts and records. Use minimal, reversible proofs; never extract real user data, execute destructive payloads, test denial of service, or probe third-party hosts. On a shared or remote target, record the active check as `Blocked` and name the local reproduction needed. Source inspection and targeted HTTP requests may supplement the browser walkthrough; they never replace its UI evidence.
3. Treat each suspicious result as a candidate. Trace the attacker-controlled input to the security-sensitive action or data. Check the effective middleware, policies, validation, escaping, framework protections, and configuration. Reproduce the boundary failure with a minimal proof and record the expected denial versus observed access or effect. A scanner warning or suspicious code alone is not a confirmed vulnerability. Mark unverified candidates `needs validation`, with the missing verification step; reject candidates whose protections prevent exploitation.
   When the tester's role forbids security classification, as `raphael`'s does, return the observed behavior and proof to the caller for `leonardo` to verify. The caller obtains that verdict before requesting a repair. The tester reports the attributed verdict and never makes its own security classification.
4. Repair nothing on your own initiative. Report a confirmed, exploitable threat as `Failed` with its evidence, including one that predates the diff. A repair runs only when the caller explicitly asked for repairs, for example `@skills/test-assignment/SKILL.md` in `fix` mode or `@skills/auto-interactive-testing/SKILL.md`. Then a write-capable caller performs the smallest repair with a regression test through `@skills/resolve-issue/references/pre-existing-issue-handling.md`. A read-only testing agent such as `raphael` never edits code and never requests a repair. In a code review round the threat is a CR finding instead (`agents/krang.md` *Parallel CR round*).
   A review-only caller (`/report-code-review`) fixes nothing: it requests no repair, and the confirmed threat stays `Failed`. The review reports a threat on a changed line as a finding and keeps a threat that predates the diff out of the public comment. Keep vulnerability details out of public trackers as that reference requires.
5. After a repair, record the new revision, refresh the local application through *Local readiness*, and repeat the original proof. Verify that the attack is denied and that the legitimate user flow still works through the interactive browser. Record the regression-test result as supplementary evidence. If repair or verification cannot finish, leave the finding `Failed` or `Blocked` with the exact next step. Never report a confirmed vulnerability as fixed before this re-test.

### 6. Restore test data and report evidence

1. Restore only values changed by this run. Restore every changed record and delete the test data this run created, as *Missing test data* states and through *Scripted cleanup*, with the read-back it requires. Stop the processes and restore the checkout, per *Local readiness*. Do not overwrite unrelated concurrent edits or delete existing user data. Report any remaining test records or side effects.
2. Produce a verdict for every scenario: `Passed`, `Failed`, `Partial`, or `Blocked`. An unexecuted scenario is never `Passed`.
3. For each failure, include exact URL, viewport, account role/record state, minimal steps, expected versus observed behavior, and a screenshot or concrete visible evidence. Redact secrets and avoid embedding unrelated user data.
4. Report which scenarios were not exercised and the exact prerequisite needed to unblock each one.
5. Overall `Passed` requires every required scenario, including applicable security checks and repair re-tests, to pass. An unresolved confirmed threat, a candidate that still needs validation, or a required blocked security check prevents `Passed`. Failures or blockers must remain explicit even when all automated tests are green. A passing focused check never establishes that the entire application is vulnerability-free.
6. Close every browser tab this run opened, as *Browser tabs* states, on every terminating path. Never close a tab this run did not open.

## Output

Return a concise report in the user's language:

- **Scope:** assignment, tested base/head and working-tree changes, project URL, account role/workspace, browser tool, viewport sizes, and every preparation step *Local readiness* ran.
- **Result:** one row per scenario with expected behavior, actual interactions and observation, evidence, and verdict.
- **Findings:** reproducible failures, transient observations, and environment blockers kept distinct; never claim a regression without evidence linking it to the change.
- **Security:** checks exercised or skipped with reasons; candidates marked confirmed, rejected, or `needs validation`; redacted proof, affected boundary, impact, repair revision or handoff, regression-test result, and attack/legitimate-flow re-test verdict. Never publish secrets or exploitable details to a public tracker.
- **Untested:** exact scenarios and missing prerequisites.
- **Cleanup:** restored values, the cleanup mechanism that ran (project mechanism, scratch script, or browser fallback with its reason), the browser tabs this run closed and any tab that stayed open, the restored checkout, processes this run started or restarted and left running, any remaining test artifacts, every test account this run created, and every test record this run created or left in place.
- **Next step:** implementation fixes, access/data preparation, or acceptance when all required rows passed.

Report in the current task by default. Do not post messages to external trackers or create a new agent/task unless asked.

## Done when

- Every *Local readiness* item was checked, and prepared when it was not ready, before the first scenario.
- The agent itself operated the interactive browser and observed the results.
- Every acceptance criterion has an honest verdict backed by executed interactions or a named blocker.
- Save/reload checks, relevant visual states, and recent browser errors were considered.
- Applicable security boundaries were checked. Each candidate was verified or left explicitly unresolved. Every confirmed threat was repaired and re-tested, or remains `Failed` or `Blocked` with its repair handoff.
- Changed test data and temporary browser settings are restored, or remaining effects are explicitly listed.
- Test data cleanup ran as *Scripted cleanup* states, and its result was read back.
- Every browser tab this run opened is closed, and no other tab was closed.
- The report makes no claim of full acceptance while a required scenario is failed, partial, or blocked.
