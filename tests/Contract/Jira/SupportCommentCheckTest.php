<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function runSupportCommentCheck(string $source): Process
{
    $packageDir = dirname(__DIR__, 3);
    $file = sys_get_temp_dir() . '/ai-olympus-support-comment-' . bin2hex(random_bytes(6));
    file_put_contents($file, $source);

    $process = new Process(['php', $packageDir . '/skills/analyze-support-issue/scripts/check-comment.php', $file], $packageDir);

    try {
        $process->run();
    } finally {
        unlink($file);
    }

    return $process;
}

function validSupportTldr(): string
{
    return implode("\n", [
        'h2. TL;DR',
        '*Aplikace funguje správně — postup v nápovědě chybí.* Nastavení je snadný krok a jiná cesta je nemožná.',
        '* *Co se děje:* Klient volal z čísla 777123456789 kvůli faktuře 2026100700.',
        '* *Co udělat teď:* Support — pošle klientovi odpověď níže.',
        '* *Co zatím nevíme:* Nic, všechno výše je ověřené.',
        '* *Nápověda:* Článek [Chytrá rozesílka|https://support.example.com/cs/articles/15971707-chytra-rozesilka] tento postup nepopisuje.',
        '* *Jak jsme to ověřili:* nastavení aplikace; související tiket ECOMAIL-7373.',
        '*Co odpovědět klientovi:*',
        '{quote}Dobrý den, the error appears again jen při ručním odeslání.{quote}',
        '----',
        '_Analýzu připravil agent pro support. Uvádí jen ověřené informace._',
    ]);
}

test('a support TL;DR with lookalike words, plain numbers, and links passes the check', function (): void {
    $process = runSupportCommentCheck(validSupportTldr());

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe('');
});

test('a support TL;DR that passes the check converts to a heading, a bullet list, and a quote', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/code-review-jira/scripts/wiki-markup-to-adf.php'], $packageDir);
    $process->setInput(validSupportTldr());
    $process->mustRun();

    /** @var array{content: list<array{type: string}>} $adf */
    $adf = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($adf['content'], 'type'))
        ->toBe(['heading', 'paragraph', 'bulletList', 'paragraph', 'blockquote', 'rule', 'paragraph'])
        ->and(str_contains($process->getOutput(), '{quote}'))->toBeFalse()
        ->and(str_contains($process->getOutput(), 'h2.'))->toBeFalse();
});

test('a source that is not a TL;DR the converter renders intact fails the check', function (string $source, string $violation): void {
    $process = runSupportCommentCheck($source);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: format: ' . $violation);
})->with([
    'dash bullet' => ["h2. TL;DR\n- Co se děje", 'Markdown: - Co se děje'],
    'Markdown bold' => ["h2. TL;DR\n**Chyba v aplikaci.**", 'Markdown: **Chyba v aplikaci.**'],
    'Markdown heading' => ["h2. TL;DR\n## Co se děje", 'Markdown: ## Co se děje'],
    'Markdown link' => ["h2. TL;DR\nČlánek [Nápověda](https://support.example.com).", 'Markdown: Článek [Nápověda](https://support.example.com).'],
    'missing TL;DR heading' => ["h2. Co se děje\nText.", 'the first line must be h2. TL;DR'],
    'multi-line quote' => ["h2. TL;DR\n{quote}Dobrý den,\nděkujeme.{quote}", 'a quote must open and close on one line: {quote}Dobrý den,'],
    'second heading' => ["h2. TL;DR\nText.\nh2. Co se děje", 'a second heading: h2. Co se děje'],
]);

test('an estimating word fails the check, matched as a whole word in Czech and in English', function (string $sentence, string $word): void {
    $process = runSupportCommentCheck("h2. TL;DR\n" . $sentence);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: estimate: ' . $word);
})->with([
    'asi' => ['Je to asi chyba ve feedu.', 'asi'],
    'mělo by' => ['Mělo by to fungovat.', 'Mělo by'],
    'pravděpodobně' => ['Pravděpodobně se feed neobnovil.', 'Pravděpodobně'],
    'probably' => ['The feed probably failed.', 'probably'],
    'snad' => ['Snad to pomůže.', 'Snad'],
]);

test('a developer token fails the check', function (string $sentence, string $token): void {
    $process = runSupportCommentCheck("h2. TL;DR\n" . $sentence);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: developer token: ' . $token);
})->with([
    'file path' => ['Chyba je v app/Helpers/Number.php.', '.php'],
    'hexadecimal hash' => ['Oprava je v commitu a3f9c2d41b.', 'a3f9c2d41b'],
    'inline code' => ['Hodnota {{price_vat}} je špatně.', '{{'],
    'static call' => ['Volá se Number::formatFloat.', '::'],
]);

test('a source over 1 500 characters fails the check', function (): void {
    $process = runSupportCommentCheck("h2. TL;DR\n" . str_repeat('ž', 1_491));

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toBe("violation: length: 1501 characters, the limit is 1500\n");
});

test('the check refuses a missing source file', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/analyze-support-issue/scripts/check-comment.php', '/nonexistent/source'], $packageDir);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('cannot read /nonexistent/source');
});
