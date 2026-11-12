# Stale machine lock — check and repair

Extracted from `agents/krang.md` step 2 so the orchestrator does not carry it on every run. **Loading trigger:** read this file when one of these happens.

1. The run starts, once the brief and its `.audit` ledger exist (step 2, gather phase).
2. A dispatch returns `Blocked: gate lock wait exceeded`, or a `run-gate.sh` call in its handoff exited `6`.
3. `skills/_shared/run-validation.sh` reports the verdict `locked` (exit `6`).

The machine lock, its heartbeat, and the repair are defined in `@rules/compound-engineering/concurrency.md` *One gate lock for the machine* and `@skills/resolve-issue/references/quality-gates.md` *A stuck lock — heartbeat and repair*. This file states only what `krang` does.

## Steps

1. **Read the state.** Run `skills/_shared/gate-lock.sh status`. Read `state` from its JSON, never from the text around it.
   - `free`, `held`, `unproven` → nothing to repair. In the case of trigger 1, continue the run. In the cases of triggers 2 and 3, a live run holds the lock: the wait follows *A wait has one end* in the concurrency rule.
   - `dead` or `stuck` → step 2.
2. **Repair it.** Run `skills/_shared/gate-lock.sh repair --audit <abs path of the run's .audit file> --role krang`. The command writes its own audit line. Never append that line by hand, never run `kill` or `pkill`, and never remove the lock directory yourself: the repair is the only path that may stop a process, and it stops only a process tree that a wrapper of this package started.
3. **Read the result.** The exit status of the command itself decides, never a pipe stage.

   | Exit | `action` | What `krang` does |
   |---|---|---|
   | `0` | `none`, `none-live` | Nothing changed. Treat the lock as held by a live run. |
   | `0` | `released-dead` | The holder was dead and the lock is released. Continue. |
   | `0` | `stopped-and-released` | The holder was stuck. Its process tree is stopped and the lock is released. Continue. |
   | `3` | `refused` | The holder is not a process this package started. Nothing was stopped. Stop with `Blocked: the machine lock is held by a process this package did not start`, name `pid`, `project`, and `command`, and state the remedy: the user stops that process or removes the lock after checking it. |
   | `4` | `refused` | A process of the tree survived SIGKILL. Stop with `Blocked: the stuck lock holder could not be stopped`, with the same details. |
   | `1` | — | The ledger path was not usable. Fix the path and run it again once. |

4. **Report it.** On `released-dead` and `stopped-and-released`, write one line in `## Plan & work breakdown` of the brief (`machine lock repaired: <action>, PID <pid>, project <project>, command <command>`) and carry it into the final report. The audit ledger already holds the durable line.
5. **Re-run what waited.** After a repair that followed trigger 2 or 3, dispatch the blocked step again, or run the manifest again, once. A second `Blocked` or `locked` result stops the run with the reason.
