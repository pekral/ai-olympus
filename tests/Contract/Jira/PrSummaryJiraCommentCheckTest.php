<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;
use Tests\Support\ReportCheckRun;

function runJiraSummaryCheck(string $source, string $criteria, string $author = 'none'): Process
{
    return ReportCheckRun::over(
        'skills/pr-summary/scripts/check-jira-comment.php',
        $source,
        '--criteria',
        $criteria,
        '--author',
        $author,
        ...jiraSummaryTaskArguments(),
    );
}

/**
 * @return list<string>
 */
function jiraSummaryTaskArguments(): array
{
    return [
        '--repository',
        'acme/app',
        ...array_merge(...array_map(static fn (int $number): array => ['--pull-request', (string) $number], [27_625, 27_600, 11, 12])),
        '--jira-issue',
        'https://example.atlassian.net/browse/ACME-6720',
        '--revision',
        jiraSummaryRevision(),
    ];
}

function jiraSummaryRevision(): string
{
    return str_repeat('0123456789', 4);
}

function jiraSummaryUsage(): string
{
    return 'Usage: check-jira-comment.php --criteria <present|none> [--author <account-id|none>] --repository <owner/name>';
}

function jiraSummaryString(mixed $value): string
{
    return is_string($value) ? $value : '';
}

/**
 * @return array<mixed>
 */
function jiraSummaryNodeField(mixed $node, string $field): array
{
    return is_array($node) && is_array($node[$field] ?? null) ? $node[$field] : [];
}

/**
 * @return list<string>
 */
function jiraSummaryPublishedTargets(mixed $node): array
{
    $links = array_filter(jiraSummaryNodeField($node, 'marks'), static fn (mixed $mark): bool => is_array($mark) && ($mark['type'] ?? null) === 'link');
    $mentionId = jiraSummaryString(jiraSummaryNodeField($node, 'attrs')['id'] ?? null);
    $mentions = is_array($node) && ($node['type'] ?? null) === 'mention' ? ['mention ' . $mentionId] : [];

    return [
        ...array_map(
            static fn (mixed $mark): string => 'link ' . jiraSummaryString(jiraSummaryNodeField($mark, 'attrs')['href'] ?? null),
            array_values($links),
        ),
        ...$mentions,
        ...array_merge([], ...array_map(jiraSummaryPublishedTargets(...), array_values(jiraSummaryNodeField($node, 'content')))),
    ];
}

/**
 * @return array<mixed>
 */
function jiraSummaryPublishedAdf(string $source): array
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/code-review-jira/scripts/wiki-markup-to-adf.php'], $packageDir);
    $process->setInput($source);
    $process->mustRun();

    $adf = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

    return is_array($adf) ? $adf : [];
}

function jiraSummaryAuthor(): string
{
    return '712020:a1b2c3d4e5f6-0a1b-4c2d-9e8f-a1b2c3d4e5f6';
}

function jiraSummaryWithRefactor(string $mention): string
{
    return jiraSummaryWithoutCriteria(['* *(bug):* Blokuje sloučení:' => "* *(bug):* Druhý bod.\n* *(refactor):* " . $mention . 'Blokuje sloučení:']);
}

/**
 * @param array<string, string> $replace
 */
function jiraSummaryWithoutCriteria(array $replace = []): string
{
    $source = implode("\n", [
        jiraSummaryVerdict(),
        '',
        '{expand:⚠️ Akceptační kritéria}',
        '# ℹ️ Zadání nemá výslovná kritéria.',
        '{expand}',
        '{expand:❌ Nálezy z kontroly kódu}',
        '',
        '* *(bug):* Blokuje sloučení: synchronizace zařazená starou verzí po nasazení selže. '
            . 'Oprava: nová verze ji musí umět dokončit. ' . jiraSummaryCodeLink(),
        '',
        'h3. Už nahlášené v dřívější kontrole',
        '',
        '* *(bug):* Objednávka bez zákazníka selže. Autor odpověděl 10. 11. 2026 v komentáři [ACME-1|https://example.atlassian.net/browse/ACME-1]. '
            . '[Zobrazit v kódu →|https://github.com/acme/app/pull/27600/files#diff-ccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccR4]',
        '{expand}',
        '',
        '{expand:⚠️ Testovací scénáře}',
        '# ⚠️ Po připojení obchodu spusťte synchronizaci a ověřte, že se nové objednávky zobrazí.',
        '{expand}',
        '{expand:ℹ️ Co se změnilo a co to ovlivní}',
        jiraSummaryChangesLine(),
        '* Připojení obchodu a synchronizace objednávek i kontaktů zůstávají beze změny.',
        '{expand}',
        '',
    ]);

    return str_replace(array_keys($replace), array_values($replace), $source);
}

function jiraSummaryCodeLink(): string
{
    return '[Zobrazit v kódu →|https://github.com/acme/app/pull/27625/files#diff-' . hash('sha256', 'src/Sync/OrderSync.php') . 'R42]';
}

function jiraSummaryChangesLine(): string
{
    return '* „Refresh products“ nyní aktualizuje jen produkty.';
}

function jiraSummaryMarkerBlock(): string
{
    return "{expand:Technická značka pro AI agenty}\n{agent-marker}\n{expand}";
}

function jiraSummaryVerdict(): string
{
    return '🤖 AI Olympus · Za mě je to skoro hotové, ale zatím to nejde nasadit. '
        . 'Sloučení blokuje jeden bod z kontroly kódu níž: synchronizace po nasazení selže. Nic není sloučené ani nasazené.';
}

function jiraSummaryWithCriteria(): string
{
    return str_replace(
        '# ℹ️ Zadání nemá výslovná kritéria.',
        '# ⚠️ Hromadné odeslání nad 500 příjemců není ověřené. Kdo může takové odeslání spustit?',
        jiraSummaryWithoutCriteria(),
    );
}

function jiraSummaryForTwoPullRequests(): string
{
    $group = static fn (int $number): string => implode("\n", [
        'h2. PR #' . $number . ' — {{Synchronizace}}',
        '',
        '{expand:✅ Nálezy z kontroly kódu}',
        'Kontrola nenašla žádný bod k vyřešení.',
        '{expand}',
        '',
        '{expand:⚠️ Testovací scénáře}',
        '# ⚠️ Po připojení obchodu spusťte synchronizaci a ověřte, že se nové objednávky zobrazí.',
        '{expand}',
        '{expand:ℹ️ Co se změnilo a co to ovlivní}',
        '* Synchronizace obchodu nově zpracuje jen nové objednávky.',
        '{expand}',

    ]);

    return implode("\n\n", [
        jiraSummaryVerdict(),
        "{expand:❌ Akceptační kritéria}\n# ❌ Hromadné odeslání vyžaduje opravu.\n{expand}",
        $group(11),
        $group(12),
    ]);
}

test('a grouped summary cannot hide a blocker behind a green findings title', function (): void {
    $source = str_replace('{expand:❌ Nálezy z kontroly kódu}', '{expand:✅ Nálezy z kontroly kódu}', jiraSummaryWithCriteria());
    $process = runJiraSummaryCheck($source, 'present');

    expect($process->getExitCode())->toBe(2)->and($process->getOutput())->toContain('red title for an open blocking finding');
});

test('a general pull request link is refused inside the impact summary', function (string $suffix): void {
    $source = jiraSummaryWithoutCriteria([jiraSummaryChangesLine() => '* [Celá změna|https://github.com/acme/app/pull/27625' . $suffix . ']']);
    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)->and($process->getOutput())->toContain('violation: links:');
})->with(['', '/files']);

test('an explicitly nonblocking question uses the pending title', function (): void {
    $source = str_replace(
        ['{expand:❌ Nálezy z kontroly kódu}', '* *(bug):* '],
        ['{expand:⚠️ Nálezy z kontroly kódu}', '* *(bug):* ⚠️ '],
        jiraSummaryWithoutCriteria(),
    );

    expect(runJiraSummaryCheck($source, 'none')->getExitCode())->toBe(0);
});

test('a shared blocker reaches its group without duplicating the finding', function (): void {
    $source = str_replace('h2. PR #11', "{expand:❌ Nálezy z kontroly kódu}\n* *(bug):* PR #11 a PR #12 sdílejí chybu. "
        . jiraSummaryCodeLink() . "\nPR #12 — {{Synchronizace}} čeká na samostatnou kontrolu.\n{expand}\nh2. PR #11", jiraSummaryForTwoPullRequests());
    $source = str_replace('{expand:✅ Nálezy z kontroly kódu}', '{expand:❌ Nálezy z kontroly kódu}', $source);

    expect(runJiraSummaryCheck($source, 'present')->getExitCode())->toBe(0)
        ->and(
            runJiraSummaryCheck(str_replace('PR #12 — {{Synchronizace}} čeká', 'PR #999 — {{Synchronizace}} čeká', $source), 'present')->getExitCode(),
        )->toBe(
            2,
        );
});

test('a JIRA summary in the shape of the template passes the check', function (string $source, string $criteria): void {
    $process = runJiraSummaryCheck($source, $criteria);

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
})->with([
    'all criteria met or no criteria stated' => [jiraSummaryWithoutCriteria(), 'none'],
    'a stack with a shared point above the groups' => [
        str_replace(
            "{expand:❌ Akceptační kritéria}\n# ❌ Hromadné odeslání vyžaduje opravu.\n{expand}",
            "{expand:❌ Akceptační kritéria}\n# ❌ Objednávka selže.\n{expand}\n"
                . "{expand:❌ Nálezy z kontroly kódu}\n* *(bug):* Obě části synchronizace (PR #11 a PR #12) po nasazení selžou.\n{expand}",
            jiraSummaryForTwoPullRequests(),
        ),
        'none',
    ],
    'two pull requests' => [jiraSummaryForTwoPullRequests(), 'unmet'],
    'unmet criteria' => [jiraSummaryWithCriteria(), 'unmet'],
]);

test('technical screenshot slots never render in a JIRA summary', function (): void {
    $source = jiraSummaryWithoutCriteria();
    $withSlots = (string) preg_replace(
        '/^(\* \*\(bug\):\*.*)$/m',
        "$1\n{screenshot:f-0123456789abcdef:base}\n{screenshot:f-0123456789abcdef:head}",
        $source,
        1,
    );
    $process = runJiraSummaryCheck($withSlots, 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('technical template content');
});

test('a summary that passes the check converts to ADF with no wiki markup left', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/code-review-jira/scripts/wiki-markup-to-adf.php'], $packageDir);
    $process->setInput(jiraSummaryWithRefactor('[~accountid:' . jiraSummaryAuthor() . '] '));
    $process->mustRun();

    /** @var array{content: list<array{type: string}>} $adf */
    $adf = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($adf['content'], 'type')[0])->toBe('paragraph')
        ->and(str_contains($process->getOutput(), 'h2.'))->toBeFalse()
        ->and(str_contains($process->getOutput(), '{expand'))->toBeFalse()
        ->and(str_contains($process->getOutput(), '"type": "expand"'))->toBeTrue()
        ->and(str_contains($process->getOutput(), '"id": "' . jiraSummaryAuthor() . '"'))->toBeTrue();
});

test('the acceptance criteria carry no call to the author of the changes', function (string $source): void {
    $process = runJiraSummaryCheck($source, 'unmet');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: criteria: the block calls on nobody: ' . jiraSummaryAuthorCall());
})->with([
    'one pull request' => [str_replace(
        "ověřené. Kdo může takové odeslání spustit?\n",
        "ověřené. Kdo může takové odeslání spustit?\n\n" . jiraSummaryAuthorCall() . "\n",
        jiraSummaryWithCriteria(),
    ),
    ],
    'two pull requests' => [str_replace("vyžaduje opravu.\n", "vyžaduje opravu.\n" . jiraSummaryAuthorCall() . "\n", jiraSummaryForTwoPullRequests())],
]);

function jiraSummaryAuthorCall(): string
{
    return '[~accountid:712020:a1b2c3d4e5f6-0a1b-4c2d-9e8f-a1b2c3d4e5f6], potvrď prosím nález a oprav ho, nebo ho v odpovědi vyvrať.';
}

test('every finding opens with its change type in bold parentheses', function (string $replacement): void {
    $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria(['* *(bug):* Blokuje sloučení:' => $replacement]), 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: findings: a finding must open with its change type, for example *(bug):*: ' . $replacement);
})->with([
    'an unknown type' => ['* *(chore):* Blokuje sloučení:'],
    'a type that is not bold' => ['* (bug): Blokuje sloučení:'],
    'a type with no colon' => ['* *(bug)* Blokuje sloučení:'],
    'no type' => ['* Blokuje sloučení:'],
]);

test('every change type the shape names passes the check', function (string $type): void {
    $process = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(
            ['* *(bug):* Blokuje sloučení:' => '* *(' . $type . '):* Blokuje sloučení:', '* *(bug):* Objednávka' => '* *(documentation):* Objednávka'],
        ),
        'none',
    );

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
})->with(['security', 'bug', 'tests', 'refactor', 'performance', 'documentation']);

test('the findings are ordered by the severity of their type', function (string $replacement, string $violation): void {
    $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria(['* *(bug):* Blokuje sloučení:' => $replacement]), 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain(
            'violation: findings: order the findings security, bug, tests, refactor, performance, documentation; ' . $violation,
        );
})->with([
    'a bug after a refactor' => ["* *(refactor):* Dva bloky kódu se opakují.\n* *(bug):* Blokuje sloučení:", '(bug) stands after (refactor)'],
    'a refactor after a documentation request' => [
        "* *(documentation):* Chybí popis.\n* *(refactor):* Blokuje sloučení:",
        '(refactor) stands after (documentation)',
    ],
    'a security point after a test' => ["* *(tests):* Chybí test.\n* *(security):* Blokuje sloučení:", '(security) stands after (tests)'],
]);

test('findings of one type keep their order, and the order restarts for the already reported ones', function (string $replacement): void {
    $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria(['* *(bug):* Blokuje sloučení:' => $replacement]), 'none');

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
})->with([
    'a new refactor above an already reported bug' => ['* *(refactor):* Blokuje sloučení:'],
    'a security point before a bug' => ["* *(security):* Citlivé soubory nejsou chráněné.\n* *(bug):* Blokuje sloučení:"],
    'two bugs in their original order' => ["* *(bug):* Druhý bod.\n* *(bug):* Blokuje sloučení:"],
]);

test('a refactor and a tests finding mention the author right after their type', function (): void {
    $mention = '[~accountid:' . jiraSummaryAuthor() . '] ';
    $source = str_replace(
        '* *(bug):* Druhý bod.',
        "* *(bug):* Druhý bod.\n* *(tests):* " . $mention . 'Chybí test synchronizace.',
        jiraSummaryWithRefactor($mention),
    );

    $process = runJiraSummaryCheck($source, 'none', jiraSummaryAuthor());

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('only a refactor finding mentions anyone, and only the author', function (string $source, string $author, string $violation): void {
    $process = runJiraSummaryCheck($source, 'none', $author);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: mention: ' . $violation);
})->with([
    'a mention in the deploy verdict' => [
        str_replace('Za mě je to', '[~accountid:' . jiraSummaryAuthor() . '] za mě je to', jiraSummaryWithoutCriteria()),
        jiraSummaryAuthor(),
        'only a (refactor) or (tests) finding mentions anyone, the author right after its type',
    ],
    'a mention on a bug finding' => [
        jiraSummaryWithoutCriteria(['* *(bug):* Blokuje sloučení:' => '* *(bug):* [~accountid:' . jiraSummaryAuthor() . '] Blokuje sloučení:']),
        jiraSummaryAuthor(),
        'only a (refactor) or (tests) finding mentions anyone, the author right after its type',
    ],
    'a mention on a security finding' => [
        jiraSummaryWithoutCriteria(['* *(bug):* Blokuje sloučení:' => '* *(security):* [~accountid:' . jiraSummaryAuthor() . '] Blokuje sloučení:']),
        jiraSummaryAuthor(),
        'only a (refactor) or (tests) finding mentions anyone, the author right after its type',
    ],
    'a mention with no matching account' => [jiraSummaryWithRefactor(
        '[~accountid:' . jiraSummaryAuthor() . '] ',
    ), 'none', 'only a (refactor) or (tests) finding mentions anyone, the author right after its type',
    ],
    'another account than the author' => [jiraSummaryWithRefactor(
        '[~accountid:acc-someone-else] ',
    ), jiraSummaryAuthor(), 'a (refactor) or (tests) finding mentions the author right after its type',
    ],
    'a refactor finding without the mention' => [jiraSummaryWithRefactor(
        '',
    ), jiraSummaryAuthor(), 'a (refactor) or (tests) finding mentions the author right after its type',
    ],
    'a tests finding without the mention' => [
        jiraSummaryWithoutCriteria(['* *(bug):* Blokuje sloučení:' => "* *(bug):* Druhý bod.\n* *(tests):* Blokuje sloučení:"]),
        jiraSummaryAuthor(),
        'a (refactor) or (tests) finding mentions the author right after its type: * *(tests):* Blokuje sloučení:',
    ],
    'the mention after the finding text' => [
        jiraSummaryWithoutCriteria(
            ['* *(bug):* Blokuje sloučení:' => "* *(bug):* Druhý bod.\n* *(refactor):* Blokuje [~accountid:" . jiraSummaryAuthor() . '] sloučení:'],
        ),
        jiraSummaryAuthor(),
        'a (refactor) or (tests) finding mentions the author right after its type',
    ],
]);

test('every link and mention the converter publishes from a passing source belongs to the task', function (): void {
    $source = jiraSummaryWithRefactor('[~accountid:' . jiraSummaryAuthor() . '] ');
    $allowed = '#^(?:link https://github\.com/acme/app/pull/(?:27625|27600)(?:/files(?:\#diff-[0-9a-f]{64}R[1-9][0-9]*)?)?'
        . '|link https://github\.com/acme/app/blob/[0-9a-f]{40}/[A-Za-z0-9/._-]+\#L[1-9][0-9]*'
        . '|link https://example\.atlassian\.net/browse/ACME-[0-9]+|mention ' . preg_quote(jiraSummaryAuthor(), '#') . ')$#D';

    $targets = jiraSummaryPublishedTargets(jiraSummaryPublishedAdf($source));
    $process = runJiraSummaryCheck($source, 'none', jiraSummaryAuthor());

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0)
        ->and($targets)->toHaveCount(4)
        ->and(preg_grep($allowed, $targets, PREG_GREP_INVERT))->toBe([]);
});

function jiraSummaryBlobLink(string $repository = 'acme/app', string $path = 'src/Sync/OrderSync.php', ?string $revision = null): string
{
    return 'https://github.com/' . $repository . '/blob/' . ($revision ?? jiraSummaryRevision()) . '/' . $path . '#L42';
}

test('a finding outside the pull request may link its file and line at the reviewed commit of the task repository', function (): void {
    $source = jiraSummaryWithoutCriteria(
        ['[Zobrazit v kódu →|https://github.com/acme/app/pull/27600/files#diff-' . str_repeat('c', 64) . 'R4]'
            => '[Zobrazit v kódu →|' . jiraSummaryBlobLink() . ']'],
    );

    $process = runJiraSummaryCheck($source, 'none');

    expect(jiraSummaryPublishedTargets(jiraSummaryPublishedAdf($source)))->toContain('link ' . jiraSummaryBlobLink())
        ->and($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('every code link the link builder prints passes the link check', function (string ...$arguments): void {
    $packageDir = dirname(__DIR__, 3);
    $builder = new Process(
        ['php', $packageDir . '/skills/pr-summary/scripts/pr-code-link.php', 'https://github.com/acme/app/pull/27625', ...$arguments],
        $packageDir,
    );
    $builder->mustRun();
    $source = jiraSummaryWithoutCriteria([jiraSummaryCodeLink() => '[Zobrazit v kódu →|' . trim($builder->getOutput()) . ']']);

    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
})->with([
    'a line at the reviewed commit' => ['src/Sync/Order Sync.php', '42', str_repeat('0123456789', 4)],
    'a line in the changes' => ['src/Sync/Order Sync.php', '42'],
]);

test(
    'every link of the comment points into the pull request or the issue of the task',
    function (string $search, string $replacement, string $href): void {
        $source = jiraSummaryWithoutCriteria([$search => $replacement]);

        $published = jiraSummaryPublishedTargets(jiraSummaryPublishedAdf($source));
        $process = runJiraSummaryCheck($source, 'none');

        expect($published)->toContain('link ' . $href)
            ->and($process->getExitCode())->toBe(2)
            ->and($process->getOutput())->toContain(
                'violation: links: a link points into a pull request of the task or to an issue of its JIRA site: ' . json_encode(
                    $href,
                    JSON_UNESCAPED_SLASHES,
                ),
            );
    },
)->with([
    'a file link into another repository' => [jiraSummaryCodeLink(), '[Zobrazit v kódu →|' . jiraSummaryBlobLink(
        'stranger/fork',
    ) . ']', jiraSummaryBlobLink(
        'stranger/fork',
    ),
    ],
    'a file link on a branch instead of a commit' => [
        jiraSummaryCodeLink(),
        '[Zobrazit v kódu →|https://github.com/acme/app/blob/main/src/Sync/OrderSync.php#L42]',
        'https://github.com/acme/app/blob/main/src/Sync/OrderSync.php#L42',
    ],
    'a file link that climbs out' => [jiraSummaryCodeLink(), '[Zobrazit v kódu →|' . jiraSummaryBlobLink(path: 'src/../A.php') . ']', jiraSummaryBlobLink(
        path: 'src/../A.php',
    ),
    ],
    'a file link with a dot segment' => [jiraSummaryCodeLink(), '[Zobrazit v kódu →|' . jiraSummaryBlobLink(path: './A.php') . ']', jiraSummaryBlobLink(
        path: './A.php',
    ),
    ],
    'a file link with an encoded dot segment' => [
        jiraSummaryCodeLink(),
        '[Zobrazit v kódu →|' . jiraSummaryBlobLink(path: 'src/%2e%2e/A.php') . ']',
        jiraSummaryBlobLink(path: 'src/%2e%2e/A.php'),
    ],
    'a file link with an encoded slash' => [jiraSummaryCodeLink(), '[Zobrazit v kódu →|' . jiraSummaryBlobLink(
        path: 'src%2FA.php',
    ) . ']', jiraSummaryBlobLink(
        path: 'src%2FA.php',
    ),
    ],
    'a file link without https' => [
        jiraSummaryCodeLink(),
        '[Zobrazit v kódu →|' . str_replace('https://', 'http://', jiraSummaryBlobLink()) . ']',
        str_replace('https://', 'http://', jiraSummaryBlobLink()),
    ],
    'a link split across a quote into a fork' => [
        '* *(bug):* Objednávka bez zákazníka',
        "{quote}\n[Zobrazit v kódu →\n|https://github.com/stranger/fork/pull/9/files]\n{quote}\n* *(bug):* Objednávka bez zákazníka",
        'https://github.com/stranger/fork/pull/9/files',
    ],
    'a link split across a quote with a script scheme' => [
        '* *(bug):* Objednávka bez zákazníka',
        "{quote}\nReporter wrote: [open\nthis|javascript:alert(1)] please\n{quote}\n* *(bug):* Objednávka bez zákazníka",
        'javascript:alert(1)',
    ],
    'a link to a commit' => [
        jiraSummaryCodeLink(),
        '[Zobrazit v kódu →|https://github.com/acme/app/commit/0123456789abcdef0123456789abcdef01234567]',
        'https://github.com/acme/app/commit/0123456789abcdef0123456789abcdef01234567',
    ],
    'a link to another host' => [jiraSummaryCodeLink(), '[podrobnosti|https://other.example/login]', 'https://other.example/login'],
    'a link to another repository' => [
        jiraSummaryCodeLink(),
        '[Zobrazit v kódu →|https://github.com/stranger/fork/pull/27625/files#diff-' . hash('sha256', 'src/A.php') . 'R1]',
        'https://github.com/stranger/fork/pull/27625/files#diff-' . hash('sha256', 'src/A.php') . 'R1',
    ],
    'a link without https' => [jiraSummaryCodeLink(), '[Zobrazit v kódu →|http://other.example/a]', 'http://other.example/a'],
    'an issue of another JIRA site' => [
        '[ACME-1|https://example.atlassian.net/browse/ACME-1]',
        '[ACME-6720|https://example.net/browse/ACME-6720]',
        'https://example.net/browse/ACME-6720',
    ],
    'a pull request the task does not name' => [
        jiraSummaryCodeLink(),
        '[Zobrazit v kódu →|https://github.com/acme/app/pull/9/files]',
        'https://github.com/acme/app/pull/9/files',
    ],
]);

test('a file link at a commit other than the reviewed one is refused', function (string $revision): void {
    $href = jiraSummaryBlobLink(revision: $revision);
    $source = jiraSummaryWithoutCriteria(
        ['[Zobrazit v kódu →|https://github.com/acme/app/pull/27600/files#diff-' . str_repeat('c', 64) . 'R4]' => '[Zobrazit v kódu →|' . $href . ']'],
    );

    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain(
            'violation: links: a link points into a pull request of the task or to an issue of its JIRA site: ' . json_encode(
                $href,
                JSON_UNESCAPED_SLASHES,
            ),
        );
})->with([
    'a longer commit hash' => [str_repeat('0123456789abcdef', 4)],
    'another full commit hash' => [str_repeat('1', 40)],
]);

test('a file link is refused when the check names no reviewed commit', function (): void {
    $source = jiraSummaryWithoutCriteria(
        ['[Zobrazit v kódu →|https://github.com/acme/app/pull/27600/files#diff-' . str_repeat('c', 64) . 'R4]'
            => '[Zobrazit v kódu →|' . jiraSummaryBlobLink() . ']'],
    );

    $process = ReportCheckRun::over(
        'skills/pr-summary/scripts/check-jira-comment.php',
        $source,
        '--criteria',
        'none',
        ...array_slice(jiraSummaryTaskArguments(), 0, -2),
    );

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain(
            'violation: links: a link points into a pull request of the task or to an issue of its JIRA site: ' . json_encode(
                jiraSummaryBlobLink(),
                JSON_UNESCAPED_SLASHES,
            ),
        );
});

test('a code link outside a finding is refused', function (string $href): void {
    $verdict = jiraSummaryVerdict() . ' [Zobrazit v kódu →|' . $href . ']';

    $process = runJiraSummaryCheck(str_replace(jiraSummaryVerdict(), $verdict, jiraSummaryWithoutCriteria()), 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: links: a code link stands only at the end of a finding: ' . $verdict);
})->with([
    'a file link at the reviewed commit in the deploy verdict' => [jiraSummaryBlobLink()],
    'a line in the changes in the deploy verdict' => ['https://github.com/acme/app/pull/27625/files#diff-' . hash('sha256', 'src/A.php') . 'R1'],
]);

test('a code link that does not end its finding is refused', function (string $link): void {
    $process = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(
            ['Oprava: nová verze ji musí umět dokončit. ' . jiraSummaryCodeLink() => $link . ' Oprava: nová verze ji musí umět dokončit.'],
        ),
        'none',
    );

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: links: a code link ends a finding: ');
})->with([
    'a file link at the reviewed commit' => ['[Zobrazit v kódu →|' . jiraSummaryBlobLink() . ']'],
    'a line in the changes' => [jiraSummaryCodeLink()],
]);

test('the duplicate whole-change navigation is refused', function (): void {
    $source = jiraSummaryWithoutCriteria(['* *(bug):* Blokuje' => "[Celá změna|https://github.com/acme/app/pull/27625/files]\n* *(bug):* Blokuje"]);
    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)->and($process->getOutput())->toContain('omit the whole-change link');
});

test('a mention the converter publishes for another account is refused', function (string $author): void {
    $source = jiraSummaryWithRefactor('[~accountid:' . jiraSummaryAuthor() . '] ');
    $source = str_replace('* *(bug):* Druhý bod.', "{quote}\nOdpověď [~accountid:acc-someone-else] zní ano.\n{quote}\n* *(bug):* Druhý bod.", $source);

    $published = jiraSummaryPublishedTargets(jiraSummaryPublishedAdf($source));
    $process = runJiraSummaryCheck($source, 'none', $author);

    expect($published)->toContain('mention acc-someone-else')
        ->and($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: mention: every mention is the author of the changes: "acc-someone-else"');
})->with(['the author is known' => [jiraSummaryAuthor()], 'no account matches the author' => ['none']]);

test('a comment must open with exactly one deploy-verdict paragraph', function (string $source): void {
    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: verdict: the comment must open with one deploy-verdict paragraph above the first section');
})->with([
    'a bullet in place of the verdict' => [str_replace(jiraSummaryVerdict(), '* Nejde nasadit.', jiraSummaryWithoutCriteria())],
    'a second paragraph that retells what the review ran' => [
        str_replace(
            jiraSummaryVerdict(),
            jiraSummaryVerdict() . "\n\nPustil jsem všechny kontroly a testy, interaktivní test jsem nedělal.",
            jiraSummaryWithoutCriteria(),
        ),
    ],
    'no verdict above the first section' => [str_replace(jiraSummaryVerdict() . "\n\n", '', jiraSummaryWithoutCriteria())],
]);

test('the title exposes the most urgent status for manual review', function (): void {
    $source = str_replace('{expand:⚠️ Akceptační kritéria}', '{expand:✅ Akceptační kritéria}', jiraSummaryWithCriteria());
    $process = runJiraSummaryCheck($source, 'present');

    expect($process->getExitCode())->toBe(2)->and($process->getOutput())->toContain('most urgent item status');
});

test(
    'what changed and what it affects is a short summary of bullets with no TL;DR label',
    function (string $search, string $replacement, string $violation): void {
        $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria([$search => $replacement]), 'none');
    
        expect($process->getExitCode())->toBe(2)
            ->and($process->getOutput())->toContain('violation: changes: ' . $violation);
    },
)->with([
    'a prose paragraph' => [
        jiraSummaryChangesLine(),
        '„Refresh products“ nyní aktualizuje jen produkty.',
        'write what changed and what it affects as a few bullets, one fact per bullet',
    ],
    'a TL;DR label' => ['{expand:ℹ️ Co se změnilo a co to ovlivní}', '{expand:TL;DR}', 'the final section carries no TL;DR label: TL;DR'],
    'over 600 characters' => [jiraSummaryChangesLine(), '* ' . str_repeat('ž', 600), '682 characters, the limit is 600'],
    'the label written with a space' => [
        '{expand:ℹ️ Co se změnilo a co to ovlivní}', '{expand:Shrnutí (tl dr)}', 'the final section carries no TL;DR label',
    ],
]);

test('a section outside a collapsed block is refused', function (): void {
    $source = jiraSummaryWithoutCriteria(
        ['{expand:ℹ️ Co se změnilo a co to ovlivní}' => "h2. Co se změnilo\n\n{expand:Co může změna ovlivnit po nasazení}"],
    );
    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: sections: text outside a collapsed block: h2. Co se změnilo');
});

test('a collapsed block must close and never nests', function (string $search, string $replacement, string $violation): void {
    $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria([$search => $replacement]), 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: blocks: ' . $violation);
})->with([
    'a block inside another one' => [
        '{expand:ℹ️ Co se změnilo a co to ovlivní}',
        "{expand:ℹ️ Co se změnilo a co to ovlivní}\n{expand:Uvnitř}",
        'a collapsed block inside another one: {expand:Uvnitř}',
    ],
    'a block without its closing line' => [
        "beze změny.\n{expand}",
        'beze změny.',
        'a collapsed block without its closing line: ℹ️ Co se změnilo a co to ovlivní',
    ],
]);

test('a review finding may be longer than the whole summary of changes', function (): void {
    $process = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(
            ['* *(bug):* Blokuje sloučení: synchronizace' => '* *(bug):* ' . str_repeat('Dlouhý nález. ', 30) . 'Blokuje sloučení: synchronizace'],
        ),
        'none',
    );

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('Markdown and developer tokens fail the check', function (string $line, string $violation): void {
    $process = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(
            [jiraSummaryChangesLine() => $line],
        ),
        'none',
    );

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: ' . $violation);
})->with([
    'code block' => ['{code:php}echo 1;{code}', 'developer token: {code'],
    'commit hash' => ['* Oprava je v commitu a3f9c2d41b.', 'developer token: a3f9c2d41b'],
    'inline code' => ['* Hodnota {{price_vat}} je špatně.', 'developer token: {{'],
    'Markdown bold' => ['* **Blokuje** sloučení.', 'format: Markdown: * **Blokuje** sloučení.'],
    'Markdown heading' => ['## Co se změnilo', 'format: Markdown: ## Co se změnilo'],
    'Markdown link' => ['* Viz [PR](https://github.com/acme/app/pull/1).', 'format: Markdown: * Viz [PR](https://github.com/acme/app/pull/1).'],
]);

test('a summary whose visible text is over 3 000 characters fails the check', function (): void {
    $source = jiraSummaryWithoutCriteria(
        ['* *(bug):* Blokuje sloučení: synchronizace' => '* *(bug):* ' . str_repeat('ž', 3_000) . ' Blokuje sloučení: synchronizace'],
    );
    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toMatch('/violation: length: 3\d{3} characters, the limit is 3000/');
});

test('the 3 000-character cap counts the label of a code link, never its address', function (): void {
    $finding = static fn (int $number): string => '* *(bug):* Bod ' . $number
        . ': synchronizace zařazená starou verzí po nasazení selže a objednávka zůstane neodeslaná. '
        . jiraSummaryCodeLink();
    $source = jiraSummaryWithoutCriteria(
        ['* *(bug):* Blokuje sloučení:' => implode("\n", array_map($finding, range(1, 14))) . "\n* *(bug):* Blokuje sloučení:"],
    );

    $process = runJiraSummaryCheck($source, 'none');

    expect(mb_strlen($source))->toBeGreaterThan(3_000)
        ->and($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('technical footers and duplicate navigation are refused even after the last block', function (string $footer): void {
    $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria() . "\n" . $footer, 'none');

    expect($process->getExitCode())->toBe(2);
})->with([
    'automatic footer' => ["----\n_Tento komentář je generovaný automaticky._"],
    'issue self-link' => ['[ACME-6720|https://example.atlassian.net/browse/ACME-6720]'],
    'standalone pull request' => ['[PR #27625|https://github.com/acme/app/pull/27625]'],
    'technical marker' => [jiraSummaryMarkerBlock()],
]);

test('the check refuses a wrong call and a missing source file', function (string $arguments, string $error): void {
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/pr-summary/scripts/check-jira-comment.php', ...explode(' ', $arguments)], $packageDir);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain($error);
})->with([
    'a JIRA issue URL that is no issue' => [
        '--criteria none --repository acme/app --pull-request 1 --jira-issue https://example.atlassian.net/login /nonexistent/source',
        jiraSummaryUsage(),
    ],
    'an author value that is no account ID' => ['--criteria none --author [x] ' . jiraSummaryTaskCall() . ' /nonexistent/source', jiraSummaryUsage()],
    'a pull request that is no number' => [
        '--criteria none --repository acme/app --pull-request 1x --jira-issue https://example.atlassian.net/browse/ACME-1 /nonexistent/source',
        jiraSummaryUsage(),
    ],
    'a reviewed commit that is no full hash' => [
        '--criteria none ' . implode(' ', array_slice(jiraSummaryTaskArguments(), 0, -2)) . ' --revision 0123abc /nonexistent/source',
        jiraSummaryUsage(),
    ],
    'missing source file' => ['--criteria none ' . jiraSummaryTaskCall() . ' /nonexistent/source', 'cannot read /nonexistent/source'],
    'no criteria flag' => [jiraSummaryTaskCall() . ' /nonexistent/source', jiraSummaryUsage()],
    'no JIRA issue' => [
        '--criteria none --repository acme/app --pull-request 1 /nonexistent/source',
        jiraSummaryUsage(),
    ],
    'no pull request' => [
        '--criteria none --repository acme/app --jira-issue https://example.atlassian.net/browse/ACME-1 /nonexistent/source',
        jiraSummaryUsage(),
    ],
    'no repository' => [
        '--criteria none --pull-request 1 --jira-issue https://example.atlassian.net/browse/ACME-1 /nonexistent/source',
        jiraSummaryUsage(),
    ],
    'unknown criteria value' => ['--criteria maybe ' . jiraSummaryTaskCall() . ' /nonexistent/source', jiraSummaryUsage()],
]);

function jiraSummaryTaskCall(): string
{
    return implode(' ', jiraSummaryTaskArguments());
}

test('the summary check and the support comment check read a developer token the same way', function (string $text, string $violation): void {
    $summary = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(
            [jiraSummaryChangesLine() => $text],
        ),
        'none',
    );
    $support = ReportCheckRun::over('skills/analyze-support-issue/scripts/check-comment.php', "h2. TL;DR\n" . $text);

    expect($summary->getOutput())->toContain('violation: ' . $violation)
        ->and($support->getOutput())->toContain('violation: ' . $violation);
})->with([
    'code block' => ['{code:php}echo 1;{code}', 'developer token: {code'],
    'commit hash' => ['* Oprava je v commitu a3f9c2d41b.', 'developer token: a3f9c2d41b'],
    'inline code' => ['* Hodnota {{price_vat}} je špatně.', 'developer token: {{'],
]);

test('the skill and the template pass the reviewed commit to the check before every JIRA publish', function (): void {
    $skill = packageSource('skills/pr-summary/SKILL.md');
    $template = (string) preg_replace('/\s+/', ' ', packageSource('skills/pr-summary/templates/pr-summary-jira.md'));

    expect($skill)->toContain('--jira-issue <issue-url> --revision <commit> <source-file>`')
        ->and($skill)->toContain('`--revision` with the `Reviewed revision:` of the code-review report')
        ->and($template)->toContain('--jira-issue <issue-url> --revision <commit> <source-file> on the rendered source, with the Reviewed revision');
});
