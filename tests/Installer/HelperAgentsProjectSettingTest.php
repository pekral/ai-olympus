<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

/**
 * @return array{state: string, source: string}
 */
function helperAgentsSettingFromManifest(string $manifest): array
{
    $process = new Process([dirname(__DIR__, 2) . '/skills/_shared/resolve-project-switch.sh', '--key', 'helper-agents', '--stdin']);
    $process->setInput($manifest);
    $process->run();

    expect($process->getExitCode())->toBe(0);

    $lines = explode("\n", trim($process->getOutput()));

    return [
        'source' => $lines[1] ?? '',
        'state' => $lines[0] ?? '',
    ];
}

/**
 * Builds a repository whose default branch carries the given manifest in composer.json and runs the resolver in it.
 *
 * @return array{state: string, source: string}
 */
function helperAgentsSettingInRepository(string $defaultBranchManifest, ?string $workingTreeManifest = null): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-helper-agents-' . bin2hex(random_bytes(6));
    mkdir($directory);
    register_shutdown_function(static fn (): int => (new Process(['rm', '-rf', $directory]))->run());

    manifestRepositoryGit($directory, 'init', '-q');
    file_put_contents($directory . '/composer.json', $defaultBranchManifest . "\n");
    manifestRepositoryGit($directory, 'add', 'composer.json');
    manifestRepositoryGit($directory, 'commit', '-q', '-m', 'manifest');
    manifestRepositoryGit($directory, 'update-ref', 'refs/remotes/origin/master', 'HEAD');
    manifestRepositoryGit($directory, 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/master');

    if ($workingTreeManifest !== null) {
        file_put_contents($directory . '/composer.json', $workingTreeManifest . "\n");
    }

    $process = new Process(['bash', dirname(__DIR__, 2) . '/skills/_shared/resolve-project-switch.sh', '--key', 'helper-agents'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(0);

    $lines = explode("\n", trim($process->getOutput()));

    return [
        'source' => $lines[1] ?? '',
        'state' => $lines[0] ?? '',
    ];
}

test('helper agents are allowed by default, and only a JSON false forbids them', function (string $manifest, string $state, string $source): void {
    expect(helperAgentsSettingFromManifest($manifest))->toBe(['source' => $source, 'state' => $state]);
})->with([
    'array' => ['{"helper-agents":[]}', 'helper-agents=allowed', 'source=invalid'],
    'false' => ['{"helper-agents":false}', 'helper-agents=forbidden', 'source=manifest'],
    'no key' => ['{}', 'helper-agents=allowed', 'source=default'],
    'not an object' => ['[]', 'helper-agents=allowed', 'source=default'],
    'not json' => ['not json', 'helper-agents=allowed', 'source=default'],
    'null' => ['{"helper-agents":null}', 'helper-agents=allowed', 'source=invalid'],
    'number zero' => ['{"helper-agents":0}', 'helper-agents=allowed', 'source=invalid'],
    'object' => ['{"helper-agents":{}}', 'helper-agents=allowed', 'source=invalid'],
    'other switch off' => ['{"interactive-testing":false}', 'helper-agents=allowed', 'source=default'],
    'string false' => ['{"helper-agents":"false"}', 'helper-agents=allowed', 'source=invalid'],
    'string forbidden' => ['{"helper-agents":"forbidden"}', 'helper-agents=allowed', 'source=invalid'],
    'true' => ['{"helper-agents":true}', 'helper-agents=allowed', 'source=manifest'],
]);

test('only the manifest of the default branch decides, never the working tree', function (): void {
    $forbiddenByThePullRequest = helperAgentsSettingInRepository(
        '{"extra":{"ai-olympus":{}}}',
        '{"extra":{"ai-olympus":{"helper-agents":false}}}',
    );
    $forbiddenOnTheDefaultBranch = helperAgentsSettingInRepository(
        '{"extra":{"ai-olympus":{"helper-agents":false}}}',
        '{"extra":{"ai-olympus":{"helper-agents":true}}}',
    );

    expect($forbiddenByThePullRequest)->toBe(['source' => 'source=default', 'state' => 'helper-agents=allowed'])
        ->and($forbiddenOnTheDefaultBranch)->toBe(['source' => 'source=manifest', 'state' => 'helper-agents=forbidden']);
});

test('a manifest that cannot be parsed keeps helper agents allowed and is named', function (): void {
    expect(helperAgentsSettingInRepository('{ not json'))
        ->toBe(['source' => 'source=unreadable', 'state' => 'helper-agents=allowed']);
});

test('the resolver accepts only the keys of its list and passes the key to jq as an argument', function (string $key): void {
    $process = new Process([dirname(__DIR__, 2) . '/skills/_shared/resolve-project-switch.sh', '--key', $key, '--stdin']);
    $process->setInput('{"voice":false}');
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('');
})->with(['a jq path' => ['.'], 'a manifest key outside the list' => ['voice'], 'an empty key' => ['']]);

test('the resolver self-test passes, is registered, and the interactive-testing script reuses it', function (): void {
    $script = dirname(__DIR__, 2) . '/skills/_shared/resolve-project-switch.sh';
    $selfTest = new Process([$script, '--self-test']);
    $selfTest->run();

    expect($selfTest->getExitCode())->toBe(0)
        ->and(packageSource('composer.json'))->toContain('skills/_shared/resolve-project-switch.sh')
        ->and(packageSource('skills/_shared/resolve-interactive-testing.sh'))
        ->toContain('exec "$HERE/resolve-project-switch.sh" --key interactive-testing "$@"')
        ->and(packageSource('skills/_shared/resolve-project-switch.sh'))->toContain('jq -r --arg key "$key"');
});

test('the manifest table states the key, its default, and the resolver that reads it', function (): void {
    $rule = packageSource('rules/general/general.md');

    expect($rule)->toContain('| `helper-agents` | bool | Helper agents for the project (`skills/_shared/orchestration/helper-agents.md`).')
        ->and($rule)->toContain('`skills/_shared/resolve-project-switch.sh` reads it. Absent: `true`. |');
});
