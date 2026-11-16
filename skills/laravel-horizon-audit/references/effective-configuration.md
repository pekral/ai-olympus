# Effective configuration, the consumer map, and static parsing

## Static parsing
- Read each PHP config file as text. Interpret a literal scalar, a literal array, a class constant of the project, and a plain `env('NAME', <literal>)` call.
- A value inside `env(...)` is **unknown**: the audit never reads `.env`, so the literal fallback shows only what applies when the variable is absent. Record the fallback as `fallback: <literal>` and the value as `unknown`.
- A value built from a function call, a conditional, a loop, string concatenation with an unknown part, or a helper is `unknown`. Never evaluate it.
- Never `include`, `require`, or execute a config file, never run `php artisan`, `tinker`, or `config:show`, never run a command that shows a configuration or environment value in any mode (`SKILL.md` *Constraints*), and never open a `.env*` file other than `.env.example`.
- Record every value with its `file:line`.

## Effective supervisor configuration
1. Read `defaults` and `environments` of `config/horizon.php`.
2. Resolve the environment key. An exact key of `environments` wins. Otherwise the first key that matches the audited `environment` as a wildcard pattern applies, and `*` is the fallback environment. Horizon 5.x selects the environment with `Str::is($name, $environment)` and takes the first match.
3. Merge the whole supervisor map. Horizon 5.x runs `array_replace_recursive($defaults, $environmentPlan)` over every supervisor of the environment. The result holds every supervisor of `defaults` and every supervisor of the environment. A supervisor that only `defaults` defines therefore runs in every environment, and for a supervisor in both the environment value wins. A list value such as `queue` is replaced index by index, not appended. A supervisor deploys only when its merged `maxProcesses` is above 0.
4. Confirm the merge against the installed `vendor/laravel/horizon` source (`ProvisioningPlan`). When it shows a different merge, follow the installed source and say so. Without the source or for another major version, the merge result of a defaults-only supervisor and of a list value is `UNVERIFIED`. The audit still lists such a supervisor and never omits it.
5. Fill each option the merged row lacks with the Horizon default of the detected version, marked `built-in default`, only when the installed source states it. Otherwise leave it unknown.
6. Keep the provenance of each value: `defaults`, `environment <name>`, or `built-in default`, with `file:line`.
7. An environment key that the audit cannot match to the audited `environment` (for example one built from an expression) is `UNVERIFIED`.

The audit of a supervisor uses the merged row. Auditing `defaults` and the override as separate fields misses the value that applies.

## Consumer map
One row for each consumer, with these columns, in this order:

`consumer` → `source file:line` → `Laravel connection` → `driver` → `Redis connection` → `database` → `prefix` → `queue name` → `environment`

- A Horizon supervisor gives its connection and its queue list from the merged row.
- A `queue:work` or `queue:listen` command in a tracked file gives its connection, `--queue`, `--timeout`, and `--tries` from the command line.
- The driver comes from the `connections.<name>.driver` of `config/queue.php`. The Redis connection, database, and prefix come from `config/database.php` (`redis`). The queue key prefix is a Redis connection option. The Horizon `prefix` setting names Horizon's own keys and is not part of the queue key.
- Fill a column with `unknown` when its value is dynamic or environment-driven. Never guess it.
- Different Laravel connection names are not different physical queues. Compare the key of HZ-001 instead.

## Consumer sources
Search tracked files only: `supervisor*` configs, `systemd` units, `Dockerfile` and `docker-compose*`, Kubernetes manifests, Forge and deploy scripts, `Procfile`, cron definitions, and CI/CD files. Look for `horizon`, `horizon:terminate`, `queue:work`, `queue:listen`, `stopwaitsecs`, and `terminationGracePeriodSeconds`.
