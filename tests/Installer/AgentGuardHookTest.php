<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;
use Tests\Support\AgentGuardDecision;
use Tests\Support\AgentGuardRun;

/**
 * @param array<string, mixed> $toolInput
 */
function agentGuardHookInput(?string $agentType, array $toolInput = ['command' => 'git status']): string
{
    $input = ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Bash', 'tool_input' => $toolInput];

    return json_encode($agentType === null ? $input : [...$input, 'agent_type' => $agentType], JSON_THROW_ON_ERROR);
}

function agentGuardHookDecision(string $root, string $input): AgentGuardDecision
{
    return AgentGuardDecision::fromHookOutput(AgentGuardRun::run($root, ['hook'], $input, ['CLAUDE_PROJECT_DIR' => $root])->getOutput());
}

test('the hook wraps the command of a confined role in the absolute exec prefix', function (string $agentType, string $role): void {
    $root = AgentGuardRun::repository();

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput($agentType));

        expect($decision->command())->toStartWith('\'' . AgentGuardRun::script() . '\' exec ' . $role . ' -- ')
            ->and($decision->permissionDecision)->toBe('allow');
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a plugin agent' => ['ai-olympus:leonardo', 'leonardo'],
    'april' => ['april', 'april'],
    'casey' => ['casey', 'casey'],
    'krang' => ['krang', 'krang'],
    'leonardo' => ['leonardo', 'leonardo'],
    'shredder' => ['shredder', 'shredder'],
    'splinter' => ['splinter', 'splinter'],
]);

test('the hook leaves a writing role and the main session alone', function (?string $agentType): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::run($root, ['hook'], agentGuardHookInput($agentType), ['CLAUDE_PROJECT_DIR' => $root]);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toBe('');
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'an empty agent type' => [''],
    'donatello' => ['donatello'],
    'michelangelo' => ['michelangelo'],
    'no agent type' => [null],
    'raphael' => ['raphael'],
]);

test('the wrapped input keeps every other field of the tool input unchanged', function (): void {
    $root = AgentGuardRun::repository();
    $toolInput = ['command' => 'git log -1', 'description' => 'Show the last commit', 'timeout' => 120_000, 'run_in_background' => true];

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput('leonardo', $toolInput));
        expect($decision->otherFields())->toBe(['description' => 'Show the last commit', 'timeout' => 120_000, 'run_in_background' => true]);
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('the wrapped command runs the original command word for word', function (string $command): void {
    $root = AgentGuardRun::repository();

    try {
        $wrapped = agentGuardHookDecision($root, agentGuardHookInput('leonardo', ['command' => $command]))->command();
        $echo = str_replace('\'' . AgentGuardRun::script() . '\' exec leonardo -- ', 'printf \'%s\' ', $wrapped);
        $process = new Process(['sh', '-c', $echo]);
        $process->run();

        expect($process->getOutput())->toBe($command);
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a newline' => ["printf a\nprintf b"],
    'expansions' => ['echo $HOME $(id -u) `date`'],
    'single quotes' => ['echo \'it\'\'s\' && echo "a"'],
]);

test('the hook ignores a tool other than Bash', function (): void {
    $root = AgentGuardRun::repository();
    $input = (string) json_encode(['tool_name' => 'Read', 'agent_type' => 'leonardo', 'tool_input' => ['file_path' => '/x']]);

    try {
        $process = AgentGuardRun::run($root, ['hook'], $input, ['CLAUDE_PROJECT_DIR' => $root]);

        expect($process->getOutput())->toBe('');
    } finally {
        AgentGuardRun::remove($root);
    }
});
