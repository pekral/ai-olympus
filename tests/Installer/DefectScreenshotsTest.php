<?php

declare(strict_types = 1);

function defectShotRead(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the reference shows errors only, one screenshot per state, and two for a regression', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');

    expect($reference)->toContain('**Errors only.** A scenario that passed gets no screenshot and no line in a comment.')
        ->and($reference)->toContain('A regression has two screenshots of the same step: `Default branch (<default>)` first, then `This change`.')
        ->and($reference)->toContain('**An API-only defect gets text only.**')
        ->and($reference)->toContain('A defect has one screenshot, taken on the step that fails, never on a page before or after it.');
});

test('the reference never lets a security or a withheld finding carry a screenshot', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');

    expect($reference)->toContain(
        '**A security finding never gets a screenshot.** Neither does a finding marked `do_not_file_publicly`, on GitHub and in JIRA alike.',
    );
});

test('the publication gate is an allow-list with five conditions and a text-only fallback', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');

    expect($reference)->toContain('an allow-list, never a deny-list')
        ->and($reference)->toContain('A condition that is unmet or cannot be verified leaves the finding text-only, and the handoff names the condition.')
        ->and($reference)->toContain('**The cropped area holds only data this run created.**')
        ->and($reference)->toContain(
            'a name, an address, an order number, or any other value of a record this run did not create counts as personal data',
        );
});

test('an error or debug page is never captured, and the detection list is pinned', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');

    expect($reference)->toContain(
        'A framework error page (Ignition, Whoops, a Symfony or Django or Rails exception page) and a page with a debug toolbar '
        . '(`phpdebugbar`, `sf-toolbar`, `sf-wdt`, a debug panel) are never captured and never published.',
    )
        ->and($reference)->toContain('`Whoops`, `Ignition`, `Stack trace`, `Request headers`, `Environment`');
});

test('the capture is cropped and never a full page', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');

    expect($reference)->toContain('It covers the failing element within the tested viewport, never the full page (`fullPage: true`).');
});

test('masking runs in the capturing session and covers values, split text, shadow roots, and embedded content', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');

    expect($reference)->toContain('**Masking ran in the browser session that writes the file, right before the capture.**')
        ->and($reference)->toContain('every `input`, `textarea`, and `select`')
        ->and($reference)->toContain('the text of an element that is spread over several child nodes')
        ->and($reference)->toContain('every open shadow root')
        ->and($reference)->toContain('It paints a canvas, an `img`, a `video`, SVG text, and a cross-origin iframe in the cropped area over as a whole.')
        ->and($reference)->toContain('A secret value of the environment is never shown.');
});

test('the baseline of a regression runs before the head migrations or in its own database', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');

    expect($reference)->toContain(
        '**Before the migrations of the head.** Take the base screenshot before the pending migrations of the head run against the local database.',
    )
        ->and($reference)->toContain('create a database for the base walk with the project\'s documented setup and drop it when the walk ends')
        ->and($reference)->toContain('A rollback and `migrate:fresh` stay forbidden.')
        ->and($reference)->toContain(
            '`Default branch (<default>)` takes the name of the resolved default branch, and it matches `^[A-Za-z0-9._/-]{1,100}$`.',
        );
});

test('the upload happens only inside the publishing step', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');

    expect($reference)->toContain('**Upload happens only inside the publishing step.**')
        ->and($reference)->toContain('A quiet review round uploads nothing.')
        ->and($reference)->toContain('**A refused write is never a failed review.**');
});

test('the evidence does not expose any component of a private step URL', function (): void {
    $reference = defectShotRead('skills/interactive-testing/references/defect-screenshots.md');
    $raphael = defectShotRead('agents/raphael.md');

    expect($reference)->toContain('Do not include the source URL, its host, path, query or fragment.')
        ->and($raphael)->toContain('Name the step without copying its URL.');
});

test('raphael, april, and the interactive-testing skill point to the reference and stop saying GitHub has no image path', function (): void {
    $raphael = defectShotRead('agents/raphael.md');
    $april = defectShotRead('agents/april.md');
    $skill = defectShotRead('skills/interactive-testing/SKILL.md');

    expect($raphael)->toContain('@skills/interactive-testing/references/defect-screenshots.md')
        ->and($april)->toContain('@skills/interactive-testing/references/defect-screenshots.md')
        ->and($skill)->toContain('@skills/interactive-testing/references/defect-screenshots.md')
        ->and($raphael)->not->toContain('GitHub has no supported API for attaching an image')
        ->and($april)->not->toContain('GitHub has no supported API for attaching an image');
});

test(
    'the Bash boundaries of the publishing agents name the upload scripts as narrow exceptions, on both platforms',
    function (string $agent, array $scripts): void {
        $definition = defectShotRead('agents/' . $agent . '.md');
        $codex = defectShotRead('codex/agents/' . $agent . '.toml');
        $boundary = substr($definition, (int) strpos($definition, 'Bash is granted for one purpose'));

        foreach ($scripts as $script) {
            expect($boundary)->toContain($script)
                ->and($codex)->toContain($script);
        }
    },
)->with([
    'april' => ['april', ['upload-review-asset.sh']],
    'leonardo' => ['leonardo', ['upload-review-asset.sh']],
    'raphael' => ['raphael', ['redact-url-params.sh', 'screenshot-guard.sh']],
]);

test('the boundaries never let an agent run an upload script directly or for a security finding', function (): void {
    $leonardo = defectShotRead('agents/leonardo.md');
    $raphael = defectShotRead('agents/raphael.md');

    expect($leonardo)->toContain('you never run it directly')
        ->and($leonardo)->toContain('it never runs for a security finding, a finding marked `do_not_file_publicly`, or a quiet review round')
        ->and($raphael)->toContain('You never run an upload script: the publisher does.');
});

test('only the GitHub review can publish a screenshot', function (): void {
    $rule = defectShotRead('rules/compound-engineering/orchestration.md');
    expect($rule)->toContain('GitHub Contents API only: no `git push`, no hook, no force');
    expect($rule)->not->toContain('Attach the masked screenshot of a reproduced defect to the JIRA work item');
    expect(defectShotRead('rules/jira/general.md'))->toContain('It never uploads or deletes attachments.');
});

test('the GitHub review template and the wrapper contract describe the one allowed image', function (): void {
    expect(defectShotRead('skills/code-review-github/templates/review-body.md'))->toContain(
        'The one image is the screenshot that the wrapper writes itself',
    )
        ->and(defectShotRead('skills/code-review-github/references/cr-wrapper-contract.md'))->toContain(
            'A quiet round and a round that does not publish pass no `--screenshots`.',
        );
});

test('the self-tests of the new scripts run in the shell self-test command', function (): void {
    $composer = defectShotRead('composer.json');

    expect($composer)->toContain('skills/_shared/screenshot-guard.sh')
        ->and($composer)->toContain('skills/_shared/redact-url-params.sh')
        ->and($composer)->toContain('skills/code-review-github/scripts/upload-review-asset.sh')
        ->and($composer)->not->toContain('skills/code-review-jira/scripts/upload-attachment.sh');
});
