<?php

declare(strict_types = 1);

// The promotion lifecycle of repeated learnings (issue #137, part of #131; security plan #216).
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so the behavioural proof lives in the
// scripts' own `--self-test` (the precedent is `tests/Installer/FeedbackLoopTest.php`). The pins below fix the
// checks that self-test enforces, the wiring that makes it run in the build, the contract, and the static promise
// that no promotion command writes a tracker label, commits, pushes, or merges.

function promotionFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function promotionProductionCode(string $script): string
{
    $code = preg_replace('/^\s*#.*$/m', '', $script) ?? '';
    $selfTestStart = strpos($code, 'self_test() {');

    expect($selfTestStart)->not->toBeFalse();

    return substr($code, 0, (int) $selfTestStart);
}

test('the lifecycle script is shipped, executable, self-testing, and run by the build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/_shared/promote-learning.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    expect((string) file_get_contents($script))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('if [[ "${1:-}" == \'--self-test\' ]]; then');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(implode(' ', $composer['scripts']['shell-self-tests']))->toContain(' skills/_shared/promote-learning.sh ');
});

test('the self-test covers every acceptance criterion and testing scenario of the assignment', function (): void {
    expect(promotionFile('skills/_shared/promote-learning.sh'))
        // Maturity states, grouping, evidence, and the happy path.
        ->toContain('check \'learnings carry an explicit maturity state, and an eligible pattern awaits analysis\'')
        ->toContain(
            'check \'three learnings of one failure give one candidate whose evidence names all three '
            . 'and whose proposal names the target file and change\'',
        )
        // Duplicate detection: existing knowledge, an existing candidate, and the supersede rule.
        ->toContain('check \'a pattern that an existing rule already covers reports the rule instead of creating a duplicate\'')
        ->toContain('check \'an existing candidate with the same proposal is not created twice\'')
        ->toContain('run \'a candidate that supersedes an unpromoted key is refused\' 4')
        // Promotion, rejection, and superseding.
        ->toContain('check \'a candidate is promoted once its change is on the default branch\'')
        ->toContain('check \'a promoted pattern gets no second candidate\'')
        ->toContain('check \'a rejected candidate records its reason\'')
        ->toContain('check \'a rejected candidate is not regenerated from the same evidence\'')
        ->toContain('check \'new evidence regenerates a rejected candidate\'')
        ->toContain('check \'a promoted candidate that a broader one covers is marked superseded, not deleted\'')
        // Thresholds.
        ->toContain('check \'two occurrences with a threshold of three produce no candidate\'')
        ->toContain('check \'three records of one pull request count as one distinct task and give no candidate\'')
        // Regression: the hand-curated memory and the knowledge files are untouched.
        ->toContain('check \'no promotion command writes rules, skills, agents, codex, AGENTS.md, CLAUDE.md, or the project memory\'')
        ->toContain('check \'no promotion command creates a commit, a push, or a merge: HEAD is unchanged\'');

    expect(promotionFile('skills/_shared/analyze-recurrence.sh'))
        ->toContain('check \'three learnings of one class are one eligible pattern whose evidence names all three\'')
        ->toContain('check \'two occurrences with a threshold of three give no eligible pattern\'')
        ->toContain('check \'three records of one pull request are one distinct task and no eligible pattern\'')
        ->toContain('check \'the confidence is the share of resolved records and gates the pattern\'')
        ->toContain('check \'rewording the text of a learning changes neither its key nor its evidence\'');

    expect(promotionFile('skills/_shared/propose-prevention.sh'))
        ->toContain('check \'the proposal names the target type, path, change, and the existing knowledge checked\'')
        ->toContain('check "a candidate can be promoted into the target type ${kind%%:*}"')
        ->toContain('check \'a partly covering knowledge file must be extended instead of duplicated\'')
        ->toContain('check \'a pattern that an existing rule covers reports that rule and writes no proposal\'');

    expect(promotionFile('skills/_shared/read-manifest.sh'))
        ->toContain('expect_output \'--default-ref prints the ref the manifest is read from\' "$repo" \'origin/master\' 0 --default-ref');
});

test('the self-test proves every item of the security plan', function (): void {
    $lifecycle = promotionFile('skills/_shared/promote-learning.sh');

    expect($lifecycle)
        // Critical: approval only from a trusted fact, bound to the digest of the exact text.
        ->toContain('check \'a store record whose text claims approval leaves the candidate unapproved\'')
        ->toContain('check \'a forged history line approves nothing\'')
        ->toContain('check \'a comment that claims approval leaves the candidate unapproved\'')
        ->toContain('check \'the approval stores the digest of the exact proposal body it was given for\'')
        ->toContain('check \'a refreshed approved proposal is not approved and awaits re-approval\'')
        ->toContain('run \'the label that approved the earlier text does not approve the changed one\' 4')
        ->toContain(
            'check \'a label approves the first text since the last decision, so a text refreshed before the run saw the label awaits re-approval\'',
        )
        // Moderate: text stays inert, identity reads schema fields, the target stays inside the knowledge roots.
        ->toContain('check \'a mention, a link, a marker, a bidirectional mark, and a fence in learning text stay inert\'')
        ->toContain('check \'rewording the text of a learning changes neither the candidate id, the dedupe key, the title, nor the suppression key\'')
        ->toContain('check \'a target path with .. is refused and records no candidate\'')
        // Moderate: the default branch governs the history, the knowledge check, and the thresholds.
        ->toContain('check \'a history that a branch carries changes no verdict\'')
        ->toContain('check \'a covering rule present only in the working tree does not mark the candidate covered\'')
        // Moderate: the approver, the rejection, the suppression, and the promotion.
        ->toContain('check \'the recorded approver comes from the trusted fact, never from a comment\'')
        ->toContain('check \'a comment that asks for rejection leaves the candidate a candidate\'')
        ->toContain('check \'a candidate whose change is on an unmerged branch stays approved\'');

    expect(promotionFile('skills/_shared/analyze-recurrence.sh'))
        ->toContain('check \'the default-branch manifest sets the promotion thresholds and a lower working-tree value does not govern\'')
        ->toContain('check \'a promotion threshold below 2 is ignored and the script says so\'');

    expect(promotionFile('skills/_shared/propose-prevention.sh'))
        ->toContain('check "a target path outside the knowledge root of its type is refused: ${bad#*:}"')
        ->toContain('check \'a mention, a link, a marker, a bidirectional mark, and a fence in a target change stay inert\'');
});

test('no promotion command writes a tracker, a label, a commit, a push, or a merge', function (): void {
    $production = promotionProductionCode(promotionFile('skills/_shared/promote-learning.sh'));

    // One needle per call: a negated toContain with several needles passes when any one of them is absent.
    $forbidden = [
        'gh ',
        '--add-label',
        'git commit',
        'git push',
        'git merge ',
        'git add',
        'git checkout',
        'git reset',
        'curl',
        'wget',
        'eval ',
        'bash -c',
        '.claude/',
        '.codex/',
        '.agents/',
    ];

    foreach ($forbidden as $needle) {
        expect($production)->not->toContain($needle);
    }

    // The source holds escapes, never the invisible characters themselves.
    expect(
        preg_match(
            '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{00AD}\x{061C}]/u',
            promotionFile('skills/_shared/promote-learning.sh'),
        ),
    )->toBe(
        0,
    );

    // The lifecycle reuses the earlier scripts and the gate path guard instead of a second copy.
    expect($production)
        ->toContain('LOADER="$HERE/../code-review-github/scripts/load-issue.sh"')
        ->toContain('. "$HERE/gate-record.sh"')
        ->toContain('gate_scratch_path --dir "$1" "$1/promotion.jsonl" "$1/merged.jsonl"')
        ->toContain('"$HERE/analyze-recurrence.sh" --store "$store" --patterns')
        ->toContain(
            '"$HERE/propose-prevention.sh" --report "$TMP/patterns.json" --store "$dir/merged.jsonl" --analysis "$TMP/effective.json" --out "$out"',
        )
        ->toContain('"$HERE/read-manifest.sh" --default-ref')
        ->toContain('git merge-base --is-ancestor "$full" "$ref"')
        ->toContain('.state == "OPEN" and ((.labels // []) | index("Resolve_by_AI") != null)')
        ->toContain('.state == "CLOSED" and .stateReason == "NOT_PLANNED"');
});

test('the contract is written once and names the states, the facts, the history, and the limits', function (): void {
    expect(promotionFile('skills/code-review/references/learning-promotion.md'))
        ->toStartWith('# Learning promotion — from a repeated learning to permanent knowledge, with a human in between')
        ->toContain('`skills/_shared/promote-learning.sh` is the only implementation.')
        ->toContain('| `observation` | The class rests on one task. |')
        ->toContain('| `superseded` | A later, broader candidate was promoted and covers this one. It stays in the history. |')
        ->toContain('A state is derived from the history in order. No record field states it, and no text sets it.')
        ->toContain('| Occurrences | `promotion.min-occurrences` | 3 | at least 2 |')
        ->toContain('| Distinct tasks | `promotion.min-distinct-tasks` | 2 | at least 2 |')
        ->toContain('| Resolved share in percent | `promotion.min-confidence` | 0 | 0 to 100 |')
        ->toContain('| `documentation` | `README.md`, `docs/memory/PROJECT_MEMORY.md` |')
        ->toContain('- **The approver comes from the fact.**')
        ->toContain('- **An approval is bound to the exact text.**')
        ->toContain('- **One label approves one text.**')
        ->toContain('- **The history is never read from a branch.**')
        ->toContain(
            'No command of the lifecycle writes `rules/`, `skills/`, `agents/`, `codex/`, `AGENTS.md`, `CLAUDE.md`, '
            . 'or `docs/memory/PROJECT_MEMORY.md`, and no command commits, pushes, or merges.',
        );

    expect(promotionFile('skills/code-review/references/feedback-loop.md'))
        ->toContain(
            '| `promotion.jsonl` | The promotion history: candidates, decisions, and promotions (`learning-promotion.md` *The promotion history*). |',
        )
        ->toContain('An approval holds for the exact text it was given for.')
        ->toContain('5. **Record the promotions.** Run `promote-learning.sh status --dir <dir>`.');

    expect(promotionFile('skills/resolve-issue/references/safeguard-implementation.md'))
        ->toContain('A proposal with a `## Promotion target` section is a promotion candidate, and its approval holds only for the text it was given for.')
        ->toContain('stops `Blocked: proposal awaiting re-approval`');

    expect(promotionFile('rules/compound-engineering/memory.md'))
        ->toContain(
            'A lesson that review keeps finding is promoted through `@skills/code-review/references/learning-promotion.md`, '
            . 'never written here by the lifecycle itself.',
        );
});

test('the three thresholds are documented in the manifest table of the rule and of the installation guide', function (): void {
    expect(promotionFile('rules/general/general.md'))
        ->toContain('| `promotion.min-occurrences` | int |')
        ->toContain('| `promotion.min-distinct-tasks` | int |')
        ->toContain('| `promotion.min-confidence` | int |');

    expect(promotionFile('docs/installation.md'))
        ->toContain('| `promotion.min-occurrences` | `analyze-recurrence.sh --patterns`, `promote-learning.sh` |')
        ->toContain('| `promotion.min-distinct-tasks` | `analyze-recurrence.sh --patterns`, `promote-learning.sh` |')
        ->toContain('| `promotion.min-confidence` | `analyze-recurrence.sh --patterns`, `promote-learning.sh` |');
});

test('filing a promotion proposal reuses the consent row of the feedback run, and a human keeps every decision', function (): void {
    expect(promotionFile('rules/compound-engineering/orchestration.md'))->toContain(
        'A promotion proposal (`@skills/code-review/references/learning-promotion.md`) is filed the same way, '
        . 'and its approval, its rejection, and its merge stay with a human.',
    );
});
