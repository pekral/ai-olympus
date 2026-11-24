<?php

declare(strict_types = 1);

use Pekral\AiOlympus\InstallerAgentGuard;
use Symfony\Component\Process\Process;
use Tests\Support\AgentGuardRun;

/**
 * @return list<string>
 */
function agentGuardProbeLeftovers(string $root): array
{
    $tree = glob($root . '/.ai-olympus-guard-probe-*');
    $gitDir = glob($root . '/.git/ai-olympus-guard-probe-*');

    return [...($tree === false ? [] : $tree), ...($gitDir === false ? [] : $gitDir)];
}

test('probe reports an inactive protection when its write into the working tree succeeds', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::run($root, ['probe']);

        expect($process->getOutput())->toBe("protection=inactive reason=working-tree-writable strict=false\n")
            ->and(agentGuardProbeLeftovers($root))->toBe([]);
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('probe reports active only when both the working-tree write and the git-dir write are refused', function (int $gitDirMode, string $expected): void {
    $root = AgentGuardRun::repository();
    chmod($root, 0555);
    chmod($root . '/.git', $gitDirMode);

    try {
        $process = AgentGuardRun::run($root, ['probe']);

        expect($process->getOutput())->toBe($expected);
    } finally {
        chmod($root, 0755);
        chmod($root . '/.git', 0755);
        $leftovers = agentGuardProbeLeftovers($root);
        AgentGuardRun::remove($root);
    }

    expect($leftovers)->toBe([]);
})->with([
    'both refused' => [0555, "protection=active\n"],
    'only the working tree refused' => [0755, "protection=inactive reason=git-dir-writable strict=false\n"],
])->skip(posix_geteuid() === 0, 'a superuser writes into a directory of mode 0555');

test('probe names strict mode of the default branch on an inactive protection', function (): void {
    $root = AgentGuardRun::repository(['security' => ['strict-agent-guard' => true]]);

    try {
        $process = AgentGuardRun::run($root, ['probe']);

        expect($process->getOutput())->toBe("protection=inactive reason=working-tree-writable strict=true\n");
    } finally {
        AgentGuardRun::remove($root);
    }
});

test('probe reports an active protection inside the confinement and leaves no probe file', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::exec($root, 'leonardo', escapeshellarg(AgentGuardRun::script()) . ' probe');

        expect($process->getOutput())->toBe("protection=active\n")
            ->and(agentGuardProbeLeftovers($root))->toBe([]);
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(!AgentGuardRun::confinementAvailable(), 'this host cannot apply the confinement');

test('probe outside a git checkout reports the protection as inactive', function (): void {
    $root = AgentGuardRun::repository();
    $outside = dirname($root) . '/home';

    try {
        $process = new Process(['bash', AgentGuardRun::script(), 'probe'], $outside, ['HOME' => $outside]);
        $process->run();

        expect($process->getOutput())->toBe("protection=inactive reason=not-a-git-checkout strict=false\n");
    } finally {
        AgentGuardRun::remove($root);
    }
});

test(
    'verify accepts the installed guard only when its hash matches the install record and the hook handler exists',
    function (string $record, string $settings, string $expected): void {
        $root = AgentGuardRun::repository();
        $hash = hash_file('sha256', $root . '/.claude/skills/_shared/agent-guard.sh');
        installerWriteFile($root . '/.git/ai-olympus/installed-copies.sha256', str_replace('{hash}', (string) $hash, $record));
        file_put_contents($root . '/.claude/settings.local.json', $settings);
    
        try {
            $process = AgentGuardRun::run($root, ['verify']);
    
            expect($process->getOutput())->toBe($expected);
        } finally {
            AgentGuardRun::remove($root);
        }
    },
)->with([
    'a changed installed copy' => [
        str_repeat('0', 64) . "  .claude/skills/_shared/agent-guard.sh\n",
        '{}',
        "agent-guard=refused reason=hash-mismatch\n",
    ],
    'a removed hook handler' => [
        "{hash}  .claude/skills/_shared/agent-guard.sh\n",
        '{}',
        "agent-guard=refused reason=no-hook-handler\n",
    ],
    'verified' => [
        "{hash}  .claude/skills/_shared/agent-guard.sh\n",
        (string) json_encode(
            ['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => InstallerAgentGuard::HOOK_COMMAND]]]]]],
        ),
        "agent-guard=verified\n",
    ],
]);
