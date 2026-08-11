<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * The machine-readable form of the Bash capability boundary.
 *
 * The prose in `rules/compound-engineering/general.md` *Bash capability boundary* and in each
 * `agents/<name>.md` *Bash boundary* block stays the human-readable, advisory contract; this class
 * carries part of the same enumeration in a form a program can evaluate. Nothing here is a new
 * rule, and nothing here is the whole rule either: the global set is the **program-level subset**
 * of the *Forbidden through Bash, for every agent* bullet — the programs it names — while the
 * conditions on intent and destination that the same bullet carries are deliberately not codified
 * and stay advisory (`getUncodifiedObligations()` names each one on the record). Each agent's set
 * is that agent's own declared purpose, likewise narrowed to what a command string can carry.
 *
 * A parity test binds the two documents in both directions: every program the normative bullet
 * names must be covered here or recorded as uncodified, and every agent rule must quote a phrase
 * its own `agents/<name>.md` still carries — so a rewording on either side fails a test instead of
 * silently changing a security decision.
 *
 * Why the prose is not parsed at runtime instead: a parser over free-form English is
 * non-deterministic, effectively untestable, fails open on anything it does not understand, and
 * would let a documentation rewording silently change a security policy with no review signal.
 *
 * Why the policy is compiled into the package rather than read from `.claude/`: every agent has
 * `Bash` and two of them have `Write`/`Edit`, so a policy file inside the working tree is
 * trivially editable by the very subject it constrains. Compiling it in moves the target one level
 * away — it does not remove it, since `Bash` still reaches `vendor/`, which is why that residual
 * risk is documented rather than claimed closed.
 */
final class AgentBashBoundaryPolicy
{

    /**
     * The normative document this policy codifies; every global reason cites it.
     */
    public const string RULE_SOURCE = 'rules/compound-engineering/general.md § Bash capability boundary';

    /**
     * The *No outbound network request of any kind* bullet, one program per token. It is a
     * superset of `InstallerClaudeSettings::getNetworkBashDenyPermissions()`: `socat`, `aria2c`,
     * `ftp`, and `httpie` are the "unlisted tools" that flag's own bypass list names as open.
     */
    private const array NETWORK_PROGRAMS = [
        'aria2c', 'curl', 'ftp', 'http', 'httpie', 'nc', 'ncat',
        'netcat', 'scp', 'sftp', 'socat', 'ssh', 'telnet', 'wget',
    ];

    /**
     * The long options of `cp` that carry no write destination. Every one of them either takes no
     * value or takes a value that is not a path (`--backup=CONTROL`, `--suffix=SUFFIX`,
     * `--preserve=ATTR_LIST`, `--reflink=WHEN`, `--sparse=WHEN`, `--update=UPDATE`).
     */
    private const array CP_LONG_OPTIONS = [
        'archive', 'attributes-only', 'backup', 'context', 'copy-contents', 'debug', 'dereference',
        'force', 'help', 'interactive', 'link', 'no-clobber', 'no-dereference', 'no-preserve',
        'no-target-directory', 'one-file-system', 'parents', 'preserve', 'recursive', 'reflink',
        'remove-destination', 'sparse', 'strip-trailing-slashes', 'suffix', 'symbolic-link',
        'update', 'verbose', 'version',
    ];

    /**
     * The same for `install`, whose `--group`, `--mode`, `--owner` and `--strip-program` values are
     * an owner, a mode, a group and a program name — none of them a place a file is written to.
     */
    private const array INSTALL_LONG_OPTIONS = [
        'backup', 'compare', 'context', 'debug', 'directory', 'group', 'help', 'mode',
        'no-target-directory', 'owner', 'preserve-context', 'preserve-timestamps', 'strip',
        'strip-program', 'suffix', 'verbose', 'version',
    ];

    /**
     * The same for `ln`.
     */
    private const array LN_LONG_OPTIONS = [
        'backup', 'debug', 'directory', 'force', 'help', 'interactive', 'logical', 'no-dereference',
        'no-target-directory', 'physical', 'relative', 'suffix', 'symbolic', 'verbose', 'version',
    ];

    /**
     * The same for `mv`.
     */
    private const array MV_LONG_OPTIONS = [
        'backup', 'context', 'debug', 'exchange', 'force', 'help', 'interactive', 'no-clobber',
        'no-copy', 'no-target-directory', 'strip-trailing-slashes', 'suffix', 'update', 'verbose',
        'version',
    ];

    /**
     * Applies to every agent and to the main session alike.
     *
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryRule>
     */
    public static function getGlobalRules(): array
    {
        $network = array_map(static fn (string $program): BashBoundaryRule => new BashBoundaryRule(
            program: $program,
            subcommands: [],
            anyArgument: [],
            decision: BashBoundaryDecision::Deny,
            reason: sprintf('`%s` makes an outbound network request, which no agent may run through Bash (%s).', $program, self::RULE_SOURCE),
        ), self::NETWORK_PROGRAMS);

        return [...$network, ...self::getGlobalSpecialRules()];
    }

    /**
     * Per-agent rules, keyed by the `agent_type` the harness reports. An agent_type with no entry
     * — absent, unknown, or the main session — is evaluated against the global rules only, never
     * treated as trusted.
     *
     * @return array<string, list<\AgenticVibes\AgentSkills\BashBoundaryRule>>
     */
    public static function getAgentRules(): array
    {
        return [
            'athena' => self::readOnlyRules('athena', BashBoundaryDecision::Deny),
            'daidalos' => self::readOnlyRules('daidalos', BashBoundaryDecision::Ask),
            'hefaistos' => [
                self::agentRule('hefaistos', 'git', ['push'], BashBoundaryDecision::Deny, '`git push --force*`', ['--force*', '-f']),
                self::agentRule(
                    'hefaistos',
                    'git',
                    ['push'],
                    BashBoundaryDecision::Deny,
                    'a push to the default branch',
                    ['main', 'master', 'refs/heads/main', 'refs/heads/master'],
                ),
                self::agentRule('hefaistos', 'gh', ['pr', 'merge'], BashBoundaryDecision::Ask, '`gh pr merge` outside `@skills/merge-github-pr/SKILL.md`'),
            ],
            'hermes' => self::readOnlyRules('hermes', BashBoundaryDecision::Deny),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getAgentNames(): array
    {
        return array_keys(self::getAgentRules());
    }

    /**
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryRule>
     */
    public static function rulesFor(?string $agentType): array
    {
        if ($agentType === null) {
            return [];
        }

        return self::getAgentRules()[$agentType] ?? [];
    }

    /**
     * The parts of the normative *Forbidden through Bash* enumeration this policy deliberately
     * does **not** codify, each with the reason, so the subset is a decision on the record rather
     * than an oversight. Every one of them is a condition on intent, on a destination, or on a
     * call path — none of which a command string carries — so codifying them would mean guessing.
     * They stay advisory, exactly as the rest of that bullet was before this component existed.
     *
     * The parity test reads this map: a phrase listed here must still appear in the normative
     * prose, and a program the prose names must be either covered by a rule or listed here.
     *
     * @return array<string, string> the phrase in the normative bullet => why it is not codified
     */
    public static function getUncodifiedObligations(): array
    {
        return [
            'git clone' => 'forbidden only "from an unknown host", and a known remote is not distinguishable from an unknown one here.',
            'installing a new package' => 'no fixed program set — every package manager and every install-subcommand alias would have to be enumerated.',
            'no write outside the current git toplevel' => 'needs the working tree resolved at decision time, and this validator touches no filesystem.',
            'piping a network response into an interpreter' => 'the network half is denied on its own program token; the pipe adds nothing checkable.',
            'raw `gh` / `acli` write call' => 'a wrapper script and an ad hoc call run the same `gh` command; only the caller tells them apart.',
        ];
    }

    /**
     * Programs whose ordinary purpose is to create, overwrite, or delete a file, checked against
     * the same writable-path allowance as output redirection. Without them "read-only agent" would
     * mean only "does not use `>`", and `tee`, `cp`, `mv` or `rm` would walk straight through.
     *
     * Every entry is a program whose non-option arguments are all paths, which is what lets the
     * allowance be checked without knowing each program's own argument grammar — except for the
     * four programs `getCoreutilsOptionGrammar()` covers, where a target can hide inside an option
     * word instead and where an option word that cannot be read as a harmless one is therefore
     * refused rather than skipped. A write that hides
     * behind a subcommand (`git checkout -- <path>`, `git restore`, `git apply`), behind a
     * non-path argument (`chmod 600 <path>`), or inside a patch file (`patch < d.diff`) is
     * therefore still not seen — as is a write by any other unlisted program, by a child process,
     * or from inside a script the validator never reads. See `docs/agents.md`
     * *Architecture constraint*: this closes a mechanism, not the class.
     *
     * @return list<string>
     */
    public static function getFileMutatingPrograms(): array
    {
        return ['cp', 'dd', 'install', 'ln', 'mv', 'rm', 'tee', 'touch', 'truncate'];
    }

    /**
     * Programs that only mutate a file when they are given an in-place flag: `sed -i`, `perl -i`.
     *
     * @return list<string>
     */
    public static function getInPlaceEditPrograms(): array
    {
        return ['perl', 'sed'];
    }

    /**
     * The whole option grammar of the four file-mutating programs that can carry a write
     * destination inside an option word, keyed by program — the map `CoreutilsOptionWord` reads a
     * single word against.
     *
     * **This enumerates the harmless options so that everything else can be refused.** An earlier
     * version enumerated the destination spellings instead and passed every other option word
     * through, which is the wrong way round: the space GNU `getopt_long` accepts is open —
     * `--target-directory=x`, any unambiguous abbreviation of it (`--target=x`, `--targ=x`,
     * `--t=x`), and `-t` at any position of a short cluster (`-ftx`) are all the same option — so
     * an enumeration of spellings leaks one spelling at a time. Listing what is *known harmless*
     * inverts which side of the enumeration a mistake falls on: a long option missing from these
     * lists, a short letter missing from `flagShort` / `valueShort`, or an option a future
     * coreutils release adds all end in `deny`, never in a silent pass.
     *
     * Only these four programs are read this strictly, because only they have such an option. `dd`
     * names its destination in the `of=…` operand, which is read as a path already; the rest have
     * no destination option at all — for `touch` a `-t` is a timestamp and for `truncate` a `-r` is
     * a file to copy a size from — so a program absent from this map keeps the permissive reading,
     * and one shared option list would invent writes for it.
     *
     * This is GNU coreutils syntax; the BSD `cp` / `install` on macOS reject it, so the hole it
     * closes is not reachable there — but CI and most consuming environments are Linux, where it
     * is.
     *
     * `destinationLong` and `destinationShort` are the one option that carries a write destination,
     * matched by abbreviation and anywhere in a cluster respectively; `long` are the long options
     * that carry none; `valueShort` are the short letters that swallow the rest of their cluster as
     * a value of their own; `flagShort` are the ones that swallow nothing.
     *
     * @return array<string, array{destinationLong: list<string>, destinationShort: string, long: list<string>, valueShort: string, flagShort: string}>
     */
    public static function getCoreutilsOptionGrammar(): array
    {
        return [
            'cp' => self::coreutilsGrammar(self::CP_LONG_OPTIONS, valueShort: 'S', flagShort: 'abdfHiLlnPpRrsTuvxZ'),
            'install' => self::coreutilsGrammar(self::INSTALL_LONG_OPTIONS, valueShort: 'gmoS', flagShort: 'bcCdDpsTvZ'),
            'ln' => self::coreutilsGrammar(self::LN_LONG_OPTIONS, valueShort: 'S', flagShort: 'bdFfiLnPrsTv'),
            'mv' => self::coreutilsGrammar(self::MV_LONG_OPTIONS, valueShort: 'S', flagShort: 'bfinTuvZ'),
        ];
    }

    /**
     * Path fragments a write-restricted agent may still redirect output into, or hand to one of
     * the file-mutating programs above. `/dev/null` is listed because discarding output is not a
     * write in any meaningful sense, and refusing it would break ordinary read-only command lines.
     *
     * A fragment is a **path prefix matched at a segment boundary**, never a substring: `.claude/run/`
     * covers `.claude/run/gh-1.md` and any absolute path ending in it, but never `foo.claude/run/bar`,
     * and `/dev/null` never covers `/dev/nullify`. A fragment ending in `-` is a deliberate name
     * prefix, because `agent-cr-` has to cover `agent-cr-<slug>-athena`.
     *
     * An agent absent from this map has no write restriction — `hefaistos` legitimately writes files.
     *
     * @return array<string, list<string>>
     */
    public static function getWritablePathFragments(): array
    {
        return [
            'athena' => ['/dev/null', '.claude/run/', '.claude/worktrees/agent-cr-'],
            'daidalos' => ['/dev/null', '.claude/run/'],
            'hermes' => ['/dev/null', '.claude/run/'],
        ];
    }

    /**
     * Files no agent may read through Bash, as `label => pattern`. The label — never the matched
     * token — is what a decision reason quotes.
     *
     * @return array<string, string>
     */
    public static function getSensitivePathPatterns(): array
    {
        return [
            'a dotenv file' => '#(^|/)\.env(\.|$)#',
            'an SSH key directory' => '#(^|/)\.ssh(/|$)#',
            'the Claude Code credentials file' => '#(^|/)\.credentials\.json$#',
        ];
    }

    /**
     * All four take their destination through the same option, in both of its spellings, which is
     * why only the harmless half differs per program.
     *
     * @param list<string> $long
     * @return array{destinationLong: list<string>, destinationShort: string, long: list<string>, valueShort: string, flagShort: string}
     */
    private static function coreutilsGrammar(array $long, string $valueShort, string $flagShort): array
    {
        return [
            'destinationLong' => ['target-directory'],
            'destinationShort' => 't',
            'flagShort' => $flagShort,
            'long' => $long,
            'valueShort' => $valueShort,
        ];
    }

    /**
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryRule>
     */
    private static function getGlobalSpecialRules(): array
    {
        return [
            new BashBoundaryRule(
                program: 'openssl',
                subcommands: ['s_client'],
                anyArgument: [],
                decision: BashBoundaryDecision::Deny,
                reason: sprintf('`openssl s_client` opens a network connection, which no agent may run through Bash (%s).', self::RULE_SOURCE),
            ),
            new BashBoundaryRule(
                program: 'sudo',
                subcommands: [],
                anyArgument: [],
                decision: BashBoundaryDecision::Deny,
                reason: sprintf('`sudo` is forbidden for every agent (%s).', self::RULE_SOURCE),
            ),
        ];
    }

    /**
     * The three read-only agents share one set: no `git` write operation and no merge. `daidalos`
     * differs only in that `gh pr merge` is `ask` rather than `deny`, because it may run one
     * through `@skills/merge-github-pr/SKILL.md` when a merge was explicitly requested — a
     * condition the command string cannot express, so the human is asked instead of guessed at.
     *
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryRule>
     */
    private static function readOnlyRules(string $agent, BashBoundaryDecision $merge): array
    {
        return [
            self::agentRule($agent, 'git', ['commit'], BashBoundaryDecision::Deny, '`git commit`'),
            self::agentRule($agent, 'git', ['push'], BashBoundaryDecision::Deny, '`git push`'),
            self::agentRule($agent, 'git', ['merge'], BashBoundaryDecision::Deny, '`git merge`'),
            self::agentRule($agent, 'gh', ['pr', 'merge'], $merge, '`gh pr merge`'),
        ];
    }

    /**
     * @param list<string> $subcommands
     * @param list<string> $anyArgument
     */
    private static function agentRule(
        string $agent,
        string $program,
        array $subcommands,
        BashBoundaryDecision $decision,
        string $subject,
        array $anyArgument = [],
    ): BashBoundaryRule
    {
        return new BashBoundaryRule(
            program: $program,
            subcommands: $subcommands,
            anyArgument: $anyArgument,
            decision: $decision,
            reason: sprintf('%s is outside `%s`\'s Bash boundary (agents/%s.md § Bash boundary).', $subject, $agent, $agent),
        );
    }

}
