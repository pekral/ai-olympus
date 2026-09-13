<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\MetricsReport;
use Pekral\AiOlympus\MetricsStore;

/**
 * @param list<array<string, mixed>> $entries
 */
function metricsWriteStore(string $path, array $entries): void
{
    $lines = array_map(static fn (array $entry): string => (string) json_encode($entry), $entries);
    file_put_contents($path, implode("\n", $lines) . "\n");
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
    $report = MetricsReport::render([
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
    expect(MetricsReport::render([metricsEntry('FAST', 'FAST')], 'all time'))
        ->toContain('Tokens                not reported by this runtime');

    $withTokens = metricsEntry('STANDARD', 'STANDARD');
    $withTokens['input_tokens'] = 1_000;
    $withTokens['output_tokens'] = 500;

    expect(MetricsReport::render([$withTokens], 'all time'))
        ->toContain('Avg tokens per run    1500 (over 1 run(s) that reported)');
});

test('the report degrades rather than failing on absent or malformed fields', function (): void {
    $sparse = ['final_tier' => 'FAST', 'initial_tier' => 'STANDARD', 'findings' => 'not-an-array', 'agent_dispatches' => 'many'];

    $report = MetricsReport::render([$sparse], 'all time');

    // The same malformed `findings` reaching the zero-findings signal (the entry was reviewed) must
    // count as no findings rather than crashing the whole report.
    $reviewed = $sparse;
    $reviewed['review_rounds'] = 1;
    expect(MetricsReport::render([$reviewed], 'all time'))->toContain('Review zero-findings  1 / 1');

    expect($report)->toContain('Runs                  1');
    expect($report)->toContain('Agent dispatches      0');
    // Nothing started FAST and nothing was reviewed, so both derived signals say so explicitly
    // rather than printing a ratio out of zero.
    expect($report)->toContain('FAST escalations      no FAST runs recorded');
    expect($report)->toContain('Review zero-findings  no reviewed runs recorded');
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
        $exitCode = Installer::run(['ai-olympus', 'stats', '--last=lately']);
        ob_get_clean();

        expect($exitCode)->toBe(1);
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
        $exitCode = Installer::run(['ai-olympus', 'stats']);
        ob_get_clean();

        expect($exitCode)->toBe(1);
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
