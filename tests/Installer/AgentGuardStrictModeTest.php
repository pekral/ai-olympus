<?php

declare(strict_types = 1);

use Tests\Support\AgentGuardRun;

/**
 * @return list<string>
 */
function agentGuardStderrLines(string $stderr): array
{
    return array_values(array_filter(explode("\n", $stderr), static fn (string $line): bool => $line !== ''));
}

test('strict mode stops the command with the reason on stderr when the confinement cannot be applied', function (): void {
    $root = AgentGuardRun::repository(['security' => ['strict-agent-guard' => true]]);

    try {
        $process = AgentGuardRun::runUnavailable($root, ['exec', 'leonardo', '--', 'echo x > target']);

        expect($process->getExitCode())->toBe(5)
            ->and(file_exists($root . '/target'))->toBeFalse()
            ->and($process->getErrorOutput())->toContain(
                'ai-olympus agent-guard: strict mode refused to run the command of leonardo without confinement: ',
            );
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('without strict mode the command runs and stderr carries exactly one protection-inactive line', function (array $manifest): void {
    $root = AgentGuardRun::repository($manifest);

    try {
        $process = AgentGuardRun::runUnavailable($root, ['exec', 'leonardo', '--', 'echo x > target']);
        $lines = agentGuardStderrLines($process->getErrorOutput());

        expect($process->getExitCode())->toBe(0)
            ->and(file_exists($root . '/target'))->toBeTrue()
            ->and($lines)->toHaveCount(1)
            ->and($lines[0])->toMatch('/^ai-olympus agent-guard: protection inactive \(.+\)$/');
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a string that is not the boolean true' => [['security' => ['strict-agent-guard' => 'true']]],
    'no manifest' => [[]],
    'strict mode false' => [['security' => ['strict-agent-guard' => false]]],
]);

test('strict mode set only in the working-tree composer.json has no effect on exec', function (): void {
    $root = AgentGuardRun::repository();
    file_put_contents($root . '/composer.json', (string) json_encode(['extra' => ['ai-olympus' => ['security' => ['strict-agent-guard' => true]]]]));
    AgentGuardRun::git($root, 'commit', '-q', '-am', 'propose strict mode');

    try {
        $process = AgentGuardRun::runUnavailable($root, ['exec', 'leonardo', '--', 'echo x > target']);

        expect($process->getExitCode())->toBe(0)
            ->and(file_exists($root . '/target'))->toBeTrue()
            ->and($process->getErrorOutput())->toContain('protection inactive');
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('a working-tree composer.json without strict mode does not lift strict mode of the default branch', function (): void {
    $root = AgentGuardRun::repository(['security' => ['strict-agent-guard' => true]]);
    file_put_contents($root . '/composer.json', '{}');

    try {
        $process = AgentGuardRun::runUnavailable($root, ['exec', 'leonardo', '--', 'echo x > target']);

        expect($process->getExitCode())->toBe(5)
            ->and(file_exists($root . '/target'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('strict mode makes the hook stop a Bash call it cannot decide', function (): void {
    $root = AgentGuardRun::repository(['security' => ['strict-agent-guard' => true]]);

    try {
        $process = AgentGuardRun::run($root, ['hook'], 'not json');

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('strict mode stopped this command');
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('without strict mode the hook lets a Bash call it cannot decide pass and says the protection is inactive', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::run($root, ['hook'], 'not json');

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain('protection inactive');
    } finally {
        AgentGuardRun::remove($root);
    }
});
