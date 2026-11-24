<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function agentGuardPackageFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('every confined agent runs the probe first and reports its protection in the handoff', function (string $agent): void {
    $definition = agentGuardPackageFile('agents/' . $agent . '.md');
    $section = installerDocsSection($definition, '## Runtime protection');

    expect($section)->toContain('Run `skills/_shared/agent-guard.sh probe` as the first command of every run, before any other command')
        ->and($section)->toContain('`**Protection:** active` or `**Protection:** inactive (<reason>)`')
        ->and(strpos($definition, '## Runtime protection'))->toBeLessThan((int) strpos($definition, '## Bash boundary'));
})->with(['leonardo', 'splinter', 'april', 'casey', 'shredder', 'krang']);

test('every confined Codex adapter runs the probe and reports its protection', function (string $agent): void {
    $adapter = agentGuardPackageFile('codex/agents/' . $agent . '.toml');

    expect($adapter)->toContain('runs `.agents/skills/_shared/agent-guard.sh probe` and reports `**Protection:**`')
        ->and($adapter)->toContain('only in a session started with `--sandbox read-only`');
})->with(['leonardo', 'splinter', 'april', 'casey', 'shredder', 'krang']);

test('every confined agent stops when strict mode meets an inactive protection', function (string $file): void {
    expect(agentGuardPackageFile($file))->toContain('Blocked: runtime protection inactive — <reason>');
})->with([
    'april' => ['agents/april.md'],
    'casey' => ['agents/casey.md'],
    'krang' => ['agents/krang.md'],
    'krang on Codex' => ['codex/agents/krang.toml'],
    'leonardo' => ['agents/leonardo.md'],
    'leonardo on Codex' => ['codex/agents/leonardo.toml'],
    'shredder' => ['agents/shredder.md'],
    'splinter' => ['agents/splinter.md'],
]);

test('no writing role carries the runtime protection step', function (string $agent): void {
    expect(agentGuardPackageFile('agents/' . $agent . '.md'))->not->toContain('## Runtime protection');
})->with(['donatello', 'michelangelo', 'raphael']);

test('the boundary rule says an agent stopped by the guard reports Blocked and never retries another way', function (): void {
    $boundary = installerDocsSection(agentGuardPackageFile('rules/compound-engineering/orchestration.md'), '## Bash capability boundary');

    expect($boundary)->toContain('**An agent stopped by the guard reports `Blocked` and never retries the action through another tool or interpreter.**');
});

test('the boundary rule names the runtime agent guard and no longer says the package ships no runtime component', function (): void {
    $boundary = installerDocsSection(agentGuardPackageFile('rules/compound-engineering/orchestration.md'), '## Bash capability boundary');

    expect($boundary)->toContain('**The runtime agent guard — the enforced layer for the read-only roles (issue #695).**')
        ->and($boundary)->not->toContain('this instructions-only package deliberately does not ship')
        ->and($boundary)->not->toContain('this package ships instructions, never runtime code');
});

test('krang renders one runtime protection line per dispatched confined role', function (): void {
    $data = json_encode([
        'status' => 'completed',
        'summary' => 'Reviewed and merged.',
        'runtime_protection' => [
            ['role' => 'leonardo', 'protection' => 'active'],
            ['role' => 'splinter', 'protection' => 'active'],
            ['role' => 'april', 'protection' => 'inactive (bwrap is not installed)'],
        ],
    ], JSON_THROW_ON_ERROR);
    $process = new Process(['bash', dirname(__DIR__, 2) . '/skills/_shared/render-report.sh', '--data', '-']);
    $process->setInput($data);
    $process->run();

    $lines = array_values(
        array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => str_starts_with($line, '- Runtime protection: ')),
    );

    expect($lines)->toBe([
        '- Runtime protection: leonardo — active',
        '- Runtime protection: splinter — active',
        '- Runtime protection: april — inactive (bwrap is not installed)',
    ])->and(agentGuardPackageFile('agents/krang.md'))->toContain('so the completion report carries one `Runtime protection:` line per role');
});

test('SECURITY.md carries one section per platform and every residual of the guard', function (): void {
    $guard = installerDocsSection(agentGuardPackageFile('SECURITY.md'), '## Runtime agent guard');

    expect($guard)->toContain('### Claude Code — what is enforced')
        ->and($guard)->toContain('### Codex — what is not enforced')
        ->and($guard)->toContain('### Strict mode')
        ->and($guard)->toContain('### What stays outside the guard')
        ->and($guard)->toContain('On Codex the read-only limit of a role inside a writing session stays **advisory**.')
        ->and($guard)->toContain('Run `vendor/bin/ai-olympus install --no-agent-guard`.')
        ->and($guard)->toContain('**The hook never allows a command only because no deny rule matched it.**')
        ->and($guard)->toContain('**The cost:** a confined role asks the user for every command that no allow rule of the settings files covers')
        ->and($guard)->toContain('**The git object store is not writable.**');

    $residuals = [
        '1. **Codex**',
        '2. **The main session without `--agent`**',
        '3. **A hook that does not run:**',
        '4. **A host without confinement:**',
        '5. **A nested sandbox:**',
        '6. **The network.**',
        '7. **Inter-process channels',
        '8. **Linux only:**',
        '9. **A writing role or the main session can change the guard.**',
        '10. **A changed `composer.lock`**',
        '11. **macOS only: a process left running after the command.**',
        '12. **`.claude/run/**` stays writable**',
    ];

    foreach ($residuals as $residual) {
        expect($guard)->toContain($residual);
    }
});

test('the manifest table documents the strict-mode key and that only the default branch sets it', function (): void {
    $manifest = installerDocsSection(agentGuardPackageFile('rules/general/general.md'), '## Project manifest');

    expect($manifest)->toContain('| `security.strict-agent-guard` | bool |')
        ->and($manifest)->toContain('The guard reads it from the default branch only, through `skills/_shared/read-manifest.sh`.');
});
