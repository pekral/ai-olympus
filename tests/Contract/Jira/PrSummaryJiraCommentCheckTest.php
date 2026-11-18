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
        'h2. Nálezy z kontroly kódu',
        '',
        '* Blokuje sloučení: synchronizace zařazená starou verzí po nasazení selže. Oprava: nová verze ji musí umět dokončit.',
        '',
        'h3. Už nahlášené v dřívější kontrole',
        '',
        '* Objednávka bez zákazníka selže. Autor odpověděl 10. 11. 2026 v komentáři [ECOMAIL-1|https://example.atlassian.net/browse/ECOMAIL-1].',
        '',
        'h2. Co se změnilo',
        '',
        '* U Ecwidu přibylo tlačítko „Refresh products“: dřív celá synchronizace -> teď jen produkty.',
        '* Synchronizace Ecwidu běží ve vlastní frontě.',
        '',
        'h2. Co může změna ovlivnit po nasazení',
        '',
        '* Tlačítko „Refresh products“: v účtu se objeví nebo aktualizují produkty obchodu.',
        '* Připojení obchodu, „Refresh orders“ a „Sync contacts“: viditelně se nic nemění.',
        '',
        '[PR #27625|https://github.com/acme/app/pull/27625] · [ECOMAIL-6720|https://example.atlassian.net/browse/ECOMAIL-6720]',
        '',
        '----',
        '',
        '_Tento komentář je generovaný automaticky. Je psaný pro vlastníka ticketu, ne pro vývojáře._',
    ]);

    return str_replace(array_keys($replace), array_values($replace), $source);
}

function jiraSummaryWithCriteria(): string
{
    return implode("\n", [
        'h2. Akceptační kritéria',
        '',
        '2 ze 3 kritérií jsou splněná.',
        '',
        '* Hromadné odeslání nad 500 příjemců není ověřené. Kdo může takové odeslání spustit?',
        '',
        '[~accountid:712020:a1b2c3d4e5f6-0a1b-4c2d-9e8f-a1b2c3d4e5f6], potvrď prosím nález a oprav ho, nebo ho v odpovědi vyvrať.',
        '',
        jiraSummaryWithoutCriteria(),
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
        'h3. Co se změnilo',
        '',
        '* Synchronizace běží ve vlastní frontě.',
        '',
        'h3. Co může změna ovlivnit po nasazení',
        '',
        '* Synchronizace obchodu: viditelně se nic nemění.',
    ]);

    return implode("\n\n", [
        "h2. Akceptační kritéria\n\nVšechna 2 kritéria jsou splněná.",
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
    'no criteria in the assignment' => [jiraSummaryWithoutCriteria(), 'none'],
    'stated criteria with an unmet one and a mention' => [jiraSummaryWithCriteria(), 'stated'],
    'two pull requests' => [jiraSummaryForTwoPullRequests(), 'stated'],
]);

test('a summary that passes the check converts to ADF with no wiki markup left', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/code-review-jira/scripts/wiki-markup-to-adf.php'], $packageDir);
    $process->setInput(jiraSummaryWithCriteria());
    $process->mustRun();

    /** @var array{content: list<array{type: string}>} $adf */
    $adf = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($adf['content'], 'type')[0])->toBe('heading')
        ->and(str_contains($process->getOutput(), 'h2.'))->toBeFalse()
        ->and(str_contains($process->getOutput(), '"mention"'))->toBeTrue();
});

test('a status text above the first heading fails the check', function (): void {
    $process = runJiraSummaryCheck("Kontrola kódu je hotová. Nic nebylo sloučeno ani nasazeno.\n\n" . jiraSummaryWithoutCriteria(), 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: status line: the comment must open with an h2 heading');
});

test('the criteria section must match what the assignment states', function (string $source, string $criteria, string $violation): void {
    $process = runJiraSummaryCheck($source, $criteria);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: sections: ' . $violation);
})->with([
    'criteria section for an assignment with none' => [jiraSummaryWithCriteria(), 'none', '4 h2 sections, expected 3 without a criteria section'],
    'criteria section in a grouped comment for an assignment with none' => [
        jiraSummaryForTwoPullRequests(),
        'none',
        '1 issue-level h2 sections, expected 0',
    ],
    'missing criteria section for stated criteria' => [jiraSummaryWithoutCriteria(), 'stated', '3 h2 sections, expected 4 with a criteria section'],
]);

test('the last two sections are a TL;DR of at most three short bullets', function (string $search, string $replacement, string $violation): void {
    $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria([$search => $replacement]), 'none');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: tldr: ' . $violation);
})->with([
    'four bullets under the impact section' => [
        '* Připojení obchodu',
        "* Objednávky: beze změny.\n* Kontakty: beze změny.\n* Připojení obchodu",
        '4 bullets under "h2. Co může změna ovlivnit po nasazení", the limit is 3',
    ],
    'four bullets under what changed' => [
        '* Synchronizace Ecwidu',
        "* Třetí změna.\n* Čtvrtá změna.\n* Synchronizace Ecwidu",
        '4 bullets under "h2. Co se změnilo", the limit is 3',
    ],
    'long bullet' => [
        '* Synchronizace Ecwidu běží ve vlastní frontě.',
        '* ' . str_repeat('ž', 201),
        'a bullet of 201 characters under "h2. Co se změnilo", the limit is 200',
    ],
]);

test('a review finding may be longer than a TL;DR bullet', function (): void {
    $process = runJiraSummaryCheck(
        jiraSummaryWithoutCriteria(['* Blokuje sloučení: synchronizace' => '* ' . str_repeat('Dlouhý nález. ', 30) . 'Blokuje sloučení: synchronizace']),
        'none',
    );

    expect($process->getOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('Markdown and developer tokens fail the check', function (string $line, string $violation): void {
    $process = runJiraSummaryCheck(jiraSummaryWithoutCriteria(['* Synchronizace Ecwidu běží ve vlastní frontě.' => $line]), 'none');

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
    'no criteria flag' => ['/nonexistent/source', 'Usage: check-jira-comment.php --criteria <stated|none> <source-file>'],
    'unknown criteria value' => ['--criteria maybe /nonexistent/source', 'Usage: check-jira-comment.php --criteria <stated|none> <source-file>'],
]);

test('the summary check and the support comment check read a developer token the same way', function (string $text, string $violation): void {
    $summary = runJiraSummaryCheck(jiraSummaryWithoutCriteria(['* Synchronizace Ecwidu běží ve vlastní frontě.' => $text]), 'none');
    $support = ReportCheckRun::over('skills/analyze-support-issue/scripts/check-comment.php', "h2. TL;DR\n" . $text);

    expect($summary->getOutput())->toContain('violation: ' . $violation)
        ->and($support->getOutput())->toContain('violation: ' . $violation);
})->with([
    'code block' => ['{code:php}echo 1;{code}', 'developer token: {code'],
    'commit hash' => ['* Oprava je v commitu a3f9c2d41b.', 'developer token: a3f9c2d41b'],
    'inline code' => ['* Hodnota {{price_vat}} je špatně.', 'developer token: {{'],
]);
