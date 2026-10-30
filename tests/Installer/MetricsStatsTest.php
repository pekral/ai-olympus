<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\MetricsEntry;
use Pekral\AiOlympus\MetricsReport;
use Pekral\AiOlympus\MetricsStore;
use Tests\Support\StderrCapture;

/**
 * @param list<array<string, mixed>> $entries
 */
function metricsWriteStore(string $path, array $entries): void
{
    $lines = array_map(static fn (array $entry): string => (string) json_encode($entry), $entries);
    file_put_contents($path, implode("\n", $lines) . "\n");
}

/**
 * @param list<array<string, mixed>> $entries
 */
function metricsRender(array $entries, string $window): string
{
    return MetricsReport::render(array_map(MetricsEntry::fromArray(...), $entries), $window);
}

/**
 * @return array<string, mixed>
 */
function metricsEntry(string $initial, string $final, int $critical = 0, int $moderate = 0, int $rounds = 1): array
{
    return [
        'agent_dispatches' => 2,
        'escalated_dispatches' => 1,
        'final_tier' => $final,
        'findings' => ['critical' => $critical, 'moderate' => $moderate],
        'initial_tier' => $initial,
        'review_rounds' => $rounds,
        'status' => 'success',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

test('the store reads entries and skips lines that do not parse', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'metrics');
    assert(is_string($path));

    // A crash mid-append leaves a truncated line. One bad line must not make every later report
    // unreadable — the store is append-only operational data, not a transaction log.
    file_put_contents($path, implode("\n", [
        (string) json_encode(metricsEntry('FAST', 'FAST')),
        '{ truncated',
        '',
        '{"no_tier": true}',
        (string) json_encode(metricsEntry('STANDARD', 'STANDARD')),
    ]) . "\n");

    expect(MetricsStore::read($path))->toHaveCount(2);

    unlink($path);
});

test('the store returns typed entries with the integer counters, the findings total, and the review loop (issue #220)', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'metrics');
    assert(is_string($path));
    $entry = metricsEntry('FAST', 'STANDARD', critical: 1, moderate: 2);
    $entry['graph_hits'] = 'many';
    $entry['review'] = ['iterations' => 2, 'result' => 'converged', 'escalation_reason' => null];
    metricsWriteStore($path, [$entry]);

    $entries = MetricsStore::read($path);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->initialTier)->toBe('FAST');
    expect($entries[0]->finalTier)->toBe('STANDARD');
    expect($entries[0]->criticalAndModerateFindings)->toBe(3);
    expect($entries[0]->counter('agent_dispatches'))->toBe(2);
    expect($entries[0]->counter('graph_hits'))->toBeNull();
    expect($entries[0]->reviewCounter('iterations'))->toBe(2);
    expect($entries[0]->reviewCounter('new'))->toBeNull();
    expect($entries[0]->reviewResult)->toBe('converged');

    unlink($path);
});

test('the store returns nothing for a missing file rather than failing', function (): void {
    expect(MetricsStore::read('/nonexistent/metrics.jsonl'))->toBe([]);
});

test('the store filters by the requested window', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'metrics');
    assert(is_string($path));

    $old = metricsEntry('FAST', 'FAST');
    $old['timestamp'] = gmdate('Y-m-d\TH:i:s\Z', time() - (30 * 86_400));

    metricsWriteStore($path, [$old, metricsEntry('STANDARD', 'STANDARD')]);

    expect(MetricsStore::read($path, time() - (7 * 86_400)))->toHaveCount(1);
    expect(MetricsStore::read($path))->toHaveCount(2);

    // An entry whose timestamp is missing or unparseable cannot be placed in a window, so a windowed
    // read excludes it rather than guessing that it is recent.
    $undated = metricsEntry('FAST', 'FAST');
    unset($undated['timestamp']);
    metricsWriteStore($path, [$undated]);
    expect(MetricsStore::read($path, time() - 86_400))->toBe([]);
    expect(MetricsStore::read($path))->toHaveCount(1);

    unlink($path);
});

test('the store resolves a path from the override, the home directory, or nowhere', function (): void {
    $home = getenv('HOME');
    $profile = getenv('USERPROFILE');

    try {
        putenv('AI_OLYMPUS_HOME=/tmp/olympus-test/');
        expect(MetricsStore::resolvePath())->toBe('/tmp/olympus-test/metrics.jsonl');

        putenv('AI_OLYMPUS_HOME');
        putenv('HOME=/home/someone');
        expect(MetricsStore::resolvePath())->toBe('/home/someone/' . MetricsStore::DEFAULT_RELATIVE_PATH);

        // With no home at all there is no location to read, and the command says so rather than
        // inventing one inside the project — where it would land in a commit.
        putenv('HOME=');
        putenv('USERPROFILE=');
        expect(MetricsStore::resolvePath())->toBeNull();
    } finally {
        putenv($home === false ? 'HOME' : 'HOME=' . $home);
        putenv($profile === false ? 'USERPROFILE' : 'USERPROFILE=' . $profile);
        putenv('AI_OLYMPUS_HOME');
    }
});

test('the report answers the three questions a single run cannot', function (): void {
    // A FAST tier that keeps escalating is routing real work to the tier that skips the review; a
    // STANDARD tier whose reviews never find anything is paying for a pass that changes no outcome.
    $report = metricsRender([
        metricsEntry('FAST', 'CRITICAL', critical: 1),
        metricsEntry('FAST', 'FAST', rounds: 0),
        metricsEntry('STANDARD', 'STANDARD'),
    ], 'the last 7 day(s)');

    expect($report)->toContain('Runs                  3');
    expect($report)->toContain('FAST                  1');
    expect($report)->toContain('CRITICAL              1');
    expect($report)->toContain('Agent dispatches      6');
    expect($report)->toContain('Escalated dispatches  3');
    expect($report)->toContain('FAST escalations      1 / 2');
    expect($report)->toContain('Review zero-findings  1 / 2');
});

test('the report never prints a fabricated token average', function (): void {
    // No runtime here reports token usage reliably. An absent number is honest; an estimated one is
    // a measurement nobody made.
    expect(metricsRender([metricsEntry('FAST', 'FAST')], 'all time'))
        ->toContain('Tokens                not reported by this runtime');

    $withTokens = metricsEntry('STANDARD', 'STANDARD');
    $withTokens['input_tokens'] = 1_000;
    $withTokens['output_tokens'] = 500;

    expect(metricsRender([$withTokens], 'all time'))
        ->toContain('Avg tokens per run    1500 (over 1 run(s) that reported)');
});

test('a non-integer input_tokens still counts the run as one that reported tokens', function (): void {
    $entry = ['final_tier' => 'STANDARD', 'input_tokens' => '1000', 'output_tokens' => 500];

    $report = metricsRender([$entry], 'all time');

    expect($report)->toContain('Avg tokens per run    500 (over 1 run(s) that reported)');
});

test('a positive non-integer review_rounds still counts the run as reviewed', function (mixed $rounds): void {
    $entry = ['final_tier' => 'STANDARD', 'review_rounds' => $rounds];

    $report = metricsRender([$entry], 'all time');

    expect($report)->toContain('Review zero-findings  1 / 1');
})->with(['numeric string' => '2', 'float' => 1.5, 'true' => true]);

test('the report degrades rather than failing on absent or malformed fields', function (): void {
    $sparse = ['final_tier' => 'FAST', 'initial_tier' => 'STANDARD', 'findings' => 'not-an-array', 'agent_dispatches' => 'many'];

    $report = metricsRender([$sparse], 'all time');

    // The same malformed `findings` reaching the zero-findings signal (the entry was reviewed) must
    // count as no findings rather than crashing the whole report.
    $reviewed = $sparse;
    $reviewed['review_rounds'] = 1;
    expect(metricsRender([$reviewed], 'all time'))->toContain('Review zero-findings  1 / 1');

    expect($report)->toContain('Runs                  1');
    expect($report)->toContain('Agent dispatches      0');
    // Nothing started FAST and nothing was reviewed, so both derived signals say so explicitly
    // rather than printing a ratio out of zero.
    expect($report)->toContain('FAST escalations      no FAST runs recorded');
    expect($report)->toContain('Review zero-findings  no reviewed runs recorded');
});

test('the report sums every context and graph counter the recorder writes (issue #195)', function (string $expectedLine): void {
    $counters = [
        'context_gate_executed' => 3,
        'context_requested' => 2,
        'context_skipped' => 1,
        'sources_searched' => 4,
        'items_returned' => 5,
        'estimated_tokens_returned' => 548,
        'items_inspected' => 6,
        'context_cache_hits' => 1,
        'context_cache_misses' => 2,
        'retrieval_seconds' => 23,
        'graph_hits' => 1,
        'graph_misses' => 2,
        'items_from_graph' => 3,
        'items_from_search' => 4,
        'files_avoided' => 7,
    ];
    $entries = [
        array_merge(metricsEntry('STANDARD', 'STANDARD'), $counters),
        array_merge(metricsEntry('CRITICAL', 'CRITICAL'), $counters),
    ];

    $report = metricsRender($entries, 'all time');

    expect($report)->toContain($expectedLine . PHP_EOL);
})->with([
    'context cache hits' => ['Context cache hits    2'],
    'context cache misses' => ['Context cache misses  4'],
    'context gate runs' => ['Context gate runs     6'],
    'context requested' => ['Context requested     4'],
    'context skipped' => ['Context skipped       2'],
    'estimated tokens returned' => ['Est. tokens returned  1096'],
    'files avoided' => ['Files avoided         14'],
    'graph hits' => ['Graph hits            2'],
    'graph misses' => ['Graph misses          4'],
    'items from graph' => ['Items from graph      6'],
    'items from search' => ['Items from search     8'],
    'items inspected' => ['Items inspected       12'],
    'items returned' => ['Items returned        10'],
    'retrieval seconds' => ['Retrieval seconds     46'],
    'sources searched' => ['Sources searched      8'],
]);

test('the report says a counter was not recorded instead of printing zero for older entries (issue #195)', function (): void {
    $older = metricsEntry('FAST', 'FAST');
    $malformed = metricsEntry('FAST', 'FAST');
    $malformed['graph_hits'] = 'many';
    $newer = metricsEntry('STANDARD', 'STANDARD');
    $newer['graph_hits'] = 0;
    $newer['context_gate_executed'] = 2;

    $olderOnly = metricsRender([$older, $malformed], 'all time');
    $mixed = metricsRender([$older, $malformed, $newer], 'all time');

    expect($olderOnly)->toContain('Context gate runs     not recorded' . PHP_EOL)
        ->and($olderOnly)->toContain('Graph hits            not recorded' . PHP_EOL)
        ->and($olderOnly)->toContain('Files avoided         not recorded' . PHP_EOL)
        ->and($mixed)->toContain('Context gate runs     2' . PHP_EOL)
        ->and($mixed)->toContain('Graph hits            0' . PHP_EOL)
        ->and($mixed)->toContain('Retrieval seconds     not recorded' . PHP_EOL);
});

test('the report sums every review-loop counter the recorder writes and counts the loop results', function (string $expectedLine): void {
    $review = [
        'iterations' => 2,
        'new_per_iteration' => [2, 1],
        'new' => 3,
        'confirmed' => 2,
        'resolved' => 1,
        'dismissed' => 4,
        'reopened' => 5,
        'deferred' => 6,
        'needs_verification' => 7,
        'remaining_blocking' => 8,
        'result' => 'converged',
        'escalation_reason' => null,
    ];
    $escalated = array_merge($review, ['result' => 'escalated', 'escalation_reason' => 'cap-reached-hard-blockers']);
    $entries = [
        array_merge(metricsEntry('STANDARD', 'STANDARD'), ['review' => $review]),
        array_merge(metricsEntry('CRITICAL', 'CRITICAL'), ['review' => $escalated]),
        metricsEntry('FAST', 'FAST'),
    ];

    expect(metricsRender($entries, 'all time'))->toContain($expectedLine . PHP_EOL);
})->with([
    'blocking remaining' => ['Blocking remaining    16'],
    'findings confirmed' => ['Findings confirmed    4'],
    'findings deferred' => ['Findings deferred     12'],
    'findings dismissed' => ['Findings dismissed    8'],
    'findings new' => ['Findings new          6'],
    'findings reopened' => ['Findings reopened     10'],
    'findings resolved' => ['Findings resolved     2'],
    'findings unverified' => ['Findings unverified   14'],
    'loop iterations' => ['Loop iterations       4'],
    'loops converged' => ['Loops converged       1 / 2'],
    'loops escalated' => ['Loops escalated       1 / 2'],
]);

test('the report says the review-loop counters were not recorded for entries without a review', function (): void {
    $malformed = metricsEntry('FAST', 'FAST');
    $malformed['review'] = 'broken';

    $report = metricsRender([metricsEntry('FAST', 'FAST'), $malformed], 'all time');

    expect($report)->toContain('Loop iterations       not recorded' . PHP_EOL)
        ->and($report)->toContain('Blocking remaining    not recorded' . PHP_EOL)
        ->and($report)->toContain('Loops converged       no review loops recorded' . PHP_EOL)
        ->and($report)->toContain('Loops escalated       no review loops recorded' . PHP_EOL);
});

test('an empty store reports the window rather than an empty table', function (): void {
    expect(MetricsReport::render([], 'the last 7 day(s)'))->toBe('No runs recorded for the last 7 day(s).' . PHP_EOL);
});

test('the stats command summarises the store for the requested window', function (): void {
    $home = getenv('AI_OLYMPUS_HOME');
    $directory = sys_get_temp_dir() . '/olympus-stats-' . uniqid();
    mkdir($directory, 0o777, recursive: true);
    metricsWriteStore($directory . '/metrics.jsonl', [metricsEntry('FAST', 'STANDARD')]);

    try {
        putenv('AI_OLYMPUS_HOME=' . $directory);

        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'stats', '--last=7d']);
        $output = (string) ob_get_clean();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Runs                  1');
        expect($output)->toContain('FAST escalations      1 / 1');

        ob_start();
        $allTime = Installer::run(['ai-olympus', 'stats', '--last=all']);
        $allOutput = (string) ob_get_clean();

        expect($allTime)->toBe(0);
        expect($allOutput)->toContain('Runs                  1');

        // No --last at all is the same as all time, so the command is useful with no arguments.
        ob_start();
        $bare = Installer::run(['ai-olympus', 'stats']);
        ob_get_clean();
        expect($bare)->toBe(0);
    } finally {
        putenv($home === false ? 'AI_OLYMPUS_HOME' : 'AI_OLYMPUS_HOME=' . $home);
        @unlink($directory . '/metrics.jsonl');
        @rmdir($directory);
    }
});

test('the stats command rejects a malformed window instead of guessing one', function (): void {
    $home = getenv('AI_OLYMPUS_HOME');

    try {
        putenv('AI_OLYMPUS_HOME=' . sys_get_temp_dir());

        ob_start();
        [$exitCode, $stderr] = StderrCapture::run(static fn (): int => Installer::run(['ai-olympus', 'stats', '--last=lately']));
        ob_get_clean();

        expect($exitCode)->toBe(1);
        expect($stderr)->toContain('--last takes a number of days');
    } finally {
        putenv($home === false ? 'AI_OLYMPUS_HOME' : 'AI_OLYMPUS_HOME=' . $home);
    }
});

test('the stats command reports an unresolvable home rather than writing into the project', function (): void {
    $home = getenv('HOME');
    $profile = getenv('USERPROFILE');
    $override = getenv('AI_OLYMPUS_HOME');

    try {
        putenv('AI_OLYMPUS_HOME');
        putenv('HOME=');
        putenv('USERPROFILE=');

        ob_start();
        [$exitCode, $stderr] = StderrCapture::run(static fn (): int => Installer::run(['ai-olympus', 'stats']));
        ob_get_clean();

        expect($exitCode)->toBe(1);
        expect($stderr)->toContain('Cannot resolve a home directory');
    } finally {
        putenv($home === false ? 'HOME' : 'HOME=' . $home);
        putenv($profile === false ? 'USERPROFILE' : 'USERPROFILE=' . $profile);
        putenv($override === false ? 'AI_OLYMPUS_HOME' : 'AI_OLYMPUS_HOME=' . $override);
    }
});

test('the help text advertises the stats command', function (): void {
    ob_start();
    $exitCode = Installer::run(['ai-olympus']);
    $output = (string) ob_get_clean();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('ai-olympus stats [--last=7d|30d|all]');
    expect($output)->toContain('Summarise local orchestration metrics');
});
