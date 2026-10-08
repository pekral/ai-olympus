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

test('a support comment with lookalike words, plain numbers, and links passes the check', function (): void {
    $process = runSupportCommentCheck(implode("\n", [
        '*Aplikace funguje správně — postup v nápovědě chybí.* Nastavení je snadný krok a jiná cesta je nemožná.',
        'h2. Co se děje',
        'Klient volal z čísla 777123456789 kvůli faktuře 2026100700. Chyba se zobrazí, když the error appears again.',
        'Související tiket ECOMAIL-7373 a článek [Chytrá rozesílka|https://support.example.com/cs/articles/15971707-chytra-rozesilka].',
    ]));

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe('');
});

test('an estimating word fails the check, matched as a whole word in Czech and in English', function (string $sentence, string $word): void {
    $process = runSupportCommentCheck("h2. Co se děje\n" . $sentence);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: estimate: ' . $word);
})->with([
    'asi' => ['Je to asi chyba ve feedu.', 'asi'],
    'pravděpodobně' => ['Pravděpodobně se feed neobnovil.', 'Pravděpodobně'],
    'snad' => ['Snad to pomůže.', 'Snad'],
    'mělo by' => ['Mělo by to fungovat.', 'Mělo by'],
    'probably' => ['The feed probably failed.', 'probably'],
]);

test('a developer token fails the check', function (string $sentence, string $token): void {
    $process = runSupportCommentCheck("h2. Co se děje\n" . $sentence);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: developer token: ' . $token);
})->with([
    'file path' => ['Chyba je v app/Helpers/Number.php.', '.php'],
    'static call' => ['Volá se Number::formatFloat.', '::'],
    'inline code' => ['Hodnota {{price_vat}} je špatně.', '{{'],
    'hexadecimal hash' => ['Oprava je v commitu a3f9c2d41b.', 'a3f9c2d41b'],
]);

test('a source over 3 000 characters fails the check', function (): void {
    $process = runSupportCommentCheck(str_repeat('ž', 3001));

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('violation: length: 3001 characters, the limit is 3000');
});

test('the check refuses a missing source file', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/analyze-support-issue/scripts/check-comment.php', '/nonexistent/source'], $packageDir);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('cannot read /nonexistent/source');
});
