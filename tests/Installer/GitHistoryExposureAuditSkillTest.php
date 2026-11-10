<?php

declare(strict_types = 1);

// The behavioural proof of the scan lives in `history-scan.sh --self-test`, which builds its
// repositories and its planted values at run time (a Pest test here never executes a shell script).
// These tests pin the skill text, the report template, the self-test scenarios, and the review-mode
// wiring, so none of them can disappear silently.

function historyExposureSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function historyExposureScriptBody(): string
{
    $script = historyExposureSource('skills/git-history-exposure-audit/scripts/history-scan.sh');
    $selfTest = strpos($script, "\n# Self-test.");

    expect($selfTest)->not->toBeFalse();

    $lines = explode("\n", substr($script, 0, (int) $selfTest));

    return implode("\n", array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '#')));
}

test('the skill ships two modes and states it is read-only, never tries a credential, and never publishes the full audit', function (): void {
    $skill = historyExposureSource('skills/git-history-exposure-audit/SKILL.md');

    expect($skill)->toContain("name: git-history-exposure-audit\n");
    expect($skill)->toContain(
        '| Starts | on an explicit user request, in the top-level session only | in every code review of a project whose GitHub repository is public |',
    );
    expect($skill)->toContain('- **Defensive and read-only.**');
    expect($skill)->toContain('- **Never try a found credential.**');
    expect($skill)->toContain('- **The full-audit report is never published.**');
    expect($skill)->toContain('Apply `@rules/security/general.md`.');
    expect($skill)->not->toContain('@skills/security-audit/SKILL.md');
});

test('the agent never opens, quotes, or tries a found value, and never reads the secret-scanning alerts', function (): void {
    $skill = historyExposureSource('skills/git-history-exposure-audit/SKILL.md');
    $body = historyExposureScriptBody();

    expect($skill)->toContain(
        'It runs no `git show`, `git cat-file -p`, `git log -p`, or `git grep` on a finding\'s commit, blob, or path. It quotes no matched line.',
    );
    expect($skill)->toContain('The skill never reads the `secret-scanning/alerts` endpoint of the GitHub API, also not with `hide_secret=true`');
    expect($body)->not->toContain('secret-scanning/alerts');
    expect(preg_match('/(^|[\s|;&(])(curl|wget|nc|ssh|scp)\s/m', $body))->toBe(0);
    expect(preg_match_all('/\bgh api\b[^\n]*/', $body, $calls))->toBe(2);
    expect($calls[0][0])->toStartWith('gh api --method GET "$@"');
    expect($calls[0][1])->toStartWith('gh api graphql --paginate -f owner=');
    expect($body)->not->toContain('--hostname');
});

test('the full audit refuses a target without push permission, a fork without parent and source push, and any host but github.com', function (): void {
    $script = historyExposureSource('skills/git-history-exposure-audit/scripts/history-scan.sh');

    expect($script)->toContain('#   Refuses the target unless `gh api --method GET repos/<owner>/<repo>` returns');
    expect($script)->toContain('#   `.permissions.push == true`; a fork also needs push on its `parent` and `source`.');
    expect($script)->toContain('GH_HOST=github.com GH_PROMPT_DISABLED=1 gh api --method GET "$@" 2>/dev/null');

    $refusals = [
        '\'o/nopush:cannot push\'',
        '\'o/noperm:no permissions field\'',
        '\'o/nullpush:permissions.push is null\'',
        '\'o/gone:the GitHub API call failed\'',
        '\'o/fork:up/r: the authenticated account cannot push\'',
        '\'github.example/o:<owner>/<repo> on github.com\'',
    ];

    foreach ($refusals as $case) {
        expect($script)->toContain($case);
    }
});

test('the self-test builds every required case at run time', function (): void {
    $script = historyExposureSource('skills/git-history-exposure-audit/scripts/history-scan.sh');

    foreach ([
        'full: a value in a file a later commit deleted is found, and the head no longer carries it',
        'full: a value only on a non-default branch is found',
        'full: a value only in a tagged commit names the tag',
        'full: a value in a commit message is found, and one value found twice carries one fingerprint',
        'full: a .env path added and removed is a sensitive-file finding',
        'full: the content of a .env blob is never read',
        'full: a commit e-mail domain is an identity finding that prints the domain',
        'full: a user-supplied term is found',
        'full: a term that looks like a pattern matches only its literal text',
        'full: a committed .mailmap changes no reported identity',
        'full: a clean repository exits 0',
        'range: a value one commit adds and the next removes is reported',
        'range: a value only in a merge commit',
        'range: a value merged in from the default branch is no finding',
        'range: a file renamed to .env is an added sensitive path',
        'range: no textconv, external diff, smudge, pager, or fsmonitor program runs',
        'range: a hostile path runs nothing and writes no marker',
        'range: a missing object exits 3, never 0',
        'range: no clone, fetch, ls-remote, gh, or curl call',
        'range: one value found in two runs carries two different fingerprints',
        'range: a fingerprint differs from the unsalted SHA-256 prefix of its value',
        'full: the clone is a hardened mirror clone of a URL the script composed',
        'full: a branch named with $(touch marker) runs nothing',
        'full: a term with a control character is refused with exit 1',
        'package: the tracked tree of this package gives no finding',
    ] as $label) {
        expect($script)->toContain($label);
    }

    expect($script)->toContain('ST_AWS_DELETED="AK""IA$(st_rnd 16 \'A-Z0-9\')"');
    expect($script)->toContain('SHELLOPTS=xtrace');
    expect($script)->toContain('"$name: no planted value on stdout, on stderr, or in a recorded argument list"');
    expect($script)->toContain('"$name: no file is left under TMPDIR"');
});

test('the scan states its exit codes, its redaction, and its fingerprint in its header', function (): void {
    $script = historyExposureSource('skills/git-history-exposure-audit/scripts/history-scan.sh');
    $exitCodes = [
        '#   0  a complete scan with no finding',
        '#   3  a failed or incomplete scan; the findings it has are printed',
        '#   4  the full audit refused the target',
        '#   5  a complete scan with findings',
    ];

    foreach ($exitCodes as $code) {
        expect($script)->toContain($code);
    }

    expect($script)->toContain('#   Fingerprint: the first 12 hex digits of SHA-256 over `salt LF class LF value`, where the salt is');
    expect($script)->toContain('set +o xtrace +o verbose');
    expect($script)->toContain("    git --no-pager --no-lazy-fetch --no-replace-objects \\\n");
    expect($script)->toContain('GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null');
    expect($script)->toContain('DIFF_OPTS=(--no-ext-diff --no-textconv --no-show-signature --ignore-submodules=all --no-renames');
    expect(substr_count(historyExposureSource('composer.json'), 'skills/git-history-exposure-audit/scripts/history-scan.sh'))->toBe(1);
});

test('the report template carries the remediation per class, the rewrite checklist, and the not-verified section', function (): void {
    $template = historyExposureSource('skills/git-history-exposure-audit/templates/REPORT.md');

    expect($template)->toContain('1. **Revoke or rotate the credential first.**');
    expect($template)->toContain('2. Delete the earlier revision from the item\'s edit history.');
    expect($template)->toContain('2. **The consequences:** every later commit SHA changes; every existing clone and fork keeps the old objects;');
    expect($template)->toContain(
        'The owner verifies every item **before** the rewrite. When one item fails, stop: do not rewrite, and settle that item first.',
    );
    expect($template)->toContain('Never publish it: no tracker comment, no');
    expect($template)->toContain('## Not verified');
    expect(substr_count($template, 'git push --force-with-lease'))->toBe(2);

    foreach (['skills/git-history-exposure-audit/SKILL.md', 'skills/git-history-exposure-audit/scripts/history-scan.sh'] as $path) {
        expect(historyExposureSource($path))->not->toContain('force-with-lease');
    }
});

test('review mode is an always-run security lens on a public repository wherever security-audit Diff mode is named', function (): void {
    expect(historyExposureSource('rules/code-review/general.md'))
        ->toContain('- **The history exposure lens runs on every review of a public repository.**')
        ->toContain(
            '`@skills/security-review/SKILL.md`, `@skills/git-history-exposure-audit/SKILL.md` in its *Review mode*, '
            . '`@skills/security-audit/SKILL.md` in its *Diff mode*',
        )
        ->toContain('All four lenses are exempt from the gate in full');
    expect(historyExposureSource('skills/code-review/SKILL.md'))
        ->toContain('`git-history-exposure-audit` in its *Review mode* on a public repository, `api-review`) one at a time');
    expect(historyExposureSource('skills/code-review/references/specialized-reviews.md'))
        ->toContain(
            '- @skills/git-history-exposure-audit/SKILL.md in its *Review mode* — '
            . '**always run when the reviewed repository is public on GitHub, never skipped there**',
        );
    expect(historyExposureSource('rules/compound-engineering/orchestration.md'))
        ->toContain(
            '`security-audit` in its *Diff mode*, `git-history-exposure-audit` in its *Review mode* on a public repository, '
            . '`api-review`, `assignment-compliance-check`',
        )
        ->toContain('The one named exception is the full audit of `@skills/git-history-exposure-audit/SKILL.md`')
        ->toContain('| top-level session | Run the full audit of `@skills/git-history-exposure-audit/SKILL.md`');
    expect(historyExposureSource('agents/leonardo.md'))
        ->toContain('and `@skills/git-history-exposure-audit/SKILL.md` in its *Review mode* on every review of a public repository')
        ->toContain('run the installed `git-history-exposure-audit/scripts/history-scan.sh` in its `range` and `text` modes only');

    foreach (['skills/code-review-github/templates/pr-comment-output.md', 'skills/code-review/templates/review-output.md'] as $template) {
        expect(historyExposureSource($template))->toContain('History exposure: not run — the repository is private.  *(render this one body line');
    }
});

test('a review-mode finding blocks at its severity and never shows the value', function (): void {
    $skill = historyExposureSource('skills/git-history-exposure-audit/SKILL.md');

    expect($skill)->toContain('- **Severity.** A credential or sensitive-file finding is **Critical**. An infrastructure finding is **Moderate**.');
    expect($skill)->toContain(
        '- **The published finding names the class, the rule id, the short commit SHA, and the path,** or the URL for a text. '
        . 'It never names the value',
    );
    expect($skill)->toContain('- **The tracker summary names the class only,** never a commit or a path.');
    expect($skill)->toContain('- **A failed scan is not a clean round.**');
    expect($skill)->toContain('Never run a copy from the checked-out branch or from the review worktree');
    expect($skill)->toContain('On `false`, and on a failed read, the mode runs');
    expect($skill)->toContain('Deleting the value in one more commit is not a fix');
});

test('the allow-list is a default-branch manifest key and the package lists the skill', function (): void {
    expect(historyExposureSource('rules/general/general.md'))->toContain('| `history-exposure.allow` | object[] | `{rule, path}` pairs');
    expect(historyExposureSource('skills/git-history-exposure-audit/SKILL.md'))
        ->toContain('- **The only suppression path is the default-branch allow-list:**');
    expect(historyExposureSource('skills/security-audit/SKILL.md'))
        ->toContain('- `@skills/git-history-exposure-audit/SKILL.md` reads the git history and the GitHub texts');
    expect(historyExposureSource('docs/skills.md'))->toContain('| [`git-history-exposure-audit`](../skills/git-history-exposure-audit/) |');
    expect(historyExposureSource('skills/_shared/gate-context.sh'))->toContain('(security-review|security-audit|git-history-exposure-audit)');
});
