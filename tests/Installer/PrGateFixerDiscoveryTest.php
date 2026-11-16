<?php

declare(strict_types = 1);

test('the discovery script proves every acceptance scenario in its self-test', function (): void {
    $script = packageSource('skills/_shared/discover-fixers.sh');

    foreach ([
        'without a project script every tool is proposed, in run order',
        'two packages of one tool give one command',
        'the output states what was searched',
        'every proposed command passes the gate allow-list',
        'the project script wins over the tools it wraps',
        'package.json tools are proposed, and stylelint without a script is unmapped',
        'each ecosystem prefers its own script',
        'a script name outside the fixed list never becomes a command',
        'a recorded pr-gate is read and nothing is analysed',
        'no fixer gives none',
        'no composer.json gives no-manifest',
        'a composer.json that is not JSON exits 3',
        'a ref that looks like an option is refused',
        'a ref with a colon is refused',
        'the default branch is read by default',
        'an added fixer tool proposes the updated pr-gate',
        'a removed fixer tool proposes the updated pr-gate',
        'a dependency change without a fixer change is unchanged',
        'a tool the recorded project script already covers is current',
    ] as $label) {
        expect($script)->toContain('check \'' . $label . '\'');
    }

    expect(packageSource('composer.json'))->toContain('skills/_shared/discover-fixers.sh');
});

test('the discovery script reads git, never the working tree, and builds commands only from its fixed tables', function (): void {
    $script = packageSource('skills/_shared/discover-fixers.sh');
    $end = strpos($script, 'self_test() {');
    $code = (string) preg_replace('/^\s*#.*$/m', '', $end === false ? $script : substr($script, 0, $end));

    expect($code)->toContain('git show "$ref:./$file"')
        ->and($code)->toContain('commands: (if $script != null then ["\($runner) \($script)"]')
        ->and($code)->toContain('{package: "laravel/pint", command: "vendor/bin/pint"}')
        ->and($code)->toContain('{package: "stylelint", command: null}')
        ->and($code)->not->toContain('eval ')
        ->and($code)->not->toContain('sh -c')
        ->and($code)->not->toContain('cat composer.json');
    expect($script)->toContain('"$SCRIPT_DIR/read-manifest.sh" --default-ref');
    expect($script)->toContain('A script name or a package name from'
        . "\n#   the file never becomes command text, so a hostile dependency file cannot plant a command.");
});

test('the pre-push gate runs the pr-gate fixers on every repository and the checks only where ci cannot check', function (): void {
    $gates = packageSource('skills/resolve-issue/references/quality-gates.md');

    expect($gates)->toContain('- **The one exception is the pre-push gate.** Before each push of a commit to a pull request')
        ->and($gates)->toContain('1. **The `pr-gate` fixers, on every repository.** `pr-gate` lists fixers only: checkers and tests never belong in it.')
        ->and($gates)->toContain('run its commands in the listed order in one `skills/_shared/run-gate.sh --exclusive` call')
        ->and($gates)->toContain('Commit every file they change, then push.')
        ->and($gates)->toContain('A second exit `4` is a problem the fixers cannot fix')
        ->and($gates)->toContain('Without `pr-gate` no fixer runs before a push.')
        ->and($gates)->toContain('2. **The checkers and tests, only where CI cannot check.**')
        ->and($gates)->toContain('On a repository with CI no checker and no test runs locally before a push.')
        ->and($gates)->toContain('On a repository without CI, and after a CI billing failure, they run locally too')
        ->and($gates)->toContain('The CI result decides whether the local checkers and tests run. The `pr-gate` fixers run in every case.')
        ->and($gates)->toContain('2. **CI → no local checker and no local test.** Run the `pr-gate` fixers before each push.');
    expect($gates)->not->toContain('`skills/_shared/run-gate.sh --tier pr` when the manifest sets `pr-gate`')
        ->and($gates)->not->toContain('Without `pr-gate` nothing runs before a push');
});

test('krang discovers the fixers once, proposes the key through one pull request, and refreshes it on a tool change', function (): void {
    $krang = packageSource('agents/krang.md');
    $discovery = packageSource('skills/_shared/orchestration/pr-gate-discovery.md');

    expect($krang)->toContain('→ load `skills/_shared/orchestration/pr-gate-discovery.md`, run its step 1, then proceed to step 4.')
        ->and($krang)->toContain('Then run steps 3 and 4 of `skills/_shared/orchestration/pr-gate-discovery.md`.')
        ->and($krang)->toContain('`skills/_shared/discover-fixers.sh` (the project\'s fixers; reads dependency files from git, writes nothing)');
    expect($discovery)->toContain('- `configured` → the manifest sets `pr-gate`. The script analysed nothing, and you analyse nothing either.')
        ->and($discovery)->toContain('`gh pr list --head chore/ai-olympus-pr-gate --state all --json number,state,url`')
        ->and($discovery)->toContain('A closed or merged one is the owner\'s decision, and a run never reopens it.')
        ->and($discovery)->toContain('`composer config --json extra.ai-olympus.pr-gate \'<the commands array>\'`')
        ->and($discovery)->toContain('`skills/_shared/run-gate.sh --exclusive --command "composer update --lock --no-install"`')
        ->and($discovery)->toContain('The pull request states what the discovery searched (`searched`)')
        ->and($discovery)->toContain('The run does not review or merge the proposal: the owner decides it.')
        ->and($discovery)->toContain('`skills/_shared/discover-fixers.sh --compare <base SHA> <head SHA>`')
        ->and($discovery)->toContain('The key changes in the pull request that changes the tools');
    expect(strlen($krang))->toBeLessThanOrEqual(135_000);
});

test('claude code and codex both carry the pr-gate rule', function (): void {
    expect(packageSource('codex/agents/krang.toml'))->toContain('`order-work-queue.sh`, `discover-fixers.sh`, `run-validation.sh`');
    $donatello = packageSource('agents/donatello.md');
    expect($donatello)
        ->toContain('Before each push, run the `pr-gate` fixers on every repository: read the key with `skills/_shared/read-manifest.sh`')
        ->toContain('The checks run as `skills/_shared/run-gate.sh --tier full --actor donatello`.');
    expect($donatello)->not->toContain('--tier pr --actor donatello');
    expect(packageSource('rules/general/general.md'))->toContain('| `pr-gate` | string[] | The project\'s fixers, and fixers only.');
    expect(packageSource('docs/installation.md'))->toContain('"pr-gate": ["composer fix"]');
    expect(packageSource('CLAUDE.md'))->toContain('The pre-push gate runs the `pr-gate` fixers on every repository');
    expect(packageSource('AGENTS.md'))->toContain('- Before each push run the `pr-gate` fixers on every repository');
    $batch = packageSource('skills/_shared/orchestration/batch-preparation.md');
    expect($batch)->toContain('- Run the `pr-gate` fixers before a push only as one `skills/_shared/run-gate.sh --exclusive` call,');
    expect($batch)->not->toContain('--tier pr --actor <your role>');
    expect(packageSource('CHANGELOG.md'))->toContain('(#555)');
});
