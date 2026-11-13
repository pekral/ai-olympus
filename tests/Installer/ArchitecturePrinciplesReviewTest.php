<?php

declare(strict_types = 1);

function architecturePrinciplesSection(): string
{
    $walk = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/core-analysis.md');
    $start = strpos($walk, "\n## Architecture principles — one lens, one finding format\n");

    expect($start)->toBeInt();

    return substr($walk, (int) $start);
}

test('one section owns the architecture lens and the four-part finding format', function (): void {
    $walk = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/core-analysis.md');
    $section = architecturePrinciplesSection();

    expect(substr_count($walk, "\n## Architecture principles — one lens, one finding format\n"))->toBe(1)
        ->and(substr_count($section, '**The finding format.**'))->toBe(1)
        ->and($section)->toContain('the changed location (`file:line`), the concrete problem')
        ->and($section)->toContain('the impact (what breaks, or which later change gets harder, shown on the real code)')
        ->and($section)->toContain('the smallest remedy (the least change that removes the problem)')
        ->and($section)->toContain(
            'They map onto the template fields **Location**, the title with **Faulty Example**, **Impact** with **Evidence**, and **Suggested Fix**.',
        )
        ->and($section)->toContain('A finding with no impact proven on the real code is not raised')
        ->and($section)->toContain('`@rules/code-review/general.md` *Default severity for a rule violation*');
});

test('the table names an owner for each of the ten principles', function (): void {
    $section = architecturePrinciplesSection();
    $rows = [];

    foreach (explode("\n", $section) as $line) {
        if (preg_match('/^\| (\d+)\. [^|]+\| (.+) \|$/', $line, $matches) === 1) {
            $rows[(int) $matches[1]] = $matches[2];
        }
    }

    expect(array_keys($rows))->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
        ->and($rows[1])->toContain('**Simplicity First**')
        ->and($rows[1])->toContain(
            '*A public member that no caller uses* of `@rules/compound-engineering/general.md` *Code Review Application*',
        )
        ->and($rows[2])->toContain('**Reuse of existing logic**')
        ->and($rows[3])->toContain('*Unify code only when it changes for the same reason*')
        ->and($rows[4])->toContain('**Dependency the code does not need**')
        ->and($rows[5])->toContain('**Pass-through Action**')
        ->and($rows[6])->toContain('**Flag parameter that selects behaviour**')
        ->and($rows[7])->toContain('**Behaviour changed outside the diff**')
        ->and($rows[8])->toContain('**State change that is not safe under failure or concurrency**')
        ->and($rows[9])->toContain('**Optimisation with no measurement**')
        ->and($rows[10])->toContain('*Minor findings are not detected*');
});

test('each new finding declares a severity, its exemptions, and its gating', function (): void {
    $section = architecturePrinciplesSection();

    $entries = [
        '- **Dependency the code does not need** — ',
        '- **Flag parameter that selects behaviour** — ',
        '- **State change that is not safe under failure or concurrency** — ',
        '- **Optimisation with no measurement** — ',
    ];

    foreach ($entries as $index => $entry) {
        expect(substr_count($section, $entry))->toBe(1);

        $start = (int) strpos($section, $entry);
        $next = $entries[$index + 1] ?? '- **A preference is not an architecture finding';
        $body = substr($section, $start, (int) strpos($section, $next) - $start);

        expect($body)->toContain('Severity: **')
            ->and($body)->toContain('**Not findings:**')
            ->and($body)->toContain('**Gating — one finding per line:**')
            ->and($body)->toContain('**Suggested Fix**');
    }

    expect($section)->toContain('Severity: **Critical** when the traced failure corrupts persisted data, money, or authorization; **Moderate** otherwise.')
        ->and($section)->toContain('a failure the reviewer cannot trace is not raised')
        ->and($section)->toContain('a job\'s retry and backoff setting to `@rules/php/core-standards.md` *Async Review Note*')
        ->and($section)->toContain('A review runs nothing, so it reports the missing measurement, never a measured one.')
        ->and($section)->toContain('a before and after figure from the real operation on data of production shape');
});

test('the entries route to the owner that already raises the finding and stay inside their trace', function (): void {
    $section = architecturePrinciplesSection();

    expect($section)->toContain('declares a parameter typed `Container` or `Application` to fetch one collaborator from it')
        ->and($section)->toContain('lazy resolution of an optional or rarely used dependency (`@rules/laravel/laravel.md` *Dependency Injection*)')
        ->and($section)->toContain('per `@rules/php/core-standards.md` *Named Arguments*')
        ->and($section)->toContain('The **Suggested Fix** is a named argument at the call site')
        ->and($section)->toContain('only when the two behaviours change for different reasons')
        ->and($section)->toContain('for the concurrency trace only (an exception between two writes is still traced)');
});

test('a race, replay, or retry on a security surface is a security finding that keeps the higher severity', function (): void {
    $section = architecturePrinciplesSection();

    expect($section)->toContain(
        'a race, a replay, or a retry on a surface the S3 test of `@rules/code-review/general.md` *Assignment-Declared Test-Only Conditions — Exclusion Gate (issue #17)* names is a security finding. `@skills/security-review/SKILL.md` owns it and it carries the higher of the two severities. An untraced one goes under *Needs verification* and blocks (`@skills/process-code-review/references/review-state.md` *Evidence and blocking*).',
    )
        ->and($section)->toContain('A client retry with no idempotency key belongs to `@skills/api-review/SKILL.md` *Idempotency keys*')
        ->and($section)->toContain('a retry-abusable or non-idempotent job to `@skills/security-review/SKILL.md` *Queues & Background Jobs*')
        ->and($section)->toContain('a replayed payment receipt to `@skills/machine-payments-protocol/SKILL.md` (idempotent settlement)');
});

test('the preference clause keeps the review inside the scope of the change', function (): void {
    $section = architecturePrinciplesSection();

    expect(substr_count($section, '- **A preference is not an architecture finding, and the remedy stays in the change.**'))->toBe(1)
        ->and($section)->toContain('A stylistic preference and a design that is different but equal are not raised.')
        ->and($section)->toContain('never requires a refactoring of untouched code');
});

test('the impact walk covers nullability, overriding subclasses, and implementers', function (): void {
    $walk = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/core-analysis.md');

    expect(substr_count($walk, 'its return value (a `null` it now returns, a type it now accepts)'))->toBe(1)
        ->and(
            substr_count($walk, 'A subclass that overrides a changed method and a class that implements a changed interface are consumers of it.'),
        )->toBe(
            1,
        );
});

test('the principles are stated in the owner only and the review skill and agent point to it', function (): void {
    $files = packageTextFiles();
    $statements = array_filter(
        $files,
        static fn (string $contents, string $path): bool => preg_match('#^(rules|skills|agents|commands)/#', $path) === 1
            && str_contains($contents, '**The finding format.**'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect(array_keys($statements))->toBe(['rules/code-review/core-analysis.md']);

    $pointer = '`@rules/code-review/core-analysis.md` *Architecture principles — one lens, one finding format*';

    expect(
        substr_count(
            $files['skills/code-review/SKILL.md'],
            '- Architecture and design quality — the ten principles and the one finding format of ' . $pointer,
        ),
    )->toBe(
        1,
    )
        ->and(substr_count($files['skills/code-review/SKILL.md'], 'the four findings of *Architecture principles*'))->toBe(1)
        ->and(substr_count($files['agents/leonardo.md'], 'it is raised by the owner that ' . $pointer . ' names for its principle, never by two.'))->toBe(
            1,
        );
});

test('the Codex reviewer reads the shared agent definition that carries the architecture pointer', function (): void {
    $toml = (string) file_get_contents(dirname(__DIR__, 2) . '/codex/agents/leonardo.toml');

    expect($toml)->toContain('First read `.codex/agent-instructions/leonardo.md` in full and follow it as your authoritative role definition.')
        ->and($toml)->toContain('`@rules/<path>` means the matching file below `.codex/rules/<path>`.');
});
