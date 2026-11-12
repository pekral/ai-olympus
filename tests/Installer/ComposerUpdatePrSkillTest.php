<?php

declare(strict_types = 1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function composerUpdateSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

/**
 * @param list<array<string, mixed>> $oldPackages
 * @param list<array<string, mixed>> $newPackages
 * @param list<array<string, mixed>> $oldDevPackages
 * @param list<array<string, mixed>> $newDevPackages
 * @return array{exit: int|null, output: string, error: string}
 */
function runLockDiff(array $oldPackages, array $newPackages, array $oldDevPackages = [], array $newDevPackages = []): array
{
    $root = sys_get_temp_dir() . '/ai-olympus-lock-diff-' . bin2hex(random_bytes(6));
    mkdir($root, 0o700, recursive: true);
    file_put_contents($root . '/old.lock', json_encode(['packages' => $oldPackages, 'packages-dev' => $oldDevPackages], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/new.lock', json_encode(['packages' => $newPackages, 'packages-dev' => $newDevPackages], JSON_THROW_ON_ERROR));

    $process = new Process([
        'php',
        dirname(__DIR__, 2) . '/skills/composer-update-pr/scripts/lock-diff.php',
        $root . '/old.lock',
        $root . '/new.lock',
    ]);
    $process->run();
    new Filesystem()->remove($root);

    return ['exit' => $process->getExitCode(), 'output' => trim($process->getOutput()), 'error' => $process->getErrorOutput()];
}

test('the skill resolves the default branch, stops on a dirty tree, and never stashes or discards', function (): void {
    $skill = composerUpdateSource('skills/composer-update-pr/SKILL.md');

    expect($skill)->toContain("name: composer-update-pr\n")
        ->and($skill)->toContain('Resolve the default branch per `@rules/git/general.md` *Pull Policy*')
        ->and($skill)->toContain('DEFAULT_BRANCH="$(git symbolic-ref --short refs/remotes/origin/HEAD | sed \'s@^origin/@@\')"')
        ->and($skill)->toContain('A non-empty output stops the run: report the output, change nothing')
        ->and($skill)->toContain('- Never stash, reset, discard, or overwrite a change you did not make')
        ->and($skill)->toContain('`git pull --ff-only origin "$DEFAULT_BRANCH"`');
});

test('the skill runs the update chain in order through the machine lock and stops on a failed step', function (): void {
    $skill = composerUpdateSource('skills/composer-update-pr/SKILL.md');

    expect($skill)->toContain('`skills/_shared/run-gate.sh --exclusive --command "composer install"`')
        ->and($skill)->toContain('`skills/_shared/run-gate.sh --exclusive --command "composer update"`')
        ->and($skill)->toContain('6. `composer bump`')
        ->and($skill)->toContain('A step that exits non-zero stops the run (step 3).')
        ->and($skill)->toContain('Name the step by its number and command, give its exit status, and print the last 40 lines of its output file.')
        ->and($skill)->toContain('report that nothing was updated, open no branch and no pull request, and stop.');
});

test('the skill cleans local branches only through the integration proof and never runs git gone', function (): void {
    $skill = composerUpdateSource('skills/composer-update-pr/SKILL.md');

    expect($skill)->toContain(
        '- Never run `git gone` and never run `git branch -D` on a branch that `@skills/cleanup-local-branches/SKILL.md` step 5 did not prove integrated',
    )
        ->and($skill)->toContain('delete only the candidates classified *integrated* in step 5, and keep every *not integrated* one')
        ->and($skill)->not->toContain('&& git gone')
        ->and($skill)->not->toContain('`git gone`;')
        ->and(composerUpdateSource('skills/cleanup-local-branches/SKILL.md'))->toContain('### 5. Determine merge status');
});

test('the skill opens a Draft pull request whose description holds only values read from the diff', function (): void {
    $skill = composerUpdateSource('skills/composer-update-pr/SKILL.md');

    expect($skill)->toContain('`gh pr create --draft --title "chore(composer): update dependencies" --body-file <file>`')
        ->and($skill)->toContain('Create `chore/composer-update-<date>`')
        ->and($skill)->toContain('Commit with the subject `chore(composer): update dependencies`.')
        ->and($skill)->toContain('`## TL;DR`')
        ->and($skill)->toContain('`## Major updates`')
        ->and($skill)->toContain('Not verified: what breaks in this project')
        ->and($skill)->toContain('`## Merge danger`')
        ->and($skill)->toContain('`isDraft` is `true`')
        ->and($skill)->toContain('The skill never promotes the Draft')
        ->and($skill)->not->toContain('A dependency update pull request carries nothing else.');
});

test('the skill commits other changed paths, lists them in the pull request, and never commits an ignored, env, or outside path', function (): void {
    $skill = composerUpdateSource('skills/composer-update-pr/SKILL.md');

    expect($skill)->toContain('the run does not stop. Record each such path for steps 5 and 7.')
        ->and($skill)->toContain('Stage `composer.json`, `composer.lock`, and every other changed path of step 4')
        ->and($skill)->toContain('`git check-ignore -q -- <path>`')
        ->and($skill)->toContain('Never commit a `.env*` file.')
        ->and($skill)->toContain('Never commit a path outside the project tree.')
        ->and($skill)->toContain('`## Other changed files`')
        ->and($skill)->not->toContain('This stop replaces opening a pull request');
});

test(
    'the skill reads every changed file as a NUL-separated path and stops on an env file at any depth, an outside symlink, or an ignored path',
    function (): void {
        $skill = composerUpdateSource('skills/composer-update-pr/SKILL.md');
    
        expect($skill)->toContain('`git status --porcelain=v1 -z --untracked-files=all`')
            ->and($skill)->toContain('NUL-separated')
            ->and($skill)->toContain('basename')
            ->and($skill)->toContain('at any depth')
            ->and($skill)->toContain('`git rev-parse --show-toplevel`')
            ->and($skill)->toContain('symbolic link whose target resolves outside')
            ->and($skill)->toContain('`git check-ignore -q -- <path>`')
            ->and($skill)->toContain('one `git add -- <path>` per path');
    },
);

test('the skill scans the commits of the branch with history-scan before the push and stops on a credential or sensitive-file finding', function (): void {
    $skill = composerUpdateSource('skills/composer-update-pr/SKILL.md');

    expect($skill)->toContain('skills/git-history-exposure-audit/scripts/history-scan.sh range')
        ->and($skill)->toContain('--base "$(git rev-parse "origin/$DEFAULT_BRANCH")" --head "$(git rev-parse HEAD)"')
        ->and($skill)->toContain('class `credential` or `sensitive-file`')
        ->and($skill)->toContain('exit `3`')
        ->and(strpos($skill, 'history-scan.sh range'))->toBeLessThan((int) strpos($skill, '`git push -u origin <branch>`'))
        ->and(composerUpdateSource('skills/git-history-exposure-audit/scripts/history-scan.sh'))->toContain(
            '--base and --head must be full commit object ids',
        );
});

test('the package lists the skill in the README, the skill catalog, and the code-review exclusion groups', function (): void {
    $count = count(glob(dirname(__DIR__, 2) . '/skills/*/SKILL.md') ?: []);

    expect(composerUpdateSource('README.md'))->toContain($count . ' reusable skills')
        ->and(composerUpdateSource('docs/skills.md'))->toContain('| [`composer-update-pr`](../skills/composer-update-pr/) |')
        ->and(composerUpdateSource('docs/skills.md'))->toContain('All ' . $count . ' skills this package ships')
        ->and(composerUpdateSource('skills/code-review/references/specialized-reviews.md'))->toContain('- `composer-update-pr` —');
});

test('lock-diff lists updated, added, and removed packages with the level of each version change', function (): void {
    $result = runLockDiff(
        [
            ['name' => 'a/major', 'version' => 'v1.9.0'],
            ['name' => 'a/minor', 'version' => '2.1.0'],
            ['name' => 'a/patch', 'version' => '3.0.1'],
            ['name' => 'a/same', 'version' => '1.0.0'],
            ['name' => 'a/removed', 'version' => '1.0.0'],
            ['name' => 'a/branch', 'version' => 'dev-main'],
        ],
        [
            ['name' => 'a/major', 'version' => 'v2.0.0'],
            ['name' => 'a/minor', 'version' => '2.2.0'],
            ['name' => 'a/patch', 'version' => '3.0.2'],
            ['name' => 'a/same', 'version' => '1.0.0'],
            ['name' => 'a/added', 'version' => '0.4.0'],
            ['name' => 'a/branch', 'version' => 'dev-next'],
        ],
        [['name' => 'b/dev', 'version' => '1.0.0']],
        [['name' => 'b/dev', 'version' => '1.0.1']],
    );

    /** @var array{changes: list<array{name: string, type: string, dev: bool, from: string|null, to: string|null, level: string|null}>} $decoded */
    $decoded = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);
    $changes = $decoded['changes'];

    expect($result['exit'])->toBe(0)
        ->and(array_column($changes, 'name'))->toBe(['a/added', 'a/branch', 'a/major', 'a/minor', 'a/patch', 'a/removed', 'b/dev'])
        ->and($changes[0])->toBe(['name' => 'a/added', 'type' => 'added', 'dev' => false, 'from' => null, 'to' => '0.4.0', 'level' => null])
        ->and($changes[1]['level'])->toBe('other')
        ->and($changes[2])->toBe(['name' => 'a/major', 'type' => 'updated', 'dev' => false, 'from' => 'v1.9.0', 'to' => 'v2.0.0', 'level' => 'major'])
        ->and($changes[3]['level'])->toBe('minor')
        ->and($changes[4]['level'])->toBe('patch')
        ->and($changes[5])->toBe(['name' => 'a/removed', 'type' => 'removed', 'dev' => false, 'from' => '1.0.0', 'to' => null, 'level' => null])
        ->and($changes[6]['dev'])->toBeTrue();
});

test('lock-diff prints no change for identical locks and exits 1 for an unreadable lock', function (): void {
    $same = runLockDiff([['name' => 'a/same', 'version' => '1.0.0']], [['name' => 'a/same', 'version' => '1.0.0']]);
    $missing = new Process([
        'php',
        dirname(__DIR__, 2) . '/skills/composer-update-pr/scripts/lock-diff.php',
        '/nonexistent/old.lock',
        '/nonexistent/new.lock',
    ]);
    $missing->run();
    $usage = new Process(['php', dirname(__DIR__, 2) . '/skills/composer-update-pr/scripts/lock-diff.php']);
    $usage->run();

    expect($same['output'])->toBe('{"changes":[]}')
        ->and($missing->getExitCode())->toBe(1)
        ->and($missing->getErrorOutput())->toContain('cannot read a composer.lock')
        ->and($usage->getExitCode())->toBe(1)
        ->and($usage->getErrorOutput())->toContain('Usage: lock-diff.php');
});

test('lock-diff reports a package whose version stays but whose source reference changes', function (): void {
    $result = runLockDiff(
        [
            ['name' => 'a/branch', 'version' => 'dev-main', 'source' => ['reference' => 'aaaaaaa1111111']],
            ['name' => 'a/dist', 'version' => 'dev-main', 'dist' => ['reference' => 'bbbbbbb2222222']],
            ['name' => 'a/same', 'version' => 'dev-main', 'source' => ['reference' => 'ccccccc3333333']],
            ['name' => 'a/noref', 'version' => 'dev-main'],
        ],
        [
            ['name' => 'a/branch', 'version' => 'dev-main', 'source' => ['reference' => 'ddddddd4444444']],
            ['name' => 'a/dist', 'version' => 'dev-main', 'dist' => ['reference' => 'eeeeeee5555555']],
            ['name' => 'a/same', 'version' => 'dev-main', 'source' => ['reference' => 'ccccccc3333333']],
            ['name' => 'a/noref', 'version' => 'dev-main', 'source' => ['reference' => 'fffffff6666666']],
        ],
    );

    /** @var array{changes: list<array<string, mixed>>} $decoded */
    $decoded = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['changes'])->toBe([
        ['name' => 'a/branch', 'type' => 'updated', 'dev' => false, 'from' => 'dev-main@aaaaaaa', 'to' => 'dev-main@ddddddd', 'level' => 'other'],
        ['name' => 'a/dist', 'type' => 'updated', 'dev' => false, 'from' => 'dev-main@bbbbbbb', 'to' => 'dev-main@eeeeeee', 'level' => 'other'],
    ]);
});

test('lock-diff treats a 0.x minor change as major, a downgrade as downgraded, and a pre-release to stable as an upgrade', function (): void {
    $result = runLockDiff(
        [
            ['name' => 'a/zero-minor', 'version' => '0.4.2'],
            ['name' => 'a/zero-patch', 'version' => '0.4.2'],
            ['name' => 'a/down-major', 'version' => '2.0.0'],
            ['name' => 'a/down-v', 'version' => 'v1.5.0'],
            ['name' => 'a/beta', 'version' => '2.0.0-beta1'],
            ['name' => 'a/back-to-beta', 'version' => '2.0.0'],
            ['name' => 'a/beta-major', 'version' => '1.9.0'],
        ],
        [
            ['name' => 'a/zero-minor', 'version' => '0.5.0'],
            ['name' => 'a/zero-patch', 'version' => '0.4.3'],
            ['name' => 'a/down-major', 'version' => '1.9.0'],
            ['name' => 'a/down-v', 'version' => '1.4.9'],
            ['name' => 'a/beta', 'version' => '2.0.0'],
            ['name' => 'a/back-to-beta', 'version' => '2.0.0-beta1'],
            ['name' => 'a/beta-major', 'version' => '2.0.0-beta1'],
        ],
    );

    /** @var array{changes: list<array<string, mixed>>} $decoded */
    $decoded = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);
    $byName = array_column($decoded['changes'], column_key: null, index_key: 'name');

    expect($byName['a/zero-minor'])->toMatchArray(['type' => 'updated', 'level' => 'major'])
        ->and($byName['a/zero-patch'])->toMatchArray(['type' => 'updated', 'level' => 'patch'])
        ->and($byName['a/down-major'])->toMatchArray(['type' => 'downgraded', 'level' => 'other', 'from' => '2.0.0', 'to' => '1.9.0'])
        ->and($byName['a/down-v'])->toMatchArray(['type' => 'downgraded', 'level' => 'other'])
        ->and($byName['a/beta'])->toMatchArray(['type' => 'updated', 'level' => 'other'])
        ->and($byName['a/back-to-beta'])->toMatchArray(['type' => 'downgraded', 'level' => 'other'])
        ->and($byName['a/beta-major'])->toMatchArray(['type' => 'updated', 'level' => 'major']);
});
