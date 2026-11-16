# Fixture scenarios T1 to T16

Each scenario is a small Laravel fixture (a `composer.lock` with the framework and Horizon versions, `config/horizon.php`, `config/queue.php`, `config/database.php`, and the named extra files). The expected result is what a correct audit reports. Run the scenarios against every Laravel and Horizon major version the audit claims to support.

The fixtures live in `tests/Fixtures/laravel-horizon-audit/T01` to `T16`, with `E01` and `E02` for cases the issue does not list (a `*` block listed before the exact environment key, and a supervisor that only `defaults` defines). A file with the suffix `.fixture` stands for the file without it, and each directory holds an `expected.json`.

The Pest test `tests/Installer/LaravelHorizonAuditSkillTest.php` runs `scripts/evaluate.php` over every fixture and compares the `outcomes` of the run with the `outcomes` of `expected.json`. The evaluator computes the deterministic part of each scenario. What stays the agent's judgement is named in the `judgement` field of the scenario and in the table below.

| ID | Fixture | Expected |
|----|---------|----------|
| T1 | No `laravel/horizon` in `composer.json` and `composer.lock` | `NOT_APPLICABLE`; no file changes |
| T2 | job timeout 120, supervisor timeout 60, `retry_after` 90 | HZ-002 `FAIL` |
| T3 | job timeout 60, supervisor timeout 90, `retry_after` 120 | HZ-002 order check `PASS`; the margin is judged separately |
| T4 | Two Laravel connections with the same Redis database, prefix, and queue, and different `retry_after` | HZ-001 `WARN` (shared queue, fully known key), HZ-002 `FAIL`, severity `HIGH` or `CRITICAL` by the evidenced impact |
| T5 | Same queue names in different Redis databases | No collision |
| T6 | Redis database and prefix set only by environment variables | HZ-001 and HZ-002 `UNVERIFIED`; no confirmed sharing |
| T7 | `defaults` supervisor plus a `production` override | HZ-002 `FAIL` on the merged supervisor: the override raises the timeout above `retry_after`, which `defaults` alone hides |
| T8 | `balance` is `auto` and the queue order is expected to set priority | HZ-004 `WARN` |
| T9 | A `waits` key for a `connection:queue` that no consumer uses | HZ-006 `WARN` |
| T10 | Deployment with no process-manager file | HZ-005 `UNVERIFIED`, not `FAIL` |
| T11 | A job with `WithoutOverlapping` and a low `$tries` | HZ-003 `WARN` with the explanation |
| T12 | A static audit run | No Redis connection and no `php artisan` call |
| T13 | A secret in `.env` | The value is absent from the report and from the skill's logs |
| T14 | The same fixture audited twice | Identical findings and identical JSON key order |
| T15 | A PHP config with dynamic expressions | HZ-002 `UNVERIFIED`; the config is never evaluated |
| T16 | A dashboard authorization that the code cannot show | HZ-007 `UNVERIFIED`; no security diagnosis |
| E1 | A `*` block listed before the exact `production` key | Horizon deploys the `*` block in production, so the audit evaluates it and reports `wildcard_shadows_exact` |
| E2 | A supervisor that only `defaults` defines, and one with `maxProcesses` 0 | The first is a consumer in production, the second is not |

## Integration checks
- Provide a minimal fixture for each supported Laravel and Horizon version with several supervisor configurations.
- Check the real precedence of `defaults` and `environments` for each version against the installed Horizon source.
- Check that each sketch of `regression-tests.md` fails on the broken fixture and passes on the fixed one.
- Run any Redis access only against an isolated test instance, never against a shared or production instance.
- When the audit changes a visual output, check it in a browser where one is relevant.
