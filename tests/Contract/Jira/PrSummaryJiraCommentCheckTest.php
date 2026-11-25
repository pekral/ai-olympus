<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;
use Tests\Support\ReportCheckRun;

function runJiraSummaryCheck(string $source, string $criteria): Process
{
    return ReportCheckRun::over('skills/pr-summary/scripts/check-jira-comment.php', $source, '--criteria', $criteria);
}

/**
 * @param array<string, string> $replace
 */
function jiraSummaryWithoutCriteria(array $replace = []): string
{
    $source = implode("\n", [
        jiraSummaryVerdict(),
        '',
        'h2. Nálezy z kontroly kódu',
        '',
        '* Blokuje sloučení: synchronizace zařazená starou verzí po nasazení selže. Oprava: nová verze ji musí umět dokončit.',
        '',
        'h3. Už nahlášené v dřívější kontrole',
        '',
        '* Objednávka bez zákazníka selže. Autor odpověděl 10. 11. 2026 v komentáři [ECOMAIL-1|https://example.atlassian.net/browse/ECOMAIL-1].',
        '',
        'h2. TL;DR',
        '',
        '„Refresh products“ nyní aktualizuje jen produkty; připojení obchodu a synchronizace objednávek i kontaktů zůstávají beze změny.',
        '',
        '[PR #27625|https://github.com/acme/app/pull/27625] · [ECOMAIL-6720|https://example.atlassian.net/browse/ECOMAIL-6720]',
        '',
        '----',
        '',
        '_Tento komentář je generovaný automaticky. Je psaný pro vlastníka ticketu, ne pro vývojáře._',
    ]);

    return str_replace(array_keys($replace), array_values($replace), $source);
}

function jiraSummaryVerdict(): string
{
    return 'Za mě je to skoro hotové, ale zatím to nejde nasadit. Sloučení blokuje jeden bod z kontroly kódu níž: synchronizace po nasazení selže. Nic není sloučené ani nasazené.';
}

function jiraSummaryWithCriteria(): string
{
    return implode("\n", [
        jiraSummaryVerdict(),
        '',
        'h2. Akceptační kritéria',
        '',
        '* Hromadné odeslání nad 500 příjemců není ověřené. Kdo může takové odeslání spustit?',
        '',
        '[~accountid:712020:a1b2c3d4e5f6-0a1b-4c2d-9e8f-a1b2c3d4e5f6], potvrď prosím nález a oprav ho, nebo ho v odpovědi vyvrať.',
        '',
        str_replace(jiraSummaryVerdict() . "\n\n", '', jiraSummaryWithoutCriteria()),
    ]);
}

function jiraSummaryForTwoPullRequests(): string
{
    $group = static fn (int $number): string => implode("\n", [
        'h2. [PR #' . $number . '|https://github.com/acme/app/pull/' . $number . '] — Synchronizace',
        '',
        'h3. Nálezy z kontroly kódu',
        '',
        'Kontrola nenašla žádný bod k vyřešení.',
        '',
        'h3. TL;DR',
        '',
        'Synchronizace obchodu nově zpracuje jen nové objednávky.',

    ]);

    return implode("\n\n", [
        jiraSummaryVerdict(),
        "h2. Akceptační kritéria\n\n* Hromadné odeslání vyžaduje opravu.",
        $group(11),
        $group(12),
        '[PR #11|https://github.com/acme/app/pull/11] · [PR #12|https://github.com/acme/app/pull/12]',
        "----\n\n_Tento komentář je generovaný automaticky._",
    ]);
}

test('a JIRA summary in the shape of the template passes the check', function (string $source, string $criteria): void {
    $process = runJiraSummaryCheck($source, $criteria);

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
})->with([
    'all criteria met or no criteria stated' => [jiraSummaryWithoutCriteria(), 'none'],
    'two pull requests' => [jiraSummaryForTwoPullRequests(), 'unmet'],
    'unmet criteria and a mention' => [jiraSummaryWithCriteria(), 'unmet'],
]);

test('a summary that passes the check converts to ADF with no wiki markup left', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/code-review-jira/scripts/wiki-markup-to-adf.php'], $packageDir);
    $process->setInput(jiraSummaryWithCriteria());
    $process->mustRun();

    /** @var array{content: list<array{type: string}>} $adf */
    $adf = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($adf['content'], 'type')[0])->toBe('paragraph')
        ->and(str_contains($process->getOutput(), 'h2.'))->toBeFalse()
        ->and(str_contains($process->getOutput(), '"mention"'))->toBeTrue();
});

test('a comment must open with exactly one deploy-verdict paragraph', function (string $source): void {
    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: verdict: the comment must open with one deploy-verdict paragraph above the first section');
})->with([
    'no verdict above the first section' => [str_replace(jiraSummaryVerdict() . "\n\n", '', jiraSummaryWithoutCriteria())],
    'a second paragraph that retells what the review ran' => [
        str_replace(jiraSummaryVerdict(), jiraSummaryVerdict() . "\n\nPustil jsem všechny kontroly a testy, interaktivní test jsem nedělal.", jiraSummaryWithoutCriteria()),
    ],
    'a bullet in place of the verdict' => [str_replace(jiraSummaryVerdict(), '* Nejde nasadit.', jiraSummaryWithoutCriteria())],
]);

test('the criteria section must match whether any requirement still needs action', function (string $source, string $criteria, string $violation): void {
    $process = runJiraSummaryCheck($source, $criteria);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: sections: ' . $violation);
})->with([
    'criteria section in a grouped comment when no requirement needs action' => [
        jiraSummaryForTwoPullRequests(),
        'none',
        '1 issue-level h2 sections, expected 0',
    ],
    'criteria section when no requirement needs action' => [jiraSummaryWithCriteria(), 'none', '3 h2 sections, expected 2 without a criteria section'],
    'missing criteria section for an unmet requirement' => [jiraSummaryWithoutCriteria(), 'unmet', '2 h2 sections, expected 3 with a criteria section'],
]);

test('the combined summary must be one short paragraph', function (string $replacement, string $violation): void {
    $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria([
        '„Refresh products“ nyní aktualizuje jen produkty; připojení obchodu a synchronizace objednávek i kontaktů zůstávají beze změny.' => $replacement,
    ]), 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: tldr: ' . $violation);
})->with([
    'list instead of prose' => ['* První změna.', 'use one paragraph combining changes and deployment impact'],
    'long paragraph' => [str_repeat('ž', 601), '601 characters, the limit is 600'],
    'separate paragraphs' => ["Změna.\n\nDopad.", 'use one paragraph combining changes and deployment impact'],
]);

test('the former separate sections are refused', function (): void {
    $source = jiraSummaryWithoutCriteria([
        'h2. TL;DR' => "h2. Co se změnilo\n\n* Nové objednávky se synchronizují.\n\nh2. Co může změna ovlivnit po nasazení",
    ]);
    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: sections:')
        ->and($process->getOutput())->toContain('violation: tldr: the final section must be TL;DR');
});

test('a review finding may be longer than a TL;DR bullet', function (): void {
    $process = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(['* Blokuje sloučení: synchronizace' => '* ' . str_repeat('Dlouhý nález. ', 30) . 'Blokuje sloučení: synchronizace']),
        'none',
    );

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('Markdown and developer tokens fail the check', function (string $line, string $violation): void {
    $process = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(
            ['„Refresh products“ nyní aktualizuje jen produkty; připojení obchodu a synchronizace objednávek i kontaktů zůstávají beze změny.' => $line],
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

test('a summary over 3 000 characters fails the check', function (): void {
    $source = jiraSummaryWithoutCriteria(['* Blokuje sloučení: synchronizace' => '* ' . str_repeat('ž', 3_000) . ' Blokuje sloučení: synchronizace']);
    $process = runJiraSummaryCheck($source, 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain(sprintf('violation: length: %d characters, the limit is 3000', mb_strlen($source)));
});

test('a summary without the footer fails the check', function (): void {
    $process = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(["\n----\n\n_Tento komentář je generovaný automaticky. Je psaný pro vlastníka ticketu, ne pro vývojáře._" => '']),
        'none',
    );

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: footer: a ---- line followed by the italic footer line is missing');
});

test('the check refuses a wrong call and a missing source file', function (string $arguments, string $error): void {
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/pr-summary/scripts/check-jira-comment.php', ...explode(' ', $arguments)], $packageDir);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain($error);
})->with([
    'missing source file' => ['--criteria none /nonexistent/source', 'cannot read /nonexistent/source'],
    'no criteria flag' => ['/nonexistent/source', 'Usage: check-jira-comment.php --criteria <unmet|none> <source-file>'],
    'unknown criteria value' => ['--criteria maybe /nonexistent/source', 'Usage: check-jira-comment.php --criteria <unmet|none> <source-file>'],
]);

test('the summary check and the support comment check read a developer token the same way', function (string $text, string $violation): void {
    $summary = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(
            ['„Refresh products“ nyní aktualizuje jen produkty; připojení obchodu a synchronizace objednávek i kontaktů zůstávají beze změny.' => $text],
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
