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
                'ai-olympus agent-guard: Blocked: claude-full-tool-isolation-unverified',
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
            ->and($process->getErrorOutput())->toContain('Blocked');
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('an invalid hook payload is refused before a tool can run', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::run($root, ['hook'], 'not json');

        expect($process->getExitCode())->toBe(2)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain('Blocked');
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('strict mode makes the hook stop a Bash call when jq is missing', function (): void {
    $root = AgentGuardRun::repository(['security' => ['strict-agent-guard' => true]]);

    try {
        $process = AgentGuardRun::runWithoutJq($root, ['hook'], '{"tool_name":"Bash","agent_type":"leonardo","tool_input":{"command":"true"}}');

        expect($process->getExitCode())->toBe(2)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain('Blocked: jq is not installed');
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(PHP_OS_FAMILY === 'Linux' && AgentGuardRun::linuxBwrap() === '', 'hiding jq on Linux needs bwrap, which the CI workflow installs');

test('a missing jq leaves the policy unknown and blocks the role', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::runWithoutJq($root, ['probe', 'codex']);

        expect($process->getExitCode())->toBe(5)
            ->and($process->getOutput())->toBe("protection=inactive reason=trusted-policy-unavailable strict=unknown blocked=true\n");
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(PHP_OS_FAMILY === 'Linux' && AgentGuardRun::linuxBwrap() === '', 'hiding jq on Linux needs bwrap');

test('the Codex preflight distinguishes optional and strict mixed sessions before dispatch', function (bool $strict, int $expectedStatus): void {
    $root = AgentGuardRun::repository(['security' => ['strict-agent-guard' => $strict]]);

    try {
        $result = AgentGuardRun::run($root, ['preflight', 'codex', 'leonardo']);
        $decision = $strict ? 'true' : 'false';

        expect($result->getExitCode())->toBe($expectedStatus)
            ->and($result->getOutput())->toBe("protection=inactive reason=codex-session-isolation-unverified strict=$decision blocked=$decision\n");
    } finally {
        AgentGuardRun::remove($root);
    }
})->with(['default fallback' => [false, 0], 'strict block' => [true, 5]]);

test('a tool Git environment cannot substitute another fixture repository for the default-branch policy', function (): void {
    $project = AgentGuardRun::repository(['security' => ['strict-agent-guard' => true]]);
    $other = AgentGuardRun::repository();

    try {
        $result = AgentGuardRun::run($project, ['preflight', 'codex', 'leonardo'], environment: [
            'GIT_DIR' => $other . '/.git',
            'GIT_WORK_TREE' => $other,
        ]);

        expect($result->getExitCode())->toBe(5)
            ->and($result->getOutput())->toContain('strict=true blocked=true');
    } finally {
        AgentGuardRun::remove($project);
        AgentGuardRun::remove($other);
    }
});

test('unreadable or invalid default-branch policy cannot start an unprotected command', function (string $policy): void {
    $root = AgentGuardRun::repository();

    try {
        if ($policy === '') {
            AgentGuardRun::git($root, 'symbolic-ref', '--delete', 'refs/remotes/origin/HEAD');
        } else {
            file_put_contents($root . '/composer.json', $policy);
            AgentGuardRun::git($root, 'commit', '-qam', 'set policy');
            AgentGuardRun::git($root, 'update-ref', 'refs/remotes/origin/master', 'HEAD');
        }
        $process = AgentGuardRun::exec($root, 'leonardo', 'touch target');

        expect($process->getExitCode())->toBe(5)
            ->and($process->getErrorOutput())->toContain('trusted-policy-unavailable')
            ->and(file_exists($root . '/target'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'missing default ref' => [''],
    'invalid JSON' => ['{'],
    'non-boolean policy' => ['{"extra":{"ai-olympus":{"security":{"strict-agent-guard":"false"}}}}'],
]);
