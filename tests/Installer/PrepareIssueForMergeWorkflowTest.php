<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

test('prepare-issue-for-merge is one shared workflow for Claude Code and Codex', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skillPath = $packageDir . '/skills/prepare-issue-for-merge/SKILL.md';
    $commandPath = $packageDir . '/commands/prepare-issue-for-merge.md';

    expect(is_file($skillPath))->toBeTrue();
    expect(is_file($commandPath))->toBeTrue();

    $skill = (string) file_get_contents($skillPath);
    $command = (string) file_get_contents($commandPath);
    $legacy = (string) file_get_contents($packageDir . '/commands/finalize-tasks.md');

    expect($skill)->toContain('name: prepare-issue-for-merge');
    expect($skill)->toContain('Delegate the orchestration to `daedalus`');
    expect($skill)->toContain('It never merges the pull request');
    expect($skill)->toContain('@skills/pr-summary/SKILL.md');
    expect($skill)->toContain('templates/pr-summary-github.md');

    expect($command)->toContain('argument-hint: [GitHub issue or pull request URL]');
    expect($command)->toContain('@skills/prepare-issue-for-merge/SKILL.md');
    expect($command)->toContain('$ARGUMENTS');

    expect($legacy)->toContain('Drive the pull requests of the tracker tasks');
    expect($legacy)->toContain('skills/code-review-jira/scripts/upsert-comment.sh');
    expect($legacy)->not->toContain('Compatibility alias');
});

test('prepare-issue-for-merge skips content-identical review rounds but fails closed on changed content', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/prepare-issue-for-merge/SKILL.md');

    expect($skill)->toContain('git diff --binary --full-index --no-color --no-ext-diff --no-renames');
    expect($skill)->toContain('git patch-id --verbatim');
    expect($skill)->toContain('content-identical');
    expect($skill)->toContain('do not dispatch another code-review round');
    expect($skill)->toContain('A missing or different fingerprint requires a fresh review');
    expect($skill)->toContain('New actionable reviewer feedback');
    expect($skill)->toContain('@skills/process-code-review/SKILL.md');
});

test('prepare-issue-for-merge consolidates only owned comments and preserves merge evidence', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/prepare-issue-for-merge/SKILL.md');
    $hermes = (string) file_get_contents($packageDir . '/agents/hermes.md');
    $helperPath = $packageDir . '/skills/_shared/delete-owned-github-comment.sh';
    $inventory = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect(is_file($helperPath))->toBeTrue();

    $helper = (string) file_get_contents($helperPath);

    expect($skill)->toContain('Publish and read back the final TL;DR before deleting anything');
    expect($skill)->toContain('Never delete another account\'s comment');
    expect($skill)->toContain('Preserve the newest trusted');
    expect($skill)->toContain('accompanying `cr-status`');
    expect($skill)->toContain('skills/_shared/delete-owned-github-comment.sh');
    expect($skill)->toContain('exactly one current `merge-readiness` comment');

    expect($helper)->toContain('assert-current-repo.sh');
    expect($helper)->toContain('gh api user --jq .login');
    expect($helper)->toContain('--method DELETE');
    expect($helper)->toContain('protected final comment');
    expect($helper)->toContain('HTTP 404');

    expect($hermes)->toContain('## Merge-preparation consolidation mode');
    expect($hermes)->toContain('Preparation report done');
    expect($inventory)->toContain('when `/prepare-issue-for-merge` runs | L2 |');
});

test('the GitHub comment delete helper protects retained ids before contacting GitHub', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $helper = $packageDir . '/skills/_shared/delete-owned-github-comment.sh';
    $process = new Process([
        $helper,
        'https://github.com/pekral/ai-olympus/issues/84',
        '123',
        '123',
        '998',
        '999',
    ], $packageDir);

    $process->run();

    expect($process->getExitCode())->toBe(4);
    expect($process->getErrorOutput())->toContain('protected final comment');
});

test('the GitHub comment delete helper validates protection, rejects foreign comments, and verifies deletion', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $helper = $packageDir . '/skills/_shared/delete-owned-github-comment.sh';
    $fixtureRoot = installerCreateProjectRoot();
    $fakeBin = $fixtureRoot . '/bin';
    $state = $fixtureRoot . '/deleted';
    $path = getenv('PATH');
    $systemPath = is_string($path) ? $path : '';

    installerWriteFile($fakeBin . '/gh', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$1" == "api" && "$2" == "user" ]]; then
  printf '%s\n' 'pekral'
  exit 0
fi

if [[ "$1" == "api" && "$2" == "--method" && "$3" == "DELETE" ]]; then
  : > "$FAKE_GH_STATE"
  exit 0
fi

if [[ "$1" == "api" && "$2" == "--include" ]]; then
  printf '%s\n' 'HTTP/2.0 404 Not Found' >&2
  exit 1
fi

if [[ "$1" == "api" && "$2" == repos/*/issues/comments/123 ]]; then
  printf '%s%s%s\n' \
    '{"user":{"login":"'"${FAKE_COMMENT_ACTOR:-pekral}"'"},"repository_url":"' \
    'https://api.github.com/repos/pekral/ai-olympus","issue_url":"' \
    'https://api.github.com/repos/pekral/ai-olympus/issues/84","body":"stale"}'
  exit 0
fi

if [[ "$1" == "api" && "$2" == repos/*/issues/comments/997 ]]; then
  printf '%s%s\n' \
    '{"user":{"login":"pekral"},"repository_url":"' \
    'https://api.github.com/repos/pekral/ai-olympus","body":"<!-- merge-readiness:actor=pekral -->"}'
  exit 0
fi

if [[ "$1" == "api" && "$2" == repos/*/issues/comments/996 ]]; then
  printf '%s\n' '{"user":{"login":"pekral"},"repository_url":"https://api.github.com/repos/pekral/ai-olympus","body":"not merge evidence"}'
  exit 0
fi

if [[ "$1" == "api" && "$2" == repos/*/issues/comments/998 ]]; then
  printf '%s\n' '{"user":{"login":"pekral"},"repository_url":"https://api.github.com/repos/pekral/ai-olympus","body":"<!-- cr-comment:actor=pekral -->"}'
  exit 0
fi

if [[ "$1" == "api" && "$2" == repos/*/issues/comments/999 ]]; then
  printf '%s\n' '{"user":{"login":"pekral"},"repository_url":"https://api.github.com/repos/pekral/ai-olympus","body":"<!-- cr-status:actor=pekral -->"}'
  exit 0
fi

exit 90
BASH);
    chmod($fakeBin . '/gh', 0755);

    try {
        $baseEnvironment = [
            'FAKE_GH_STATE' => $state,
            'PATH' => $fakeBin . PATH_SEPARATOR . $systemPath,
        ];

        $unprotected = new Process([
            $helper,
            'https://github.com/pekral/ai-olympus/issues/84',
            '123',
            '996',
            '998',
            '999',
        ], $packageDir, $baseEnvironment);
        $unprotected->run();

        expect($unprotected->getExitCode())->toBe(4);
        expect($unprotected->getErrorOutput())->toContain('does not carry the required merge-readiness marker');
        expect(is_file($state))->toBeFalse();

        $foreign = new Process([
            $helper,
            'https://github.com/pekral/ai-olympus/issues/84',
            '123',
            '997',
            '998',
            '999',
        ], $packageDir, $baseEnvironment + ['FAKE_COMMENT_ACTOR' => 'someone-else']);
        $foreign->run();

        expect($foreign->getExitCode())->toBe(4);
        expect($foreign->getErrorOutput())->toContain('not owned by the authenticated actor');
        expect(is_file($state))->toBeFalse();

        $owned = new Process([
            $helper,
            'https://github.com/pekral/ai-olympus/issues/84',
            '123',
            '997',
            '998',
            '999',
        ], $packageDir, $baseEnvironment + ['FAKE_COMMENT_ACTOR' => 'pekral']);
        $owned->run();

        expect($owned->getExitCode())->toBe(0);
        expect($owned->getOutput())->toContain('deleted id=123');
        expect(is_file($state))->toBeTrue();
    } finally {
        installerRemoveDirectory($fixtureRoot);
    }
});
