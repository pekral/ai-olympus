<?php

declare(strict_types = 1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * @return array{exit: int|null, values: array<string, string>, error: string}
 */
function runAssignmentScript(string $script, string $assignment, string ...$options): array
{
    $filesystem = new Filesystem();
    $directory = sys_get_temp_dir() . '/assignment-script-' . bin2hex(random_bytes(6));
    $filesystem->mkdir($directory);
    $filesystem->dumpFile($directory . '/assignment.md', $assignment);

    $process = new Process(['bash', dirname(__DIR__, 2) . '/' . $script, '--assignment', $directory . '/assignment.md', ...$options]);
    $process->run();
    $filesystem->remove($directory);

    $values = [];

    foreach (explode("\n", trim($process->getOutput())) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }
    }

    return ['exit' => $process->getExitCode(), 'values' => $values, 'error' => trim($process->getErrorOutput())];
}

/**
 * @return array{exit: int|null, values: array<string, string>, error: string}
 */
function countAssignment(string $assignment): array
{
    return runAssignmentScript('skills/_shared/count-assignment.sh', $assignment);
}

/**
 * @return array{exit: int|null, values: array<string, string>, error: string}
 */
function deriveEffort(string $assignment, ?string $tier): array
{
    return runAssignmentScript('skills/github-issue-triage/scripts/derive-effort.sh', $assignment, ...($tier === null ? [] : ['--tier', $tier]));
}

/**
 * @return array{small: int, medium: int}
 */
function effortThresholds(): array
{
    $script = packageSource('skills/github-issue-triage/scripts/derive-effort.sh');
    $read = static fn (string $name): int => preg_match('/^' . $name . '=(\d+)$/m', $script, $match) === 1
        ? (int) $match[1]
        : throw new RuntimeException($name . ' is not defined in derive-effort.sh');

    return ['small' => $read('SMALL_MAX'), 'medium' => $read('MEDIUM_MAX')];
}

function effortAssignment(int $criteria, string $extra = ''): string
{
    return "Problem today.\n\n<details open>\n<summary><strong>The rules</strong></summary>\n\n"
        . implode("\n", array_map(static fn (int $number): string => '- item ' . $number, range(1, max(1, $criteria))))
        . "\n\n</details>\n" . $extra;
}

test('the helper sizes the count against its constants and prints the method with it', function (): void {
    $limits = effortThresholds();
    $small = deriveEffort(effortAssignment($limits['small']), 'STANDARD');
    $medium = deriveEffort(effortAssignment($limits['small'] + 1), 'STANDARD');
    $atMedium = deriveEffort(effortAssignment($limits['medium']), 'STANDARD');
    $large = deriveEffort(effortAssignment($limits['medium'] + 1), 'STANDARD');

    expect($small['exit'])->toBe(0)
        ->and($small['values']['label'])->toBe('effort: small')
        ->and($small['values']['count'])->toBe((string) $limits['small'])
        ->and($small['values']['method'])->toBe('files-and-criteria')
        ->and($medium['values']['label'])->toBe('effort: medium')
        ->and($atMedium['values']['label'])->toBe('effort: medium')
        ->and($large['values']['label'])->toBe('effort: large')
        ->and($large['values']['split-candidate'])->toBe('yes')
        ->and($small['values']['split-candidate'])->toBe('no')
        ->and($small['values']['thresholds'])->toBe(sprintf('small<=%d medium<=%d large>=%d', $limits['small'], $limits['medium'], $limits['medium'] + 1));
});

test('a CRITICAL tier raises the size by one step and a FAST tier changes nothing', function (): void {
    $limits = effortThresholds();
    $small = effortAssignment($limits['small']);
    $medium = effortAssignment($limits['small'] + 1);

    expect(deriveEffort($small, 'CRITICAL')['values']['label'])->toBe('effort: medium')
        ->and(deriveEffort($medium, 'CRITICAL')['values']['label'])->toBe('effort: large')
        ->and(deriveEffort($medium, 'CRITICAL')['values']['tier-raise'])->toBe('yes')
        ->and(deriveEffort($small, 'FAST')['values']['label'])->toBe('effort: small')
        ->and(deriveEffort($medium, 'FAST')['values']['label'])->toBe('effort: medium');
});

test('the same assignment text always gets the same label', function (): void {
    $text = effortAssignment(4);

    expect(deriveEffort($text, 'STANDARD')['values'])->toBe(deriveEffort($text, 'STANDARD')['values']);
});

test('the helper takes the tier from the risk classifier when the caller gives none', function (): void {
    $result = deriveEffort(effortAssignment(2), tier: null);

    expect($result['exit'])->toBe(0)
        ->and($result['values']['tier-source'])->toBe('classify-risk')
        ->and($result['values']['tier'])->toBeIn(['FAST', 'STANDARD', 'CRITICAL']);
});

test('the helper refuses an unknown tier and an unreadable assignment', function (): void {
    $unknown = deriveEffort(effortAssignment(1), 'HUGE');
    $process = new Process(
        ['bash', dirname(
            __DIR__,
            2,
        ) . '/skills/github-issue-triage/scripts/derive-effort.sh', '--assignment', '/nonexistent/assignment.md', '--tier', 'FAST',
        ],
    );
    $process->run();

    expect($unknown['exit'])->toBe(1)
        ->and($process->getExitCode())->toBe(1);
});

test('the counter ignores an unclosed fence, a tilde fence, and a mixed fence the way the risk classifier does', function (): void {
    $unclosed = countAssignment("# The rules\n\n- one\n```text\n- two\n- three\nsrc/Late/File.php\n");
    $tilde = countAssignment("# The rules\n\n- one\n~~~text\nsrc/Hidden/File.php\n- hidden\n~~~\n- two\n");
    $backtick = countAssignment("# The rules\n\n- one\n```text\nsrc/Hidden/File.php\n- hidden\n```\n- two\n");
    $mixed = countAssignment("# The rules\n\n- one\n~~~text\n- inside\n```\n- still inside\n~~~\n- after\n");

    expect($unclosed['values']['criteria'])->toBe('3')
        ->and($unclosed['values']['files'])->toBe('1')
        ->and($tilde['values']['criteria'])->toBe('2')
        ->and($tilde['values']['files'])->toBe('0')
        ->and($backtick['values']['criteria'])->toBe('2')
        ->and($backtick['values']['files'])->toBe('0')
        ->and($mixed['values']['criteria'])->toBe('2');
});

test('the fence rule has one owner that both the counter and the risk classifier call', function (): void {
    $vocabulary = packageSource('skills/_shared/domain-vocabulary.sh');
    $counter = packageSource('skills/_shared/count-assignment.sh');
    $classifier = packageSource('skills/_shared/classify-risk.sh');

    expect($vocabulary)->toContain('strip_assignment_fences() {')
        ->and($counter)->toContain('strip_assignment_fences |')
        ->and($classifier)->toContain('strip_assignment_fences | awk')
        ->and($counter)->not->toContain('buffer[')
        ->and($classifier)->not->toContain('(```|~~~)')
        ->and($counter)->not->toMatch('/fence\s*=\s*1/');
});

test('the business headings are one set, in English and Czech, that the classifier and the counter share', function (): void {
    $vocabulary = packageSource('skills/_shared/domain-vocabulary.sh');
    $classifier = packageSource('skills/_shared/classify-risk.sh');
    $details = static fn (string $title, string ...$bullets): string => "<details open>\n<summary><strong>" . $title . "</strong></summary>\n\n"
        . implode("\n", array_map(static fn (string $bullet): string => '- ' . $bullet, $bullets)) . "\n\n</details>\n\n";
    $czech = countAssignment(
        $details('Co musí uživatel umět', 'a') . $details('Pravidla', 'b', 'c') . $details('Co teď neděláme', 'd', 'e', 'f', 'g'),
    );

    expect($vocabulary)->toContain('ASSIGNMENT_BUSINESS_HEADINGS_RE=\'the rules|what the user must be able to do|pravidla|co musí uživatel umět\'')
        ->and($classifier)->toContain('$ASSIGNMENT_BUSINESS_HEADINGS_RE')
        ->and($classifier)->not->toContain('(the rules|what the user must be able to do|pravidla|co musí uživatel umět)')
        ->and($czech['values']['criteria'])->toBe('3')
        ->and($czech['values']['criteria-source'])->toBe('sections');
});

test('a heading counts only when its whole name is a counted section name', function (): void {
    $contains = countAssignment("# How we break the rules of the game\n\n- a\n- b\n\n# The rules\n\n- c\n");
    $startsWith = countAssignment("# Rules and limits\n\n- a\n- b\n- c\n");

    expect($contains['values']['criteria'])->toBe('1')
        ->and($startsWith['values']['criteria-source'])->toBe('bullets');
});

test('the original assignment adds no bullet and no file in either counting mode', function (): void {
    $original = "<details>\n<summary><strong>Original assignment</strong></summary>\n\n"
        . "- q1\n- q2\n- q3\n- q4\n- q5\n- q6\nsrc/Quoted/File.php\n\n</details>\n";
    $fallback = countAssignment("- own\n\n" . $original);
    $sections = countAssignment(effortAssignment(1, $original));

    expect($fallback['values']['criteria'])->toBe('1')
        ->and($fallback['values']['criteria-source'])->toBe('bullets')
        ->and($fallback['values']['files'])->toBe('0')
        ->and($sections['values']['criteria'])->toBe('1')
        ->and($sections['values']['files'])->toBe('0');
});

/**
 * @return array<string, array{string, string, string}>
 */
function originalBlockVariants(): array
{
    $quoted = "- q1\n# A heading inside\n- q2\n**A bold line**\n- q3\n<summary><strong>The rules</strong></summary>\n- q4\nsrc/Quoted/File.php\n- q5";
    $open = "<details>\n<summary><strong>Original assignment</strong></summary>\n\n";

    $rules = "# The rules\n\n- one\n\n";
    $own = "- own\n\n";
    $close = "\n\n</details>\n";
    $inner = "- a\n<details>\n<summary>inner</summary>\n- b\n</details>\n- c\nsrc/Inner.php";
    $fenced = "- a\n```text\n</details>\n```\n- b\nsrc/Quoted/File.php";
    $markdown = "# The rules\n\n- one\n\n# Original assignment\n\n- a\n## Sub\n- b\nsrc/Quoted/File.php\n\n# Out\n\n- after\n";

    return [
        'block in the middle, sections' => [$rules . $open . $quoted . $close . "\n# Out\n\n- after\n", '1', '0'],
        'block last, fallback' => [$own . $open . $quoted . $close, '1', '0'],
        'block last, sections' => [$rules . $open . $quoted . $close, '1', '0'],
        'closing tag inside a fence' => [$own . $open . $fenced . $close . "\n- after\n", '2', '0'],
        'CRLF line endings' => [str_replace("\n", "\r\n", $own . $open . $quoted . $close), '1', '0'],
        'Czech heading name' => [str_replace('Original assignment', 'Původní zadání', $own . $open . $quoted . $close), '1', '0'],
        'Markdown heading ended by a shallower heading' => ["## The rules\n\n- one\n\n## Original assignment\n\n- a\n# The rules\n- b\n", '2', '0'],
        'Markdown heading with a deeper heading inside' => [$markdown, '1', '0'],
        'nested block inside the quote' => [$own . $open . $inner . $close . "\n- after\n", '2', '0'],
        'no closing tag' => [$rules . $open . $quoted . "\n", '1', '0'],
    ];
}

test('a quoted original assignment is skipped as one block, whatever it holds inside', function (string $text, string $criteria, string $files): void {
    $counted = countAssignment($text);

    expect($counted['values']['criteria'])->toBe($criteria)
        ->and($counted['values']['files'])->toBe($files);
})->with(originalBlockVariants());

test('a named file adds to the count once and a URL is no file', function (): void {
    $files = countAssignment(effortAssignment(2, "Touch skills/a/SKILL.md and skills/a/SKILL.md, see https://example.com/docs/page.html.\n"));

    expect($files['values']['files'])->toBe('1')
        ->and($files['values']['count'])->toBe('3');
});

test('do-it and the triage read the one count, and the counter owns the method', function (): void {
    $doIt = normalizedPackageSource('skills/do-it/SKILL.md');
    $triage = normalizedPackageSource('skills/github-issue-triage/SKILL.md');
    $derive = packageSource('skills/github-issue-triage/scripts/derive-effort.sh');
    $counter = packageSource('skills/_shared/count-assignment.sh');

    expect($doIt)->toContain('the `count=` line of `skills/_shared/count-assignment.sh --assignment <a file holding the text of the item>`')
        ->and($doIt)->toContain('`@skills/github-issue-triage/SKILL.md` *Effort derivation* reads the same line')
        ->and($triage)->toContain('the `count=` line of `skills/_shared/count-assignment.sh --assignment <file>`')
        ->and($triage)->toContain('the one owner of the method `files-and-criteria`')
        ->and($derive)->toContain('count-assignment.sh')
        ->and($derive)->not->toContain('match(rest')
        ->and($counter)->toContain('the one owner of the method `files-and-criteria`')
        ->and($counter)->toContain('--self-test');
});

test('the thresholds are stated once, as the constants, and no other text carries them', function (): void {
    $script = packageSource('skills/github-issue-triage/scripts/derive-effort.sh');
    $header = substr($script, 0, (int) strpos($script, 'set -euo pipefail'));
    $skill = normalizedPackageSource('skills/github-issue-triage/SKILL.md');
    $pending = normalizedPackageSource('skills/github-issue-triage/references/pending-analysis.md');

    expect(substr_count($script, 'SMALL_MAX=5'))->toBe(1)
        ->and(substr_count($script, 'MEDIUM_MAX=10'))->toBe(1)
        ->and($header)->not->toMatch('/\d+\s*-\s*\d+|(?:<=|>=|<|>)\s*\d+|\b(?:up to|over|above|below)\s+\d+/')
        ->and($header)->toContain('SMALL_MAX and MEDIUM_MAX below are the only statement of them')
        ->and($script)->toContain('--self-test')
        ->and($skill)->not->toMatch('/small<=|medium<=|large>=|\b(?:1-5|6-10)\b/')
        ->and($skill)->toContain('never a time')
        ->and($pending)->toContain('It states no hours or days');
});

test('the effort labels are seeded and the skill names the one-label-at-most rule and the split candidate', function (): void {
    $seed = packageSource('skills/github-issue-triage/scripts/seed-labels.sh');
    $skill = normalizedPackageSource('skills/github-issue-triage/SKILL.md');

    foreach (['effort: small', 'effort: medium', 'effort: large'] as $label) {
        expect($seed)->toContain('"' . $label . '|')
            ->and($skill)->toContain('`' . $label . '`');
    }

    expect($skill)->toContain('at most one per issue')
        ->and($skill)->toContain('A new analysis replaces the old label')
        ->and($skill)->toContain('A `CRITICAL` tier raises the size by one step')
        ->and($skill)->toContain('`skills/_shared/classify-risk.sh --assignment <file>`')
        ->and($skill)->toContain('`large` means yes');
});

test('the pending-analysis pass derives the effort from the assignment it produced, names it, and replaces the old label', function (): void {
    $pending = normalizedPackageSource('skills/github-issue-triage/references/pending-analysis.md');

    expect($pending)->toContain('Derive it from the assignment text this pass produced for the issue, when it produced one')
        ->and($pending)->toContain('Only when the pass produced no assignment text, save the issue body instead')
        ->and($pending)->toContain('An issue that is only a link then gets its size from the assignment the analysis settled, never from the link')
        ->and($pending)->toContain('skills/github-issue-triage/scripts/derive-effort.sh --assignment <assignment-file>')
        ->and($pending)->toContain('Add an **Effort:** field after the **Priority:** field of section 11 of the report')
        ->and($pending)->toContain('the count with its method')
        ->and($pending)->toContain('and remove the other effort labels the issue carries')
        ->and($pending)->toContain('--remove-label "<replaced effort>" --add-label "<effort label>"')
        ->and($pending)->toContain('An issue labelled `effort: large` is named as a split candidate; the pass never splits it');
});

test('both effort self-tests run as part of the project build', function (): void {
    $composer = (array) json_decode(
        packageSource('composer.json'),
        associative: true,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );

    /** @var array<string, array<int, string>> $scripts */
    $scripts = $composer['scripts'];

    foreach (['skills/github-issue-triage/scripts/derive-effort.sh', 'skills/_shared/count-assignment.sh'] as $script) {
        expect(is_executable(dirname(__DIR__, 2) . '/' . $script))->toBeTrue()
            ->and(implode(' ', $scripts['shell-self-tests']))->toContain(' ' . $script);
    }
});
