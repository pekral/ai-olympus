<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

/**
 * The fingerprint the carry script records for one agent-written line.
 */
function carryTestFingerprint(string $line): string
{
    return substr(hash('sha256', $line), 0, 8);
}

/**
 * @param array<string, mixed> ...$nodes
 * @return array<string, mixed>
 */
function carryTestAdfDocument(array ...$nodes): array
{
    return ['version' => 1, 'type' => 'doc', 'content' => $nodes];
}

/**
 * @return array{type: string, content: array<int, array<string, mixed>>}
 */
function carryTestAdfMarker(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, 'marks' => [['type' => 'em']]]]];
}

function runCarryOperatorLines(string $format, string $newBody, ?string $previousBody = null): Process
{
    $packageDir = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/ai-olympus-carry-' . bin2hex(random_bytes(6));
    mkdir($directory, 0o700, recursive: true);
    file_put_contents($directory . '/new', $newBody);
    $arguments = ['php', $packageDir . '/skills/_shared/carry-operator-lines.php', $format, 'cr-comment', $directory . '/new'];

    if ($previousBody !== null) {
        file_put_contents($directory . '/previous', $previousBody);
        $arguments[] = $directory . '/previous';
    }

    $process = new Process($arguments, $packageDir);
    $process->run();

    array_map(unlink(...), array_filter([$directory . '/new', $directory . '/previous'], is_file(...)));
    rmdir($directory);

    return $process;
}

test('a markdown body records the fingerprint of the lines the agent wrote', function (): void {
    $process = runCarryOperatorLines('markdown', "## TL;DR\n\n- fixed\n\n<!-- cr-comment:actor=bot -->");

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toMatch(
            '/^## TL;DR\n\n- fixed\n\n<!-- cr-comment:lines=[0-9a-f]{8},[0-9a-f]{8} -->\n<!-- cr-comment:actor=bot -->$/',
        )
        ->and($process->getErrorOutput())->toBe('');
});

test('an operator line added to the previous markdown version is carried verbatim and reported', function (): void {
    $first = runCarryOperatorLines('markdown', "## TL;DR\n\n- round one\n\n<!-- cr-comment:actor=bot -->")->getOutput();
    $edited = str_replace('- round one', "- round one\nRozhodnuto: limit zůstává 100 položek.", $first);

    $process = runCarryOperatorLines('markdown', "## TL;DR\n\n- round two\n\n<!-- cr-comment:actor=bot -->", $edited);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain("- round two\n\nRozhodnuto: limit zůstává 100 položek.\n\n<!-- cr-comment:lines=")
        ->and($process->getOutput())->not->toContain('round one')
        ->and($process->getErrorOutput())->toBe("carried_lines=1\ncarried: Rozhodnuto: limit zůstává 100 položek.\n");

    // The carried line keeps travelling: the next rewrite still does not know its fingerprint.
    $third = runCarryOperatorLines('markdown', "## TL;DR\n\n- round three", $process->getOutput());

    expect($third->getOutput())->toContain('Rozhodnuto: limit zůstává 100 položek.')
        ->and($third->getErrorOutput())->toStartWith('carried_lines=1');
});

test('a previous version without a fingerprint carries nothing and says why', function (): void {
    $process = runCarryOperatorLines('markdown', 'new body', "old body\nRozhodnuto: ano\n\n<!-- cr-comment:actor=bot -->");

    expect($process->getOutput())->not->toContain('Rozhodnuto')
        ->and($process->getErrorOutput())->toBe("carried_lines=0 reason=no-fingerprint\n");
});

test('an operator node added to the previous ADF version is carried verbatim, mention included', function (): void {
    $paragraph = static fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    $operatorNode = ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Rozhodnuto: '],
        ['type' => 'mention', 'attrs' => ['id' => 'u1', 'text' => '@Petr']],
    ],
    ];
    $previousBody = carryTestAdfDocument(
        $paragraph('Round one'),
        $operatorNode,
        carryTestAdfMarker('cr-comment:actor=abc lines=' . carryTestFingerprint('Round one')),
    );

    $process = runCarryOperatorLines(
        'adf',
        json_encode(carryTestAdfDocument($paragraph('Round two'), carryTestAdfMarker('cr-comment:actor=abc')), JSON_THROW_ON_ERROR),
        json_encode($previousBody, JSON_THROW_ON_ERROR),
    );

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR))->toEqual(
            carryTestAdfDocument(
                $paragraph('Round two'),
                $operatorNode,
                carryTestAdfMarker('cr-comment:actor=abc lines=' . carryTestFingerprint('Round two')),
            ),
        )
        ->and($process->getErrorOutput())->toBe("carried_lines=1\ncarried: Rozhodnuto: @u1\n");
});

test('an agent paragraph with a mention is not carried once JIRA stored the display name on the mention', function (): void {
    $agentParagraph = static fn (array $mention): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Question for '], $mention]];
    $publishedMention = ['type' => 'mention', 'attrs' => ['id' => 'u1']];
    $storedMention = ['type' => 'mention', 'attrs' => ['id' => 'u1', 'text' => '@Jan Novák']];
    $previousBody = carryTestAdfDocument(
        $agentParagraph($storedMention),
        carryTestAdfMarker('cr-comment:actor=abc lines=' . carryTestFingerprint('Question for @u1')),
    );

    $process = runCarryOperatorLines(
        'adf',
        json_encode(carryTestAdfDocument($agentParagraph($publishedMention), carryTestAdfMarker('cr-comment:actor=abc')), JSON_THROW_ON_ERROR),
        json_encode($previousBody, JSON_THROW_ON_ERROR),
    );

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR))->toEqual(
            carryTestAdfDocument(
                $agentParagraph($publishedMention),
                carryTestAdfMarker('cr-comment:actor=abc lines=' . carryTestFingerprint('Question for @u1')),
            ),
        )
        ->and($process->getErrorOutput())->toBe("carried_lines=0\n");
});
