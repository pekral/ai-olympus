<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;
use Tests\Support\AgentGuardDecision;
use Tests\Support\AgentGuardRun;

/**
 * @param array<string, mixed> $toolInput
 */
function agentGuardHookInput(?string $agentType, array $toolInput = ['command' => 'git status'], string $permissionMode = 'default'): string
{
    $input = ['hook_event_name' => 'PreToolUse', 'permission_mode' => $permissionMode, 'tool_name' => 'Bash', 'tool_input' => $toolInput];

    return json_encode($agentType === null ? $input : [...$input, 'agent_type' => $agentType], JSON_THROW_ON_ERROR);
}

function agentGuardHookDecision(string $root, string $input): AgentGuardDecision
{
    return AgentGuardDecision::fromHookOutput(AgentGuardRun::run($root, ['hook'], $input, ['CLAUDE_PROJECT_DIR' => $root])->getOutput());
}

function agentGuardExecPrefix(string $role, string $root): string
{
    return '/bin/bash \'' . AgentGuardRun::script() . '\' exec ' . $role . ' --project \'' . $root . '\' -- ';
}

/**
 * @param array<array-key, mixed> $permissions
 */
function agentGuardWriteSettings(string $path, array $permissions): void
{
    installerWriteFile($path, json_encode(['permissions' => $permissions], JSON_THROW_ON_ERROR));
}

test('the hook wraps the command of a confined role in the absolute exec prefix with the project', function (string $agentType, string $role): void {
    $root = AgentGuardRun::repository();

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput($agentType));

        expect($decision->command())->toStartWith(agentGuardExecPrefix($role, $root))
            ->and($decision->permissionDecision)->toBe('ask');
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
        $echo = str_replace(agentGuardExecPrefix('leonardo', $root), 'printf \'%s\' ', $wrapped);
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

test('the hook denies an original command that a supported Bash deny rule matches', function (string $settings, string $command): void {
    $root = AgentGuardRun::repository();
    $home = dirname($root) . '/home';
    agentGuardWriteSettings(str_replace(['{root}', '{home}'], [$root, $home], $settings), ['deny' => ['Bash(curl:*)', 'Bash(git push *)']]);

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput('leonardo', ['command' => $command]));

        expect($decision->permissionDecision)->toBe('deny')
            ->and($decision->updatedInput)->toBeNull();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a prefix rule in the project settings' => ['{root}/.claude/settings.json', 'curl https://example.test'],
    'a rule in the local settings, second segment' => ['{root}/.claude/settings.local.json', 'cd x && curl https://example.test'],
    'a rule in the user settings, behind a wrapper' => ['{home}/.claude/settings.json', 'FOO=1 timeout 5 curl https://example.test'],
    'a space-star rule behind a pipe' => ['{root}/.claude/settings.json', 'echo x | git push origin HEAD'],
]);

test(
    'the hook never allows a command that an ask rule matches or that a rule of an unsupported shape could match',
    function (array $permissions, string $command): void {
        $root = AgentGuardRun::repository();
        agentGuardWriteSettings($root . '/.claude/settings.json', $permissions);
    
        try {
            $decision = agentGuardHookDecision($root, agentGuardHookInput('splinter', ['command' => $command]));
    
            expect($decision->permissionDecision)->toBe('ask')
                ->and($decision->command())->toStartWith(agentGuardExecPrefix('splinter', $root));
        } finally {
            AgentGuardRun::remove($root);
        }
    },
)->with([
    'a deny rule with a star in the middle' => [['deny' => ['Bash(git * --force)']], 'git status'],
    'an ask rule' => [['ask' => ['Bash(gh pr merge:*)']], 'gh pr merge 5'],
    'an ask rule with a star in the middle' => [['ask' => ['Bash(rm -rf * now)']], 'ls'],
]);

test('the hook asks for a confined command that no allow rule matches', function (string $command): void {
    $root = AgentGuardRun::repository();
    agentGuardWriteSettings($root . '/.claude/settings.json', ['deny' => ['Bash(curl:*)', 'Bash(git push:*)'], 'allow' => ['Bash(gh pr view:*)']]);

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput('april', ['command' => $command]));

        expect($decision->permissionDecision)->toBe('ask')
            ->and($decision->command())->toStartWith(agentGuardExecPrefix('april', $root));
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a command no rule names' => ['gh pr merge 1 --rebase'],
    'a destructive API call' => ['gh api -X DELETE repos/o/r/git/refs/heads/main'],
    'a push past the deny rule' => ['git -C . push origin HEAD:main'],
    'one allowed segment beside an unmatched one' => ['gh pr view 5 && rm -rf ~/Documents'],
    'a write into a shell startup file' => ['echo x >> ~/.zshrc'],
]);

test('the hook allows a confined command only when an allow rule matches every segment', function (): void {
    $root = AgentGuardRun::repository();
    agentGuardWriteSettings($root . '/.claude/settings.json', ['deny' => ['Bash(curl:*)'], 'allow' => ['Bash(gh pr view:*)', 'Bash(git status)']]);

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput('april', ['command' => 'gh pr view 5 && git status']));

        expect($decision->permissionDecision)->toBe('allow');
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('the hook denies a deny-rule command hidden in a construct it splits', function (string $command): void {
    $root = AgentGuardRun::repository();
    agentGuardWriteSettings($root . '/.claude/settings.json', ['deny' => ['Bash(curl:*)']]);

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput('leonardo', ['command' => $command], 'bypassPermissions'));

        expect($decision->permissionDecision)->toBe('deny');
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a backtick' => ['echo `curl https://example.test`'],
    'a command substitution' => ['echo $(curl https://example.test)'],
    'a negation' => ['! curl https://example.test'],
    'a subshell' => ['(curl https://example.test)'],
    'an if clause' => ['if curl https://example.test; then :; fi'],
]);

test('the hook asks for a construct it cannot split even when an allow rule matches its first word', function (string $command): void {
    $root = AgentGuardRun::repository();
    agentGuardWriteSettings($root . '/.claude/settings.json', ['allow' => ['Bash(echo:*)']]);

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput('leonardo', ['command' => $command]));

        expect($decision->permissionDecision)->toBe('ask');
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a command substitution' => ['echo $(id -u)'],
    'a for loop' => ['for f in a b; do echo $f; done'],
    'a subshell' => ['(echo x)'],
]);

test('the hook allows into the confinement in bypassPermissions mode and still denies a deny-rule command', function (string $command, string $expected): void {
    $root = AgentGuardRun::repository();
    agentGuardWriteSettings($root . '/.claude/settings.json', ['deny' => ['Bash(curl:*)']]);

    try {
        $decision = agentGuardHookDecision($root, agentGuardHookInput('shredder', ['command' => $command], 'bypassPermissions'));

        expect($decision->permissionDecision)->toBe($expected);
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a command no rule matches' => ['git log -1', 'allow'],
    'a deny-rule command' => ['curl https://example.test', 'deny'],
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
