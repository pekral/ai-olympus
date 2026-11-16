# Regression-test sketches and a CI step

The skill shows these sketches in the report. It never adds a file to the project. The project owner adopts a sketch through a normal change.

Each sketch fails on the broken configuration of its finding and passes after the fix. Adapt the config keys to the project and confirm the merge of `defaults` and `environments` against the installed Horizon version.

## Timeouts stay below `retry_after` (HZ-002)
```php
<?php

declare(strict_types = 1);

test('every Horizon supervisor timeout stays below the retry_after of its connection', function (): void {
    foreach (config('horizon.environments') as $environment => $supervisors) {
        foreach ($supervisors as $name => $override) {
            $supervisor = array_replace_recursive(config('horizon.defaults.' . $name, []), $override);
            $retryAfter = config('queue.connections.' . $supervisor['connection'] . '.retry_after');

            expect($supervisor['timeout'])
                ->toBeLessThan($retryAfter, $environment . '.' . $name);
        }
    }
})->group('horizon-config');
```

## A job timeout stays below its supervisor timeout (HZ-002)
Read the `$timeout` property of each job class that runs on the supervisor, with reflection, and assert `$jobTimeout < $supervisorTimeout`.

## No two connections share one physical queue with different `retry_after` (HZ-001, HZ-002)
Build the key `driver | redis connection | database | prefix | queue` for each queue connection and assert that every group of equal keys holds one `retry_after`.

## Every `waits` key matches a consumed queue (HZ-006)
Assert that each key of `horizon.waits` is a `connection:queue` pair that a supervisor of the audited environment consumes.

## Side-effect jobs declare their retry safeguard (HZ-003)
Assert that each job class that sends mail, charges a payment, or calls a webhook defines `$tries` or `retryUntil()`, and implements `ShouldBeUnique` or carries an idempotency key.

## CI step
Tag every sketch with the Pest group `horizon-config`, and run that group on every pull request through the project's own test command in its CI workflow. A project that runs the full test suite already includes the group and needs no extra step.
