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
        'a passed run deletes its log and its file list and reports none',
        'a failed run keeps the log, and the log names every check that ran',
        'no default-branch ref refuses the run with the set-head hint instead of reading no checks',
        'the removed --log option is a usage error',
        'a link to an outside file or directory, an inside link, a glob-shaped link name, and a file turned into a link never reach a checker',
        'a checker that reads its files reads only files inside the worktree, never the outside secret',
        'a path an earlier check replaced with a link is never passed, and the run is refused instead of passing',
        'a path with a .. component is outside the worktree',
        'a default-branch composer.json that is not JSON refuses the run instead of reading no checks',
        'a git status that fails refuses the run instead of reading a clean tree',
        'a git diff that fails refuses the run instead of reading no changed file',
        'a project in a subdirectory checks its changed files relative to its directory, renamed and unusual names included',
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
        ->and($code)->toContain('if ! git diff -z --relative --name-only --no-renames --diff-filter=ACMRT "$BASE" HEAD >"$list" 2>/dev/null; then')
        ->and($code)->toContain('[[ "$path" != -* ]] || path="./$path"')
        ->and($code)->toContain('run_command "${commands[index]}" "$LOG" ${files[@]+"${files[@]}"} || status=$?')
        ->and($code)->toContain('gate_no_symlink "$1" >/dev/null 2>&1')
        ->and($code)->not->toContain('valid_ref() {')
        ->and($code)->not->toContain('"${argv[@]}"')
        ->and($code)->not->toContain('--log')
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
        ->and($gates)->toContain('Exit `0` → push; a passed run leaves no log.')
        ->and($gates)->toContain(
            'Exit `4` → a check failed: read the log the JSON names, delete it, fix what it reports, commit, and run the script again.',
        )
        ->and($gates)->toContain('blocks the push until you name the check, its failure, and why it cannot be fixed in the handoff.')
        ->and($gates)->toContain('Exit `5` → the manifest sets no `fast-checks`: no fast check runs, and the push goes on.')
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

test('the shared command library is the one executor and the one ref check of the fast checks and the discovery', function (): void {
    $library = packageSource('skills/_shared/project-commands.sh');

    expect($library)->toContain('valid_ref() {')
        ->and(packageSource('skills/_shared/discover-fixers.sh'))->not->toContain('valid_ref() {')
        ->and(packageSource('skills/_shared/gate-record.sh'))
        ->toContain('IFS=\'/\' read -r -d \'\' -a parts < <(printf \'%s\\0\' "$path") || true');
});

test('every list of machine lock wrappers names the fast checks', function (string $path, string $pin): void {
    expect(packageSource($path))->toContain($pin);
})->with([
    'concurrency holder file' => [
        'rules/compound-engineering/concurrency.md',
        '`WRAPPER` (`run-gate.sh`, `run-validation.sh`, `run-fast-checks.sh`, or `test-integrity.sh`)',
    ],
    'gate-record lock entry' => [
        'skills/_shared/gate-record.sh',
        '# The one entry into the machine lock of run-gate.sh, run-validation.sh, and run-fast-checks.sh:',
    ],
    'orchestration machine directory' => [
        'rules/compound-engineering/orchestration.md',
        '`skills/_shared/run-validation.sh`, `skills/_shared/run-fast-checks.sh`, and `skills/_shared/test-integrity.sh` create and remove it',
    ],
    'quality-gates repair conditions' => [
        'skills/resolve-issue/references/quality-gates.md',
        'the holder file names `run-gate.sh`, `run-validation.sh`, `run-fast-checks.sh`, or `test-integrity.sh`, the live process',
    ],
    'stale-lock-repair report step' => [
        'skills/_shared/orchestration/stale-lock-repair.md',
        'the `reason` of a `run-gate.sh`, `run-validation.sh`, `run-fast-checks.sh`, or `test-integrity.sh prove` exit `6`',
    ],
    'stale-lock-repair trigger 2' => [
        'skills/_shared/orchestration/stale-lock-repair.md',
        'or a `run-gate.sh` or `run-fast-checks.sh` call in its handoff exited `6`.',
    ],
    'stale-lock-repair trigger 4' => [
        'skills/_shared/orchestration/stale-lock-repair.md',
        '4. A `run-gate.sh`, `run-validation.sh`, `run-fast-checks.sh`, or `test-integrity.sh prove` call that the harness stopped',
    ],
]);

test('a stuck fast-check run is a wrapper the lock repair may stop', function (): void {
    $lock = packageSource('skills/_shared/gate-lock.sh');

    expect($lock)->toContain(
        '[[ "$wrapper" != run-gate.sh && "$wrapper" != run-validation.sh && "$wrapper" != '
        . 'run-fast-checks.sh && "$wrapper" != test-integrity.sh ]] || name="$wrapper"',
    )
        ->and($lock)->toContain('check \'a stale heartbeat of run-fast-checks.sh reads as a stuck wrapper of this package\'');
});

test('the script of the pre-push step is inside the implementer bash boundary and its handoff summary', function (): void {
    $donatello = packageSource('agents/donatello.md');
    $start = (int) strpos($donatello, '## Bash boundary');
    $boundary = substr($donatello, $start, (int) strpos($donatello, '## Shared task brief') - $start);

    expect($boundary)->toContain('`skills/_shared/run-fast-checks.sh` for the project\'s `fast-checks` before each push')
        ->and($donatello)->toContain('the `fast-checks` result of `skills/_shared/run-fast-checks.sh`, and the local checks');
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
