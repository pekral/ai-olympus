# Fixture scenarios T1 to T16

Each scenario is a small Laravel fixture (a `composer.lock` with the framework and Horizon versions, `config/horizon.php`, `config/queue.php`, `config/database.php`, and the named extra files). The expected result is what a correct audit reports. Run the scenarios against every Laravel and Horizon major version the audit claims to support.

| ID | Fixture | Expected |
|----|---------|----------|
| T1 | No `laravel/horizon` in `composer.json` and `composer.lock` | `NOT_APPLICABLE`; no file changes |
| T2 | job timeout 120, supervisor timeout 60, `retry_after` 90 | HZ-002 `FAIL` |
| T3 | job timeout 60, supervisor timeout 90, `retry_after` 120 | HZ-002 order check `PASS`; the margin is judged separately |
| T4 | Two Laravel connections with the same Redis database, prefix, and queue, and different `retry_after` | HZ-001 collision, HZ-002 `FAIL`, severity `HIGH` or `CRITICAL` by the evidenced impact |
| T5 | Same queue names in different Redis databases | No collision |
| T6 | Redis database and prefix set only by environment variables | HZ-001 `UNVERIFIED`; no confirmed sharing |
| T7 | `defaults` supervisor plus a `production` override | The audit uses the merged supervisor, not single fields |
| T8 | `balance` is `auto` and the queue order is expected to set priority | HZ-004 `WARN` |
| T9 | A `waits` key for a `connection:queue` that no consumer uses | HZ-006 `WARN` |
| T10 | Deployment with no process-manager file | HZ-005 `UNVERIFIED`, not `FAIL` |
| T11 | A job with `WithoutOverlapping` and a low `$tries` | HZ-003 `WARN` with the explanation |
| T12 | A static audit run | No Redis connection and no `php artisan` call |
| T13 | A secret in `.env` | The value is absent from the report and from the skill's logs |
| T14 | The same fixture audited twice | Identical findings and identical JSON key order |
| T15 | A PHP config with dynamic expressions | `UNVERIFIED`; the config is never evaluated |
| T16 | A dashboard authorization that the code cannot show | HZ-007 `UNVERIFIED`; no security diagnosis |

## Integration checks
- Provide a minimal fixture for each supported Laravel and Horizon version with several supervisor configurations.
- Check the real precedence of `defaults` and `environments` for each version against the installed Horizon source.
- Check that each sketch of `regression-tests.md` fails on the broken fixture and passes on the fixed one.
- Run any Redis access only against an isolated test instance, never against a shared or production instance.
- When the audit changes a visual output, check it in a browser where one is relevant.
