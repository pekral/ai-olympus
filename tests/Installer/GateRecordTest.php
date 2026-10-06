<?php

declare(strict_types = 1);

// Shared quality-gate records (issue #169): one gate run per git tree, verified by a script.
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so the behavioural proof
// lives in the `--self-test` of `run-gate.sh` and `verify-gate.sh` (wired into `composer
// shell-self-tests`), and the guards below pin the scenarios those self-tests must keep covering,
// plus the wiring that makes every agent and skill use the record.

function gateScript(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/' . $name);
}

/**
 * The part of a script that runs outside its self-test, with comment lines removed.
 */
function gateExecutableCode(string $name): string
{
    $script = gateScript($name);
    $end = strpos($script, 'self_test() {');
    $executable = $end === false ? $script : substr($script, 0, $end);

    return (string) preg_replace('/^\s*#.*$/m', '', $executable);
}

test('the gate scripts run every command as argv, never through a shell, and never flock', function (): void {
    foreach (['run-gate.sh', 'verify-gate.sh', 'gate-record.sh', 'project-commands.sh'] as $name) {
        $code = gateExecutableCode($name);

        expect($code)->not->toContain('eval ');
        expect($code)->not->toContain('sh -c');
        expect($code)->not->toContain('bash -c');
        expect($code)->not->toContain('flock');
        expect($code)->not->toContain('source ');
    }

    // One executor for the whole package: the gate scripts reach commands only through it.
    expect(gateScript('gate-record.sh'))->toContain('. "$GATE_LIB_DIR/project-commands.sh"');
    expect(gateExecutableCode('gate-record.sh'))->toContain('run_command "$command" "$tmp"');
    expect(gateExecutableCode('run-gate.sh'))->toContain('run_command "$command" "$log_tmp"');
    expect(gateExecutableCode('project-commands.sh'))->toContain('"${argv[@]}" >>"$log" 2>&1 || status=$?');

    // The lock is the atomic `mkdir` of the concurrency rule, with a reclaim on confirmed death only.
    $record = gateScript('gate-record.sh');
    expect($record)->toContain('until (umask 077 && mkdir -- "$GATE_LOCK") 2>/dev/null; do');
    expect($record)->toContain('LC_ALL=C kill -0 "$pid"');
    expect($record)->toContain('ps -o etime= -p "$pid"');
});

test('run-gate self-test covers the record, the lock, concurrency, and every refusal', function (): void {
    $runner = gateScript('run-gate.sh');

    // GNU `stat -f` reports the file system and succeeds, so the GNU form must come first (CI runs Linux).
    expect(gateScript('gate-record.sh'))->toContain('stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"');

    foreach ([
        'a passing gate writes a record and a log',
        'the record carries tier, tree, head, commands, environment, actor, times, exit, log hash',
        'the log hash in the record matches the log',
        'the evidence directory is 0700 and its files are 0600',
        'gate-fresh runs after a passing gate and never enters the record',
        'the record carries no environment value and no unvalidated actor',
        'a second run on the same tree takes the result over',
        'a record of other commands is not taken over',
        'concurrent runs on one tree run the gate once',
        'a live lock holder times out without running the gate',
        'a lock left by a dead holder is reclaimed',
        'a failing command fails the gate and records the failure',
        'a gate that changes the tree during the run never records a pass',
        'a dirty tree at the start refuses both tiers and writes nothing',
        'an interrupted run leaves no record, no temporary file, and no lock',
        'a missing pr-gate runs nothing',
        'a missing gate runs nothing',
        'a manifest with gate alone keeps the built-in path',
        'a chained gate command is refused and never runs',
        'a manifest only the branch carries changes nothing',
        'a manifest env that preloads a library runs nothing',
        'an evidence directory outside gitignore is refused',
        'a symlinked evidence directory is refused',
    ] as $label) {
        expect(substr_count($runner, '\'' . $label . '\''))->toBe(1);
    }

    // The chained command is the exact payload of the security plan, and the refusal is checked by
    // the absence of the file it would create.
    expect($runner)->toContain('\"gate\": [\"composer build; touch pwned\"]');
    expect($runner)->toContain('for value in \'../x\' \'/tmp/x\' \'a/../../x\' \'~/x\'; do');
});

test('verify-gate self-test covers tree identity, staleness, fail-closed fields, and gate-fresh', function (): void {
    $verifier = gateScript('verify-gate.sh');

    foreach ([
        'no record is missing',
        'a valid record verifies',
        'gate-fresh runs on every verification and never from the record',
        'a squash with the same tree keeps the record',
        'a rebase with the same tree keeps the record',
        'a different tree is rejected',
        'a record renamed to another tree is stale',
        'changed gate commands make the record stale',
        'a changed environment makes the record stale',
        'a tampered log makes the record stale',
        'an unreadable record fails closed',
        'a record carrying a command never executes it',
        'shell syntax in a record field is never evaluated',
        'a failed record is failed',
        'a symlinked record is refused',
        'a failing gate-fresh fails a valid record',
        'an option in place of the sha is refused',
        'a sha that names no commit is refused',
        'without pr-gate nothing is verified',
        'a manifest with gate alone is not configured',
    ] as $label) {
        expect(substr_count($verifier, '\'' . $label . '\''))->toBe(1);
    }

    // Every field of the record fails closed on its own, and both spellings of an unset gate-fresh
    // still run the dependency audit.
    expect($verifier)->toContain(
        'for field in schema tier tree head merge_base commands environment actor started_at finished_at duration_seconds exit_code log_sha256; do',
    );
    expect($verifier)->toContain('check "a record without $field fails closed"');
    expect($verifier)->toContain('for value in \'absent\' \'[]\'; do');
    expect($verifier)->toContain('check "a gate-fresh that is $value still runs composer audit"');
});

test('a record is accepted only through one check, keyed to the tree and read only through jq', function (): void {
    $record = gateScript('gate-record.sh');

    // The exit codes the issue defines, and the one function both scripts call.
    expect($record)->toContain('returns 0 valid, 10 missing, 11 failed, 12 stale or unusable');
    expect(substr_count(gateExecutableCode('run-gate.sh'), 'gate_check_record "$TIER" "$TREE"'))->toBe(1);
    expect(substr_count(gateExecutableCode('verify-gate.sh'), 'gate_check_record "$TIER" "$TREE"'))->toBe(1);

    // A file name comes from the validated tree and the tier, never from a value in a record.
    expect($record)->toContain('record="$GATE_EVIDENCE/$tree.$tier.json"');
    expect($record)->toContain('[[ "$1" =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]]');
    expect(gateScript('verify-gate.sh'))->toContain('git rev-parse --verify --quiet "$SHA^{commit}"');

    // The evidence directory: relative, ignored, untracked, symlink-free, private.
    expect($record)->toContain('git check-ignore -q -- "$probe"');
    expect($record)->toContain('git ls-files -- "$dir"');
    expect($record)->toContain('chmod 700 -- "$dir"');

    // A missing or empty gate-fresh is never "audit nothing".
    expect($record)->toContain('GATE_FRESH_COMMANDS=(\'composer audit\')');
});

test('the trust model is stated where the record is written, read, and documented', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $gates = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/quality-gates.md');

    expect(gateScript('gate-record.sh'))->toContain('TRUST MODEL — what a record proves, and what it does not');
    expect(gateScript('gate-record.sh'))->toContain('The SHA-256 of the log detects a damaged or swapped log. It does not prove');

    foreach (['run-gate.sh', 'verify-gate.sh'] as $name) {
        expect(gateScript($name))->toContain('TRUST MODEL — see gate-record.sh. In short: the record is a local cache.');
    }

    // The scripts that run are the installed package copy; a branch diff over that copy is a merge-gate change.
    expect(gateScript('gate-record.sh'))->toContain('#     vendor/pekral/ai-olympus. A diff over that installed copy is a merge-gate');
    expect(gateScript('gate-record.sh'))->toContain('#     change: Critical under rules/security/general.md *Code Review Application*.');
    expect($gates)->toContain('**The gate scripts come from the package install, not from the branch.**');
    expect($gates)->toContain('is the copy the installer wrote from `vendor/pekral/ai-olympus`.');
    expect($gates)->toContain(
        'Review it under `@rules/security/general.md` *Code Review Application* at severity **Critical**, never as an ordinary script edit.',
    );

    expect($gates)->toContain('## Machine gate record — one run per tree');
    expect($gates)->toContain('**Trust model.** The record is a local cache on one machine and one account.');
    expect($gates)->toContain('The defence against hostile branch code is code review, required CI, and a fresh `gate-fresh` run, never the record.');
    expect($gates)->toContain('it does not prove that the log is authentic');
});

test('the merge and the readiness check keep CI and never merge on a non-zero verdict', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $merge = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');
    $readiness = (string) file_get_contents($packageDir . '/skills/verify-merge-readiness/SKILL.md');

    expect($merge)->toContain('Run `skills/_shared/verify-gate.sh --tier full <headRefOid>` on the checked-out head');
    expect($merge)->toContain(
        'It replaces **only** the acceptance of the textual `Quality gate:` line; CI must still be green on the merged head (step 2)',
    );
    expect($merge)->toContain('Never accept the textual record in its place, and never merge on a non-zero verdict.');
    expect($merge)->toContain('A refusal is never a pass, and the textual record never stands in for it.');
    expect($merge)->toContain('Run it through `skills/_shared/run-gate.sh --tier full` and act on its exit code');
    expect($merge)->toContain(
        '- **Any other non-zero exit** — `1`, `2`, or a code the table does not list: handled like `3`. Report the reason and stop.',
    );

    // HOTFIX: step 3's exit-code verdict must not contradict step 4's coverage waiver.
    expect($merge)->toContain(
        'The one exception is a qualified HOTFIX PR: an exit `4` whose log shows the coverage threshold as the only failure counts as green under step 4',
    );

    // The four textual conditions stay for a project without a machine record (backward compatible).
    expect($merge)->toContain('**The record is authentic.**');

    expect($readiness)->toContain('`skills/_shared/verify-gate.sh --tier full <head SHA>` first');
    expect($readiness)->toContain('`donatello` to run `skills/_shared/run-gate.sh --tier full`, or stops; the PR is never reported');
    expect($readiness)->toContain('Exit `3` stops with the reason, and any other non-zero exit is handled like `3`.');
    expect($readiness)->toContain('Required CI stays a separate condition below.');
});

test('every agent and skill in the issue table uses the gate scripts', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $read = static fn (string $path): string => (string) file_get_contents($packageDir . '/' . $path);

    expect($read('agents/donatello.md'))->toContain('run `skills/_shared/run-gate.sh --tier pr --actor donatello` before each push');
    expect($read('agents/donatello.md'))->toContain('`skills/_shared/run-gate.sh --tier pr` before each push, and the project');
    expect($read('agents/donatello.md'))->toContain('Exit `3` or `6` → do not push; return `Blocked` with the reason.');
    expect($read('agents/donatello.md'))->toContain('Any other non-zero exit is handled like `3`: do not push.');
    expect($read('skills/process-code-review/SKILL.md'))->toContain('Run the gate through `skills/_shared/run-gate.sh --tier full --actor donatello`');
    expect($read('skills/process-code-review/SKILL.md'))->toContain('Exit `3` is a hard stop, like a gate that cannot be run.');
    expect($read('skills/process-code-review/SKILL.md'))->toContain('plus the tree and the record path when `run-gate.sh` wrote a record');
    expect(splinterContractText())->toContain('**Verify before every gate step — never run a tree twice.**');
    expect(splinterContractText())->toContain('`gate skipped — record <record path> valid for tree <tree>`');
    expect($read('agents/splinter.md'))->toContain('Exit `3` is a blocker: stop and report the reason. Any other non-zero exit is handled like `3`.');
    expect($read('agents/splinter.md'))->toContain('and `skills/_shared/verify-gate.sh` (the machine gate record check;');
    expect($read('skills/_shared/orchestration/merge-preparation.md'))->toContain(
        '`0` skip the dispatch and record `gate skipped — record <path> valid for tree <tree>` in the brief.',
    );
    expect($read('agents/leonardo.md'))->toContain('You never run the gate: when `run-gate.sh` wrote a record, cite its tree and record path');
    expect($read('skills/_shared/record-metrics.sh'))->toContain('--gate-record)');
    expect($read('skills/_shared/record-metrics.sh'))->toContain('\'gate durations are summed, never copied\'');
    expect($read('rules/git/general.md'))->toContain('**One gate run per tree, shared by every agent.**');
});

test('the manifest documents the three gate keys and keeps the built-in behaviour without them', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/general/general.md');
    $install = (string) file_get_contents($packageDir . '/docs/installation.md');
    $gates = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/quality-gates.md');
    $changelog = (string) file_get_contents($packageDir . '/CHANGELOG.md');

    foreach (['pr-gate', 'gate-fresh', 'gate-evidence'] as $key) {
        expect($rule)->toContain('| `' . $key . '` |');
        expect($install)->toContain('| `' . $key . '` |');
        expect($changelog)->toContain('`' . $key . '`');
    }

    expect($gates)->toContain(
        'A project whose manifest sets none of `pr-gate`, `gate-fresh`, and `gate-evidence` gets exit `5` and keeps the textual record exactly as before.',
    );
    expect($gates)->toContain('The record is opt-in: it applies when the manifest sets at least one of `pr-gate`, `gate-fresh`, or `gate-evidence`.');
    expect($gates)->toContain('| `3` | refused | refused | stops and reports the reason; never runs the commands another way and never merges on it |');
    expect($gates)->toContain(
        '| `1` / `2` | usage error / `git` or `jq` missing | usage error, including an invalid or unknown `<sha>` / `git` or `jq` missing'
        . ' | handled like `3`: stops and reports the reason; never merges on it |',
    );
    expect($gates)->toContain('Any other non-zero exit code is handled like `3`. A code the table does not list is never a pass.');
    expect(gateScript('gate-record.sh'))->toContain('has("pr-gate") or has("gate-fresh") or has("gate-evidence")');
    expect($gates)->toContain('Without `pr-gate` nothing runs before a push, exactly as before.');
    expect(gateScript('gate-record.sh'))->toContain('the built-in gate path applies');
});
