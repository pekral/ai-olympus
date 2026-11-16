# Rules HZ-001 to HZ-007

Each rule lists its inputs, its evaluation, and the outcomes. A rule that lacks an input returns `UNVERIFIED` with the missing input named. Every outcome carries `file:line` evidence.

## Version rule
Interpret each setting by the detected `laravel/framework` and `laravel/horizon` versions. When `vendor/laravel/horizon` exists, read the installed source to confirm the merge order, the option names, and the defaults. The modelled merge and environment selection (`effective-configuration.md` *Effective supervisor configuration*) apply to a detected 5.x version, and without the source the report says that the source was not read. When the version is unknown or another major, or the version is outside what the source shows, return `UNVERIFIED` for every behaviour that depends on it. Never apply the rules of one version to another without stating it.

## HZ-001 — Real queue consumers

**Inputs.** The consumer map (`effective-configuration.md` *Consumer map*): every Horizon supervisor, every `queue:work` and `queue:listen` command in a tracked process or deployment file, and the connection, driver, Redis connection, database, prefix, queue name, and environment of each.

**Evaluate.**
1. Group consumers by the physical queue key: driver, Redis connection (host and port when literal), database, prefix, and queue name.
2. Two consumers share a physical queue when every part of that key is equal. Two Laravel connection names never decide this by themselves: different names can share one queue, and equal queue names in different Redis databases or prefixes do not collide.
3. A part of the key that comes from an environment variable or an expression is unknown. Report a **potential collision** for that group, never a confirmed one. The `verification` step is a comparison of the effective configuration of the instances.
4. Keep deployments apart. Consumers of different environments or different deployments that share no infrastructure are not grouped.

**Outcomes.** `FAIL` is never produced from an unknown part. A group with all parts known and equal gives `WARN` here and feeds HZ-002 for the `retry_after` comparison. A group with an unknown part gives `UNVERIFIED`. A project with one consumer per physical queue gives `PASS`.

## HZ-002 — Timeouts and `retry_after` (critical check)

**Inputs.** Per supervisor and per Redis connection: the job timeout (`$timeout` on the job class, the `--timeout` of the worker), the Horizon supervisor `timeout`, and the connection `retry_after` from `config/queue.php`. For a standalone worker, its `--timeout` and the `retry_after` of its connection.

**Evaluate.**
1. Where a job timeout is defined, require `job_timeout < supervisor timeout < connection retry_after`, each applied where that limit takes effect. A job timeout applies to the job class that declares it. A supervisor `timeout` applies to every job on that supervisor.
2. Require a positive margin between the limits. The margin is not a universal number: state the margin the files show and ask the owner to justify it against the longest job of the workload. Never print 600, 1800, or 3600 seconds as a recommended value.
3. When several connections consume one physical queue (HZ-001) and every part of the key is known, compare their effective `retry_after` values. A difference is a `FAIL`: the same queue then has two visibility windows.
4. Report a `retry_after` far above the longest job: a worker that dies leaves its job invisible until `retry_after` passes, so recovery takes that long.
5. Apply the semantics of the driver. `redis` and `database` use `retry_after`. `sqs` uses the visibility timeout of the queue, set in SQS. Do not apply the Redis rule to another driver without stating it.

**Outcomes.** `FAIL` (severity `HIGH`, or `CRITICAL` when the evidence shows a side effect that a duplicate run repeats) when a verified order is broken (`job >= supervisor`, or `supervisor >= retry_after`) or when connections that share a physical queue with a fully known key differ in `retry_after`. `WARN` when the order holds with a margin the owner has not justified, or when `retry_after` is far above the longest job. `PASS` when the order holds and the margin is stated. `UNVERIFIED` when a limit comes from an unknown value.

## HZ-003 — Retry, backoff, and idempotence

**Inputs.** Job classes with `$tries`, `$backoff` or `backoff()`, `retryUntil()`, `$maxExceptions`, `$failOnTimeout`, `$timeout`, their `middleware()`, and the supervisor `tries`.

**Evaluate.**
1. Record each job-level setting and whether it overrides the supervisor default.
2. A job that uses `WithoutOverlapping`, `RateLimited`, `RateLimitedWithRedis`, or another middleware that releases the job back to the queue spends an attempt on each release. Report a `tries` value that is low for such a job and explain the effect.
3. For side-effect jobs (mail, invoicing, payments, webhooks, external APIs), look for an idempotency key, a unique constraint, or a `ShouldBeUnique` / `ShouldBeUniqueUntilProcessing` contract. A missing safeguard is a risk (`WARN`), never a proven defect.
4. Report unlimited retries (`tries` 0, or no `tries`, `retryUntil`, or `maxExceptions` bound) without a protective mechanism.

**Outcomes.** `WARN` for each risk above, with the missing evidence named. `FAIL` only when a verified setting contradicts itself in a way that the values show. A `$backoff` list longer than `$tries` is not one: the worker takes the entry of the attempt, or the last entry, so extra entries stay unused. Report them as `INFO`. `PASS` when the safeguards are present. `UNVERIFIED` when the job class or its configuration is dynamic.

## HZ-004 — Horizon supervisor configuration

**Inputs.** The effective supervisor rows: `balance` (`auto`, `simple`, `false`), `autoScalingStrategy`, `minProcesses`, `maxProcesses`, `balanceMaxShift`, `balanceCooldown`, `memory`, `maxJobs`, `maxTime`, `timeout`, `queue`.

**Evaluate.**
1. Read each option by the detected version (*Version rule*). An option the version does not support is reported, never assumed.
2. With `balance` set to `auto`, the order of the queues does not set a strict priority. With `false`, the order of the queues can set the priority. Report a queue list that expects order priority under `auto`.
3. Separate the per-queue limits from the total limit of the supervisor, and state which one each value sets.
4. Report an option that the balance mode ignores (for example the balancing shift or cooldown with a mode that does not balance), when the installed source confirms it.
5. Never recommend a target process count without CPU, memory, and queue metrics. State the metrics that are missing.

**Outcomes.** `WARN` for a coherence problem. `FAIL` for a verified contradiction (for example `minProcesses` above `maxProcesses`). `UNVERIFIED` for a dynamic value or an unknown version. `PASS` otherwise.

## HZ-005 — Deployment and process control

**Inputs.** Tracked process-manager files (Supervisor, systemd, Docker, Kubernetes, managed-platform and deploy scripts, cron, CI/CD) and the commands in them.

**Evaluate.**
1. Look for a graceful stop of Horizon (`horizon:terminate`) in the deploy path and a restart by the process manager.
2. Compare the stop grace period (`stopwaitsecs`, `terminationGracePeriodSeconds`, or the equivalent) with the longest job timeout and the shutdown strategy. A grace period below the longest job can kill a running job.
3. Report old and new workers that can run at the same time, duplicate process managers, and a standalone `queue:work` beside Horizon on the same queue.
4. When no process-manager configuration is tracked, return `UNVERIFIED` and not `FAIL`.
5. Run, restart, and deploy nothing.

**Outcomes.** `WARN` for the risks above. `FAIL` for a verified duplicate consumer on one physical queue with a different `retry_after`, which also feeds HZ-002. `UNVERIFIED` when no process configuration is tracked. `PASS` when a graceful stop and a sufficient grace period are shown.

## HZ-006 — Monitoring and alerting

**Inputs.** The `waits` map of `config/horizon.php`, the queue connections and queue names of the consumer map, and the notification routing in `HorizonServiceProvider`.

**Evaluate.**
1. Each `waits` key is a `connection:queue` pair. Report a key with no matching consumer (orphaned) and a consumed `connection:queue` with no key (missing).
2. Check that a notification route exists (`Horizon::routeMailNotificationsTo`, `routeSlackNotificationsTo`, `routeSmsNotificationsTo`, or the equivalent) without sending any notification. Report a route that depends on an unknown environment value as `UNVERIFIED`.
3. Recommend tracking queue depth, wait time p50, p95, and p99, throughput, execution time, failed and retried jobs, CPU, memory, swap, worker process count, and the PHP-FPM workload. Compare throughput with an application throttle or a provider limit only when the value is known.
4. Distinguish *monitoring is not declared in the repository* (`UNVERIFIED`) from *monitoring is not in place* (`WARN`, only when the repository shows that no route or `waits` entry exists).

**Outcomes.** `WARN` for an orphaned or missing key, or a verified lack of any alert route. `UNVERIFIED` for an external or dynamic setup. `PASS` when keys and routes match.

## HZ-007 — Horizon dashboard security

**Inputs.** `Horizon::auth`, the `viewHorizon` gate, `horizon.path` and `horizon.middleware`, the route exposure, and the way the dashboard shows payloads.

**Evaluate.**
1. Read the authorization of the dashboard: a gate definition, a custom `Horizon::auth` callback, and the middleware of the dashboard route.
2. Check whether the gate lets every authenticated user in, or lets anyone in, in a non-local environment.
3. Note the risk of payloads and personal data in the dashboard and in logs, and name the setting that limits it when the files show one.
4. Classify as `CRITICAL` only when the configuration is shown to be exploitable. When the authorization cannot be read from the code (for example the default gate relies on a runtime environment value), return `UNVERIFIED`, and never a security diagnosis.

**Outcomes.** `FAIL` (`CRITICAL` or `HIGH`) for a gate that is verified to admit unauthorized users in production. `WARN` for a broad gate or an open dashboard path. `UNVERIFIED` when the authorization cannot be read statically. `PASS` when a restrictive gate is shown.

## Folding into a code review
The audit covers the whole repository, not a diff. A code review does not run it (`@skills/code-review/references/specialized-reviews.md` *Skills deliberately not run*). A finding folded into a review follows the mapping in `SKILL.md` *Statuses, severities, and evidence basis*.
