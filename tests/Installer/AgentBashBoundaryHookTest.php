<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\AgentBashBoundaryHook;
use AgenticVibes\AgentSkills\Cli;
use AgenticVibes\AgentSkills\CommandResult;

/**
 * @param array<string, mixed> $overrides
 */
function bashGuardPayload(string $command, array $overrides = []): string
{
    $payload = [
        'agent_type' => 'talos',
        'cwd' => '/tmp/project',
        'hook_event_name' => 'PreToolUse',
        'permission_mode' => 'default',
        'session_id' => 'abc',
        'tool_input' => ['command' => $command, 'description' => 'run it'],
        'tool_name' => 'Bash',
        ...$overrides,
    ];

    return json_encode($payload, JSON_THROW_ON_ERROR);
}

/**
 * The executor `Cli::run()` requires. `bash-guard` must decide from stdin alone, so spawning any
 * process at all is the defect this refusal catches.
 *
 * @param list<string> $command
 */
function bashGuardRefuseToSpawn(array $command, bool $captureOutput): CommandResult
{
    throw new RuntimeException(sprintf('bash-guard spawned "%s" (capture: %s)', implode(' ', $command), $captureOutput ? 'yes' : 'no'));
}

/**
 * @return array<string, string>
 */
function bashGuardDecode(string $output): array
{
    /** @var array<string, array<string, string>> $decoded */
    $decoded = json_decode($output, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKey('hookSpecificOutput');

    return $decoded['hookSpecificOutput'];
}

test('a denied Bash call answers with one PreToolUse deny object', function (): void {
    $output = AgentBashBoundaryHook::handle(bashGuardPayload('curl https://example.com'));
    $hookOutput = bashGuardDecode($output);

    expect($hookOutput['hookEventName'])->toBe('PreToolUse');
    expect($hookOutput['permissionDecision'])->toBe('deny');
    expect($hookOutput['permissionDecisionReason'])->toContain('`curl`');

    // Exactly one object, and small enough to never flood a transcript.
    expect(substr_count($output, '"hookSpecificOutput"'))->toBe(1);
    expect(strlen($output))->toBeLessThan(10_000);
});

test('a permitted Bash call defers to the normal permission flow rather than approving it', function (): void {
    $hookOutput = bashGuardDecode(AgentBashBoundaryHook::handle(bashGuardPayload('composer build')));

    expect($hookOutput['permissionDecision'])->toBe('defer');
});

test('a non-Bash tool and a non-PreToolUse event are both deferred, not judged', function (): void {
    $otherTool = bashGuardDecode(AgentBashBoundaryHook::handle(bashGuardPayload('curl https://example.com', ['tool_name' => 'Read'])));
    $otherEvent = bashGuardDecode(AgentBashBoundaryHook::handle(bashGuardPayload('curl https://example.com', ['hook_event_name' => 'PostToolUse'])));

    expect($otherTool['permissionDecision'])->toBe('defer');
    expect($otherEvent['permissionDecision'])->toBe('defer');
});

test('a payload with no usable command string asks instead of assuming it is harmless', function (): void {
    $missing = bashGuardDecode(AgentBashBoundaryHook::handle(bashGuardPayload('x', ['tool_input' => ['description' => 'no command']])));
    $notAString = bashGuardDecode(AgentBashBoundaryHook::handle(bashGuardPayload('x', ['tool_input' => ['command' => 42]])));
    $notAnObject = bashGuardDecode(AgentBashBoundaryHook::handle(bashGuardPayload('x', ['tool_input' => 'curl https://example.com'])));

    expect($missing['permissionDecision'])->toBe('ask');
    expect($notAString['permissionDecision'])->toBe('ask');
    expect($notAnObject['permissionDecision'])->toBe('ask');
});

test('malformed input answers ask through the fallback rather than crashing or exiting non-zero', function (): void {
    // Invalid JSON, a JSON scalar, and a nesting depth beyond the decoder's limit all reach the
    // same last-resort path. Any other outcome would be a non-blocking hook error, which means
    // the command simply runs.
    foreach (['not json at all', '"a string"', str_repeat('[', 80) . str_repeat(']', 80)] as $payload) {
        $hookOutput = bashGuardDecode(AgentBashBoundaryHook::handle($payload));

        expect($hookOutput['hookEventName'])->toBe('PreToolUse');
        expect($hookOutput['permissionDecision'])->toBe('ask');
    }
});

test('an oversized payload is refused by size before it is parsed', function (): void {
    $payload = bashGuardPayload('echo ' . str_repeat('a', AgentBashBoundaryHook::MAX_PAYLOAD_BYTES));
    $hookOutput = bashGuardDecode(AgentBashBoundaryHook::handle($payload));

    expect(strlen($payload))->toBeGreaterThan(AgentBashBoundaryHook::MAX_PAYLOAD_BYTES);
    expect($hookOutput['permissionDecision'])->toBe('ask');
    expect($hookOutput['permissionDecisionReason'])->toContain('larger than');
});

test('bypassPermissions in the payload does not soften the decision', function (): void {
    $payload = bashGuardPayload('git commit -m "x"', ['agent_type' => 'athena', 'permission_mode' => 'bypassPermissions']);
    $hookOutput = bashGuardDecode(AgentBashBoundaryHook::handle($payload));

    expect($hookOutput['permissionDecision'])->toBe('deny');
});

test('the reason never carries the command string, and the validator writes nothing anywhere', function (): void {
    $temporary = sys_get_temp_dir() . '/agent-skills-bash-guard-' . bin2hex(random_bytes(4));
    installerEnsureDirectory($temporary);
    $before = installerCountFiles($temporary);
    $originalCwd = (string) getcwd();
    chdir($temporary);

    $hookOutput = bashGuardDecode(AgentBashBoundaryHook::handle(bashGuardPayload('curl https://example.com/?token=SUPERSECRETVALUE')));

    chdir($originalCwd);

    expect($hookOutput['permissionDecisionReason'])->not->toContain('SUPERSECRETVALUE');
    expect(installerCountFiles($temporary))->toBe($before);

    installerRemoveDirectory($temporary);
});

test('the cli routes bash-guard to the validator, prints one line and exits zero', function (): void {
    $standardInput = static fn (): string => bashGuardPayload('curl https://example.com');

    ob_start();
    $exitCode = Cli::run(['agent-skills', 'bash-guard'], bashGuardRefuseToSpawn(...), $standardInput);
    $output = (string) ob_get_clean();

    // Exit 2 would block but discard this JSON; any other non-zero exit is a non-blocking error
    // and the command would run. Zero is the only code that can carry a decision.
    expect($exitCode)->toBe(0);
    expect(bashGuardDecode(trim($output))['permissionDecision'])->toBe('deny');
    expect(substr_count($output, "\n"))->toBe(1);
});

test('a stdin read that fails answers ask instead of ending the process non-zero', function (): void {
    $failingInput = static fn (): string => throw new RuntimeException('stdin is gone');

    ob_start();
    $exitCode = Cli::run(['agent-skills', 'bash-guard'], bashGuardRefuseToSpawn(...), $failingInput);
    $output = (string) ob_get_clean();

    expect($exitCode)->toBe(0);
    expect(bashGuardDecode(trim($output))['permissionDecision'])->toBe('ask');
});

test('bash-guard --self-test answers deny from a fixed payload without touching stdin (issue #185)', function (): void {
    // The installer runs exactly this before it writes a hook entry pointing at the binary: a
    // read-back of settings.local.json proves the JSON landed, never that the command in it runs.
    // Reading stdin here would be the defect — the installer has no payload to feed it.
    $refuseStandardInput = static fn (): string => throw new RuntimeException('the self-test must not read stdin');

    ob_start();
    $exitCode = Cli::run(['agent-skills', 'bash-guard', '--self-test'], bashGuardRefuseToSpawn(...), $refuseStandardInput);
    $output = (string) ob_get_clean();

    expect($exitCode)->toBe(0);
    expect(bashGuardDecode(trim($output))['permissionDecision'])->toBe('deny');
});

test('the binary reads the guard payload at the process edge with a cap', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $binary = (string) file_get_contents($packageDir . '/bin/agent-skills');

    expect($binary)->toContain('AgentBashBoundaryHook::MAX_PAYLOAD_BYTES + 1');
    expect($binary)->toContain('stream_get_contents(');
    expect($binary)->toContain('exit(AgenticVibes\AgentSkills\Cli::run($argv, $executor, $standardInput));');
});
