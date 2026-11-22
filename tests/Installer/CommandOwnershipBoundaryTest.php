<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

test('skills contain no team-role names', function (): void {
    $roles = '/\\b(krang|donatello|leonardo|splinter|michelangelo|raphael|april|casey|shredder)\\b/i';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/skills', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !in_array($file->getExtension(), ['md', 'sh', 'php', 'jq', 'json'], strict: true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        expect($contents)->not->toMatch($roles);
        expect($contents)->not->toContain('commands/_shared/krang-workflow')
            ->and($contents)->not->toContain('commands/_shared/orchestration/');
    }
});

test('commands own the roster mapping and the moved orchestration protocols', function (): void {
    $contract = packageSource('commands/_shared/krang-workflow.inc');

    expect($contract)->toContain('You are a **true orchestrator**')
        ->and($contract)->toContain('dispatch `donatello`')
        ->and($contract)->toContain('`leonardo`')
        ->and(is_dir(dirname(__DIR__, 2) . '/commands/_shared/orchestration'))->toBeTrue()
        ->and(is_file(dirname(__DIR__, 2) . '/commands/_shared/plan-route.sh'))->toBeTrue();
});

test('every public command starts the command-owned root workflow', function (): void {
    $files = glob(dirname(__DIR__, 2) . '/commands/*.md');

    expect($files)->not->toBeFalse();

    foreach (is_array($files) ? $files : [] as $file) {
        expect((string) file_get_contents($file))->toMatch('/`krang` role|role.*`krang`/s');
    }
});

test(
    'a merge requires a fresh delivered security assessment for every delegated summary',
    function (string $summary, ?string $delivery, int $expected): void {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/commands/_shared/security-delivery.php'],
            input: json_encode(['summary' => $summary, 'head_date' => '2026-05-28T12:00:00Z', 'delivered_date' => $delivery], JSON_THROW_ON_ERROR),
        );
    
        expect($process->run())->toBe($expected);
    },
)->with([
    ['0 Critical · security: owned by leonardo', null, 3],
    ['0 Critical · security: owned by leonardo', '2026-05-28T11:59:59Z', 3],
    ['0 Critical · security: owned by review', null, 3],
    ['0 Critical · security: owned by leonardo', '2026-05-28T14:00:00+02:00', 0],
    ['0 Critical · security: owned by review', 'not a date', 3],
    ['0 Critical · 0 Moderate', null, 0],
]);
