<?php

declare(strict_types = 1);

// The shared repository-ownership gate. Every skill that acts on a GitHub
// reference must prove the reference belongs to the current checkout first,
// otherwise a pasted foreign URL silently redirects the whole run.
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so
// the behavioural proof lives in the script's own `--self-test` (the precedent
// is `skills/_shared/scan-attachments.sh`) and these guards pin the scenarios
// that self-test is required to cover. Pinning source patterns instead would
// repeat the first version's mistake: every assertion passed while the script
// misjudged four of ten real remote URL forms.

test('shared repository-ownership guard is shipped and executable', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/_shared/assert-current-repo.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    $content = (string) file_get_contents($script);
    expect($content)->toStartWith('#!/usr/bin/env bash');
    expect($content)->toContain('set -euo pipefail');
    expect($content)->toContain('--self-test');
});

test('guard self-test accepts every remote URL form git itself accepts', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // Each of these spellings reached a wrong verdict before: some fell through
    // to a hidden `gh` dependency, the SSH host alias blocked all skills
    // outright, and the trailing slash reported a MISMATCH on the correct repo.
    $acceptedRemoteForms = [
        'https remote',
        'scp-style remote',
        'ssh:// remote',
        'git+ssh:// remote',
        'git:// remote',
        'credentials in remote',
        'trailing slash remote',
        'email-style userinfo',
    ];

    foreach ($acceptedRemoteForms as $label) {
        expect($content)->toContain('\'' . $label . '\'');
    }
});

test('guard self-test blocks foreign repositories and path traversal', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // Traversal is the bypass that made the first version worthless: the first
    // two path segments read as ours while the server resolves a different repo.
    expect($content)->toContain('\'traversal in reference\'');
    expect($content)->toContain('pekral/ecomail-cockpit');
    expect($content)->toContain('\'foreign repo\'');
    expect($content)->toContain('\'foreign owner\'');
    expect($content)->toContain('\'foreign repo name\'');
    expect($content)->toContain('\'host suffix attack\'');
    expect($content)->toContain('\'percent-encoded separator\'');
    expect($content)->toContain('\'encoded traversal\'');
    expect($content)->toContain('\'double-encoded traversal\'');
    expect($content)->toContain('\'backslash separator\'');

    // Both round-two Critical findings: an `@` in the path read as userinfo,
    // and an SSH host alias honoured over https (`github.com-evil.example` is
    // registrable). Each is pinned on the side it actually applies to.
    expect($content)->toContain('\'userinfo lookalike in path\'');
    expect($content)->toContain('\'dash-alias host over https\'');
    expect($content)->toContain('\'dash-alias remote over https\'');
    expect($content)->toContain('\'glob wildcards in reference\'');
    expect($content)->toContain('\'bracket glob in reference\'');
});

test('guard resolves ssh host aliases instead of guessing from the host name', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // A `github.com-*` prefix rule cannot tell a local ssh alias from
    // `github.com-evil.example`, a registrable domain. `ssh -G` answers from
    // the operator's own config, which is what gh does internally.
    expect($content)->toContain('ssh_host_is_github');
    expect($content)->toContain('ssh -G -- ');
    expect($content)->toContain('configured ssh alias resolves to github.com');
    expect($content)->toContain('unconfigured alias is not github.com');

    // The alias lookup must run once per distinct host, not once per remote URL.
    // A memo on the resolver could not work: it runs inside a `$()` subshell, so
    // cache state never persisted — measured 4 ssh calls where 2 were due. The
    // caller de-duplicates URLs instead, which does not depend on subshell state.
    expect($content)->not->toContain('SSH_CACHE_HOST');
    expect($content)->toContain('!seen[$0]++');
});

test('guard splits the path without exposing it to pathname expansion', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // An unquoted `set -- $path` also globs, so `https://github.com/*/*`
    // expanded against the caller's cwd and the verdict depended on which
    // files happened to sit there — fail-open, exit 0.
    expect($content)->toContain('IFS=\'/\' read -r -a segments');

    $code = (string) preg_replace('/^\s*#.*$/m', '', $content);
    expect($code)->not->toContain('set -- $path');
});

test('guard self-test covers the fork workflow in both directions', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // origin = fork, upstream = canonical repo the PR lives on. Both are this
    // project, so the guard must accept upstream while still blocking a repo
    // that is on neither remote.
    expect($content)->toContain('fork workflow accepts upstream');
    expect($content)->toContain('fork workflow still blocks foreign');
    expect($content)->toContain('\'insteadOf rewrite\'');
    expect($content)->toContain('\'pushurl points at this repo\'');
    expect($content)->toContain('\'multi-valued remote url\'');
});

test('guard reads effective remote URLs rather than raw config', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // `git config --get remote.origin.url` ignores insteadOf rewrites, pushurl,
    // multi-valued url entries, and every remote that is not named origin —
    // each of which compares against a repository git would never contact.
    expect($content)->toContain('git remote get-url --all');
    expect($content)->toContain('git remote get-url --push --all');

    // Strip comments first: the header legitimately *names* the naive call to
    // document why it is not used. Only executable code matters.
    $code = (string) preg_replace('/^\s*#.*$/m', '', $content);
    expect($code)->not->toContain('git config --get remote.origin.url');
});

test('guard separates a mismatch from an unprovable verdict and never suggests a fallback', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // Exit 3 is the loader convention's "fall back to MCP" code. Reusing it
    // here invited callers to route around an ownership verdict, so a mismatch
    // (4) and an unprovable check (5) are deliberately distinct from it.
    expect($content)->toContain('4  MISMATCH');
    expect($content)->toContain('5  the current repository could not be resolved');
    expect($content)->toContain('Code 3 is intentionally unused');
    expect($content)->toContain('\'non-github remote only\'');
});

test('guard surfaces git errors instead of reporting them as a missing remote', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // A `dubious ownership` refusal or a broken config must not masquerade as
    // "no remote configured" — that sends the caller chasing a remote that is
    // in fact present (@rules/security/backend.md, Suppressed error output).
    expect($content)->toContain('git could not read this directory');
    expect($content)->toContain('git could not list remotes');
});

test('guard renders untrusted references without letting them rewrite the message', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // The reference comes from tracker fields any user can write, and it is
    // printed in the message meant to stop the operator. ANSI escapes could
    // erase that message; bidi overrides could reverse it.
    expect($content)->toContain('safe_display');
    expect($content)->toContain('$(safe_display "$INPUT")');
});

test('every skill that acts on a GitHub reference wires the repository-ownership guard', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // These skills implement, review, comment on, merge, seed data from, or
    // publish a report derived from a GitHub reference.
    $guarded = [
        'merge-github-pr',
        'code-review-github',
        'process-code-review',
        'prepare-issue-context',
        'code-review-jira',
        'code-review-bugsnag',
        'resolve-issue',
        'tester-cookbook',
        'analyze-problem',
    ];

    foreach ($guarded as $skill) {
        $content = (string) file_get_contents($packageDir . '/skills/' . $skill . '/SKILL.md');
        expect($content)->toContain('skills/_shared/assert-current-repo.sh');
    }
});

test('guarded skills state that only a zero exit continues and no MCP fallback applies', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // Without this the agent has no instruction for exit 1 / 2 / 127 (a missing
    // git, or a stale consumer install without the script) and the gate opens.
    $guarded = [
        'merge-github-pr',
        'code-review-github',
        'process-code-review',
        'prepare-issue-context',
        'code-review-jira',
        'code-review-bugsnag',
        'tester-cookbook',
        'analyze-problem',
        'resolve-issue',
    ];

    foreach ($guarded as $skill) {
        $content = (string) file_get_contents($packageDir . '/skills/' . $skill . '/SKILL.md');
        expect($content)->toContain('Only a zero exit permits');
        expect($content)->toContain('never applies to this guard');
    }
});

test('read-only and external-reference skills stay free of the repository-ownership guard', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // Two distinct reasons, neither of them "read-only":
    //   security-threat-analysis reads github.com/advisories/<GHSA> as an
    //     external threat source, never an issue/PR of the current repo.
    //   code-review, pr-summary, and assignment-compliance-check never take a
    //     caller-supplied reference — they derive it from the checked-out
    //     branch, or receive it already validated from a wrapper. pr-summary
    //     does publish, so it is unguarded for provenance, not for read-only.
    // Guarding any of them would reject legitimate input.
    $unguarded = [
        'security-threat-analysis',
        'code-review',
        'pr-summary',
        'assignment-compliance-check',
    ];

    foreach ($unguarded as $skill) {
        $content = (string) file_get_contents($packageDir . '/skills/' . $skill . '/SKILL.md');
        expect($content)->not->toContain('assert-current-repo.sh');
    }
});

test('composer check actually runs the shared shell self-tests', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $composer = (string) file_get_contents($packageDir . '/composer.json');

    // Without this wiring the self-tests are dead weight. Both of them passed
    // for an entire review round while the guard accepted foreign repositories,
    // because nothing ever executed them: a mutation flipping `exit 4` to
    // `exit 0` left `composer build` green.
    expect($composer)->toContain('"shell-self-tests"');
    expect($composer)->toContain('"@shell-self-tests"');
    expect($composer)->toContain('skills/_shared/assert-current-repo.sh --self-test');
    expect($composer)->toContain('skills/_shared/scan-attachments.sh --self-test');
});

test('CR wrappers skip the inline security pass only when athena owns it', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // The always-run CR set is strictly sequential, so running security-review
    // inline while athena runs the same review in parallel is pure latency.
    // The flag must be opt-in: unset keeps the inline pass, so coverage can
    // never drop just because a caller forgot to set it.
    foreach (['code-review', 'code-review-github', 'code-review-jira', 'code-review-bugsnag'] as $wrapper) {
        $content = (string) file_get_contents($packageDir . '/skills/' . $wrapper . '/SKILL.md');
        expect($content)->toContain('SECURITY_OWNER=athena');
        expect($content)->toContain('Absence of the flag means it runs');
        expect($content)->toContain('security: owned by athena');
    }

    // argos is the only caller that may set it, and only when athena is real.
    $argos = (string) file_get_contents($packageDir . '/agents/argos.md');
    expect($argos)->toContain('SECURITY_OWNER=athena');
    expect($argos)->toContain('Never set the flag when `athena` is not actually running');
});

test('a skipped security pass has somewhere to be declared on the summary line', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // Pinning the SKILL.md instruction alone proved insufficient: the wrappers
    // told the agent to declare `security: owned by athena`, but no template
    // carried the slot, so the declaration depended on the agent inventing it.
    // A skipped pass that is not declared is indistinguishable from one that
    // ran — which would make the whole opt-in contract unsafe on the way out.
    $templates = [
        'code-review-github/templates/pr-comment-output.md',
        'code-review-bugsnag/templates/github-output.md',
        'code-review-jira/templates/github-output.md',
        'code-review/templates/review-output.md',
    ];

    foreach ($templates as $template) {
        $content = (string) file_get_contents($packageDir . '/skills/' . $template);
        expect($content)->toContain('security: owned by athena');
        expect($content)->toContain('SECURITY_OWNER=athena');
    }
});

test('the ssh resolution comment does not claim to be side-effect free', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/assert-current-repo.sh');

    // `ssh -G` evaluates `Match exec`, which runs an arbitrary shell command.
    // The earlier comment asserted the opposite. A guard that runs first in
    // nine skills must not understate what it does.
    expect($content)->toContain('Match exec');
    expect($content)->not->toContain('reads local config only');
});

test('the merge gate verifies a delegated security review actually arrived', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $merge = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');

    // `security: owned by athena` records a delegation, not a delivery. A
    // security pass that dies mid-run (API error, session limit, cancelled
    // agent) leaves the PR positively marked as covered with zero coverage —
    // strictly worse than an obviously missing review.
    expect($merge)->toContain('Delegated security coverage is verified, never assumed');
    expect($merge)->toContain('security: owned by athena');

    // The token must carry a link, so a missing delivery is visible in the
    // comment itself rather than only in the merge gate.
    foreach ([
        'code-review-github/templates/pr-comment-output.md',
        'code-review-bugsnag/templates/github-output.md',
        'code-review-jira/templates/github-output.md',
        'code-review/templates/review-output.md',
    ] as $template) {
        $content = (string) file_get_contents($packageDir . '/skills/' . $template);
        expect($content)->toContain('url of athena\'s security comment');
        expect($content)->toContain('The URL is mandatory');
    }
});

test('the billing exception substitutes local evidence instead of waiving it', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $merge = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');

    // A billing failure means the jobs never ran, so it says nothing about the
    // code — but "we could not measure" must not become "it is fine". The
    // local build replaces the missing signal at equal strictness.
    expect($merge)->toContain('Substitute evidence is mandatory');
    expect($merge)->toContain('green local `composer build` on the exact head commit');
    expect($merge)->toContain('Verify the jobs truly did not start');
    expect($merge)->toContain('check-runs/<id>/annotations');
    expect($merge)->not->toContain('billing exception (explicit merge only)');
});
