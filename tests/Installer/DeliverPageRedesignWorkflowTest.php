<?php

declare(strict_types = 1);

test('deliver-page-redesign is one shared workflow for Claude Code and Codex', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skillPath = $packageDir . '/skills/deliver-page-redesign/SKILL.md';
    $commandPath = $packageDir . '/commands/redesign-page.md';

    expect(is_file($skillPath))->toBeTrue();
    expect(is_file($commandPath))->toBeTrue();

    $skill = (string) file_get_contents($skillPath);
    $command = (string) file_get_contents($commandPath);

    expect($skill)->toContain('name: deliver-page-redesign');
    expect($skill)->toContain('Delegate the orchestration to `splinter`');
    expect($skill)->toContain('never merges the pull request');

    expect($command)->toContain('argument-hint: [page URL]');
    expect($command)->toContain('@skills/deliver-page-redesign/SKILL.md');
    expect($command)->toContain('$ARGUMENTS');

    // Codex carries no user-defined slash command, so the command names the skill mention that is
    // the Codex entry point. The skill name differs from the command name so Claude Code does not
    // list two identical-looking entries in the / menu.
    expect($command)->toContain('$deliver-page-redesign');
});

test('deliver-page-redesign routes through the redesign stage and a mandatory interactive walkthrough', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/deliver-page-redesign/SKILL.md');
    $planner = (string) file_get_contents($packageDir . '/skills/_shared/plan-route.sh');

    expect($skill)->toContain('passes `--redesign` and `--runtime-acceptance` to `skills/_shared/plan-route.sh`');
    expect($skill)->toContain('passes `--tracker no`');
    expect($skill)->toContain('@skills/page-redesign/SKILL.md');
    expect($skill)->toContain('@skills/interactive-testing/SKILL.md');
    expect($skill)->toContain('the `raphael` pass is never skipped');
    expect($skill)->toContain('When no interactive browser is available, the run reports `Blocked`');
    expect($skill)->toContain('Keep the main layout shell');

    // The skill passes these flags to the planner, so the planner must accept each of them.
    expect($planner)->toContain('--redesign)');
    expect($planner)->toContain('--runtime-acceptance)');
    expect($planner)->toContain('--tracker)');
});
