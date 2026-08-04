<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\AgenticIssueResolver;
use AgenticVibes\AgentSkills\AgenticOptions;
use AgenticVibes\AgentSkills\Cli;
use AgenticVibes\AgentSkills\CommandResult;
use AgenticVibes\AgentSkills\InstallerFailure;

/**
 * The stdin reader `Cli::run()` requires for `bash-guard`. Every test in this file exercises a
 * subcommand that must never read stdin, so calling it at all would be the defect.
 */
function resolveNextNoStandardInput(): string
{
    throw new RuntimeException('resolve-next must never read stdin');
}

/**
 * Records what the resolver asked to run, so a test asserts the intended command line
 * without ever spawning gh or claude. A small typed object rather than a by-reference
 * array: the project's coding standard disallows reference parameters.
 */
final class ResolveNextExecutorSpy
{

    /**
     * @var list<array{0: list<string>, 1: bool}>
     */
    private array $calls = [];

    /**
     * @param list<\AgenticVibes\AgentSkills\CommandResult> $results
     */
    public function __construct(private readonly array $results)
    {
    }

    /**
     * @return list<array{0: list<string>, 1: bool}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param list<string> $command
     */
    public function __invoke(array $command, bool $captureOutput): CommandResult
    {
        $this->calls[] = [$command, $captureOutput];

        return $this->results[count($this->calls) - 1] ?? new CommandResult(0, '');
    }

}

function resolveNextListing(string ...$entries): string
{
    return '[' . implode(',', $entries) . ']';
}

function resolveNextIssue(int $number, string $createdAt, string ...$labels): string
{
    $encodedLabels = array_map(static fn (string $label): string => sprintf('{"name":"%s"}', $label), $labels);

    return sprintf(
        '{"number":%d,"url":"https://github.com/o/r/issues/%d","createdAt":"%s","labels":[%s]}',
        $number,
        $number,
        $createdAt,
        implode(',', $encodedLabels),
    );
}

test('options default to the package claim label and to leaving the pull request unmerged', function (): void {
    $options = AgenticOptions::fromArgv(['agent-skills', 'resolve-next']);

    expect($options->labels)->toBe([AgenticOptions::DEFAULT_LABEL]);
    expect($options->repository)->toBeNull();
    expect($options->merge)->toBeFalse();
    expect($options->dryRun)->toBeFalse();
});

test('options collect every repeated label and keep one containing spaces intact', function (): void {
    $options = AgenticOptions::fromArgv(['agent-skills', 'resolve-next', '--label=bug', '--label=good first issue']);

    expect($options->labels)->toBe(['bug', 'good first issue']);
});

test('options read the repository and the merge and dry-run switches', function (): void {
    $options = AgenticOptions::fromArgv(['agent-skills', 'resolve-next', '--repo=owner/name', '--merge', '--dry-run']);

    expect($options->repository)->toBe('owner/name');
    expect($options->merge)->toBeTrue();
    expect($options->dryRun)->toBeTrue();
});

test('an empty option value is ignored rather than becoming a blank label', function (): void {
    $options = AgenticOptions::fromArgv(['agent-skills', 'resolve-next', '--label=', '--repo=']);

    expect($options->labels)->toBe([AgenticOptions::DEFAULT_LABEL]);
    expect($options->repository)->toBeNull();
});

test('the listing command passes every label and the repository to the GitHub CLI', function (): void {
    $command = AgenticIssueResolver::listCommand(new AgenticOptions(['bug', 'triage'], 'owner/name', merge: false, dryRun: false));

    expect($command)->toBe([
        'gh', 'issue', 'list', '--state', 'open', '--json', 'number,url,createdAt,labels', '--limit', '100',
        '--label', 'bug', '--label', 'triage', '--repo', 'owner/name',
    ]);
});

test('the listing command omits the repository flag when no repository is given', function (): void {
    $command = AgenticIssueResolver::listCommand(new AgenticOptions(['bug'], repository: null, merge: false, dryRun: false));

    expect($command)->not->toContain('--repo');
});

test('the oldest issue wins regardless of the order the CLI returned them in', function (): void {
    $issue = AgenticIssueResolver::selectOldest(resolveNextListing(
        resolveNextIssue(9, '2026-03-01T00:00:00Z'),
        resolveNextIssue(3, '2026-01-01T00:00:00Z'),
        resolveNextIssue(7, '2026-02-01T00:00:00Z'),
    ));

    expect($issue?->number)->toBe(3);
    expect($issue?->url)->toBe('https://github.com/o/r/issues/3');
});

test('an issue already claimed by another run is skipped', function (): void {
    $issue = AgenticIssueResolver::selectOldest(resolveNextListing(
        resolveNextIssue(3, '2026-01-01T00:00:00Z', AgenticIssueResolver::CLAIM_LABEL),
        resolveNextIssue(7, '2026-02-01T00:00:00Z', 'Resolve_by_AI'),
    ));

    expect($issue?->number)->toBe(7);
});

test('a listing whose every issue is claimed selects nothing', function (): void {
    $issue = AgenticIssueResolver::selectOldest(resolveNextListing(
        resolveNextIssue(3, '2026-01-01T00:00:00Z', AgenticIssueResolver::CLAIM_LABEL),
    ));

    expect($issue)->toBeNull();
});

test('an empty listing selects nothing', function (): void {
    expect(AgenticIssueResolver::selectOldest('[]'))->toBeNull();
});

test('a listing entry that is not an object is ignored', function (): void {
    expect(AgenticIssueResolver::selectOldest('["not-an-object"]'))->toBeNull();
});

test('an entry with a non-array labels field is treated as unclaimed', function (): void {
    $issue = AgenticIssueResolver::selectOldest('[{"number":4,"url":"u","createdAt":"2026-01-01T00:00:00Z","labels":"broken"}]');

    expect($issue?->number)->toBe(4);
});

test('an entry missing every field still yields a usable placeholder issue', function (): void {
    $issue = AgenticIssueResolver::selectOldest('[{}]');

    expect($issue?->number)->toBe(0);
    expect($issue?->url)->toBe('');
});

test('a listing that is not JSON fails loudly instead of resolving nothing', function (): void {
    expect(static fn (): mixed => AgenticIssueResolver::selectOldest('not json'))
        ->toThrow(InstallerFailure::class);
});

test('a listing that parses but is not an array fails rather than resolving nothing', function (): void {
    expect(static fn (): mixed => AgenticIssueResolver::selectOldest('"a string"'))
        ->toThrow(InstallerFailure::class, 'not a JSON array');
});

test('the prompt chains resolve, review, and process, and forbids merging by default', function (): void {
    $prompt = AgenticIssueResolver::buildPrompt('https://github.com/o/r/issues/5', merge: false);

    expect($prompt)->toContain('/resolve-issue https://github.com/o/r/issues/5');
    expect($prompt)->toContain('/code-review-github https://github.com/o/r/issues/5');
    expect($prompt)->toContain('/process-code-review https://github.com/o/r/issues/5');
    expect($prompt)->toContain('do not merge it');
    expect($prompt)->not->toContain('/merge-github-pr');
});

test('the prompt appends the merge step only when merging was requested', function (): void {
    $prompt = AgenticIssueResolver::buildPrompt('https://github.com/o/r/issues/5', merge: true);

    expect($prompt)->toContain('/merge-github-pr https://github.com/o/r/issues/5');
    expect($prompt)->not->toContain('do not merge it');
});

test('the agent command hands the prompt to Claude Code in print mode', function (): void {
    expect(AgenticIssueResolver::agentCommand('do the thing'))->toBe(['claude', '-p', 'do the thing']);
});

test('a failing issue listing stops the run without starting an agent', function (): void {
    $spy = new ResolveNextExecutorSpy([new CommandResult(1, '')]);
    $resolver = new AgenticIssueResolver($spy(...));

    $exitCode = $resolver->run(new AgenticOptions(['bug'], repository: null, merge: false, dryRun: false));

    expect($exitCode)->toBe(1);
    expect($spy->calls())->toHaveCount(1);
});

test('an empty backlog exits successfully so a cron schedule does not report a failure', function (): void {
    $spy = new ResolveNextExecutorSpy([new CommandResult(0, '[]')]);
    $resolver = new AgenticIssueResolver($spy(...));

    ob_start();
    $exitCode = $resolver->run(new AgenticOptions(['bug'], repository: null, merge: false, dryRun: false));
    $output = (string) ob_get_clean();

    expect($exitCode)->toBe(0);
    expect($spy->calls())->toHaveCount(1);
    expect($output)->toContain('No unclaimed open issue matches bug');
});

test('a dry run prints the chosen issue and prompt without starting an agent', function (): void {
    $listing = resolveNextListing(resolveNextIssue(11, '2026-01-01T00:00:00Z'));
    $spy = new ResolveNextExecutorSpy([new CommandResult(0, $listing)]);
    $resolver = new AgenticIssueResolver($spy(...));

    ob_start();
    $exitCode = $resolver->run(new AgenticOptions(['bug'], repository: null, merge: false, dryRun: true));
    $output = (string) ob_get_clean();

    expect($exitCode)->toBe(0);
    expect($spy->calls())->toHaveCount(1);
    expect($output)->toContain('Would resolve #11');
    expect($output)->toContain('/resolve-issue https://github.com/o/r/issues/11');
});

test('a real run captures the listing, streams the agent, and returns the agent exit code', function (): void {
    $listing = resolveNextListing(resolveNextIssue(11, '2026-01-01T00:00:00Z'));
    $spy = new ResolveNextExecutorSpy([new CommandResult(0, $listing), new CommandResult(3, '')]);
    $resolver = new AgenticIssueResolver($spy(...));

    ob_start();
    $exitCode = $resolver->run(new AgenticOptions(['bug'], repository: null, merge: true, dryRun: false));
    $output = (string) ob_get_clean();

    expect($exitCode)->toBe(3);
    expect($spy->calls())->toHaveCount(2);
    expect($spy->calls()[0][1])->toBeTrue();
    expect($spy->calls()[1][1])->toBeFalse();
    expect($spy->calls()[1][0][0])->toBe('claude');
    expect($spy->calls()[1][0][2])->toContain('/merge-github-pr');
    expect($output)->toContain('Resolving #11');
});

test('the cli routes resolve-next to the resolver', function (): void {
    $spy = new ResolveNextExecutorSpy([new CommandResult(0, '[]')]);

    ob_start();
    $exitCode = Cli::run(['agent-skills', 'resolve-next'], $spy(...), resolveNextNoStandardInput(...));
    ob_end_clean();

    expect($exitCode)->toBe(0);
    expect($spy->calls()[0][0][0])->toBe('gh');
});

test('the cli reports a malformed listing instead of letting the failure escape', function (): void {
    $spy = new ResolveNextExecutorSpy([new CommandResult(0, 'not json')]);

    $exitCode = Cli::run(['agent-skills', 'resolve-next'], $spy(...), resolveNextNoStandardInput(...));

    expect($exitCode)->toBe(1);
});

test('the cli passes every other command through to the installer', function (): void {
    $spy = new ResolveNextExecutorSpy([]);

    ob_start();
    $exitCode = Cli::run(['agent-skills'], $spy(...), resolveNextNoStandardInput(...));
    $output = (string) ob_get_clean();

    expect($exitCode)->toBe(0);
    expect($spy->calls())->toHaveCount(0);
    expect($output)->toContain('resolve-next');
});

test('the binary propagates the dispatcher exit code instead of always exiting zero', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $binary = (string) file_get_contents($packageDir . '/bin/agent-skills');

    // Until #158 the last line was a bare `Installer::run($argv);`, so the script always
    // exited 0 — a cron entry or CI step checking $? could never see a failure. Pinned
    // here rather than by running the binary, which the testing rules forbid. Issue #185 added
    // the stdin reader `bash-guard` needs as a third injected edge argument; the property being
    // pinned is still that the dispatcher's exit code is the process's.
    expect($binary)->toContain('exit(AgenticVibes\AgentSkills\Cli::run($argv, $executor, $standardInput));');
    expect($binary)->not->toMatch('/^AgenticVibes\\\\AgentSkills\\\\Installer::run\(\$argv\);$/m');
});

test('the binary never disables Claude Code permission checks on the user behalf', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $sources = (string) file_get_contents($packageDir . '/bin/agent-skills')
        . (string) file_get_contents($packageDir . '/src/AgenticIssueResolver.php');

    expect($sources)->not->toContain('--dangerously-skip-permissions');
});
