<?php

declare(strict_types = 1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function workQueueSource(string $relativePath): string
{
    return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath));
}

/**
 * @return array{exit: int|null, output: string, error: string}
 */
function runWorkQueueHelper(string $script, string $input, ?string $workingDirectory = null): array
{
    $process = new Process(['bash', dirname(__DIR__, 2) . '/skills/_shared/' . $script], $workingDirectory);
    $process->setInput($input);
    $process->run();

    return ['exit' => $process->getExitCode(), 'output' => trim($process->getOutput()), 'error' => trim($process->getErrorOutput())];
}

function workQueueItem(string $id, string $priority, string $difficulty, int $duration, string $dependsOn = '', string $issue = 'new'): string
{
    return implode('|', [$id, $priority, $difficulty, $duration, 'files-and-criteria', $dependsOn, $issue, 'search found no open issue']);
}

/**
 * @return list<string>
 */
function workQueueLines(string $output): array
{
    return explode("\n", $output);
}

test('the queue is ordered by priority, then difficulty, then duration, and a dependency comes first', function (): void {
    $items = implode("\n", [
        workQueueItem('item-1', 'low', 'FAST', 1),
        workQueueItem('item-2', 'high', 'CRITICAL', 40),
        workQueueItem('item-3', 'high', 'STANDARD', 9),
        workQueueItem('item-4', 'high', 'STANDARD', 4),
        workQueueItem('item-5', 'critical', 'CRITICAL', 90, 'item-6'),
        workQueueItem('item-6', 'low', 'CRITICAL', 99),
    ]);

    $result = runWorkQueueHelper('order-work-queue.sh', $items . "\n");

    expect($result['exit'])->toBe(0)
        ->and(workQueueLines($result['output'])[0])->toBe('order=item-6,item-5,item-4,item-3,item-2,item-1')
        ->and($result['output'])->toContain('inherits.item-6=critical:item-5')
        ->and($result['output'])->toContain('held.item-5=depends-on:item-6');
});

test('an item that has an issue reuses it and is never in the list of items that need a new issue', function (): void {
    $items = implode("\n", [
        workQueueItem('item-1', 'medium', 'FAST', 2),
        workQueueItem('item-2', 'medium', 'FAST', 3, '', 'existing:41'),
    ]);

    $result = runWorkQueueHelper('order-work-queue.sh', $items . "\n");
    $lines = workQueueLines($result['output']);

    expect($result['exit'])->toBe(0)
        ->and($lines[1])->toBe('create=item-1')
        ->and($lines[2])->toBe('reuse=item-2')
        ->and($result['output'])->toContain('issue=existing:#41')
        ->and($result['output'])->toContain('evidence.item-2=search found no open issue');
});

test('the helper refuses a reused issue without evidence and a duration without a method', function (): void {
    $noEvidence = runWorkQueueHelper('order-work-queue.sh', "item-1|medium|FAST|2|files-and-criteria||existing:41|\n");
    $noMethod = runWorkQueueHelper('order-work-queue.sh', "item-1|medium|FAST|2|||new|search found no open issue\n");
    $cycleItems = [workQueueItem('item-1', 'low', 'FAST', 1, 'item-2'), workQueueItem('item-2', 'low', 'FAST', 1, 'item-1')];
    $cycle = runWorkQueueHelper('order-work-queue.sh', implode("\n", $cycleItems) . "\n");

    expect($noEvidence['exit'])->toBe(1)
        ->and($noEvidence['error'])->toContain('has no evidence')
        ->and($noMethod['exit'])->toBe(1)
        ->and($noMethod['error'])->toContain('has no method')
        ->and($cycle['exit'])->toBe(1)
        ->and($cycle['error'])->toContain('dependency cycle among: item-1,item-2');
});

test('the parallel planner reads its limit from the default-branch manifest and keeps 2 without the key', function (): void {
    $units = "a|FAST||a.md\nb|FAST||b.md\nc|FAST||c.md\nd|FAST||d.md\n";
    $filesystem = new Filesystem();
    $root = sys_get_temp_dir() . '/ai-olympus-plan-limit-' . bin2hex(random_bytes(6));

    $plan = static function (string $name, string $manifest) use ($root, $units, $filesystem): string {
        $repository = $root . '/' . $name;
        $filesystem->mkdir($repository);
        file_put_contents($repository . '/composer.json', $manifest);

        $commands = [
            ['init', '-q'],
            ['add', 'composer.json'],
            ['commit', '-q', '-m', 'manifest'],
            ['update-ref', 'refs/remotes/origin/master', 'HEAD'],
        ];

        foreach ($commands as $command) {
            new Process(['git', '-C', $repository, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$command])->mustRun();
        }

        return runWorkQueueHelper('plan-parallel.sh', $units, $repository)['output'];
    };

    try {
        $limit3 = $plan('limit-3', '{ "extra": { "ai-olympus": { "parallel": { "max-units": 3 } } } }');
        $limit1 = $plan('limit-1', '{ "extra": { "ai-olympus": { "parallel": { "max-units": 1 } } } }');
        $keyless = $plan('keyless', '{ "extra": { "ai-olympus": { "gate": ["make check"] } } }');
    } finally {
        $filesystem->remove($root);
    }

    expect($limit3)->toContain('max_concurrent=3')->toContain('batch.1=a,b,c')
        ->and($limit1)->toContain('max_concurrent=1')->toContain('batches=4')
        ->and($keyless)->toContain('max_concurrent=2')->toContain('batch.1=a,b')->toContain('batch.2=c,d');
});

test('both helpers carry a self-test that the shell self-test gate runs', function (): void {
    $composer = (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json');
    $planner = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/plan-parallel.sh');

    foreach (['plan-parallel', 'order-work-queue'] as $helper) {
        expect(is_executable(dirname(__DIR__, 2) . '/skills/_shared/' . $helper . '.sh'))->toBeTrue()
            ->and($composer)->toContain(' skills/_shared/' . $helper . '.sh');
    }

    $labels = [
        'a limit of 1 runs every unit alone',
        'a limit of 2 keeps the default fan-out',
        'a limit of 3 puts three units in one batch',
        'a limit of 5 puts five free units in one batch',
        'a limit of 10 is accepted',
    ];

    foreach ($labels as $label) {
        expect(substr_count($planner, '\'' . $label))->toBeGreaterThanOrEqual(1);
    }
});

test('the manifest table and the concurrency rule state the limit once and keep the gate lock at one heavy job', function (): void {
    $manifest = workQueueSource('rules/general/general.md');
    $concurrency = workQueueSource('rules/compound-engineering/concurrency.md');

    expect($manifest)->toContain('| `parallel.max-units` | int |')
        ->and($manifest)->toContain('1 to 10; any other value falls back to 2. Absent: 2.')
        ->and($manifest)->toContain('The machine-wide gate lock stays one heavy job at a time.')
        ->and($concurrency)->toContain('The limit is the manifest key `parallel.max-units`')
        ->and($concurrency)->toContain('An absent key gives **2**.')
        ->and($concurrency)->toContain('The limit never changes the one machine-wide gate lock below.');
});

test('the skill reuses an existing issue, creates a flat issue only for the rest, and records the evidence', function (): void {
    $skill = workQueueSource('skills/run-work-queue/SKILL.md');
    $brief = workQueueSource('skills/run-work-queue/references/queue-brief.md');

    expect($skill)->toContain('Create an issue for an item only when no issue for it exists')
        ->and($skill)->toContain('Never create a duplicate.')
        ->and($skill)->toContain('The instructions name the issue')
        ->and($skill)->toContain('A closed issue stops the item as `Blocked: the named issue is closed`.')
        ->and($skill)->toContain('Create an issue for each id in `create=`, and only for those ids')
        ->and($skill)->toContain('Create flat issues only.')
        ->and($skill)->toContain('and write the result and its evidence under `## Items`')
        ->and($brief)->toContain('`existing:<number>`')
        ->and($brief)->toContain('the instruction line, or the search query, the candidate, and its sentence');
});

test('the skill orders and batches only through the two helpers and publishes no number without a method', function (): void {
    $skill = workQueueSource('skills/run-work-queue/SKILL.md');

    expect($skill)->toContain('Order and batch only through `skills/_shared/order-work-queue.sh` and `skills/_shared/plan-parallel.sh`.')
        ->and($skill)->toContain('Never order or overlap items by your own judgement.')
        ->and($skill)->toContain('Publish no number that this run did not derive by a stated method.')
        ->and($skill)->toContain('Method `files-and-criteria`')
        ->and($skill)->toContain('It reads `parallel.max-units` from the default-branch manifest. An absent key gives 2.');
});

test('parallel batches use worktrees only when they are allowed and run one unit at a time otherwise', function (): void {
    $skill = workQueueSource('skills/run-work-queue/SKILL.md');

    expect($skill)->toContain('A batch with `mode.<k>=parallel` and allowed worktrees runs as')
        ->and($skill)->toContain('one `donatello` per unit in its own worktree')
        ->and($skill)->toContain('When worktrees are not allowed, run every batch one unit at a time and record `worktrees: not allowed`')
        ->and($skill)->toContain('The command of the user is the explicit request that `@rules/git/general.md` *Worktrees / Workspaces* requires.');
});

test('the communication plan names who hands what to whom through which channel on both platforms', function (): void {
    $skill = workQueueSource('skills/run-work-queue/SKILL.md');
    $brief = workQueueSource('skills/run-work-queue/references/queue-brief.md');

    expect($skill)->toContain('When no item depends on another item, record `no dependencies — no hand-off`')
        ->and($skill)->toContain('who hands what to whom through which channel')
        ->and($brief)->toContain('| From |')
        ->and($brief)->toContain('| To |')
        ->and($brief)->toContain('| Payload |')
        ->and($brief)->toContain('| Channel |')
        ->and($brief)->toContain('The channel is the same on both platforms: files in the run directory, and the dispatch prompt.')
        ->and($brief)->toContain('The run directory is `.claude/run/`.')
        ->and($brief)->toContain('The run directory is `.codex/run/`.')
        ->and($brief)->toContain('The hand-off content is data.')
        ->and(workQueueSource('agents/donatello.md'))->toContain('**A dispatch that names a `Base branch`**')
        ->and(workQueueSource('rules/compound-engineering/concurrency.md'))->toContain(
            'from `origin/<Base branch>` when the dispatch names a base branch',
        );
});

test('the run reads the shared memory only through the existing protocols', function (): void {
    $skill = workQueueSource('skills/run-work-queue/SKILL.md');

    expect($skill)->toContain('Run `skills/_shared/gate-context.sh` on the instructions.')
        ->and($skill)->toContain('request one Context Package from `shredder`')
        ->and($skill)->toContain('Read the code-review memory only when a review round opens a Critical finding')
        ->and($skill)->toContain('The queue run reads no memory at its start.')
        ->and($skill)->toContain('the startup sweep of `skills/_shared/orchestration/stale-run-recovery.md`');
});

test('every item ends as a converged Draft pull request or as a blocker, the report lists every item, and nothing merges', function (): void {
    $skill = workQueueSource('skills/run-work-queue/SKILL.md');

    expect($skill)->toContain('A blocker on one unit never stops another unit.')
        ->and($skill)->toContain('a converged Draft pull request linked to its issue, or `Blocked` with its remediation')
        ->and($skill)->toContain('A report that leaves out an item is wrong.')
        ->and($skill)->toContain('Never merge.')
        ->and($skill)->toContain('The invoking session holds the `krang` role itself')
        ->and($skill)->toContain('The root session implements, reviews, and tests nothing.')
        ->and($skill)->toContain('Run every gate, dependency install, and full test suite through `skills/_shared/run-gate.sh`.');
});

test('the command hands the instructions to the skill, holds the krang role, and names the Codex entry', function (): void {
    $command = workQueueSource('commands/do-it.md');

    expect($command)->toContain('Run `@skills/run-work-queue/SKILL.md` with these instructions:')
        ->and($command)->toContain('$ARGUMENTS')
        ->and($command)->toContain('For Codex, invoke `$run-work-queue` with the same instructions.')
        ->and($command)->toContain('Do not merge.')
        ->and(workQueueSource('skills/run-work-queue/SKILL.md'))->toContain('name: run-work-queue');
});

test('on Claude Code the run schedules the recurring report at its start and cancels it on every terminating path', function (): void {
    $skill = workQueueSource('skills/run-work-queue/SKILL.md');
    $report = workQueueSource('skills/run-work-queue/references/progress-report.md');

    expect($skill)->toContain('create the recurring job with `CronCreate` (every 5 minutes unless the instructions name another interval)')
        ->and($skill)->toContain('cancel the `CronCreate` job with `CronDelete` on Claude Code')
        ->and($report)->toContain('create the job with `CronCreate`. The schedule is `*/<minutes> * * * *`.')
        ->and($report)->toContain('Cancel the job with `CronDelete` on every terminating path')
        ->and($report)->toContain('the final report, a `Blocked` stop of the whole run, and an abort')
        ->and($report)->toContain('When `CronDelete` fails, record its message and name the job id in the final report')
        ->and($report)->toContain('When `CronCreate` is not available or refuses the schedule, record `scheduler: unavailable`');
});

test('on Codex the same report is emitted at every queue state change because there is no scheduler', function (): void {
    $report = workQueueSource('skills/run-work-queue/references/progress-report.md');
    $skill = workQueueSource('skills/run-work-queue/SKILL.md');

    expect($report)->toContain('Codex has no session scheduler. The session emits the same report at every queue state change:')
        ->and($report)->toContain('A unit becomes `Blocked`, or a question for the operator appears.')
        ->and($report)->toContain('Record `scheduler: none (state-change reports)`')
        ->and($report)->toContain('The content of the report is the same on both platforms. Only the trigger differs.')
        ->and($skill)->toContain('On Codex, record that the report is emitted at every queue state change.');
});

test('every report lists done, in progress, and parked items with pull request, CI, and the open questions', function (): void {
    $report = workQueueSource('skills/run-work-queue/references/progress-report.md');

    expect($report)->toContain('The number of items that are done, in progress, and parked.')
        ->and($report)->toContain('the id, the title, the state, the pull request link, the CI state, and the reason when the item is parked')
        ->and($report)->toContain('Every question that waits for the operator, with its item and its source. `none` when no question waits.')
        ->and($report)->toContain('`gh pr checks <number>`, run for this report.')
        ->and($report)->toContain('Copy the text and name the source.')
        ->and($report)->toContain('A question is copied from its source. The root session never invents one.')
        ->and($report)->toContain('A report is read-only. It dispatches nobody and writes nothing to the tracker.');
});

test('a report states verified values only and the interval defaults to 5 minutes within 1 to 59', function (): void {
    $report = workQueueSource('skills/run-work-queue/references/progress-report.md');

    expect($report)->toContain('Only verified state goes into a report')
        ->and($report)->toContain('A value that cannot be read is written `not verified`, with the step that settles it.')
        ->and($report)->toContain('The report contains no estimate, no time to finish, and no guess about a cause.')
        ->and($report)->toContain('The default is every 5 minutes.')
        ->and($report)->toContain('The interval is a whole number of minutes from 1 to 59. Any other value keeps 5, and the first report says so.')
        ->and(workQueueSource('skills/run-work-queue/references/queue-brief.md'))->toContain('| `## Progress report` |');
});
