<?php

declare(strict_types = 1);

/**
 * The package is distributed twice — as a Composer plugin and as a Claude Code plugin (issue #261).
 * The second channel is two JSON manifests plus the files under `commands/`, none of which the
 * Composer installer touches, so nothing else in the suite would notice them breaking.
 *
 * Each reader decodes the manifest itself and narrows the result before returning, so no
 * unconstrained value reaches an assertion or a signature.
 */
function pluginManifestValue(string $file, string $path): string
{
    $node = json_decode(pluginManifestContents($file), associative: true, flags: JSON_THROW_ON_ERROR);

    foreach (explode('.', $path) as $segment) {
        expect($node)->toBeArray()->toHaveKey($segment);
        $node = is_array($node) ? $node[$segment] : null;
    }

    expect($node)->toBeString();

    return is_string($node) ? $node : '';
}

/**
 * @return list<string>
 */
function pluginManifestKeys(string $file, string $path = ''): array
{
    $node = json_decode(pluginManifestContents($file), associative: true, flags: JSON_THROW_ON_ERROR);

    foreach ($path === '' ? [] : explode('.', $path) as $segment) {
        expect($node)->toBeArray()->toHaveKey($segment);
        $node = is_array($node) ? $node[$segment] : null;
    }

    expect($node)->toBeArray();

    return is_array($node) ? array_map(strval(...), array_keys($node)) : [];
}

function pluginManifestContents(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/.claude-plugin/' . $file);
}

test('the marketplace manifest exposes the repository root as a single plugin', function (): void {
    expect(pluginManifestValue('marketplace.json', 'name'))->toBe('ai-olympus');
    expect(pluginManifestValue('marketplace.json', 'owner.name'))->toBe('Petr Král');
    expect(pluginManifestKeys('marketplace.json', 'plugins'))->toBe(['0']);

    // The plugin IS the repository root, which is what keeps the two channels on one version: the
    // plugin ships whatever the git checkout holds, with no second copy to keep in step. Verified
    // against Claude Code 2.1.x rather than assumed — `claude plugin marketplace add <dir>`
    // accepted the manifest and `claude plugin details` listed 54 skills and 4 agents.
    expect(pluginManifestValue('marketplace.json', 'plugins.0.name'))->toBe('ai-olympus');
    expect(pluginManifestValue('marketplace.json', 'plugins.0.source'))->toBe('./');
});

test('the plugin manifest names the package and carries no component-path override', function (): void {
    expect(pluginManifestValue('plugin.json', 'name'))->toBe('ai-olympus');
    expect(pluginManifestValue('plugin.json', 'license'))->toBe('MIT');
    expect(pluginManifestValue('plugin.json', 'repository'))->toBe('https://github.com/pekral/ai-olympus');

    $keys = pluginManifestKeys('plugin.json');

    // skills/, agents/, and commands/ already sit at the plugin root, so Claude Code finds them by
    // its own default scan. A path override here would be a second place to update whenever one of
    // those directories moves.
    //
    // `version` is absent deliberately: the marketplace source is the git checkout, so a number
    // here would be a second one to bump on every release and the first one to go stale.
    //
    // `hooks` is absent for the reason issue #265 restored — agents/*.md and now the plugin ship
    // unconditionally, so a hooks entry would install an active runtime component into every
    // consuming project.
    foreach (['skills', 'agents', 'commands', 'hooks', 'mcpServers', 'version'] as $absent) {
        expect($keys)->not->toContain($absent);
    }
});

test('the plugin ships no runtime component of its own', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect(file_exists($packageDir . '/hooks'))->toBeFalse();
    expect(file_exists($packageDir . '/.mcp.json'))->toBeFalse();
});

test('the install-rules command copies what the plugin channel cannot load by itself', function (): void {
    $command = (string) file_get_contents(dirname(__DIR__, 2) . '/commands/install-rules.md');

    // Claude Code reads skills/ and agents/ out of a plugin directory but neither rules/ nor a
    // CLAUDE.md, so this command is the only way the marketplace path delivers them. A rewrite that
    // drops either copy silently reduces the plugin to skills and agents.
    expect($command)->toContain('${CLAUDE_PLUGIN_ROOT}/rules/');
    expect($command)->toContain('${CLAUDE_PLUGIN_ROOT}/CLAUDE.md');
    expect($command)->toContain('.claude/rules/');

    // The same guarantee the Composer installer carries: CLAUDE.md is the file a team customises.
    expect($command)->toContain('only when the project has no');
    expect($command)->toContain('Never overwrite an existing one');

    // The opt-in security switches stay bound to the Composer path (issue #261, owner's answer).
    expect($command)->toContain('writes no `.claude/settings.local.json` entry');
});

test(
    'the finalize-tasks command pins its argument, its consolidation contract, its additive guard and its per-tracker wrapper routing',
    function (): void {
        $command = (string) file_get_contents(dirname(__DIR__, 2) . '/commands/finalize-tasks.md');

        // The tracker link is the one input the command cannot work without, and Claude Code passes it
        // only through `$ARGUMENTS`. A rewrite that drops the placeholder still reads like a working
        // command while silently discarding the link the caller typed.
        expect($command)->toContain('argument-hint: [issue or PR link(s)]');
        expect($command)->toContain('$ARGUMENTS');
        expect($command)->toContain('stop and ask me for it');

        // The consolidation is the command's whole reason to exist: ONE comment carrying the TLDR, the
        // acceptance-criteria status and the merge-vs-review recommendation. An edit that drops any of
        // the four changes what the command delivers, and nothing else in the suite reads this file.
        expect($command)->toContain('into a **single** new tracker comment');
        expect($command)->toContain('states the assignment as a TLDR');
        expect($command)->toContain('status of the acceptance criteria');
        expect($command)->toContain('whether a direct merge is recommended or a');

        // The consolidation is additive because no agent in the roster can be anything else: every
        // comment wrapper the package ships posts and never patches, and there is no delete wrapper at
        // all. A rewrite that reintroduces an edit or a delete mandates a write nobody can perform, and
        // a deleted comment is not a failure a later run can undo.
        expect($command)->toContain('The consolidation posts one new comment.');
        expect($command)->toContain('Never edit and never delete a comment written by anybody');
        expect($command)->toContain('never edit or delete one of your own either');
        expect($command)->toContain('posts a comment and never patches one');
        expect($command)->toContain('Do not compose a raw `gh` or `acli` write to work around that');

        // The package ships one comment wrapper per tracker, and the command supports JIRA explicitly.
        // Naming only the GitHub wrapper leaves the publish unperformable on a JIRA task, because the
        // sentence above closes the raw-CLI escape at the same time.
        expect($command)->toContain('Publish through the wrapper for the task\'s own tracker');
        expect($command)->toContain('skills/code-review-github/scripts/upsert-comment.sh');
        expect($command)->toContain('skills/code-review-jira/scripts/upsert-comment.sh');
        expect($command)->toContain('skills/code-review-bugsnag/scripts/upsert-comment.sh');
        expect($command)->toContain('skills/resolve-issue/references/source-detection.md');

        // The publish routes through the roster's only publishing agent, which is what keeps the
        // command inside the consent inventory it now carries a row in.
        expect($command)->toContain('`daedalus` does not publish the comment itself');
        expect($command)->toContain('`hermes`, the roster\'s only');

        // The account resolution selects which comments the summary speaks for; it authorises nothing.
        // JIRA hands back a display name where GitHub hands back a login, so the guard fails safe.
        expect($command)->toContain('gh api user --jq .login');
        expect($command)->toContain('acli jira auth status');
        expect($command)->toContain('it authorises no write on any of them');
        expect($command)->toContain('corroboration and never proof');

        // The file reads every comment on a tracker task, so it names the boundary that keeps an
        // imperative sentence inside one of them from reading as an instruction to the agent.
        expect($command)->toContain('rules/security/general.md');
        expect($command)->toContain('untrusted data');

        // The template ships the shape the assignment asked for — the three headings and the
        // plain-text layout — filled with placeholders instead of a past run's real facts. The real
        // account name is deliberately NOT asserted here: pinning it would put the identifier this
        // template was anonymised to remove back into the repository.
        expect($command)->toContain('HOW TO TEST');
        expect($command)->toContain('OPEN QUESTION');
        expect($command)->toContain('ASSIGNMENT COMPLIANCE');
        expect($command)->toContain('never ship a placeholder in a published comment');
        expect($command)->toContain('<the account or data set the test needs>');
        expect($command)->toContain('<acceptance criterion>: still missing.');

        // An externally-visible action gets its row in the consent inventory in the same change that
        // introduces it, so the publish this command asks for must be listed there with a level.
        $inventory = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/compound-engineering/orchestration.md');
        expect($inventory)->toContain('when `/finalize-tasks` runs | L2 |');
    },
);

test('the finalize-tasks command pins the merge-ready loop it drives and the merge it never performs', function (): void {
    $command = (string) file_get_contents(dirname(__DIR__, 2) . '/commands/finalize-tasks.md');

    // The deliverable is the task's pull request brought to a merge-ready state, not the tracker
    // item in the abstract. A rewrite that drops the pull request from the sentence leaves the
    // command asking for something none of its steps produces.
    expect($command)->toContain('description: Drive the pull requests of the tracker tasks');
    expect($command)->toContain('Prepare for merge the pull requests of the issue-tracker tasks');
    expect($command)->toContain('Drive the task\'s pull request to a merge-ready state');

    // The command works the findings instead of reporting them, and the loop that does that work
    // already exists in one skill. The command cites that skill's convergence gate rather than
    // carrying a second copy, because a second copy is a second answer to the same question.
    expect($command)->toContain('Work the code-review findings on that');
    expect($command)->toContain('`@skills/process-code-review/SKILL.md`');
    expect($command)->toContain('Read that gate in the skill and never restate it here');

    // A pull request carrying no review yet is that loop's first iteration, because the loop runs
    // the review itself. Leaving this open would let a run stop on a task it could have driven.
    expect($command)->toContain('when the pull request carries no review yet');
    expect($command)->toContain('first iteration and never a reason to');

    // Merge-ready is where the command stops. The consolidated comment's verdict is for a person,
    // so a command that merged on its own would settle the question it was asked to raise.
    expect($command)->toContain('## "Prepare for merge" is not "merge"');
    expect($command)->toContain('and stops there. It never merges the');
});

test('both installation paths are documented with the difference between them', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $readme = (string) file_get_contents($packageDir . '/README.md');
    expect($readme)->toContain('/plugin marketplace add pekral/ai-olympus');
    expect($readme)->toContain('/ai-olympus:install-rules');
    expect($readme)->toContain('### Via the plugin marketplace (no Composer)');
    expect($readme)->toContain('### Via Composer');

    $docs = (string) file_get_contents($packageDir . '/docs/installation.md');
    $section = installerDocsSection($docs, '## Installing without Composer (plugin marketplace)');

    // The honest limitation, not a promise the channel cannot keep.
    expect($section)->toContain('reads **neither `rules/` nor a `CLAUDE.md`**');
    expect($section)->toContain('/ai-olympus:install-rules');
    expect($section)->toContain('Composer only');
});
