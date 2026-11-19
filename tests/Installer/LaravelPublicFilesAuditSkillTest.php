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

    expect($skill)->toContain("name: laravel-public-files-audit\n")
        ->and($skill)->toContain("license: MIT\n")
        ->and($skill)->toContain('author: "Petr Král (pekral.cz)"')
        ->and(packageSource('docs/skills.md'))->toContain('| [`laravel-public-files-audit`](../skills/laravel-public-files-audit/) |');
});

test('the skill covers the seven checks of the issue with their severities (issue #580)', function (): void {
    $skill = packageSource('skills/laravel-public-files-audit/SKILL.md');

    foreach ([
        '| `dump-archive-backup` | SQL dumps, archives, and backups in `public/` | Critical |',
        '| `env-config-copy` | a public `.env` copy and configuration copies | Critical |',
        '| `log-debug` | logs and debug output | High |',
        '| `public-storage-script` | risky files in the public disk (`storage/app/public/`, served as `public/storage`) | High |',
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

    // The two matches are the usage text and the removal of the script's own temporary header file.
    expect(preg_match_all('/(^|[\s|;&(])(rm|mv|chmod|chown|cp|ln|cat|head|tail|less|strings|unzip|tar)\s[^\n]*/m', $body, $calls))->toBe(2)
        ->and($calls[0][0])->toContain('cat >&2 <<\'EOF\'')
        ->and($calls[0][1])->toContain('rm -f "$hdr"')
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

    expect(packageSource('agents/splinter.md'))
        ->toContain('   - `@skills/laravel-public-files-audit/SKILL.md` in its *Review mode*, only when the reviewed project is a Laravel project')
        ->and(deliberatelyNotRunGroups($section)[3] ?? [])->toContain('laravel-public-files-audit')
        ->and(packageSource('skills/laravel-public-files-audit/SKILL.md'))
        ->toContain('Run the copy the session loaded the skill from')
        ->and(packageSource('composer.json'))->toContain('skills/laravel-public-files-audit/scripts/public-files-scan.sh');
});
