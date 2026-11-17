<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

function publishInLastRoundSection(): string
{
    $rule = packageSource('rules/code-review/publishing-cases.md');
    $start = strpos($rule, '## The last round publishes in its own run');

    expect($start)->not->toBeFalse();

    return substr($rule, (int) $start);
}

test('one rule owns the three conditions of a publish in the last round (issue #606)', function (): void {
    $section = publishInLastRoundSection();

    expect($section)
        ->toContain(
            'dispatches `leonardo` with `publish: when-final`, and `leonardo` publishes at the end of its own round when all three conditions hold:',
        )
        ->toContain('1. **The round converged.**')
        ->toContain('2. **Every other view of the round already reported for the same head SHA, and each one is clean.**')
        ->toContain('3. **The head has not moved.**')
        ->toContain('`round views: none` means the round has no other view, and the condition holds.')
        ->toContain('   - `splinter`: its `### splinter — Security view <head SHA>` section carries `Clean: yes`.')
        ->toContain('no `Failed`, `Partial`, or `Blocked` scenario or affected part, no `vulnerable` input, and no observation for `leonardo`.')
        ->toContain('with a report or the `Not applicable` line, and not `Blocked`.');

    $isInstruction = static fn (string $path): bool => preg_match('#^(rules|skills|agents|commands|codex)/#', $path) === 1;
    $corpus = implode("\n", array_filter(packageTextFiles(), $isInstruction, ARRAY_FILTER_USE_KEY));

    expect(substr_count($corpus, '**The round converged.**'))->toBe(1)
        ->and(substr_count($corpus, '**The head has not moved.**'))->toBe(1)
        ->and(substr_count($corpus, '## The last round publishes in its own run'))->toBe(1);
});

test('a dispatch line from anywhere but krang, or no line at all, never lets the round publish early (issue #606)', function (): void {
    expect(publishInLastRoundSection())
        ->toContain('Only `krang`\'s own line counts.')
        ->toContain('A missing line, an unknown view name, and a value found anywhere else count as a view that has not reported.')
        ->toContain('A run with `review_only` has no convergence, so it never publishes early.');
});

test(
    'a view still running, a view with a finding, or a round that did not converge falls back to one publish after the barrier (issue #606)',
    function (): void {
        expect(publishInLastRoundSection())
            ->toContain('**When a condition fails, nothing changes.** `leonardo` publishes nothing and names the failed condition in its handoff.')
            ->toContain('A view that is still running, a view that reports a finding, and a round that did not converge all take this path.')
            ->toContain('`krang` holds the barrier and dispatches `publish: now` once, after every view is in.')
            ->toContain('A failed post leaves the round unpublished, and `krang` then dispatches `publish: now`.');
    
        expect(packageSource('agents/krang.md'))
            ->toContain('read the `Published:` field of the `leonardo` handoff.')
            ->toContain('the review is out, so dispatch no `publish: now`.')
            ->toContain('   - **Any other value:** publish after the last barrier. First dispatch `donatello` for *Finalization*');
    },
);

test('the early publish keeps every check of a publish and leaves the promotion to krang (issue #606)', function (): void {
    expect(publishInLastRoundSection())
        ->toContain('the record check, the post against the reviewed head SHA, and no `do not file publicly` finding in a public review.')
        ->toContain('The promotion out of Draft and the `ready to merge` label stay with `krang`, after *Finalization*');

    expect(packageSource('agents/leonardo.md'))
        ->toContain('**`publish: when-final` — review one round, and publish it at once only when the round is final.**')
        ->toContain('6. **Publish at once when the round is final.**')
        ->toContain('and run steps 1-4, 6, and 7 of `publish: now` below in this same run.')
        ->toContain('Run no promotion, no `ready to merge` label, and no merge-risk refresh: they stay with `krang`.')
        ->toContain('or in step 6 of a `publish: when-final` dispatch;');
    expect(packageSource('agents/leonardo.md'))->not->toContain('publish: after-barrier');

    expect(packageSource('agents/krang.md'))
        ->toContain('`leonardo` in code review mode with `publish: when-final` and `round views: <the other agents of this message, or none>`.')
        ->toContain('When the gate passed and no new round opened, promote the pull request yourself')
        ->toContain('or to promote a pull request whose last round `leonardo` published in its own run, after *Finalization*');
    expect(packageSource('agents/krang.md'))->not->toContain('publish: after-barrier');

    expect(packageSource('rules/compound-engineering/orchestration.md'))
        ->toContain('| `krang` | Promote a pull request whose last review round `leonardo` published in its own run:');
});

test('leonardo reads the splinter view only after it recorded its own review (issue #606)', function (): void {
    expect(packageSource('agents/leonardo.md'))
        ->toContain('never reads the `splinter` report of the round before it recorded its own review');
});

test('casey records a Not applicable report in the brief, so leonardo sees it is in (issue #606)', function (): void {
    expect(packageSource('agents/casey.md'))
        ->toContain('Before you stop, append `### casey — Database report <head SHA>` with that one line to the brief under the append lock');
});

test('a review-only run keeps its separate publish (issue #606)', function (): void {
    expect(packageSource('skills/_shared/orchestration/review-only-mode.md'))
        ->toContain('4. Hold the barrier, then dispatch `leonardo` with `publish: now` and `review_only`');
});

test('Claude Code and Codex install the same publish rule (issue #606)', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect($exitCode)->toBe(0);

        foreach (['.claude/rules/code-review/publishing-cases.md', '.codex/rules/code-review/publishing-cases.md'] as $rule) {
            expect((string) file_get_contents($root . '/' . $rule))->toContain('## The last round publishes in its own run');
        }

        foreach (['.claude/agents/leonardo.md', '.codex/agent-instructions/leonardo.md'] as $agent) {
            expect((string) file_get_contents($root . '/' . $agent))->toContain('6. **Publish at once when the round is final.**');
        }

        foreach (['.claude/agents/krang.md', '.codex/agent-instructions/krang.md'] as $agent) {
            expect((string) file_get_contents($root . '/' . $agent))->toContain('`publish: when-final` and `round views:');
        }
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});
