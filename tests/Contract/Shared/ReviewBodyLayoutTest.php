<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

/**
 * Guards the visible layout of the GitHub pull-request review (templates/review-body.md): a golden file for the
 * rendered body and the inline comments, and the structural rules of the layout. Every test runs the real renderer,
 * post-inline-review.sh, against a stand-in `gh`; no test judges text with a model.
 *
 * To change the layout on purpose, run `UPDATE_REVIEW_LAYOUT_GOLDEN=1 vendor/bin/pest tests/Contract/Shared/ReviewBodyLayoutTest.php`
 * and review the diff of tests/Fixtures/review-layout/expected-*.md.
 */
const REVIEW_LAYOUT_HEAD = '7c1d2aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const REVIEW_LAYOUT_RECORD_MARKER = "\n\n<!-- cr-review-meta\n";

function reviewLayoutFixtureDir(): string
{
    return dirname(__DIR__, 2) . '/Fixtures/review-layout';
}

/**
 * @return array<mixed>
 */
function reviewLayoutJson(string $file): array
{
    $decoded = json_decode(
        (string) file_get_contents(reviewLayoutFixtureDir() . '/' . $file),
        associative: true,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );
    assert(is_array($decoded));

    return $decoded;
}

function reviewLayoutSummary(): string
{
    $summary = reviewLayoutJson('walkthrough.json')['tldr'];
    assert(is_string($summary));

    return $summary;
}

/**
 * @return list<array<mixed>>
 */
function reviewLayoutFindings(): array
{
    $findings = [];

    foreach (reviewLayoutJson('findings.json') as $finding) {
        assert(is_array($finding));
        $findings[] = $finding;
    }

    return $findings;
}

function reviewLayoutGhStub(): string
{
    return <<<'STUB'
        #!/usr/bin/env bash
        set -euo pipefail
        case "$*" in
          "api user --jq .login") echo bot ;;
          "repo view "*) echo true ;;
          "api graphql "*) cat "$REVIEW_WORK/threads.json" ;;
          "api repos/o/r/pulls/7/files"*) cat "$REVIEW_WORK/files.json" ;;
          "api repos/o/r/git/trees/"*) cat "$REVIEW_WORK/tree.json" ;;
          "api repos/o/r/pulls/7/reviews"*)
            cat >"$REVIEW_WORK/review.json"
            printf 'x\n' >>"$REVIEW_WORK/review.calls"
            echo '{"id":99,"html_url":"https://github.com/o/r/pull/7#pullrequestreview-99"}'
            ;;
          "api repos/o/r/pulls/7") printf '{"head":{"sha":"%s"}}\n' "$REVIEW_HEAD" ;;
          *) echo "gh stub: unexpected call: $*" >&2; exit 1 ;;
        esac
        STUB;
}

/**
 * Writes the files the stand-in `gh` and the script read into a new directory.
 *
 * @param array<string, mixed> $walkthroughChanges keys set on the walkthrough object; a null value removes the key
 * @param list<array<mixed>>|null $findings the findings document; the fixture findings when null
 */
function reviewLayoutPrepareWork(array $walkthroughChanges, ?array $findings): string
{
    $work = sys_get_temp_dir() . '/ai-olympus-review-layout-' . bin2hex(random_bytes(4));
    installerEnsureDirectory($work . '/bin');

    foreach (['files.json', 'tree.json', 'record.md'] as $file) {
        copy(reviewLayoutFixtureDir() . '/' . $file, $work . '/' . $file);
    }

    $walkthrough = reviewLayoutJson('walkthrough.json');

    foreach ($walkthroughChanges as $key => $value) {
        if ($value === null) {
            unset($walkthrough[$key]);

            continue;
        }

        $walkthrough[$key] = $value;
    }

    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    file_put_contents($work . '/walkthrough.json', json_encode($walkthrough, $flags));
    file_put_contents($work . '/findings.json', json_encode($findings ?? reviewLayoutFindings(), $flags));
    file_put_contents(
        $work . '/threads.json',
        '{"data":{"repository":{"pullRequest":{"reviewThreads":{"pageInfo":{"hasNextPage":false,"endCursor":null},"nodes":[]}}}}}',
    );
    file_put_contents($work . '/bin/gh', reviewLayoutGhStub());
    chmod($work . '/bin/gh', 0755);

    return $work;
}

/**
 * @return array{exit: int, stderr: string, body: string, visible: string, comments: list<string>, requests: int}
 */
function reviewLayoutResult(Process $process, string $work): array
{
    $result = [
        'body' => '',
        'comments' => [],
        'exit' => (int) $process->getExitCode(),
        'requests' => 0,
        'stderr' => $process->getErrorOutput(),
        'visible' => '',
    ];

    if (!is_file($work . '/review.json')) {
        return $result;
    }

    $review = json_decode((string) file_get_contents($work . '/review.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
    assert(is_array($review) && is_string($review['body']) && is_array($review['comments']));
    $result['body'] = $review['body'];
    $result['visible'] = explode(REVIEW_LAYOUT_RECORD_MARKER, $review['body'])[0];

    foreach ($review['comments'] as $comment) {
        assert(is_array($comment) && is_string($comment['body']));
        $result['comments'][] = $comment['body'];
    }

    $calls = file($work . '/review.calls', FILE_IGNORE_NEW_LINES);
    $result['requests'] = $calls === false ? 0 : count($calls);

    return $result;
}

/**
 * Renders one review through the real script against a stand-in `gh`.
 *
 * @param array<string, mixed> $walkthroughChanges keys set on the walkthrough object; a null value removes the key
 * @param list<array<mixed>>|null $findings the findings document; the fixture findings when null
 * @return array{exit: int, stderr: string, body: string, visible: string, comments: list<string>, requests: int}
 */
function renderReview(array $walkthroughChanges = [], ?array $findings = null): array
{
    $work = reviewLayoutPrepareWork($walkthroughChanges, $findings);
    $script = dirname(__DIR__, 3) . '/skills/code-review-github/scripts/post-inline-review.sh';
    $process = new Process(
        [
            '/bin/bash', $script, 'post', 'https://github.com/o/r/pull/7',
            '--commit-id', REVIEW_LAYOUT_HEAD,
            '--meta-file', $work . '/record.md',
            '--walkthrough-file', $work . '/walkthrough.json',
            $work . '/findings.json',
        ],
        env: [
            'AUDIT_TRAIL_FILE' => $work . '/ledger.audit',
            'AUDIT_TRAIL_ROLE' => 'leonardo',
            'PATH' => $work . '/bin:' . getenv('PATH'),
            'REVIEW_HEAD' => REVIEW_LAYOUT_HEAD,
            'REVIEW_WORK' => $work,
        ],
    );
    $process->run();

    $result = reviewLayoutResult($process, $work);

    installerRemoveDirectory($work);

    return $result;
}

/**
 * Compares rendered text with a committed expected file, or rewrites the file when the update flag is set.
 */
function reviewLayoutGolden(string $name, string $actual): string
{
    $path = reviewLayoutFixtureDir() . '/' . $name;

    if (getenv('UPDATE_REVIEW_LAYOUT_GOLDEN') === '1') {
        file_put_contents($path, $actual);
    }

    return (string) file_get_contents($path);
}

/**
 * @return list<string> the collapsed blocks of a visible body, in order
 */
function reviewLayoutBlocks(string $visible): array
{
    preg_match_all('#<details>\n<summary>.*?</summary>\n<dl>\n<dd>\n<br>\n\n.*?\n\n</dd>\n</dl>\n</details>#s', $visible, $matches);

    return $matches[0];
}

function reviewLayoutBlock(string $visible, string $title): string
{
    foreach (reviewLayoutBlocks($visible) as $block) {
        if (str_contains($block, '<strong>' . $title)) {
            return $block;
        }
    }

    return '';
}

test('the visible review body is byte for byte the golden file', function (): void {
    $review = renderReview();

    expect($review['exit'])->toBe(0, $review['stderr'])
        ->and($review['visible'])->toBe(reviewLayoutGolden('expected-body.md', $review['visible']));
});

test('every inline comment is byte for byte its golden file', function (int $index): void {
    $review = renderReview();

    expect($review['exit'])->toBe(0, $review['stderr'])
        ->and($review['comments'])->toHaveCount(3)
        ->and($review['comments'][$index - 1])->toBe(reviewLayoutGolden('expected-inline-' . $index . '.md', $review['comments'][$index - 1]));
})->with([1, 2, 3]);

test('the visible body is the summary, then Merge Risk, Walkthrough, Where to test, and Actionable comments, in that order', function (): void {
    $review = renderReview();
    $positions = array_map(
        static fn (string $needle): int|false => strpos($review['visible'], $needle),
        [
            reviewLayoutSummary(),
            '<summary><strong>Merge Risk:</strong>',
            '<summary><strong>Walkthrough</strong>',
            '<summary><strong>Where to test</strong>',
            '<summary><strong>Actionable comments (3)</strong>',
        ],
    );

    $sorted = $positions;
    sort($sorted);

    expect($positions[0])->toBe(0)
        ->and($positions)->not->toContain(false)
        ->and($positions)->toBe($sorted);
});

test('the review opens with the summary paragraph: no TL;DR label, no heading, no bold label before it', function (): void {
    $review = renderReview();
    $head = (string) strstr($review['visible'], '<details>', before_needle: true);

    expect($head)->toBe(reviewLayoutSummary() . "\n\n")
        ->and(stripos($review['visible'], 'TL;DR'))->toBeFalse()
        ->and($head)->not->toMatch('/^\s*(#|\*\*|>|\|)/m');
});

test(
    'the summary holds 4000 code points at most: 3999 and 4000 pass, 4001 is refused and nothing is posted',
    function (int $length, bool $accepted): void {
        $review = renderReview(['tldr' => str_repeat('a', $length)]);
    
        if ($accepted) {
            expect($review['exit'])->toBe(0, $review['stderr'])
                ->and($review['visible'])->toStartWith(str_repeat('a', $length) . "\n\n<details>");
    
            return;
        }
    
        expect($review['exit'])->toBe(1)
            ->and($review['requests'])->toBe(0)
            ->and($review['stderr'])->toContain('tldr is ' . $length . ' characters once normalised');
    },
)->with([
    'at the limit' => [4_000, true],
    'just over' => [4_001, false],
    'just under' => [3_999, true],
]);

test('the summary limit counts code points and not bytes', function (): void {
    $review = renderReview(['tldr' => str_repeat('é', 4_000)]);

    expect($review['exit'])->toBe(0, $review['stderr']);
});

test('every block is a closed details block and nothing else stands between the blocks but the summary', function (): void {
    $review = renderReview();
    $blocks = reviewLayoutBlocks($review['visible']);

    expect(substr_count($review['visible'], '<details>'))->toBe(4)
        ->and(substr_count($review['visible'], '</details>'))->toBe(4)
        ->and($blocks)->toHaveCount(4)
        ->and(trim(str_replace($blocks, '', $review['visible'])))->toBe(reviewLayoutSummary())
        ->and($review['visible'])->not->toMatch('/<details[^>]*\sopen/');
});

test('the one fenced block of the visible body is the mermaid diagram of the Walkthrough', function (): void {
    $review = renderReview();
    $walkthrough = reviewLayoutBlock($review['visible'], 'Walkthrough');

    expect(preg_match_all('/```|~~~/', $review['visible']))->toBe(2)
        ->and(preg_match_all('/```|~~~/', $walkthrough))->toBe(2)
        ->and($walkthrough)->toContain("```mermaid\nsequenceDiagram");
});

test('without a diagram the visible body has no fenced block at all', function (): void {
    $review = renderReview(['diagram' => null]);

    expect($review['exit'])->toBe(0, $review['stderr'])
        ->and($review['visible'])->not->toMatch('/```|~~~/')
        ->and($review['visible'])->not->toContain('Sequence Diagram');
});

test('the Actionable comments, the review body, and every inline comment hold no fence and no suggestion block', function (): void {
    $review = renderReview();
    $actionable = reviewLayoutBlock($review['visible'], 'Actionable comments');

    expect($actionable)->not->toBe('')
        ->and($actionable)->not->toMatch('/```|~~~|suggestion/i')
        ->and($review['body'])->not->toMatch('/```\s*suggestion|~~~\s*suggestion/i')
        ->and($review['comments'])->toHaveCount(3);

    foreach ($review['comments'] as $comment) {
        expect($comment)->not->toMatch('/```|~~~|suggestion/i');
    }
});

test('the visible body shows no pre-merge checks, recent review info, button, badge, image, or share link', function (): void {
    $review = renderReview();

    expect($review['visible'])->not->toMatch(
        '/pre-merge|recent review|explain change|\bshare\b|\bCI\b|<button|<img|<a\s|!\[|shields\.io|badge|https?:\/\//i',
    );
});

test('Where to test is present when the change touches routes and absent when it touches none', function (): void {
    $with = renderReview();
    $without = renderReview(['test_routes' => null]);
    $empty = renderReview(['test_routes' => []]);
    $block = reviewLayoutBlock($with['visible'], 'Where to test');

    expect($block)->toContain("| Method and path | What to try |\n| :--- | :--- |\n")
        ->and($block)->toContain('| `GET /invitations/{token}` | ')
        ->and(preg_match_all('/^\| `(GET|POST|PUT|PATCH|DELETE) \/[^`]*` \| .+ \|$/m', $block))->toBe(3)
        ->and($without['exit'])->toBe(0, $without['stderr'])
        ->and($without['visible'])->not->toContain('Where to test')
        ->and(reviewLayoutBlocks($without['visible']))->toHaveCount(3)
        ->and($empty['visible'])->not->toContain('Where to test');
});

test('every finding carries a Fix sentence, in the Actionable comments and in the inline comment', function (): void {
    $review = renderReview();
    $actionable = reviewLayoutBlock($review['visible'], 'Actionable comments');

    expect(preg_match_all('/^\*\*Fix:\*\* \S.+\.$/m', $actionable))->toBe(3)
        ->and(preg_match_all('/^\*\*(🔴|🟠) (Critical|Moderate) \d+ — .+\*\* · `[^`]+:\d+`$/m', $actionable))->toBe(3);

    foreach ($review['comments'] as $comment) {
        expect($comment)->toMatch('/\n\n\*\*Fix:\*\* \S.+\.\n\n/');
    }
});

test('a round with no finding shows no Actionable comments block and keeps the rest of the layout', function (): void {
    $review = renderReview(findings: []);

    expect($review['exit'])->toBe(0, $review['stderr'])
        ->and($review['visible'])->not->toContain('Actionable comments')
        ->and(reviewLayoutBlocks($review['visible']))->toHaveCount(3)
        ->and($review['comments'])->toBe([]);
});

test('a finding that carries code or a suggestion is refused and nothing is posted', function (array $change): void {
    $findings = reviewLayoutFindings();
    $findings[0] = [...$findings[0], ...$change];
    $review = renderReview(findings: $findings);

    expect($review['exit'])->toBe(1)
        ->and($review['requests'])->toBe(0);
})->with([
    'a faulty example key' => [['faulty_example' => 'echo $v;']],
    'a fenced fix' => [['fix' => "Use\n```php\necho e(\$v);\n```"]],
    'a fenced problem' => [['problem' => "~~~\ncode\n~~~"]],
    'a suggestion key' => [['suggestion' => 'echo e($v);']],
    'no fix' => [['fix' => null]],
]);

test('the Merge Risk block reads its grade and its sentence from the record and names the short head', function (): void {
    $review = renderReview();
    $block = reviewLayoutBlock($review['visible'], 'Merge Risk');

    expect($block)->toContain('<strong>🟡 Medium</strong> · up to <code>' . substr(REVIEW_LAYOUT_HEAD, 0, 7) . '</code>')
        ->and($block)->toContain('A new sign-in path over workspace membership.')
        ->and($block)->not->toContain('impact:');
});
