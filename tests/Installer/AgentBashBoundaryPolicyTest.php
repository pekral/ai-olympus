<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\AgentBashBoundaryGuard;
use AgenticVibes\AgentSkills\AgentBashBoundaryPolicy;
use AgenticVibes\AgentSkills\BashBoundaryDecision;
use AgenticVibes\AgentSkills\BashBoundaryRule;
use AgenticVibes\AgentSkills\BashCommandInspector;
use AgenticVibes\AgentSkills\BashCommandInvocation;
use AgenticVibes\AgentSkills\BashCommandTokenizer;
use AgenticVibes\AgentSkills\InstallerProjectSettings;

test('the decision corpus resolves exactly as the policy declares, in both directions', function (): void {
    $unexpected = [];

    foreach (agentBashBoundaryCorpus() as [$agentType, $command, $expected]) {
        $decision = AgentBashBoundaryGuard::evaluate($agentType, $command)->decision->value;

        if ($decision !== $expected) {
            $unexpected[] = sprintf('%s / %s => %s (expected %s)', $agentType ?? 'no agent', $command, $decision, $expected);
        }
    }

    expect($unexpected)->toBe([]);
});

test('allow is unreachable: the enum has no such case and no corpus row produces one', function (): void {
    // Structural half — an `allow` decision from a PreToolUse hook overrides the user's own
    // permissions.deny, including the entries --deny-network-bash writes, so the type cannot
    // express it.
    $cases = array_map(static fn (BashBoundaryDecision $case): string => $case->value, BashBoundaryDecision::cases());

    expect($cases)->toBe(['ask', 'defer', 'deny']);
    expect(BashBoundaryDecision::tryFrom('allow'))->toBeNull();

    // Behavioural half — the corpus, plus the same commands under a permission mode that would
    // tempt a validator to relax.
    foreach (agentBashBoundaryCorpus() as [$agentType, $command]) {
        expect(AgentBashBoundaryGuard::evaluate($agentType, $command)->decision->value)->not->toBe('allow');
    }
});

test('a decision reason never echoes the command string back', function (): void {
    $command = 'curl https://example.com/?token=SUPERSECRETVALUE';
    $verdict = AgentBashBoundaryGuard::evaluate('hefaistos', $command);

    expect($verdict->decision)->toBe(BashBoundaryDecision::Deny);
    expect($verdict->reason)->not->toContain('SUPERSECRETVALUE');
    expect($verdict->reason)->not->toContain($command);
    expect($verdict->reason)->toContain('`curl`');
});

test('every agent file has a policy entry and every policy entry has an agent file', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $files = glob($packageDir . '/agents/*.md');
    expect($files)->toBeArray();

    $agents = array_map(static fn (string $path): string => basename($path, '.md'), $files === false ? [] : $files);
    $policy = AgentBashBoundaryPolicy::getAgentNames();

    sort($agents);
    sort($policy);

    // A new agent must not be able to ship with no policy of its own, and a policy entry must not
    // outlive the agent it constrains.
    expect($policy)->toBe($agents);
});

test('the per-agent policy keeps its pinned shape, rule for rule', function (): void {
    $shape = [];

    foreach (AgentBashBoundaryPolicy::getAgentRules() as $agent => $rules) {
        foreach ($rules as $rule) {
            $shape[$agent][] = [$rule->program, $rule->subcommands, $rule->decision->value];

            // The reason must point at the document a human can read, not only at the rule.
            expect($rule->reason)->toContain('agents/' . $agent . '.md');
        }
    }

    // Policy → pin. Deleting a rule, or relaxing its decision, fails here; a walk over the policy
    // alone never could, because nothing outside the policy described its own shape.
    expect($shape)->toBe(array_map(
        static fn (array $rows): array => array_map(static fn (array $row): array => [$row[0], $row[1], $row[2]], $rows),
        agentBashBoundaryProsePins(),
    ));
});

test('every agent rule quotes a phrase its own agent file still carries', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $drift = [];

    // Pin → prose. Rewriting an agent file so it permits what the policy denies removes the
    // sentence that forbids it, and this is what notices; matching the bare program token — all
    // the previous parity walk did — survives that rewrite untouched.
    foreach (agentBashBoundaryProsePins() as $agent => $rows) {
        $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
        $boundary = installerDocsSection($content, '## Bash boundary');

        foreach ($rows as [$program, , , $phrase]) {
            $drift = [...$drift, ...agentBashBoundaryPhraseDrift($agent, $program, $phrase, $boundary, $content)];
        }
    }

    expect($drift)->toBe([]);
});

test('the global policy is at least as strict as the session-wide --deny-network-bash list', function (): void {
    $programs = [];

    foreach (AgentBashBoundaryPolicy::getGlobalRules() as $rule) {
        $programs[] = $rule->program;
    }

    $denied = InstallerProjectSettings::getNetworkBashDenyPermissions();
    $sessionWide = [];

    foreach ($denied as $pattern) {
        if (preg_match('/^Bash\(([a-z_]+)/', $pattern, $match) === 1) {
            $sessionWide[] = $match[1];
        }
    }

    expect($sessionWide)->toHaveCount(count($denied));

    foreach ($sessionWide as $program) {
        expect($programs)->toContain($program);
    }
});

test('the policy covers the normative Forbidden through Bash enumeration, or records what it deliberately does not', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.md');
    $boundary = installerDocsSection($rule, '## Bash capability boundary');
    $programs = [];

    foreach (AgentBashBoundaryPolicy::getGlobalRules() as $globalRule) {
        $programs[] = $globalRule->program;
    }

    // The enumeration is the network bullet's first sentence — the rest of the bullet names the
    // *permitted* paths (`WebFetch`, `gh`, `acli`), which must never be read as forbidden. The
    // list is extracted from the prose instead of restated here, so adding a program to the
    // normative document fails this test until the policy covers it or records why it does not.
    $start = strpos($boundary, '**No outbound network request of any kind**');
    expect($start)->toBeInt();

    $sentence = substr($boundary, (int) $start);
    $stop = strpos($sentence, '. ');
    $sentence = $stop === false ? $sentence : substr($sentence, 0, $stop);

    expect(preg_match_all('/`([^`]+)`/', $sentence, $matches))->toBeGreaterThan(0);

    $uncodified = AgentBashBoundaryPolicy::getUncodifiedObligations();
    $uncovered = [];

    foreach ($matches[1] as $named) {
        // `openssl s_client` is one rule on the program `openssl`.
        $program = strtok($named, ' ');

        if (in_array($program, $programs, strict: true) || array_key_exists($named, $uncodified)) {
            continue;
        }

        $uncovered[] = $named;
    }

    expect($uncovered)->toBe([]);

    // The other direction: an obligation recorded as uncodified must still be one the prose
    // actually states, so a stale entry cannot go on excusing a rule nobody asks for any more.
    foreach (array_keys($uncodified) as $phrase) {
        expect($boundary)->toContain($phrase);
        expect(AgentBashBoundaryPolicy::getUncodifiedObligations()[$phrase])->not->toBe('');
    }

    // `sudo` lives in its own bullet of the same section.
    expect($boundary)->toContain('No `sudo`');
    expect($programs)->toContain('sudo');

    expect(AgentBashBoundaryPolicy::RULE_SOURCE)->toContain('rules/compound-engineering/general.md');
});

test('every sensitive path the prose forbids reading is matched by a pattern, and every pattern still has prose', function (): void {
    $literals = installerBoundaryBulletLiterals('no read of');
    expect($literals)->not->toBe([]);

    $patterns = AgentBashBoundaryPolicy::getSensitivePathPatterns();
    $uncovered = [];
    $used = [];

    foreach ($literals as $literal) {
        $matched = installerPatternsMatchingLiteral($patterns, $literal);
        $used = [...$used, ...$matched];

        if ($matched === []) {
            $uncovered[] = $literal;
        }
    }

    // Prose → policy: adding `~/.aws/credentials` to the bullet fails here until a pattern covers it.
    expect($uncovered)->toBe([]);

    // Policy → prose: a pattern no literal reaches any more is a rule nobody declared.
    expect(array_values(array_diff(array_values($patterns), $used)))->toBe([]);
});

test('every writable-path fragment is granted by the prose or recorded as an addition with its reason', function (): void {
    // A prose grant names a family (`.claude/run/*`); the fragment is that family up to its glob.
    $granted = [];

    foreach (installerBoundaryBulletLiterals('Read-only agents') as $literal) {
        if (str_contains($literal, '/')) {
            $granted[] = strtok($literal, '*');
        }
    }

    $fragments = array_values(array_unique(array_merge(...array_values(AgentBashBoundaryPolicy::getWritablePathFragments()))));
    $ungranted = AgentBashBoundaryPolicy::getUngrantedWritableFragments();

    expect($granted)->not->toBe([]);
    expect($fragments)->not->toBe([]);

    // Policy → prose: a fragment the prose does not grant must carry a recorded reason, so
    // `/dev/null` is a decision on the record instead of an undocumented widening.
    expect(array_values(array_diff($fragments, $granted, array_keys($ungranted))))->toBe([]);

    // Prose → policy: a path the rule file grants that no agent can actually write is a grant
    // the validator silently refuses.
    expect(array_values(array_diff($granted, $fragments)))->toBe([]);

    // A record whose fragment the prose has since adopted is stale, and an empty reason records nothing.
    foreach ($ungranted as $fragment => $reason) {
        expect($granted)->not->toContain($fragment);
        expect($reason)->not->toBe('');
    }
});

test('an unknown or absent agent_type falls back to the global rules and is never treated as trusted', function (): void {
    expect(AgentBashBoundaryPolicy::rulesFor(agentType: null))->toBe([]);
    expect(AgentBashBoundaryPolicy::rulesFor('not-an-agent'))->toBe([]);

    // Global half still applies with no agent at all.
    expect(AgentBashBoundaryGuard::evaluate(agentType: null, command: 'curl https://example.com')->decision)->toBe(BashBoundaryDecision::Deny);
    expect(AgentBashBoundaryGuard::evaluate('not-an-agent', 'git commit -m "x"')->decision)->toBe(BashBoundaryDecision::Defer);
});

test('the tokenizer separates commands the way a shell would rather than by substring', function (): void {
    $result = BashCommandTokenizer::tokenize('git status && FOO=bar curl -s "https://example.com/a b" | tee out.txt');

    expect($result['ambiguous'])->toBeFalse();
    expect(bashCommandWordTexts($result['segments'][0]))->toBe(['git', 'status']);
    expect(bashCommandWordTexts($result['segments'][1]))->toBe(['FOO=bar', 'curl', '-s', 'https://example.com/a b']);
    expect(bashCommandWordTexts($result['segments'][2]))->toBe(['tee', 'out.txt']);
});

test('a redirection operator breaks a word with or without whitespace, and is marked as an operator', function (): void {
    // Glued: a shell reads three words here, and so must the tokenizer — otherwise the program
    // token is `curl>/dev/null`, whose basename is `null`, and no rule ever sees curl.
    $glued = BashCommandTokenizer::tokenize('curl>/dev/null https://example.com');

    expect(bashCommandWordTexts($glued['segments'][0]))->toBe(['curl', '>', '/dev/null', 'https://example.com']);
    expect($glued['segments'][0][1]->isRedirection)->toBeTrue();
    expect($glued['segments'][0][0]->isRedirection)->toBeFalse();

    // The file-descriptor prefix belongs to the operator, not to the word before it.
    expect(bashCommandWordTexts(BashCommandTokenizer::tokenize('php -v 2>err.log')['segments'][0]))->toBe(['php', '-v', '2>', 'err.log']);
    expect(bashCommandWordTexts(BashCommandTokenizer::tokenize('a2>b')['segments'][0]))->toBe(['a2', '>', 'b']);

    // Every spelling of the metacharacter, including the ones `;|&` alone would mis-split.
    foreach (['echo x >| f' => '>|', 'echo x &> f' => '&>', 'echo x &>> f' => '&>>', 'cat <> f' => '<>', 'cat <&3' => '<&'] as $command => $operator) {
        $words = BashCommandTokenizer::tokenize($command)['segments'][0];
        expect(bashCommandWordTexts($words))->toContain($operator);
    }
});

test('a quoted or escaped redirection character stays a literal word', function (): void {
    $quoted = BashCommandTokenizer::tokenize('grep -rn \'>foo\' README.md');

    expect(bashCommandWordTexts($quoted['segments'][0]))->toBe(['grep', '-rn', '>foo', 'README.md']);
    expect($quoted['segments'][0][2]->isRedirection)->toBeFalse();

    $escaped = BashCommandTokenizer::tokenize('echo \\>x');

    expect(bashCommandWordTexts($escaped['segments'][0]))->toBe(['echo', '>x']);
    expect($escaped['segments'][0][1]->isRedirection)->toBeFalse();
});

test('a heredoc body is skipped as data instead of being tokenized as command text', function (): void {
    // `<<` used to match the redirection pattern as two separate `<` operators, which left the body
    // to be scanned as ordinary command text — so a PHP object operator inside it became an output
    // redirect and the delimiter became its target.
    $result = BashCommandTokenizer::tokenize("cat <<'XEOF'\n" . 'a->b' . "\nXEOF\n");

    expect($result['ambiguous'])->toBeFalse();
    expect(bashCommandWordTexts($result['segments'][0]))->toBe(['cat', '<<', 'XEOF']);
    expect($result['segments'][0][1]->isRedirection)->toBeTrue();

    // Every spelling of the delimiter reaches the same body-skipping path.
    foreach (['cat <<XEOF', 'cat << XEOF', 'cat <<"XEOF"', 'cat <<\'XEOF\''] as $opening) {
        expect(bashCommandWordTexts(BashCommandTokenizer::tokenize($opening . "\n" . 'a->b' . "\nXEOF\n")['segments'][0]))
            ->toBe(['cat', '<<', 'XEOF']);
    }

    // `<<-` strips leading tabs from the terminator, so an indented delimiter still closes the body.
    $dashed = BashCommandTokenizer::tokenize("cat <<-XEOF\n\t" . 'a->b' . "\n\tXEOF\n");

    expect(bashCommandWordTexts($dashed['segments'][0]))->toBe(['cat', '<<-', 'XEOF']);

    // A here-string takes its word on the same line and has no body, so it must not be read as a
    // heredoc — doing so would swallow the rest of the command looking for a terminator.
    expect(bashCommandWordTexts(BashCommandTokenizer::tokenize('cat <<< word')['segments'][0]))->toBe(['cat', '<<<', 'word']);

    // A command after the terminator is command text again, so a real redirection still registers.
    expect(bashCommandWordTexts(BashCommandTokenizer::tokenize("cat <<'XEOF'\nbody\nXEOF\necho hi > out.txt")['segments'][1]))
        ->toBe(['echo', 'hi', '>', 'out.txt']);

    // Only the body is data. The rest of the opening line is still command text, so a quoted `<<`
    // stays a literal word and never opens a heredoc at all.
    expect(bashCommandWordTexts(BashCommandTokenizer::tokenize('echo "<<XEOF"')['segments'][0]))->toBe(['echo', '<<XEOF']);
});

test('skipping a heredoc body hides no write the shell would still perform', function (): void {
    // The body stops being scanned, and nothing else does — a real redirection on the opening line,
    // on either side of the opening, or after the terminator must still reach the write rule.
    $writes = [
        "cat <<'XEOF' > src/Foo.php\nbody\nXEOF\n",
        "cat > src/Foo.php <<'XEOF'\nbody\nXEOF\n",
        "cat <<'XEOF'\nbody\nXEOF\necho x > src/Foo.php\n",
    ];

    foreach ($writes as $command) {
        expect(AgentBashBoundaryGuard::evaluate('athena', $command)->decision)->toBe(BashBoundaryDecision::Deny);
    }

    // A body that never meets its delimiter swallows whatever follows it, so the verdict is `ask`
    // rather than the silent pass that skipping to the end of the command would produce.
    expect(AgentBashBoundaryGuard::evaluate('athena', "cat <<'XEOF'\nbody\necho x > src/Foo.php\n")->decision)
        ->toBe(BashBoundaryDecision::Ask);
});

test('an unterminated heredoc is reported as ambiguous instead of being guessed at', function (): void {
    expect(BashCommandTokenizer::tokenize("cat <<'XEOF'\nbody never closed\n")['ambiguous'])->toBeTrue();
    expect(BashCommandTokenizer::tokenize('cat <<XEOF')['ambiguous'])->toBeTrue();
    expect(BashCommandTokenizer::tokenize("cat <<'XEOF'\nbody\nXEOF\n")['ambiguous'])->toBeFalse();
});

test('neither heredoc nor quoted content can change the boundary verdict', function (): void {
    // Issue #222: `athena` could not publish any review comment quoting PHP, because the object
    // operator in the heredoc body was read as a write outside its permitted paths. The invariant
    // is that payload text — heredoc body or quoted argument — never moves the verdict.
    $payload = 'expect($content)->not->toContain(\'x\');';

    $cases = [
        ["skills/code-review-github/scripts/upsert-comment.sh 219 - <<'XEOF'\n%s\nXEOF\n", 'plain body'],
        ['skills/code-review-github/scripts/upsert-comment.sh 219 --body "%s"', 'plain body'],
    ];

    foreach ($cases as [$template, $inert]) {
        $withPayload = AgentBashBoundaryGuard::evaluate('athena', sprintf($template, $payload));
        $withoutPayload = AgentBashBoundaryGuard::evaluate('athena', sprintf($template, $inert));

        expect($withPayload->decision)->toBe($withoutPayload->decision);
        expect($withPayload->decision)->not->toBe(BashBoundaryDecision::Deny);
    }
});

test('a backslash escape keeps the escaped character inside the token', function (): void {
    $result = BashCommandTokenizer::tokenize('echo a\\ b');

    expect(bashCommandWordTexts($result['segments'][0]))->toBe(['echo', 'a b']);
});

test('an unterminated quote is reported as ambiguous instead of being guessed at', function (): void {
    expect(BashCommandTokenizer::tokenize('echo \'oops')['ambiguous'])->toBeTrue();
    expect(BashCommandTokenizer::tokenize('echo $(oops')['ambiguous'])->toBeTrue();
    expect(BashCommandTokenizer::tokenize('echo `oops')['ambiguous'])->toBeTrue();
    expect(BashCommandTokenizer::tokenize('echo ok')['ambiguous'])->toBeFalse();
});

test('a process substitution is reported as ambiguous rather than split into fragments', function (): void {
    // The parenthesis separates the segment, so `src/Foo.php` is parsed as a program of its own and
    // the write target is gone. Being unsure is the only honest answer to a command read that way.
    expect(BashCommandTokenizer::tokenize('cp <(echo x) src/Foo.php')['ambiguous'])->toBeTrue();
    expect(BashCommandTokenizer::tokenize('tee >(cat) src/Foo.php')['ambiguous'])->toBeTrue();

    // Quoted or escaped, it is a literal word: the marker runs after the structure scan.
    expect(BashCommandTokenizer::tokenize('echo \'<(cat)\'')['ambiguous'])->toBeFalse();
    expect(BashCommandTokenizer::tokenize('echo \\<\\(cat\\)')['ambiguous'])->toBeFalse();

    // An ordinary redirection is still not one, and neither is a spaced parenthesis.
    expect(BashCommandTokenizer::tokenize('echo x > out.txt')['ambiguous'])->toBeFalse();
    expect(BashCommandTokenizer::tokenize('echo x > (out)')['ambiguous'])->toBeFalse();
});

test('command substitutions are inspected as commands in their own right, up to a nesting cap', function (): void {
    $inspection = BashCommandInspector::inspect('echo $(git status)');
    $programs = array_map(static fn (BashCommandInvocation $invocation): string => $invocation->program, $inspection->invocations);

    expect($programs)->toBe(['echo', 'git']);
    expect($inspection->ambiguous)->toBeFalse();

    // Past the cap the validator says so rather than stopping silently.
    expect(BashCommandInspector::inspect('$($($($($($(curl https://example.com)))))) ')->ambiguous)->toBeTrue();
});

test('a wrapper is recorded before it is peeled, so the wrapper itself stays visible', function (): void {
    $inspection = BashCommandInspector::inspect('sudo env curl https://example.com');
    $programs = array_map(static fn (BashCommandInvocation $invocation): string => $invocation->program, $inspection->invocations);

    expect($programs)->toBe(['sudo', 'env', 'curl']);
});

test('a wrapper with nothing left to run, and a shell without -c, are read as no inner command', function (): void {
    expect(BashCommandInspector::inspect('xargs -n 1')->invocations)->toHaveCount(1);
    expect(BashCommandInspector::inspect('bash script.sh')->invocations)->toHaveCount(1);
    expect(BashCommandInspector::inspect('bash -c')->invocations)->toHaveCount(1);

    // A segment that is only shell syntax carries no program at all.
    expect(BashCommandInspector::inspect('if')->invocations)->toBe([]);
    expect(BashCommandInspector::inspect('  ')->invocations)->toBe([]);
});

test('redirection targets are collected in both attached and detached spellings', function (): void {
    expect(BashCommandInspector::inspect('echo hi > out.txt')->redirectionTargets)->toBe(['out.txt']);
    expect(BashCommandInspector::inspect('echo hi >>out.txt')->redirectionTargets)->toBe(['out.txt']);
    expect(BashCommandInspector::inspect('php -v 2>err.log')->redirectionTargets)->toBe(['err.log']);
    expect(BashCommandInspector::inspect('echo hi >| out.txt')->redirectionTargets)->toBe(['out.txt']);
    expect(BashCommandInspector::inspect('echo hi &>out.txt')->redirectionTargets)->toBe(['out.txt']);
    expect(BashCommandInspector::inspect('echo hi >')->redirectionTargets)->toBe([]);

    // A read is not a write, a descriptor is not a path, and two operators in a row have no target
    // between them — all three would otherwise produce a phantom write for a read-only agent.
    expect(BashCommandInspector::inspect('cat <in.txt')->redirectionTargets)->toBe([]);
    expect(BashCommandInspector::inspect('php -v 2>&1')->redirectionTargets)->toBe([]);
    expect(BashCommandInspector::inspect('php -v 2>&-')->redirectionTargets)->toBe([]);
    expect(BashCommandInspector::inspect('echo hi > >out.txt')->redirectionTargets)->toBe(['out.txt']);

    // `>&word` with a non-numeric word is the `&>` spelling, and does write a file.
    expect(BashCommandInspector::inspect('echo hi >&out.txt')->redirectionTargets)->toBe(['out.txt']);

    // The operator is punctuation, never a program; its target stays an argument, which is how a
    // sensitive-path read is still visible in `cat <.env`.
    $inspection = BashCommandInspector::inspect('cat <.env');

    expect($inspection->invocations[0]->program)->toBe('cat');
    expect($inspection->invocations[0]->arguments)->toBe(['.env']);
});

test('the command builtin probe is only exempt while -v is still one of its own options', function (): void {
    // `-v` before the first non-option word is the probe: it asks whether curl exists.
    expect(AgentBashBoundaryGuard::evaluate(agentType: null, command: 'command -v curl')->decision)->toBe(BashBoundaryDecision::Defer);

    // After the program name it is curl's own verbose flag, and curl actually runs.
    expect(AgentBashBoundaryGuard::evaluate(agentType: null, command: 'command curl -v https://example.com')->decision)->toBe(BashBoundaryDecision::Deny);
    expect(AgentBashBoundaryGuard::evaluate(agentType: null, command: 'command -p curl https://example.com')->decision)->toBe(BashBoundaryDecision::Deny);

    // Options all the way down and nothing to probe: no inner program either way.
    expect(BashCommandInspector::inspect('command -p')->invocations)->toHaveCount(1);
});

test('a value-taking global option is dropped together with its value', function (): void {
    $rule = new BashBoundaryRule('git', ['commit'], [], BashBoundaryDecision::Deny, 'reason');

    expect($rule->matches(new BashCommandInvocation('git', ['-C', '/repo', 'commit', '-m', 'x'])))->toBeTrue();
    expect($rule->matches(new BashCommandInvocation('git', ['--git-dir=/repo/.git', 'commit'])))->toBeTrue();
    expect($rule->matches(new BashCommandInvocation('git', ['-C', '/repo', 'status'])))->toBeFalse();

    // An option this table does not know keeps its value in the positional window, which is the
    // conservative direction: the rule stops matching rather than matching the wrong word.
    expect($rule->matches(new BashCommandInvocation('git', ['--unknown-option', 'value', 'commit'])))->toBeFalse();
});

test('a writable-path fragment is a normalised path prefix, not a substring', function (): void {
    $traversal = AgentBashBoundaryGuard::evaluate('athena', 'echo x > .claude/run/../../../etc/hosts');

    expect($traversal->decision)->toBe(BashBoundaryDecision::Deny);
    expect($traversal->reason)->not->toContain('etc/hosts');

    // A `..` that cannot be collapsed away is refused rather than accepted.
    expect(AgentBashBoundaryGuard::evaluate('athena', 'echo x > ../../.claude/run/a.md')->decision)->toBe(BashBoundaryDecision::Deny);

    // And the ordinary spellings keep working: relative, absolute, and the worktree name prefix.
    expect(AgentBashBoundaryGuard::evaluate('athena', 'echo x > ./.claude/run/a.md')->decision)->toBe(BashBoundaryDecision::Defer);
});
