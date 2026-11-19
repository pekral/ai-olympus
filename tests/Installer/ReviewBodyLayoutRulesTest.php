<?php

declare(strict_types = 1);

function reviewLayoutRepoFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

/**
 * @return list<string> the repository-relative paths of the Markdown, shell, and TOML files under the given directories
 */
function reviewLayoutPackageFiles(string ...$directories): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['md', 'sh', 'toml'], strict: true)) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    return $files;
}

function reviewBodyTemplateSample(): string
{
    $template = reviewLayoutRepoFile('skills/code-review-github/templates/review-body.md');
    $start = strpos($template, "````markdown\n");
    $end = strpos($template, "\n````", (int) $start + 1);

    return substr($template, (int) $start + strlen("````markdown\n"), (int) $end - (int) $start - strlen("````markdown\n"));
}

test('the review body template shows the summary, Merge Risk, Walkthrough, Where to test, and Actionable comments in that order', function (): void {
    $sample = reviewBodyTemplateSample();
    $positions = array_map(
        static fn (string $needle): int|false => strpos($sample, $needle),
        [
            '{what the pull request changes',
            'Merge Risk:</strong>',
            '<strong>Walkthrough</strong>',
            '<strong>Where to test</strong>',
            '<strong>Actionable comments ({n})</strong>',
        ],
    );
    $sorted = $positions;
    sort($sorted);

    expect($positions)->not->toContain(false)
        ->and($positions[0])->toBe(0)
        ->and($positions)->toBe($sorted);
});

test('the review body template opens with the summary and no label, and every block is a closed details block', function (): void {
    $sample = reviewBodyTemplateSample();

    expect($sample)->not->toMatch('/TL;DR/i')
        ->and($sample)->toStartWith('{what the pull request changes')
        ->and(substr_count($sample, '<details>'))->toBe(4)
        ->and(substr_count($sample, '</details>'))->toBe(4)
        ->and($sample)->not->toMatch('/pre-merge|recent review|explain change|share|<button|<img|badge/i');
});

test('the review body template states the 500 character limit and the two fences it allows', function (): void {
    $template = reviewLayoutRepoFile('skills/code-review-github/templates/review-body.md');
    $sample = reviewBodyTemplateSample();

    expect($template)->toContain('The summary holds at most 500 characters')
        ->and($template)->toContain(
            'Two fences are allowed: the mermaid sequence diagram of the Walkthrough, and the suggested code of an Actionable comments entry',
        )
        ->and(substr_count($sample, '```'))->toBe(4)
        ->and($sample)->toContain('```mermaid')
        ->and($sample)->toContain("**Suggested code** (replaces lines {start}–{end}):\n```{language of the file}\n")
        ->and($template)->toContain('Route Access of Changed Routes');
});

test('the layout has one owner: the Merge Risk block markup is in the template and the renderer only', function (): void {
    $owners = [
        'skills/code-review-github/templates/review-body.md',
        'skills/code-review-github/scripts/post-inline-review.sh',
    ];
    $holders = array_values(array_filter(
        reviewLayoutPackageFiles('rules', 'skills', 'agents', 'codex'),
        static fn (string $path): bool => str_contains(reviewLayoutRepoFile($path), '<summary><strong>Merge Risk:</strong>'),
    ));
    sort($holders);
    sort($owners);

    expect($holders)->toBe($owners);
});

test('the rule, the wrapper contract, the skill, and the agent point to the one layout owner', function (string $path): void {
    expect(reviewLayoutRepoFile($path))->toContain('templates/review-body.md');
})->with([
    'rules/code-review/general.md',
    'skills/code-review-github/references/cr-wrapper-contract.md',
    'skills/code-review-github/SKILL.md',
    'skills/code-review-github/templates/pr-comment-output.md',
    'agents/leonardo.md',
]);

test('the code review rule owns the visible layout in one place and names the blocks and the limit', function (): void {
    $rule = reviewLayoutRepoFile('rules/code-review/general.md');
    $section = installerDocsSection($rule, '## One published comment per review run');

    expect($section)->toContain('The visible body of the review has one owner, `@skills/code-review-github/templates/review-body.md`')
        ->and($section)->toContain('a summary paragraph of what the pull request changes (at most 500 characters, with no label and no heading)')
        ->and($section)->toContain('a collapsed **Merge Risk** block, a collapsed **Walkthrough** block, a collapsed **Where to test** block')
        ->and($section)->toContain('a collapsed **Actionable comments** block')
        ->and($section)->toContain('no pre-merge checks, no recent review information, no CI result, no button, and no share link')
        ->and($section)->toContain('*Route Access of Changed Routes* (a second inventory is never built)')
        ->and($section)->not->toContain('visible TL;DR')
        ->and($section)->not->toContain('CI result of the reviewed head');
});

test('the code review rule has one section that bans code in a review and states what stays allowed', function (): void {
    $rule = reviewLayoutRepoFile('rules/code-review/general.md');
    $section = installerDocsSection($rule, '### A review carries no code');

    expect(substr_count($rule, '### A review carries no code'))->toBe(1)
        ->and($section)->toContain(
            'An agent never writes code into a published review output: the review body, or the review report and its record.',
        )
        ->and($section)->toContain('The fix is a plain sentence.')
        ->and($section)->toContain('The faulty example is a description.')
        ->and($section)->toContain('No fenced block and no `suggestion` block')
        ->and($section)->toContain('the mermaid sequence diagram of the Walkthrough')
        ->and($section)->toContain('**Code examples in rules and references stay** for the agents that generate code.')
        ->and($section)->toContain(
            'A snippet for the fixing agent travels in the review handoff only, never in a published output, '
            . 'except the corrected code of a finding\'s lines',
        )
        ->and($section)->toContain('The suggested code of an Actionable comments entry below is the one exception.')
        ->and($section)->toContain('A review output template that asks for a snippet is a Moderate finding.')
        ->and($section)->toContain('**The one exception is the suggested code of an Actionable comments entry.**')
        ->and($section)->toContain(
            'The `Fix:` sentence stays, the hidden record keeps the sentence only, and a finding with no code fix carries the sentence alone.',
        )
        ->and($section)->not->toContain('A rule that asks for a snippet');
});

/**
 * @return list<string> the templates of the published review outputs: the review body and the review reports and their
 *                      hidden record. Only these are scanned for code; rules and references keep their code
 *                      examples for the agents that generate code (operator decision on issue #595).
 */
function publishedReviewOutputTemplates(): array
{
    return [
        'skills/code-review-github/templates/review-body.md',
        'skills/code-review-github/templates/pr-comment-output.md',
        'skills/code-review/templates/review-output.md',
        'skills/api-review/templates/review-output.md',
        'skills/security-review/templates/audit-report.md',
    ];
}

test('no published review output template but the review body asks for a code snippet as the fix or the faulty example', function (string $path): void {
    $banned = '/corrected (code )?snippet|minimal corrected|minimal code snippet|runnable snippet|corrected code'
        . '|carrying a concrete [A-Za-z \/-]*snippet|concrete (SQL|query)[^.\n]{0,60}(rewrite|snippet)'
        . '|index DDL, or batch-operation (snippet|replacement)|Add a code snippet only/i';

    expect(preg_match($banned, reviewLayoutRepoFile($path)))->toBe(0);
})->with(array_values(array_diff(publishedReviewOutputTemplates(), ['skills/code-review-github/templates/review-body.md'])));

test('the review body asks for the suggested code of a finding, as the corrected code of its lines', function (): void {
    expect(reviewLayoutRepoFile('skills/code-review-github/templates/review-body.md'))
        ->toContain('{the corrected code of those lines, as written}')
        ->toContain('The code is the corrected code of the finding\'s lines and renders as written.');
});

test('rules and lens skills keep their code examples for the agents that generate code', function (): void {
    $coreAnalysis = reviewLayoutRepoFile('rules/code-review/core-analysis.md');

    expect($coreAnalysis)->toContain('`scopeEnabled(Builder $query): Builder => $query->where(\'status\', Status::Active)`')
        ->and($coreAnalysis)->toContain('**Expected Behavior:** `return $this->repository->findActive($customerId);`.')
        ->and($coreAnalysis)->toContain(
            '**A literal Suggested Fix template below is a handoff instruction.** Its text, code included, is the fix instruction that the '
            . 'review handoff carries to the fixing agent. The published `Suggested Fix` and `**Fix:**` field states the same fix as a plain '
            . 'sentence with no code (`@rules/code-review/general.md` *A review carries no code*).',
        )
        ->and(substr_count($coreAnalysis, 'is a handoff instruction.'))->toBe(1)
        ->and(strpos($coreAnalysis, 'is a handoff instruction.'))->toBeLessThan((int) strpos($coreAnalysis, '**Suggested Fix:** `In'))
        ->and(reviewLayoutRepoFile('skills/mysql-patterns/SKILL.md'))->toContain(
            'is emitted as a written proposal carrying a concrete SQL / query-builder snippet, never applied to the project. '
            . 'The snippet reaches the fixing agent through the review handoff only; the published review states the proposal in plain sentences',
        );
});

test('no review output template renders a fenced code block for a finding', function (string $path): void {
    expect(reviewLayoutRepoFile($path))->not->toMatch('/^\s*```(php|sql|suggestion|js|ts|bash|sh|json|diff)/m');
})->with(publishedReviewOutputTemplates());

test('every review output template says a faulty example and a suggested fix carry no code', function (string $path): void {
    $template = reviewLayoutRepoFile($path);

    expect($template)->toMatch('/Faulty Example[^\n]*(no code|with no code)/i')
        ->and($template)->toMatch('/(Suggested Fix|Suggested fix)[^\n]*(no snippet|No snippet|no fenced block)/i');
})->with([
    'skills/code-review-github/templates/pr-comment-output.md',
    'skills/code-review/templates/review-output.md',
    'skills/api-review/templates/review-output.md',
    'skills/security-review/templates/audit-report.md',
]);

test('the review body template has the Fix line and the suggested code, and the wrapper refuses any other code', function (): void {
    $template = reviewLayoutRepoFile('skills/code-review-github/templates/review-body.md');
    $script = reviewLayoutRepoFile('skills/code-review-github/scripts/post-inline-review.sh');

    expect($template)->toContain('**Fix:** {what to change, in one sentence}')
        ->and($template)->toContain('**Suggested code** (replaces lines {start}–{end}):')
        ->and($template)->toContain(
            'Two fences are allowed: the mermaid sequence diagram of the Walkthrough, and the suggested code of an Actionable comments entry',
        )
        ->and($script)->toContain('a review carries no faulty example code: leave out faulty_example and faulty_example_lang')
        ->and($script)->toContain('the visible review body holds a fenced block other than the sequence diagram and the suggested code of a finding');
});

test('the wrapper contract names the walkthrough file, its sources, and the reused route inventory', function (): void {
    $contract = reviewLayoutRepoFile('skills/code-review-github/references/cr-wrapper-contract.md');

    expect($contract)->toContain('--walkthrough-file <walkthrough-file>')
        ->and($contract)->toContain('`tldr` (what the pull request changes, in plain sentences, at most 500 characters)')
        ->and($contract)->toContain('Route Access of Changed Routes')
        ->and($contract)->toContain('reused, never rebuilt, and never read from a running application')
        ->and($contract)->toContain('The wrapper refuses a record without it')
        ->and($contract)->toContain('the author lists no test file and no row for the tests, unless the pull request changes only tests');
});

test('the Walkthrough lists no test file, in the template, the wrapper header, and the fixture', function (): void {
    $template = reviewLayoutRepoFile('skills/code-review-github/templates/review-body.md');
    $script = (string) preg_replace('/\s*\n#\s*/', ' ', reviewLayoutRepoFile('skills/code-review-github/scripts/post-inline-review.sh'));
    /** @var array{areas: list<array{files: list<string>}>} $walkthrough */
    $walkthrough = json_decode(reviewLayoutRepoFile('tests/Fixtures/review-layout/walkthrough.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    $files = array_merge(...array_column($walkthrough['areas'], 'files'));

    expect($template)->toContain('**The Walkthrough lists no test file.** A row names production files only, and the table has no row for the tests.')
        ->and($template)->toContain('A pull request that changes only tests is the one exception.')
        ->and($script)->toContain('a walkthrough area names a test file: the walkthrough lists no test file unless the pull request changes only tests')
        ->and($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect($file)->not->toStartWith('tests/');
    }
});
