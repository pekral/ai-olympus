# Checks of `php-memory-leak-audit`

Referenced from `@skills/php-memory-leak-audit/SKILL.md` *Execution*. Every check reads the changed lines that an asynchronous entry point reaches, and nothing else. Every hit is a Moderate finding of the class *risk* or *confirmed leak*.

Contents: *Entry points*, *Worker lifecycle*, *ML-1* to *ML-5*, *Not findings in any check*.

## Entry points
A changed file holds an entry point when it contains one of these:
- A class that implements `ShouldQueue`: a queued job, a queued event listener, a queued notification, a queued mailable, or a queued broadcast event.
- A closure passed to a dispatch helper that queues it.
- A console command or a daemon class with an endless consumer loop (`while (true)`, `for (;;)`) that reads a queue or a stream.
- A file that starts or configures a worker: a process-manager, container, or deployment file that runs `queue:work`, `queue:listen`, or Horizon, and `config/horizon.php` or `config/queue.php`.

A class that has `Dispatchable` or `Queueable` and no `ShouldQueue` runs in the process that dispatches it. It is an entry point only when the project dispatches it to a queue.

## Worker lifecycle
Read this before every check.

- **Lives for one job:** the job object, its properties, and the local variables of `handle()`.
- **Lives as long as the worker:** a `static` property, a function-level `static` variable, a container `singleton`, a registered event listener, an enabled query log, and an open connection.
- A `scoped` binding lives as long as the worker unless the worker resets scoped instances between jobs. Read `forgetScopedInstances` in the installed `laravel/framework` source (`Illuminate/Queue/QueueServiceProvider.php`). Without `vendor/`, the point goes under *Not verified*.
- Memory that a job allocates and releases when it ends is the normal case. Only state that outlives the job is a finding.

## ML-1 — Unbounded static state and singletons
**Hit:** a line the diff adds or changes writes to state that lives as long as the worker, and no code in the same class bounds or clears it:
- an element added to a `static` array (`self::$cache[$key] = …`, `static::$items[] = …`) or to a function-level `static` array;
- an element added to a property of a class that the container binds as a `singleton`, or that an entry point reaches through a static call;
- a retained Eloquent model, collection, or DTO in such a place.

**Fix:** remove the state, or give it an explicit lifecycle: a size cap with eviction, a clear at the end of the job (in `finally`, or from a `JobProcessed` callback), or a cache store with a TTL. Pass the value as an argument when it is not shared between jobs.

**Not a finding:** a value assigned once from a fixed domain (a `??=` memo over a known set of keys such as class names or config keys); a map built once and never written again; a state that the same class caps or clears; a property of the job object itself.

## ML-2 — Reference cycles and object retention
**Hit:**
- a reference cycle (a parent and a child that hold each other, a closure stored on `$this` that captures `$this`) that hangs from a holder of ML-1, or that a `gc_disable()` call in the diff or in the project's worker code leaves to the process;
- a closure, callback, or listener list stored in a worker-lifetime holder that captures a model or another large object;
- a file handle, stream, curl handle, or large buffer kept in a property of a worker-lifetime holder.

**Fix:** break the cycle with a weak reference (`WeakReference`, `WeakMap`) or an explicit release, release the handle in `finally`, and store an identifier instead of the object.

**Not a finding:** a cycle among objects that die with the job object while the PHP cycle collector is enabled; a small value object.

## ML-3 — Repeated listener registration
**Hit:** a registration that runs on every job, so each job in the worker adds another callback:
- `Event::listen(…)`, `Event::subscribe(…)`, `$dispatcher->listen(…)`, `DB::listen(…)`, `Queue::before(…)`, `Queue::after(…)`, `Queue::failing(…)`, or a model event hook (`Model::saved(…)`, `static::creating(…)`) inside `handle()`, `__construct()`, `__invoke()`, or a method that an entry point calls on each job.

**Fix:** move the registration to a service provider `boot()`, or to the model's `booted()`, which run once per process. Register inside the job only behind a guard that makes it run once per process.

**Not a finding:** a registration in a service provider `boot()` or `register()`, in a model `booted()`, or behind a guard that runs once per process.

## ML-4 — Active query log
**Hit:** a line the diff adds or changes enables the query log in a path an entry point reaches (`DB::enableQueryLog()`, `$connection->enableQueryLog()`), and the same job does not disable it or flush it (`DB::disableQueryLog()`, `DB::flushQueryLog()`) in `finally`. Also a `DB::listen(…)` callback that appends each query to a worker-lifetime array.

**Fix:** disable or flush the log at the end of the job, or enable it only in the code path that reads it and flush it there.

**Not a finding:** a query log enabled only in a path that no entry point reaches; a log flushed in `finally` of the same job.

## ML-5 — Worker recycle bounds
Only a worker command line or a Horizon supervisor entry that the diff adds or changes is read, and every other line of the same file is left alone (*Entry points*, last item). The whole-repository configuration audit is `@skills/laravel-horizon-audit/SKILL.md` and is not part of a review.

**Hit:**
- the worker command line sets neither `--max-jobs` nor `--max-time`, or a Horizon supervisor sets neither `maxJobs` nor `maxTime`, so no count or time recycles the worker. Read the default of each flag and the meaning of `0` in the installed `WorkCommand` signature. Without `vendor/`, the point goes under *Not verified*;
- the `--memory` value of the worker is not lower than the memory limit that the same changed file states for its container or process, so the limit ends the process before the worker stops itself.

**Fix:** add the flag to the command or the supervisor. The value is the project's decision: name a value only when the project already sets a comparable bound, and never recommend a worker count or a memory figure without a measurement (`@skills/laravel-horizon-audit/SKILL.md` *Constraints*).

**Not a finding:** a worker that processes one job per process (`--once`); a bound set where the diff does not show it, which goes under *Not verified* with the file that settles it.

## Not findings in any check
- Code outside an asynchronous process: an HTTP request, a controller, middleware, a synchronous service, a one-off CLI command. A shared service with no path to an entry point.
- A collection that one job loads, uses, and releases. How much it holds at once belongs to `@rules/code-review/general.md` *Bulk Data & Batch Processing (issue #223)*.
- A `foreach`. A `foreach` is never a finding on its own.
- A line on which a later job reads the stored value as its own context of identity, tenant, or permission. The rule walk and the security owners own it, and the lens reports nothing on it (`@skills/code-review/references/specialized-reviews.md` *Gating*).
- A defect of the same job that the walk of `@rules/code-review/general.md` *Bulk Data & Batch Processing (issue #223)* or the latency lens owns. The lens reports only what survives the job.
- A static property that holds a constant, a request-independent lookup, or a value of a fixed size.
