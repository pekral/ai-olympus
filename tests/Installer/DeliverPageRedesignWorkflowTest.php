<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

test('deliver-page-redesign is one shared workflow for Claude Code and Codex', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skillPath = $packageDir . '/skills/deliver-page-redesign/SKILL.md';
    $commandPath = $packageDir . '/commands/redesign-page.md';

    expect(is_file($skillPath))->toBeTrue();
    expect(is_file($commandPath))->toBeTrue();

    $skill = (string) file_get_contents($skillPath);
    $command = (string) file_get_contents($commandPath);

    expect($skill)->toContain('name: deliver-page-redesign');
    expect($skill)->toContain('### 4. Delegate the delivery route to `splinter`');
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
    $command = (string) file_get_contents($packageDir . '/commands/redesign-page.md');

    expect($skill)->toContain('passes `--runtime-acceptance`, `--thorough`, and `--tracker no`');
    expect($skill)->toContain('It does not pass `--redesign`, because the approved proposal is');
    expect($skill)->toContain('@skills/page-redesign/SKILL.md');
    expect($skill)->toContain('@skills/interactive-testing/SKILL.md');
    expect($skill)->toContain('the `raphael` pass is never skipped');
    expect($skill)->toContain('When no interactive browser is available, the run reports `Blocked`');
    expect($skill)->toContain('Keep the main layout shell');

    // The command already hands the run to `splinter`, so the skill must never tell `splinter` to
    // dispatch itself. The invoking session resolves and captures the page, then delegates.
    expect($skill)->not->toContain('Dispatch `splinter`');
    expect($skill)->toContain('The invoking session performs this step itself, before it delegates anything');
    expect($command)->toContain('then delegate the delivery route to the');
});

test('deliver-page-redesign walks the local instance and never writes to the host in the URL', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/deliver-page-redesign/SKILL.md');

    expect($skill)->toContain('The host of the URL never receives a write');
    expect($skill)->toContain('run on the local instance of this repository');
    expect($skill)->toContain('Never run them on a shared, staging, or');
    expect($skill)->toContain('`skills/_shared/browser-drive.sh`');
});

test('the delivery plan carries the review and the browser walkthrough on every tier', function (string $tier): void {
    // The skill promises a converged review and a walkthrough that is never skipped. The planner
    // adds `raphael` on every tier for runtime acceptance but drops `leonardo` on FAST, so the
    // delivery flags must buy both on every tier the classifier can return.
    $planner = dirname(__DIR__, 2) . '/skills/_shared/plan-route.sh';
    $process = new Process([$planner, '--tier', $tier, '--runtime-acceptance', '--thorough', '--tracker', 'no']);
    $process->mustRun();

    $plan = json_decode($process->getOutput(), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
    assert(is_array($plan) && is_array($plan['stages']));

    $roles = [];

    foreach ($plan['stages'] as $stage) {
        if (is_array($stage) && is_string($stage['role'] ?? null)) {
            $roles[] = $stage['role'];
        }
    }

    // The user already approved the proposal, so the delivery plan never replays the redesign.
    expect($roles)->not->toContain('michelangelo');
    expect($roles)->toContain('donatello');
    expect($roles)->toContain('leonardo');
    expect($roles)->toContain('raphael');
})->with(['FAST', 'STANDARD', 'CRITICAL']);

test('deliver-page-redesign writes no code before the user approves the previews', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/deliver-page-redesign/SKILL.md');
    $command = (string) file_get_contents($packageDir . '/commands/redesign-page.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');
    $splinter = (string) file_get_contents($packageDir . '/agents/splinter.md');

    expect($skill)->toContain('**No code before approval.**');
    expect($skill)->toContain('### 3. Get the design approved');
    expect($skill)->toContain('Only an explicit approval in the user\'s own reply counts');
    expect($skill)->toContain('dispatch `michelangelo` again with the current proposal path and the');
    expect($skill)->toContain('`APPROVED.md`');
    expect($command)->toContain('Write no code before that approval.');

    // The previews must reach the user on both clients.
    expect($skill)->toContain('On Claude Code, open each PNG with the Read tool');
    expect($skill)->toContain('On Codex, open each PNG with the image viewer tool');
    expect($command)->toContain('registered `michelangelo`');

    // Delivery must implement the approved proposal instead of producing a competing one.
    expect($orchestration)->toContain('**An approved redesign is never replayed.**');
    expect($splinter)->toContain('**An approved redesign is never replayed.**');
});
