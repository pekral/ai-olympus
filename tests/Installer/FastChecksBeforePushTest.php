<?php

declare(strict_types = 1);

test('the fast-checks runner proves every acceptance scenario in its self-test', function (): void {
    $script = packageSource('skills/_shared/run-fast-checks.sh');

    foreach ([
        'a manifest without fast-checks runs nothing and exits 5',
        'a files entry receives each matching changed file as one literal argument, after its own arguments',
        'no character in a changed path runs as a shell operator',
        'a path that starts with a dash is passed as ./<path>',
        'a deleted file is never passed',
        'a command string runs once as written, without the changed files',
        'an entry whose pattern matches no changed file is skipped',
        'the machine lock is released after the run',
        'a failing check exits 4, and every later check still runs',
        'no changed file to check exits 0 with status skipped and takes no lock',
        'fast-checks that is not an array is refused',
        'fast-checks set only on the branch under review never run',
        'uncommitted changes to tracked files exit 7 and run nothing',
        'a live lock holder makes the run wait, then exit 6 without running a check',
        '--base computes the changed files against the named branch',
    ] as $label) {
        expect($script)->toContain('check \'' . $label . '\'');
    }

    expect($script)->toContain('check "an unacceptable entry refuses the whole run before anything runs: $refusal"');
    expect(is_executable(dirname(__DIR__, 2) . '/skills/_shared/run-fast-checks.sh'))->toBeTrue();

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode(packageSource('composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
    expect(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/run-fast-checks.sh '))->toBe(1);
});

test('the fast-checks runner reads the default-branch manifest, takes the machine lock, and passes a path only as an argv element', function (): void {
    $script = packageSource('skills/_shared/run-fast-checks.sh');
    $end = strpos($script, 'self_test() {');
    $code = (string) preg_replace('/^\s*#.*$/m', '', $end === false ? $script : substr($script, 0, $end));

    expect($code)->toContain('load_project_manifest || stop refused 3 "$PROJECT_REFUSAL"')
        ->and($code)->toContain('if ! reason="$(validate_command "$command" 2>&1 >/dev/null)"; then')
        ->and($code)->toContain('gate_lock_enter "$GATE_LOCK_TIMEOUT" || lock_status=$?')
        ->and($code)->toContain('done < <(git diff -z --name-only --no-renames --diff-filter=ACMRT "$BASE" HEAD)')
        ->and($code)->toContain('[[ "$path" != -* ]] || path="./$path"')
        ->and($code)->toContain('"${argv[@]}" </dev/null >>"$LOG" 2>&1 || status=$?')
        ->and($code)->not->toContain('eval ')
        ->and($code)->not->toContain('sh -c')
        ->and($code)->not->toContain('cat composer.json');
});

test('the pre-push gate runs the fast checks on every repository and keeps ci and the pre-merge gate as the final word', function (): void {
    $gates = packageSource('skills/resolve-issue/references/quality-gates.md');

    expect($gates)->toContain('the implementer runs it in three parts:')
        ->and($gates)->toContain('2. **The `fast-checks`, on every repository, on the changed files only.**')
        ->and($gates)->toContain(
            'The package assumes no tool.' . "\n" . '     - After the fixer changes are committed, run `skills/_shared/run-fast-checks.sh`',
        )
        ->and($gates)->toContain('A path is always its own argument, never part of a shell string.')
        ->and($gates)->toContain('Exit `4` → a check failed: read the log the JSON names, fix what it reports, commit, and run the script again.')
        ->and($gates)->toContain('blocks the push until you name the check, its failure, and why it cannot be fixed in the handoff.')
        ->and($gates)->toContain('Exit `5` → the manifest sets no `fast-checks`: no fast check runs, and the push goes on as before.')
        ->and($gates)->toContain('- The fast checks never replace CI or the pre-merge gate: those stay the final word on every merge.')
        ->and($gates)->toContain('`fast-checks: <passed | skipped | failed — <check>: <reason> | not set>`');
    expect(strpos($gates, '2. **The `fast-checks`, on every repository'))
        ->toBeGreaterThan((int) strpos($gates, '1. **The `pr-gate` fixers, on every repository.**'))
        ->toBeLessThan((int) strpos($gates, '3. **The checkers and tests, only where CI cannot check.**'));

    expect(packageSource('rules/general/general.md'))->toContain('| `fast-checks` | (string \| object)[] | The project\'s own fast checks:')
        ->toContain('Absent: no fast check runs before a push, and `krang` proposes the key');
    expect(packageSource('docs/installation.md'))->toContain('| `fast-checks` | `run-fast-checks.sh`, the implementer before each push;');
    expect(packageSource('rules/compound-engineering/concurrency.md'))
        ->toContain('The pre-push `fast-checks` take it through `skills/_shared/run-fast-checks.sh`.');
});

test('krang proposes the fast checks the way it proposes the fixers', function (): void {
    $discovery = packageSource('skills/_shared/orchestration/pr-gate-discovery.md');
    $script = packageSource('skills/_shared/discover-fixers.sh');

    expect($discovery)->toContain('## The fast checks')
        ->and($discovery)->toContain('- **Step 1** runs `skills/_shared/discover-fixers.sh --key fast-checks`')
        ->and($discovery)->toContain('`composer config --json extra.ai-olympus.fast-checks \'<the commands array>\'`')
        ->and($discovery)->toContain('use the branch `chore/ai-olympus-fast-checks`')
        ->and($discovery)->toContain('A refresh keeps every entry the owner added by hand.');

    foreach ([
        'fast-checks proposes each installed checker with the changed files it reads, and never a fixer or a project script',
        'every proposed fast check passes the gate allow-list',
        'a recorded fast-checks is read and nothing is analysed',
        'the pr-gate discovery is unchanged by a recorded fast-checks',
        'a fast-checks refresh keeps the hand-added pin test and adds the new checker in run order',
        'a key other than pr-gate or fast-checks is a usage error',
    ] as $label) {
        expect($script)->toContain('check \'' . $label . '\'');
    }
});

test('a stuck fast-check run is a wrapper the lock repair may stop', function (): void {
    $lock = packageSource('skills/_shared/gate-lock.sh');

    expect($lock)->toContain('[[ "$wrapper" != run-gate.sh && "$wrapper" != run-validation.sh && "$wrapper" != run-fast-checks.sh ]] || name="$wrapper"')
        ->and($lock)->toContain('check \'a stale heartbeat of run-fast-checks.sh reads as a stuck wrapper of this package\'');
});

test('claude code and codex both carry the fast checks before a push', function (): void {
    expect(packageSource('agents/donatello.md'))
        ->toContain('Then run `skills/_shared/run-fast-checks.sh` on every repository: it runs the project\'s `fast-checks` on the changed files');
    expect(packageSource('codex/agents/donatello.toml'))->toContain('`run-validation.sh`, `run-fast-checks.sh`, `run-gate.sh`');
    expect(packageSource('CLAUDE.md'))->toContain(
        'It then runs the project\'s `fast-checks` on the changed files through `skills/_shared/run-fast-checks.sh`',
    );
    expect(packageSource('AGENTS.md'))->toContain(
        'Then run the project\'s `fast-checks` on the changed files through `skills/_shared/run-fast-checks.sh`',
    );
    expect(packageSource('skills/_shared/orchestration/batch-preparation.md'))
        ->toContain('- Run the `fast-checks` before a push only as `skills/_shared/run-fast-checks.sh`.');
    expect(packageSource('CHANGELOG.md'))->toContain('(#605)');
});
