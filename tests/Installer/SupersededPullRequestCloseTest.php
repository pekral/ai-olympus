<?php

declare(strict_types = 1);

function supersededPrSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function supersededPrConsentRow(string $startsWith): string
{
    $section = installerDocsSection(
        supersededPrSource('rules/compound-engineering/orchestration.md'),
        '## Externally-visible actions & consent levels',
    );

    foreach (explode("\n", $section) as $line) {
        if (str_starts_with($line, $startsWith)) {
            return $line;
        }
    }

    return '';
}

test('the consent table names krang as the only agent that closes a superseded pull request, with its level and gate', function (): void {
    $row = supersededPrConsentRow('| `krang` (or the top-level session in the `krang` role) | Close a pull request that a `<branch>-vN`');

    expect($row)->not->toBe('')
        ->and($row)->toContain('after a pointer comment on it that names the new pull request')
        ->and($row)->toContain('| L1 |')
        ->and($row)->toContain('the one owner of the `-vN` path')
        ->and($row)->toContain('L1 inside an orchestrated run that already owns the work unit')
        ->and($row)->toContain('The comment goes through `upsert-comment.sh` (`agent-note`)')
        ->and($row)->toContain('the close through `skills/_shared/audit-trail.sh run --`')
        ->and($row)->toContain('It is the only agent that closes a superseded pull request, and it closes no other pull request');
});

test('the consent table forbids every helper agent to close a pull request', function (): void {
    $row = supersededPrConsentRow('| `donatello` and every other helper agent | Close a pull request |');

    expect($row)->not->toBe('')
        ->and($row)->toContain('forbidden — no level')
        ->and($row)->toContain('hands the number of the superseded one back as `Superseded PR`')
        ->and($row)->toContain('closing it is a delivery decision of `krang`');
});

test('donatello never closes a pull request and hands the superseded number back', function (): void {
    $donatello = supersededPrSource('agents/donatello.md');

    expect($donatello)->toContain('**You never close a pull request**, and `gh pr close` is not in your grant.')
        ->and($donatello)->toContain('you open the new pull request and hand the number of the replaced one back as `Superseded PR` in your handoff')
        ->and($donatello)->toContain('`krang` alone closes it')
        ->and($donatello)->toContain('- **Superseded PR:** the number of the pull request that your `<branch>-vN` pull request replaces, or `none`.');
});

test('the -vN path has one owner, and the other files point to it', function (): void {
    $batch = supersededPrSource('skills/_shared/orchestration/batch-preparation.md');
    $krang = supersededPrSource('agents/krang.md');
    $orchestration = supersededPrSource('rules/compound-engineering/orchestration.md');

    expect($batch)->toContain('**The close belongs to the root session.**')
        ->and($batch)->toContain('This is the one description of the `-vN` path, and every other file points here.')
        ->and($batch)->toContain('It never closes a pull request.')
        ->and($batch)->toContain(
            'AUDIT_TRAIL_FILE=<abs .audit path> AUDIT_TRAIL_ROLE=krang skills/_shared/audit-trail.sh run -- gh pr close <replaced number>',
        )
        ->and($batch)->toContain('A new pull request that is not open leaves the replaced one open.')
        ->and($batch)->not->toContain('This file grants no `gh pr close`')
        ->and($orchestration)->toContain('and the next row closes the replaced one')
        ->and($krang)->toContain('the one `gh pr close <N>` of a pull request that a `<branch>-vN` pull request supersedes')
        ->and($krang)->not->toContain('batch-preparation.md')
        ->and(strlen($krang))->toBeLessThanOrEqual(135_000 - 1);
});

test('no agent definition other than krang and donatello mentions closing a pull request', function (): void {
    $mentions = [];

    $globResult = glob(dirname(__DIR__, 2) . '/agents/*.md');
    $agentFiles = $globResult !== false ? $globResult : [];

    foreach ($agentFiles as $file) {
        if (str_contains((string) file_get_contents($file), 'gh pr close')) {
            $mentions[] = basename($file);
        }
    }

    expect($mentions)->toBe(['donatello.md', 'krang.md']);
});
