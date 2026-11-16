# Effective configuration, the consumer map, and static parsing

## Static parsing
- Read each PHP config file as text. Interpret a literal scalar, a literal array, a class constant of the project, and a plain `env('NAME', <literal>)` call.
- A value inside `env(...)` is **unknown**: the audit never reads `.env`, so the literal fallback shows only what applies when the variable is absent. Record the fallback as `fallback: <literal>` and the value as `unknown`.
- A value built from a function call, a conditional, a loop, string concatenation with an unknown part, or a helper is `unknown`. Never evaluate it.
- Never `include`, `require`, or execute a config file, never run `php artisan`, `tinker`, or `config:show`, and never open a `.env*` file other than `.env.example`.
- Record every value with its `file:line`.

## Effective supervisor configuration
1. Read `defaults` and `environments` of `config/horizon.php`.
2. For each audited environment, take each supervisor of the environment and merge it over the `defaults` entry of the same name. The environment value wins. When the installed `vendor/laravel/horizon` shows a different merge, follow the installed source and say so. Without the source, treat a list value (the `queue` list) as `UNVERIFIED` for the merge result.
3. Fill each option the merged row lacks with the Horizon default of the detected version, marked `built-in default`, only when the installed source states it. Otherwise leave it unknown.
4. Keep the provenance of each value: `defaults`, `environment <name>`, or `built-in default`, with `file:line`.
5. An environment key that is a wildcard or that the audit cannot match to the audited `environment` is `UNVERIFIED`.

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
