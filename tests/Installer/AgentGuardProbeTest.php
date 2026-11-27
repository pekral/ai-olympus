<?php

declare(strict_types = 1);

use Pekral\AiOlympus\InstallerAgentGuard;
use Tests\Support\AgentGuardRun;

test('a probe cannot infer full protection from the filesystem permissions of a checkout', function (string $platform): void {
    $root = AgentGuardRun::repository();
    chmod($root, 0555);
    chmod($root . '/.git', 0555);

    try {
        $process = AgentGuardRun::run($root, ['probe', $platform]);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toStartWith('protection=inactive ')
            ->and($process->getOutput())->toContain('strict=false blocked=false');
    } finally {
        chmod($root, 0755);
        chmod($root . '/.git', 0755);
        AgentGuardRun::remove($root);
    }
})->with(['claude', 'codex']);

test('verified Bash confinement still does not attest to the other tools in a Claude session', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::exec($root, 'leonardo', escapeshellarg(AgentGuardRun::script()) . ' probe claude');

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toBe("protection=inactive reason=claude-full-tool-isolation-unverified strict=false blocked=false\n");
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(!AgentGuardRun::confinementAvailable(), 'this host cannot apply the Bash confinement');

test('verify checks the installed files and both exact hook configurations', function (string $change, string $expected): void {
    $root = AgentGuardRun::repository();
    $shared = $root . '/.claude/skills/_shared/';
    $reader = $shared . 'read-manifest.sh';
    file_put_contents($reader, 'policy reader');
    installerWriteFile($root . '/.git/ai-olympus/installed-copies.sha256', sprintf(
        "%s  .claude/skills/_shared/agent-guard.sh\n%s  .claude/skills/_shared/read-manifest.sh\n",
        hash_file('sha256', $shared . 'agent-guard.sh'),
        hash_file('sha256', $reader),
    ));

    try {
        InstallerAgentGuard::register($root);
        $settingsPath = $root . '/.claude/settings.local.json';
        $settings = (string) file_get_contents($settingsPath);

        match ($change) {
            'guard' => file_put_contents($shared . 'agent-guard.sh', 'changed guard'),
            'reader' => file_put_contents($reader, 'changed reader'),
            'matcher' => file_put_contents($settingsPath, str_replace('"matcher": "*"', '"matcher": "Bash"', $settings)),
            'fail mode' => file_put_contents($settingsPath, str_replace('"onFailure": "block"', '"onFailure": "warn"', $settings)),
            'removed' => file_put_contents($settingsPath, '{}'),
            default => null,
        };

        $process = AgentGuardRun::run($root, ['verify']);

        expect($process->getExitCode())->toBe($expected === 'agent-guard=verified' ? 0 : 3)
            ->and(trim($process->getOutput()))->toBe($expected);
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'dependency hash changed' => ['reader', 'agent-guard=refused reason=unverified-installed-copies'],
    'fail mode changed' => ['fail mode', 'agent-guard=refused reason=unverified-hook-settings'],
    'guard hash changed' => ['guard', 'agent-guard=refused reason=unverified-installed-copies'],
    'handler removed' => ['removed', 'agent-guard=refused reason=unverified-hook-settings'],
    'matcher changed' => ['matcher', 'agent-guard=refused reason=unverified-hook-settings'],
    'verified' => ['', 'agent-guard=verified'],
]);
