<?php

declare(strict_types = 1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function effortSource(string $relativePath): string
{
    return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath));
}

/**
 * @return array{exit: int|null, values: array<string, string>, error: string}
 */
function deriveEffort(string $assignment, ?string $tier): array
{
    $filesystem = new Filesystem();
    $directory = sys_get_temp_dir() . '/derive-effort-' . bin2hex(random_bytes(6));
    $filesystem->mkdir($directory);
    $filesystem->dumpFile($directory . '/assignment.md', $assignment);

    $command = ['bash', dirname(__DIR__, 2) . '/skills/github-issue-triage/scripts/derive-effort.sh', '--assignment', $directory . '/assignment.md'];

    if ($tier !== null) {
        $command[] = '--tier';
        $command[] = $tier;
    }

    $process = new Process($command);
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

function effortAssignment(int $behaviours, int $rules, string $extra = ''): string
{
    $bullets = static fn (int $count): string => implode(
        "\n",
        array_map(static fn (int $number): string => '- item ' . $number, range(1, max(1, $count))),
    );

    return "Problem today.\n\n<details open>\n<summary><strong>What the user must be able to do</strong></summary>\n\n"
        . $bullets($behaviours) . "\n\n</details>\n\n<details open>\n<summary><strong>The rules</strong></summary>\n\n"
        . $bullets($rules) . "\n\n</details>\n" . $extra;
}

test('the helper derives the size from the count of files and criteria and prints the method with it', function (): void {
    $small = deriveEffort(effortAssignment(2, 3), 'STANDARD');
    $medium = deriveEffort(effortAssignment(3, 3), 'STANDARD');
    $large = deriveEffort(effortAssignment(5, 6), 'STANDARD');

    expect($small['exit'])->toBe(0)
        ->and($small['values']['label'])->toBe('effort: small')
        ->and($small['values']['count'])->toBe('5')
        ->and($small['values']['method'])->toBe('files-and-criteria')
        ->and($medium['values']['label'])->toBe('effort: medium')
        ->and($large['values']['label'])->toBe('effort: large')
        ->and($large['values']['split-candidate'])->toBe('yes')
        ->and($small['values']['split-candidate'])->toBe('no')
        ->and($small['values']['thresholds'])->toBe('small<=5 medium<=10 large>=11');
});

test('a named file adds to the count once, and a CRITICAL tier raises the size by one step', function (): void {
    $withFile = deriveEffort(effortAssignment(2, 3, "Touch skills/a/SKILL.md and skills/a/SKILL.md.\n"), 'FAST');
    $raised = deriveEffort(effortAssignment(2, 3), 'CRITICAL');

    expect($withFile['values']['files'])->toBe('1')
        ->and($withFile['values']['label'])->toBe('effort: medium')
        ->and($raised['values']['label'])->toBe('effort: medium')
        ->and($raised['values']['tier-raise'])->toBe('yes');
});

test('the same assignment text always gets the same label', function (): void {
    $text = effortAssignment(4, 4);

    expect(deriveEffort($text, 'STANDARD')['values'])->toBe(deriveEffort($text, 'STANDARD')['values']);
});

test('the helper takes the tier from the risk classifier when the caller gives none', function (): void {
    $result = deriveEffort(effortAssignment(2, 2), tier: null);

    expect($result['exit'])->toBe(0)
        ->and($result['values']['tier-source'])->toBe('classify-risk')
        ->and($result['values']['tier'])->toBeIn(['FAST', 'STANDARD', 'CRITICAL']);
});

test('the helper refuses an unknown tier and an unreadable assignment', function (): void {
    $unknown = deriveEffort(effortAssignment(1, 1), 'HUGE');
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

test('the helper states its thresholds once and publishes no time', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/github-issue-triage/scripts/derive-effort.sh');
    $skill = effortSource('skills/github-issue-triage/SKILL.md');
    $pending = effortSource('skills/github-issue-triage/references/pending-analysis.md');

    expect($script)->toContain('SMALL_MAX=5')
        ->and($script)->toContain('MEDIUM_MAX=10')
        ->and(substr_count($script, 'SMALL_MAX=5'))->toBe(1)
        ->and($script)->toContain('--self-test')
        ->and($skill)->not->toMatch('/small<=|medium<=|count[^.]{0,20}(?:1-5|6-10)/')
        ->and($skill)->toContain('never a time')
        ->and($pending)->toContain('It states no hours or days');
});

test('the effort labels are seeded and the skill names the one-label-at-most rule and the split candidate', function (): void {
    $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/github-issue-triage/scripts/seed-labels.sh');
    $skill = effortSource('skills/github-issue-triage/SKILL.md');

    foreach (['effort: small', 'effort: medium', 'effort: large'] as $label) {
        expect($seed)->toContain('"' . $label . '|')
            ->and($skill)->toContain('`' . $label . '`');
    }

    expect($skill)->toContain('at most one per issue')
        ->and($skill)->toContain('A new analysis replaces the old label')
        ->and($skill)->toContain('A `CRITICAL` tier raises the size by one step')
        ->and($skill)->toContain('`@skills/do-it/SKILL.md` *Derive the keys of each item*')
        ->and($skill)->toContain('`skills/_shared/classify-risk.sh --assignment <file>`')
        ->and($skill)->toContain('`large` means yes');
});

test('the pending-analysis pass derives the effort, names it in the analysis, and replaces the old label', function (): void {
    $pending = effortSource('skills/github-issue-triage/references/pending-analysis.md');

    expect($pending)->toContain('skills/github-issue-triage/scripts/derive-effort.sh --assignment <issue-body-file>')
        ->and($pending)->toContain('Add an **Effort:** field after the **Priority:** field of section 11 of the report')
        ->and($pending)->toContain('the count with its method')
        ->and($pending)->toContain('and remove the other effort labels the issue carries')
        ->and($pending)->toContain('--remove-label "<replaced effort>" --add-label "<effort label>"')
        ->and($pending)->toContain('An issue labelled `effort: large` is named as a split candidate; the pass never splits it');
});

test('the effort helper self-test runs as part of the project build', function (): void {
    $script = dirname(__DIR__, 2) . '/skills/github-issue-triage/scripts/derive-effort.sh';
    $composer = (array) json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        associative: true,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );

    /** @var array<string, array<int, string>> $scripts */
    $scripts = $composer['scripts'];

    expect(is_executable($script))->toBeTrue()
        ->and(implode(' ', $scripts['shell-self-tests']))->toContain(' skills/github-issue-triage/scripts/derive-effort.sh');
});
