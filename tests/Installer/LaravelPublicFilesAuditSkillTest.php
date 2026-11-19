<?php

declare(strict_types = 1);

// Issue #580 — the behavioural proof of the scan lives in `public-files-scan.sh --self-test`, which
// builds its fixture projects at run time (a Pest test here never executes a shell script). These
// tests pin the skill text, the check table, the safety limits of the script, and the wiring into
// the independent security view, so none of them can disappear silently.

function publicFilesScanBody(): string
{
    $script = packageSource('skills/laravel-public-files-audit/scripts/public-files-scan.sh');
    $selfTest = strpos($script, "\n# Self-test.");

    expect($selfTest)->not->toBeFalse();

    $lines = explode("\n", substr($script, 0, (int) $selfTest));

    return implode("\n", array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '#')));
}

test('laravel-public-files-audit ships as a skill with its catalog row (issue #580)', function (): void {
    $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');
    $catalog = packageSource('docs/skills.md');
    $security = explode("\n## ", explode("\n## Security\n", $catalog)[1] ?? '')[0];

    expect($skill)->toContain("name: laravel-public-files-audit\n")
        ->and($skill)->toContain("license: MIT\n")
        ->and($skill)->toContain('author: "Petr Král (pekral.cz)"')
        ->and($catalog)->toContain('| [`laravel-public-files-audit`](../skills/laravel-public-files-audit/) |')
        ->and($catalog)->toContain('| [Security](#security) | ' . preg_match_all('/^\| \[`/m', $security) . ' |');
});

test('the skill covers the seven checks of the issue with their severities (issue #580)', function (): void {
    $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');

    foreach ([
        '| `dump-archive-backup` | SQL dumps, archives, and backups in `public/` | Critical |',
        '| `env-config-copy` | a public `.env` copy and configuration copies | Critical |',
        '| `log-debug` | logs and debug output | High |',
        '| `public-storage-script` | risky files in the public disk (`storage/app/public/`, served as `public/storage`, '
            . 'and every directory or file a `links` entry or a served disk serves) | High |',
        '| `symlink-permission` | public symlinks and wrong permissions | High |',
        '| `gitignore-deploy` | `.gitignore` coverage and deployment steps | Medium |',
        '| `http-reachable` | reachability of a file over HTTP | High |',
    ] as $row) {
        expect($skill)->toContain($row);
    }
});

test('the skill is read-only, never opens a reported file, and never publishes the report (issue #580)', function (): void {
    $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');
    $template = packageSource('skills/laravel-public-files-audit/templates/report.md');

    expect($skill)->toContain('- **Read-only.** The skill detects and reports. It never deletes, moves, renames, or changes the mode of a file')
        ->and($skill)->toContain('- **Never open a reported file.**')
        ->and($skill)->toContain('*Environment values are never read* applies unchanged: no `.env*` file other than `.env.example`')
        ->and($skill)->toContain('- **The report is never published.**')
        ->and($template)->toContain('| Severity | Check | Path | Evidence | Remediation |')
        ->and($template)->toContain('The owner performs every step. The audit deletes, moves, and changes nothing.');
});

test('the scan script changes no file and reads no reported content (issue #580)', function (): void {
    $body = publicFilesScanBody();

    // The one match is the usage text. The only removal is the exit trap of the script's own scratch directory.
    expect(preg_match_all('/(^|[\s|;&(])(rm|mv|chmod|chown|cp|ln|cat|head|tail|less|strings|unzip|tar)\s[^\n]*/m', $body, $calls))->toBe(1)
        ->and($calls[0][0])->toContain('cat >&2 <<\'EOF\'')
        ->and($body)->toContain('trap \'rm -rf -- "$SCRATCH"\' EXIT')
        ->and($body)->not->toContain('printenv')
        ->and($body)->not->toContain('source ')
        ->and($body)->toContain('bash "$CLASSIFIER" classify');
});

test('the runtime mode sends only HEAD requests to a local or reserved test host (issue #580)', function (): void {
    $script = packageSource('skills/laravel-public-files-audit/scripts/public-files-scan.sh');
    $body = publicFilesScanBody();

    expect(preg_match_all('/\bcurl -q [^\n]*/', $body, $calls))->toBe(1)
        ->and($calls[0][0])->toContain('--head --max-redirs 0 --proto \'=http,https\' --noproxy \'*\'')
        ->and($body)->not->toMatch('/curl[^\n]*(\s-L\b|--location|\s-u\b|--user|--netrc|--cookie)/')
        ->and($body)->toContain('[[ "$host" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+(localhost|test)$ ]]')
        ->and($body)->toContain('pin=(--resolve "$host:$port:127.0.0.1")')
        ->and($calls[0][0])->toContain('--noproxy \'*\' ${pin[@]+"${pin[@]}"}')
        ->and(packageSource('skills/laravel-public-files-audit/SKILL.md'))
        ->toContain(
            '- **No request leaves the machine.** The script pins every accepted name to `127.0.0.1` with curl `--resolve <host>:<port>:127.0.0.1`',
        )
        ->and($script)->toContain('"runtime: $target is refused with exit 4 before any request"')
        ->and($script)->toContain('http://127.0.0.1.example.com http://example.test.evil.com');
});

test('the scan shares the sensitive-file table of git-history-exposure-audit (issue #580)', function (): void {
    $history = packageSource('skills/git-history-exposure-audit/scripts/history-scan.sh');

    expect($history)->toContain('#   history-scan.sh classify < <paths, one per line>')
        ->and($history)->toContain('  classify) shift; mode_classify "$@" ;;')
        ->and($history)->toContain('classify: each sensitive path prints its line number and rule, any other path prints nothing')
        ->and(packageSource('skills/laravel-public-files-audit/scripts/public-files-scan.sh'))
        ->toContain('CLASSIFIER="$SCRIPT_DIR/../../git-history-exposure-audit/scripts/history-scan.sh"');
});

test('splinter runs the review mode and the code review classifies the other modes as not run (issue #580)', function (): void {
    $section = explode('## Skills deliberately not run', packageSource('skills/code-review/references/specialized-reviews.md'))[1] ?? '';
    $splinter = packageSource('agents/splinter.md');

    expect($splinter)
        ->toContain('   - `@skills/laravel-public-files-audit/SKILL.md` in its *Review mode*, in every round of a Laravel project, '
            . 'whatever paths the diff touches;')
        ->and($splinter)->toContain(
            'and the `laravel-public-files-audit/scripts/public-files-scan.sh` of that clone in its `static` mode with `--changed-from` only',
        )
        ->and(deliberatelyNotRunGroups($section)[3] ?? [])->toContain('laravel-public-files-audit')
        ->and(packageSource('composer.json'))->toContain('skills/laravel-public-files-audit/scripts/public-files-scan.sh');
});

test('the review mode runs the scan and its classifier only as the verified copy of the untouched clone (issue #580)', function (): void {
    $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');

    expect($skill)->toContain('2. **The trusted copy of the script.** Run the copy of the untouched clone of '
        . '`skills/merge-github-pr/references/merge-exemptions.md` *The trusted classifier* item 6')
        ->and($skill)->toContain('Use the procedure of `@skills/git-history-exposure-audit/SKILL.md` *Review mode* step 2 unchanged')
        ->and($skill)->toContain('The paths are `skills/laravel-public-files-audit/scripts/public-files-scan.sh '
            . 'skills/git-history-exposure-audit/scripts/history-scan.sh`.')
        ->and($skill)->not->toContain('Run the copy the session loaded the skill from')
        ->and(packageSource('rules/compound-engineering/orchestration.md'))
        ->toContain('The review mode of `@skills/laravel-public-files-audit/SKILL.md` runs its scan from the same clone.');
});

test('the scan never reads outside the physical project root (issue #580)', function (): void {
    $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');
    $script = packageSource('skills/laravel-public-files-audit/scripts/public-files-scan.sh');

    expect($skill)->toContain('- **The scan stays inside the project.** The scan never reads, lists, or stats an entry outside the physical project root.')
        ->and(publicFilesScanBody())->not->toContain('find -H')
        ->and($script)->toContain('st_confine_cases')
        ->and($script)->toContain('"confine $spec: no line carries metadata of a file outside the project"');
});

test('the review mode reports the full static scan on every review of a Laravel project (issue #580)', function (): void {
    $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');
    $script = packageSource('skills/laravel-public-files-audit/scripts/public-files-scan.sh');

    expect($skill)->toContain('1. **When it runs.** On every code review of a Laravel project')
        ->and($skill)->toContain('   - **The review reports every finding of the full static scan.** It never narrows the findings')
        ->and($skill)->toContain('   - **A git submodule is not verified.**')
        ->and($skill)->not->toContain('trigger path')
        ->and($skill)->not->toContain('scope=diff')
        ->and(publicFilesScanBody())->not->toContain('in_scope')
        ->and(publicFilesScanBody())->toContain('git_ro diff --raw -z --no-renames --ignore-submodules=none "$base" HEAD --')
        ->and($script)->toContain('st_submodule_cases');
});

test('the review mode reads every links entry of the filesystems configuration in every round (issue #580)', function (): void {
    $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');
    $script = packageSource('skills/laravel-public-files-audit/scripts/public-files-scan.sh');

    expect($skill)->toContain('4. **The configuration.** The scan reads every `links` entry of `config/filesystems.php` in every round, '
        . 'whether or not the diff touches that file')
        ->and($skill)->toContain('   - An entry it cannot read statically (a variable, an `env()` or `config()` call, a concatenation) is a '
            . '`Not verified: public files — …` line and an incomplete scan.')
        ->and($skill)->not->toContain('on the changed lines')
        ->and($script)->toContain('st_links_cases')
        ->and($script)->toContain('\'links: a dump added to a linked directory of an unchanged config is a critical finding, exit 5\'')
        ->and(publicFilesScanBody())->toContain('config_roots')
        ->and(publicFilesScanBody())->not->toMatch('/\b(printenv|getenv)\b|source\s/');
});

test(
    'a form of project-controlled input that the scan cannot read is a not-verified line, and no symlinked config is opened (issue #580)',
    function (): void {
        $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');
        $script = packageSource('skills/laravel-public-files-audit/scripts/public-files-scan.sh');
        $body = publicFilesScanBody();

        expect($skill)->toContain('- **Unknown is not clean.** The scan reads project-controlled input')
            ->and($skill)->toContain('It never opens the file through a symlink: a symlinked file or directory is a '
                . '`Not verified: public files — …` line')
            ->and($skill)->toContain('- A deployment step that names a path below a served path is checked as a step that names '
                . '`public/` is.')
            ->and($body)->toContain('if [[ -L "$file" ]]; then')
            ->and($body)->toContain('the file is a symlink, so the scan reads nothing from it')
            ->and($body)->toContain('OVERRIDE_PATH=')
            ->and($script)->toContain('st_failclosed_cases')
            ->and($script)->toContain('\'s8: a config symlink to .env is a not-verified line, exit 3\'')
            ->and($script)->toContain('\'s9: a heredoc is a not-verified line\'')
            ->and($script)->toContain('\'m3: a deployment step that writes a dump into a linked directory '
                . 'is a gitignore-deploy finding, exit 5\'');
    },
);
