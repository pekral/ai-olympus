# Bug diagnosis — evidence first, in fixed order

Referenced from `skills/resolve-issue/SKILL.md` *If bug*. This file is the one owner of the diagnosis procedure for a bug or regression assignment. `skills/analyze-problem/SKILL.md` stays read-only: it analyzes, and this procedure runs while you implement.

Run the six steps in this order. A step starts only when the step before it is done.

## 1. Feedback loop first

Build **one command** that reliably fails on the reported symptom. Do not search for the cause until that command exists.

Try the options in this order and stop at the first that works:

1. A Pest test at the strongest boundary. A feature (HTTP) test comes before a unit test.
2. `curl` against the local instance.
3. An Artisan command run against fixture data.
4. A replay of the request captured by Laravel Telescope (`@skills/laravel-telescope/SKILL.md`), against the local instance only. Never send it to the host it was captured on, and replace the captured cookies, `Authorization` header, and CSRF token with a local test user's.
5. For a regression between two known states, `git bisect run vendor/bin/pest --filter=<test>`. Record the first bad commit, then run `git bisect reset` before any further step.

Done when one command demonstrably fails on this symptom. If no option produces such a command, stop as **Blocked** and name the missing piece (data, access, or environment). The analysis does not continue without it.

## 2. Minimisation

Remove input data and steps from the failing command one at a time. After each removal, run the command again. Keep a removal only when the command still fails. Stop when every remaining element is necessary for the failure.

## 3. Hypotheses

Write each hypothesis as a falsifiable statement with a prediction: "if X is the cause, then changing Y makes the command pass". Run the loop to confirm or refute it. Test one hypothesis at a time.

Only the verified cause enters any output (`@rules/general/general.md` *Verified statements only*). Never publish a ranked list of candidate causes.

## 4. Instrumentation

Add temporary logging with a unique tag: `[DEBUG-` plus four hex characters plus `]`, for example `Log::debug('[DEBUG-xxxx] …')` with `xxxx` replaced by the four characters. Change one thing at a time and run the loop after each change.

Performance branch: measure a baseline before any change. Record the query count and time with `DB::listen`, and read the plan with `EXPLAIN` through `@skills/mysql-problem-solver/SKILL.md`. Compare every later run against that baseline.

## 5. Fix and regression test

Follow `@skills/test-driven-development/SKILL.md` and `@skills/test-audit/SKILL.md`. The test that failed before the fix passes after it. The test and the fix land in the same commit (`@rules/git/general.md` *The merged head is green; intermediate commits are not gated*). A feedback-loop command that is not a test stays out of the commit.

## 6. Cleanup

1. Remove every temporary log of step 4.
2. Run the commands below in one shell call, because the agent shell keeps no variables between calls. The first four lines check the lines the change adds, committed or not. The last command checks the untracked files. The excluded directories hold the installed copy of this procedure. **Any output blocks the pull request, including `CHECK FAILED`.**
   ```
   DEBUG_DIFF="${TMPDIR:-/tmp}/debug-tag-$$.diff"
   DEFAULT_BRANCH="$(git symbolic-ref --short refs/remotes/origin/HEAD | sed 's@^origin/@@')"
   git diff --merge-base "origin/$DEFAULT_BRANCH" -U0 -- . ':!.claude' ':!.codex' ':!.agents' > "$DEBUG_DIFF" || echo 'CHECK FAILED: git diff did not run'
   grep -n '^+.*\[DEBUG-[0-9a-fA-F]\{4\}\]' "$DEBUG_DIFF"; rm -f "$DEBUG_DIFF"
   git ls-files --others --exclude-standard -z -- . ':!.claude' ':!.codex' ':!.agents' | xargs -0 grep -ln '\[DEBUG-[0-9a-fA-F]\{4\}\]'
   ```
3. State the verified cause in the commit body and in the PR description.

The *Pre-PR self-check* in `skills/resolve-issue/SKILL.md` runs the same two commands, so a leftover tag blocks the pull request. Write the tag as `[DEBUG-xxxx]` in documentation and tests, so the documentation never matches the check.
