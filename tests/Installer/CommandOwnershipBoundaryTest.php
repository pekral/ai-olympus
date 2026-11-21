<?php

declare(strict_types = 1);

test('skills contain no team-role names', function (): void {
    $roles = '/\\b(krang|donatello|leonardo|splinter|michelangelo|raphael|april|casey|shredder)\\b/i';
    $files = packageTextFiles();

    foreach ($files as $path => $contents) {
        if (!str_starts_with($path, 'skills/')) {
            continue;
        }

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
