<?php

declare(strict_types = 1);

// The external skill registry and its upstream sync (issue #267). Per the project's test-isolation
// rule a Pest test cannot exec a real .sh, so the behavioural proof - a changed and an unchanged
// upstream, several independent sources, licence handling, path safety - lives in the script's own
// `--self-test`, which `composer shell-self-tests` runs. These guards pin the scenarios that
// self-test must keep covering, validate the shipped registry and lock with the rules the script
// enforces, and pin the workflow's trust boundary.

function externalSkillSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

/**
 * @return array<mixed>
 */
function externalSkillJson(string $relativePath): array
{
    $decoded = json_decode(externalSkillSource($relativePath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

function externalSkillString(mixed $value): string
{
    return is_string($value) ? $value : '';
}

/**
 * @return list<string>
 */
function externalSkillStrings(mixed $value): array
{
    return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
}

test('the sync script is shipped outside skills/, so the installer never copies it to a consumer', function (): void {
    $script = dirname(__DIR__, 2) . '/scripts/sync-external-skills.sh';
    $content = externalSkillSource('scripts/sync-external-skills.sh');

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();
    expect($content)->toStartWith('#!/usr/bin/env bash');
    expect($content)->toContain('set -euo pipefail');
    expect($content)->toContain('--self-test');

    // `skills/` ships verbatim to every consumer; a maintenance tool for this repository does not.
    foreach (['config/external-skills', 'sync-external-skills'] as $needle) {
        expect(externalSkillSource('src/Installer.php'))->not->toContain($needle);
    }

    $composer = externalSkillJson('composer.json');
    $scripts = (array) ($composer['scripts'] ?? []);
    expect(implode(' ', externalSkillStrings($scripts['shell-self-tests'] ?? null)))->toContain(' scripts/sync-external-skills.sh');
});

test('the self-test covers every scenario the issue acceptance criteria name', function (): void {
    $content = externalSkillSource('scripts/sync-external-skills.sh');

    $scenarios = [
        'first sync writes the declared file',
        'the lock records the upstream commit SHA',
        'the lock records the repository URL, ref, source path, licence, and date',
        'an unchanged upstream leaves the lock byte for byte, so the date causes no commit',
        'a new upstream commit with the same declared content changes nothing',
        'a changed upstream file is written',
        'a changed upstream file moves the locked commit',
        'several sources sync independently',
        'a disabled skill is not fetched or written',
        'an unavailable upstream exits 3',
        'the other source still syncs when one fails',
        'a file the registry stops declaring is removed',
        'a dry run writes no file',
        'a licence outside allowed_licenses needs a manual decision',
        'a missing licence needs a manual decision',
        'a path that exists and is not managed needs a manual decision',
        'a file that starts with a shebang is refused',
        'a file with NUL bytes is refused',
        'one failing file stops the whole skill before anything is written',
        'a symbolic link at the target is never followed',
        'an unsafe registry entry is refused',
        'a duplicate name is refused',
        'a local edit is kept while upstream is unchanged',
    ];

    foreach ($scenarios as $label) {
        expect($content)->toContain($label);
    }
});

test('the script treats upstream content as untrusted data and never runs it', function (): void {
    $content = externalSkillSource('scripts/sync-external-skills.sh');

    // One pinned download path, shared with the attachment helpers: https only, size cap, TLS on.
    expect($content)->toContain('source "$PACKAGE_DIR/skills/_shared/attachments.sh"');
    expect($content)->toContain('att_download "$url" "$out" "$cfg"');
    expect($content)->not->toMatch('/^\s*curl\s/m');
    expect($content)->not->toContain('--insecure');
    expect($content)->not->toContain('curl -k');

    // Only the two fixed GitHub hosts are ever requested, and files are fetched at the commit SHA.
    expect($content)->toContain('API_BASE=\'https://api.github.com/repos\'');
    expect($content)->toContain('RAW_BASE=\'https://raw.githubusercontent.com\'');
    expect($content)->toContain('"$RAW_BASE/$repo/$sha/$src/$f"');

    // The token reaches api.github.com through a 0600 curl config, never argv, never the raw host.
    expect($content)->toContain('umask 077');
    expect($content)->toContain('[[ "$kind" == api ]] && cfg="$API_CFG"');
    expect($content)->toContain('printf \'header = "Authorization: Bearer %s"\\n\' "$GITHUB_TOKEN"');

    // Downloaded bytes are copied, never executed, sourced, or evaluated.
    expect($content)->not->toMatch('/\beval\b/');
    expect($content)->not->toMatch('/\bsource\s+"?\$(stage|WORK|ROOT)/');
    expect($content)->not->toMatch('/\bbash\s+"?\$(stage|WORK|ROOT)/');
    expect($content)->not->toMatch('/\bgh\s+pr\s+merge\b/');

    // Path safety: segments start alphanumeric (no "..", no hidden), and the target is skills/<name>.
    expect($content)->toContain('RE_PATH=\'^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$\'');
    expect($content)->toContain('[[ "$f" == "skills/$name" ]]');
    expect($content)->toContain('RE_EXT=\'\\.(md|txt|json|ya?ml|csv)$\'');
    expect($content)->toContain('[[ "$f" == pull-request ]]');
});

test('the shipped registry only holds entries the script accepts', function (): void {
    $registry = externalSkillJson('config/external-skills.json');

    expect(array_keys($registry))->toBe(['allowed_licenses', 'skills']);
    expect(externalSkillStrings($registry['allowed_licenses'] ?? null))->not->toBeEmpty();

    $names = [];

    foreach ((array) $registry['skills'] as $entry) {
        $entry = (array) $entry;
        $keys = array_keys($entry);
        sort($keys);

        expect($keys)->toBe(['enabled', 'files', 'name', 'ref', 'repository', 'source_path', 'target_path', 'update_strategy']);

        $name = externalSkillString($entry['name'] ?? null);
        expect($name)->toMatch('/^[a-z0-9][a-z0-9-]{0,63}$/');
        expect($names)->not->toContain($name);
        $names[] = $name;

        expect($entry['target_path'])->toBe('skills/' . $name);
        expect($entry['update_strategy'])->toBe('pull-request');
        expect($entry['enabled'])->toBeBool();
        expect($entry['repository'])->toMatch('#^[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9._-]+$#');
        expect((array) $entry['files'])->toContain('SKILL.md');

        foreach ([externalSkillString($entry['source_path'] ?? null), ...externalSkillStrings($entry['files'] ?? null)] as $path) {
            expect($path)->toMatch('#^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$#');
            expect($path)->not->toContain('..');
        }
    }
});

test('every locked external skill has a commit SHA, file hashes, a licence, and the licence file beside it', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $lockPath = $packageDir . '/config/external-skills.lock.json';

    if (!is_file($lockPath)) {
        expect(externalSkillJson('config/external-skills.json')['skills'])->toBe([]);

        return;
    }

    $registry = externalSkillJson('config/external-skills.json');
    $skills = (array) (externalSkillJson('config/external-skills.lock.json')['skills'] ?? []);

    foreach ($skills as $name => $record) {
        $record = (array) $record;
        $license = (array) $record['license'];

        expect($record['commit'])->toMatch('/^[0-9a-f]{40}$/');
        expect($record['synced_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
        expect($record['repository'])->toStartWith('https://github.com/');
        expect($registry['allowed_licenses'])->toContain($license['spdx']);
        expect($license['sha256'])->toMatch('/^[0-9a-f]{64}$/');
        expect(is_file($packageDir . '/skills/' . $name . '/UPSTREAM-LICENSE'))->toBeTrue();

        foreach ((array) $record['files'] as $hash) {
            expect($hash)->toMatch('/^[0-9a-f]{64}$/');
        }
    }
});

test('the workflow runs daily and by hand, grants the minimum, and never merges', function (): void {
    $workflow = externalSkillSource('.github/workflows/sync-external-skills.yml');

    expect($workflow)->toContain("schedule:\n    - cron: '23 3 * * *'");
    expect($workflow)->toContain('workflow_dispatch:');

    // Exactly the two permissions the pull request step needs, and no trigger that runs with a
    // writable token on code from a fork.
    expect($workflow)->toContain("permissions:\n  contents: write\n  pull-requests: write\n");
    expect(substr_count($workflow, 'permissions:'))->toBe(1);
    expect($workflow)->not->toContain('pull_request_target');
    expect($workflow)->not->toContain('id-token');
    expect($workflow)->not->toContain('actions: write');

    // One stable branch and one pull request; a second run waits for the first.
    expect($workflow)->toContain('branch: automation/sync-external-skills');
    expect($workflow)->toContain('group: sync-external-skills');
    expect($workflow)->toContain('cancel-in-progress: false');

    // No automatic merge, no approval, no auto-merge flag.
    expect($workflow)->not->toMatch('/pr\s+merge|--auto|automerge|auto-merge|enable-auto-merge|gh pr review|--approve/i');
});

test('the workflow commits only skills and the lock file, so upstream content cannot change a workflow', function (): void {
    $workflow = externalSkillSource('.github/workflows/sync-external-skills.yml');

    expect($workflow)->toContain("add-paths: |\n            skills/\n            config/external-skills.lock.json\n");
    expect($workflow)->not->toContain('git add');
    expect($workflow)->not->toContain('git push');
    expect($workflow)->toContain('bash scripts/sync-external-skills.sh');

    // No secret other than the built-in token, and no expression from user input inside a script.
    preg_match_all('/secrets\.([A-Za-z0-9_]+)/', $workflow, $secrets);
    expect(array_unique($secrets[1]))->toBe(['GITHUB_TOKEN']);
    expect($workflow)->not->toContain('github.event.inputs');
    expect($workflow)->not->toContain('inputs.');
});

test('the workflow reports a failed sync after the pull request step, so a failure is never silent', function (): void {
    $workflow = externalSkillSource('.github/workflows/sync-external-skills.yml');

    $syncStep = strpos($workflow, 'id: sync');
    $pullRequestStep = strpos($workflow, 'peter-evans/create-pull-request');
    $reportStep = strpos($workflow, 'Report sync errors');

    expect($syncStep)->not->toBeFalse();
    expect($pullRequestStep)->toBeGreaterThan((int) $syncStep);
    expect($reportStep)->toBeGreaterThan((int) $pullRequestStep);

    expect($workflow)->toContain('echo "exit_code=$code" >> "$GITHUB_OUTPUT"');
    expect($workflow)->toContain('if: steps.sync.outputs.exit_code != \'0\'');
    expect($workflow)->toContain('exit 1');
    expect($workflow)->toContain('$GITHUB_STEP_SUMMARY');
});

test('the README documents how to add, run, version, roll back, disable, and resolve conflicts of an external skill', function (): void {
    $readme = externalSkillSource('README.md');

    expect($readme)->toContain('## External Skills');

    foreach ([
        '### Add an external skill',
        '### Run the sync',
        '### Versioning',
        '### Roll back an update',
        '### Disable an external skill',
        '### Conflicts',
        '### Licences',
        '### Repository settings',
    ] as $heading) {
        expect($readme)->toContain($heading);
    }

    foreach ([
        'config/external-skills.json',
        'config/external-skills.lock.json',
        'scripts/sync-external-skills.sh',
        'UPSTREAM-LICENSE',
        'workflow_dispatch',
        'Allow GitHub Actions to create and approve pull requests',
    ] as $needle) {
        expect($readme)->toContain($needle);
    }
});
