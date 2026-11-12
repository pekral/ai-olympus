<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function agentVoiceSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

/**
 * @return list<string>
 */
function agentVoiceGlob(string $pattern): array
{
    $paths = glob(dirname(__DIR__, 2) . '/' . $pattern);

    return $paths === false ? [] : $paths;
}

function agentVoiceResolve(string $manifest): string
{
    $process = new Process([dirname(__DIR__, 2) . '/skills/agent-voice/scripts/resolve-voice.sh', '--stdin']);
    $process->setInput($manifest);
    $process->run();

    return trim($process->getOutput());
}

test('the skill ships with both voices and the selection order', function (): void {
    $skill = agentVoiceSource('skills/agent-voice/SKILL.md');

    expect($skill)->toContain("name: agent-voice\n")
        ->and($skill)->toContain('### `relaxed` (default)')
        ->and($skill)->toContain('### `technical`')
        ->and($skill)->toContain('When the user instructed a voice in this session, use that voice.')
        ->and($skill)->toContain('The instruction overrides the manifest')
        ->and($skill)->toContain('skills/agent-voice/scripts/resolve-voice.sh')
        ->and($skill)->toContain('`@rules/writing/general.md`')
        ->and($skill)->toContain('*Verified statements only* unchanged in every voice');
});

test('the skill covers chat output and human-read tracker comments and keeps every required structure', function (): void {
    $skill = agentVoiceSource('skills/agent-voice/SKILL.md');

    expect($skill)->toContain('**Conversational output to the operator.**')
        ->and($skill)->toContain('**A comment published to an issue tracker for people to read.**')
        ->and($skill)->toContain('the agent marker and the marker namespace')
        ->and($skill)->toContain('the mandatory headings, fields, and their order')
        ->and($skill)->toContain('finding identifiers and severity labels')
        ->and($skill)->toContain('the language choice and the length limits of `@rules/reports/general.md`')
        ->and($skill)->toContain('A code review report keeps its finding format.');
});

test('commit messages, pull request titles, handoffs, and machine-parsed text stay under the writing rule', function (): void {
    $skill = agentVoiceSource('skills/agent-voice/SKILL.md');
    $writing = agentVoiceSource('rules/writing/general.md');

    expect($skill)->toContain('A commit message, a pull request title and description, and a branch name.')
        ->and($skill)->toContain('A handoff, a shared brief, or a dispatch prompt addressed to another agent.')
        ->and($skill)->toContain('Any text a script parses')
        ->and($writing)->toContain('`@skills/agent-voice/SKILL.md`')
        ->and($writing)->toContain('a handoff, a commit message, a pull request title and description, a branch name, and any text a script parses')
        ->and($writing)->not->toContain('a reply to the user, a handoff')
        ->and($writing)->toContain('stays as `@rules/reports/general.md` defines it, in every voice');
});

test('every Claude agent and every Codex agent points to the skill and none restates the voice', function (): void {
    $claude = agentVoiceGlob('agents/*.md');
    $codex = agentVoiceGlob('codex/agents/*.toml');

    expect($claude)->toHaveCount(9)
        ->and($codex)->toHaveCount(9);

    foreach ($claude as $path) {
        $content = (string) file_get_contents($path);

        expect($content)->toContain('`@skills/agent-voice/SKILL.md`')
            ->and($content)->not->toContain('Talk like a calm colleague')
            ->and($content)->not->toContain('straight-to-the-point');
    }

    foreach ($codex as $path) {
        $content = (string) file_get_contents($path);

        expect($content)->toContain('`$agent-voice` (`@skills/agent-voice/SKILL.md`)')
            ->and($content)->not->toContain('Talk like a calm colleague');
    }
});

test('every skill that publishes a human-read tracker comment applies the voice', function (): void {
    $publishers = [
        'skills/pr-summary/SKILL.md',
        'skills/analyze-support-issue/SKILL.md',
        'skills/auto-interactive-testing/SKILL.md',
        'skills/test-assignment/SKILL.md',
        'skills/verify-merge-readiness/SKILL.md',
        'skills/process-code-review/references/answering-reviewer-questions.md',
        'skills/code-review/SKILL.md',
    ];

    foreach ($publishers as $path) {
        $content = agentVoiceSource($path);

        expect($content)->toContain('`@skills/agent-voice/SKILL.md`')
            ->and($content)->not->toContain('Talk like a calm colleague');
    }

    expect(agentVoiceSource('agents/april.md'))->toContain('`@skills/agent-voice/SKILL.md`');
});

test('the reports rule keeps its markers and language rules and hands the prose to the voice', function (): void {
    $reports = agentVoiceSource('rules/reports/general.md');

    expect($reports)->toContain('`@skills/agent-voice/SKILL.md` selects')
        ->and($reports)->toContain('The voice governs the prose only: every marker, heading, field, finding identifier,')
        ->and($reports)->toContain('severity label, and link this rule requires stays.');
});

test('the manifest table documents the voice key and the fallback', function (): void {
    $general = agentVoiceSource('rules/general/general.md');
    $installation = agentVoiceSource('docs/installation.md');

    expect($general)->toContain('| `voice` | string |')
        ->and($general)->toContain('`relaxed` or `technical`')
        ->and($general)->toContain('Absent, not a string, or an unknown name: `relaxed`.')
        ->and($general)->toContain('The user\'s own instruction in the session overrides it.')
        ->and($installation)->toContain('| `voice` |')
        ->and($installation)->toContain('An absent, non-string, or unknown value means `relaxed`.');
});

test('the resolver selects the manifest voice and falls back to relaxed', function (string $manifest, string $expected): void {
    expect(agentVoiceResolve($manifest))->toBe($expected);
})->with([
    'absent key' => ['{}', 'relaxed'],
    'not a string' => ['{"voice":true}', 'relaxed'],
    'not json' => ['not json', 'relaxed'],
    'relaxed' => ['{"voice":"relaxed"}', 'relaxed'],
    'technical' => ['{"voice":"technical"}', 'technical'],
    'unknown value' => ['{"voice":"sarcastic"}', 'relaxed'],
    'wrong case' => ['{"voice":"Technical"}', 'relaxed'],
]);

test('the resolver self-test passes and an unknown option is a usage error', function (): void {
    $selfTest = new Process([dirname(__DIR__, 2) . '/skills/agent-voice/scripts/resolve-voice.sh', '--self-test']);
    $selfTest->run();
    $usage = new Process([dirname(__DIR__, 2) . '/skills/agent-voice/scripts/resolve-voice.sh', '--bogus']);
    $usage->run();

    expect($selfTest->getExitCode())->toBe(0)
        ->and($usage->getExitCode())->toBe(1);
});

test('the skill is listed in the catalog and the skill counts agree', function (): void {
    $skillCount = count(agentVoiceGlob('skills/*/SKILL.md'));
    $catalog = agentVoiceSource('docs/skills.md');

    expect($catalog)->toContain('[`agent-voice`](../skills/agent-voice/)')
        ->and($catalog)->toContain('All ' . $skillCount . ' skills')
        ->and(agentVoiceSource('README.md'))->toContain($skillCount . ' reusable skills')
        ->and(agentVoiceSource('CHANGELOG.md'))->toContain('`agent-voice`');
});
