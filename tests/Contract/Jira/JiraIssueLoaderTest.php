<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `acli` stands in for the real CLI so the loader's contract is exercised without reaching a JIRA
// site. Every invocation is appended to `$FAKE_ACLI_CALLS`, so a test can assert which key the
// loader resolved out of the argument it was given.
const JIRA_LOADER_ACLI_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_ACLI_CALLS"
printf '\n' >> "$FAKE_ACLI_CALLS"

if [[ "$3" == "view" ]]; then
  if [[ "${FAKE_ACLI_VIEW_OK:-1}" != "1" ]]; then
    echo 'acli: issue not found' >&2
    exit 1
  fi

  # A subtask is loaded with its own `view` call; FAKE_ACLI_VIEW_JSON_<KEY with _ for -> answers it.
  per_key_view="FAKE_ACLI_VIEW_JSON_${4//-/_}"
  printf '%s\n' "${!per_key_view:-$FAKE_ACLI_VIEW_JSON}"
  exit 0
fi

if [[ "$3" == "comment" ]]; then
  if [[ "${FAKE_ACLI_COMMENTS_OK:-1}" != "1" ]]; then
    echo 'acli: comment list failed' >&2
    exit 1
  fi

  printf '%s\n' "$FAKE_ACLI_COMMENTS_JSON"
  exit 0
fi

exit 1
BASH;

function jiraLoaderViewJson(): string
{
    return (string) json_encode([
        'fields' => [
            'assignee' => ['displayName' => 'Maintainer'],
            'attachment' => [],
            'components' => [['name' => 'API']],
            'created' => '2026-01-05T09:00:00.000+0100',
            'creator' => ['displayName' => 'Reporter'],
            // The loader flattens this ADF tree into `descriptionText`; the raw tree stays
            // available under `descriptionAdf`.
            'description' => [
                'content' => [
                    ['content' => [['text' => 'A 2 MB PDF is rejected as too large.', 'type' => 'text']], 'type' => 'paragraph'],
                    ['content' => [['text' => 'Expected: the upload succeeds.', 'type' => 'text']], 'type' => 'paragraph'],
                ],
                'type' => 'doc',
                'version' => 1,
            ],
            'fixVersions' => [],
            'issuelinks' => [],
            'issuetype' => ['name' => 'Bug'],
            'labels' => ['upload'],
            'priority' => ['name' => 'High'],
            'reporter' => ['displayName' => 'Reporter'],
            'status' => ['name' => 'In Progress'],
            'subtasks' => [],
            'summary' => 'Upload validation rejects valid PDFs',
            'updated' => '2026-01-06T09:00:00.000+0100',
            'watches' => ['watchCount' => 2],
        ],
        'key' => 'ACME-1234',
    ]);
}

function jiraLoaderCommentsJson(): string
{
    // Keys are written in the order the project's fixers sort them into, so a fix pass has nothing
    // to reorder — it rewrote an earlier revision of this fixture into invalid PHP.
    return (string) json_encode([
        'comments' => [
            [
                'author' => ['displayName' => 'Maintainer'],
                'body' => [
                    'content' => [
                        ['content' => [['text' => 'Reproduced on staging.', 'type' => 'text']], 'type' => 'paragraph'],
                    ],
                    'type' => 'doc',
                    'version' => 1,
                ],
                'created' => '2026-01-06T08:00:00.000+0100',
                // `id` is not decoration: the loader falls back to `$viewCommentIdx[$c.id]` for
                // `created` and `visibility`, and jq aborts the whole document with
                // "Cannot index object with null" when the key is absent. Real `acli` output
                // always carries it.
                'id' => '10001',
            ],
        ],
    ]);
}

/**
 * The real binaries the loader needs on PATH, `acli` excluded — it is always the fake. `gh` is
 * deliberately absent: the loader treats it as optional and must still produce a document without
 * it.
 *
 * @return list<string>
 */
function jiraLoaderRequiredBinaries(): array
{
    return [
        'bash', 'jq', 'sed', 'awk', 'cat', 'grep', 'head', 'dirname',
        // `download-attachments.sh` and the scan it hands every file to need these as well.
        'base64', 'basename', 'chmod', 'cp', 'file', 'mkdir', 'mktemp', 'mv', 'rm', 'sha256sum', 'shasum', 'tr', 'wc',
    ];
}

/**
 * @return array{bin: string, calls: string, directory: string}
 */
function createJiraLoaderFixture(bool $withAcli = true): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-jira-loader-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    $calls = $directory . '/calls';

    mkdir($bin, 0o700, recursive: true);
    file_put_contents($calls, '');

    foreach (jiraLoaderRequiredBinaries() as $binary) {
        $resolved = trim((string) shell_exec('command -v ' . $binary));

        if ($resolved !== '') {
            symlink($resolved, $bin . '/' . $binary);
        }
    }

    if ($withAcli) {
        file_put_contents($bin . '/acli', JIRA_LOADER_ACLI_SCRIPT);
        chmod($bin . '/acli', 0o700);
    }

    return ['bin' => $bin, 'calls' => $calls, 'directory' => $directory];
}

/**
 * @param array{bin: string, calls: string, directory: string} $fixture
 */
function removeJiraLoaderFixture(array $fixture): void
{
    foreach (['acli', ...jiraLoaderRequiredBinaries()] as $binary) {
        if (file_exists($fixture['bin'] . '/' . $binary) || is_link($fixture['bin'] . '/' . $binary)) {
            unlink($fixture['bin'] . '/' . $binary);
        }
    }

    unlink($fixture['calls']);
    rmdir($fixture['bin']);
    rmdir($fixture['directory']);
}

/**
 * @param array{bin: string, calls: string, directory: string} $fixture
 * @param array<string, string> $environment
 */
function runJiraLoader(array $fixture, string $argument, array $environment = [], string $script = 'load-issue.sh'): Process
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/' . $script,
        $argument,
    ], $packageDir, [
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'HOME' => $fixture['directory'],
        'PATH' => $fixture['bin'],
    ] + $environment + [
        'FAKE_ACLI_COMMENTS_JSON' => jiraLoaderCommentsJson(),
        'FAKE_ACLI_VIEW_JSON' => jiraLoaderViewJson(),
    ]);

    $process->run();

    return $process;
}

test('a bare issue key is loaded and returned as a stable issue document', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234');

    $output = $process->getOutput();
    $calls = (string) file_get_contents($fixture['calls']);
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect($calls)->toContain('workitem view ACME-1234');
    expect(decodedJsonField($output, 'key'))->toBe('ACME-1234');
    expect(decodedJsonField($output, 'summary'))->toBe('Upload validation rejects valid PDFs');
    expect(decodedJsonField($output, 'status'))->toBe('In Progress');
    expect(decodedJsonField($output, 'assignee'))->toBe('Maintainer');
});

test('a browse URL resolves to the same key as the bare form', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'https://acme.atlassian.net/browse/ACME-1234');

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'key'))->toBe('ACME-1234');
    expect(decodedJsonField($output, 'url'))->toContain('acme.atlassian.net');
});

test('a selectedIssue query parameter resolves to the key even with other params present', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'https://acme.atlassian.net/jira/software/projects/ACME/boards/7?atlOrigin=abc&selectedIssue=ACME-1234');

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'key'))->toBe('ACME-1234');
});

test('an ADF description is flattened into plain text and the raw tree is preserved', function (): void {
    // The flattening is the loader's own logic, not the CLI's — a consumer reads `descriptionText`
    // and never has to walk an ADF tree itself.
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234');

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    // One newline per ADF block, not the blank line a Markdown reader would expect: `adfText`
    // appends a single "\n" per paragraph and the collapse pass only squeezes runs of two or more.
    expect(decodedJsonField($output, 'descriptionText'))->toBe("A 2 MB PDF is rejected as too large.\nExpected: the upload succeeds.");
    expect(decodedJsonField($output, 'descriptionAdf.type'))->toBe('doc');
    expect(decodedJsonField($output, 'comments.0.author'))->toBe('Maintainer');
    expect(decodedJsonField($output, 'comments.0.body'))->toContain('Reproduced on staging.');
});

test('an argument that is neither a key nor a URL is rejected before any acli call', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'not-a-key');

    $calls = (string) file_get_contents($fixture['calls']);
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(1);
    expect($process->getErrorOutput())->toContain('must be a bare key or a URL');
    expect($calls)->toBe('');
});

test('a missing acli binary exits with the dedicated missing-tool code', function (): void {
    $fixture = createJiraLoaderFixture(withAcli: false);

    $process = runJiraLoader($fixture, 'ACME-1234');

    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(2);
    expect($process->getErrorOutput())->toContain('required tool not found: acli');
});

test('a failed issue fetch exits with the fetch-failure code and emits no partial document', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234', ['FAKE_ACLI_VIEW_OK' => '0']);

    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(3);
    expect($process->getErrorOutput())->toContain('failed to fetch JIRA issue ACME-1234');
    expect($process->getOutput())->toBe('');
});

test('a failed comment fetch degrades to an empty list while the issue still loads', function (): void {
    // `@rules/compound-engineering/tracker.md` *Truncated input is disclosed* rests on this exact
    // behaviour: an empty JIRA `comments[]` is indistinguishable from a failed fetch, so a caller
    // must read it as unverified rather than as "no comments". Pinning it keeps the loader from
    // drifting away from the rule that describes it.
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234', ['FAKE_ACLI_COMMENTS_OK' => '0']);

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'comments'))->toBe([]);
    expect(decodedJsonField($output, 'key'))->toBe('ACME-1234');
});

/**
 * The shape of a real decision comment: a mention, a numbered emoji, and the decision itself
 * written as a bullet list. `comment list` flattens it to "ad . AC7 — „open (human)“ ad …",
 * dropping every list item, so an agent reading only that text sees no decision at all.
 *
 * @return array<string, mixed>
 */
function jiraLoaderDecisionCommentAdf(): array
{
    $decision = ' tuhle část měření v rámci F1 úplně vynecháme. To by mělo být součástí až F2';
    $link = ['marks' => [['attrs' => ['href' => 'https://example.com/f2'], 'type' => 'link']], 'text' => 'plán F2', 'type' => 'text'];
    $nested = ['attrs' => ['order' => 1], 'content' => [jiraLoaderListItem([$link])], 'type' => 'orderedList'];
    $secondItem = jiraLoaderListItem([['text' => 'v F1 půjde čistě o rozdělování kontaktů', 'type' => 'text']]);
    $secondItem['content'][] = $nested;

    return [
        'content' => [
            jiraLoaderParagraph([
                ['attrs' => ['id' => '712020:a114afbb', 'text' => '@Dominik Vondra'], 'type' => 'mention'],
                ['text' => ' děkuju za dotazy:', 'type' => 'text'],
                ['type' => 'hardBreak'],
                ['text' => 'ad ', 'type' => 'text'],
                ['attrs' => ['shortName' => ':1_one_circle_red:', 'text' => ':1_one_circle_red:'], 'type' => 'emoji'],
                ['text' => ' . ', 'type' => 'text'],
                ['marks' => [['type' => 'strong']], 'text' => 'AC7 — „open (human)“', 'type' => 'text'],
            ]),
            ['content' => [jiraLoaderListItem([['text' => $decision, 'type' => 'text']]), $secondItem], 'type' => 'bulletList'],
            ['type' => 'rule'],
            jiraLoaderParagraph([['attrs' => ['url' => 'https://acme.atlassian.net/browse/ACME-1'], 'type' => 'inlineCard']]),
        ],
        'type' => 'doc',
        'version' => 1,
    ];
}

/**
 * @param list<array<string, mixed>> $inline
 * @return array{content: list<array<string, mixed>>, type: string}
 */
function jiraLoaderParagraph(array $inline): array
{
    return ['content' => $inline, 'type' => 'paragraph'];
}

/**
 * @param list<array<string, mixed>> $inline
 * @return array{content: list<array<string, mixed>>, type: string}
 */
function jiraLoaderListItem(array $inline): array
{
    return ['content' => [jiraLoaderParagraph($inline)], 'type' => 'listItem'];
}

/**
 * @return array<string, string>
 */
function jiraLoaderDecisionEnvironment(): array
{
    $view = json_decode(jiraLoaderViewJson(), associative: true);
    assert(is_array($view) && is_array($view['fields']));
    $view['fields']['comment'] = [
        'comments' => [
            ['body' => jiraLoaderDecisionCommentAdf(), 'created' => '2026-09-12T10:00:00.000+0200', 'id' => '116641'],
        ],
        'total' => 1,
    ];

    return [
        'FAKE_ACLI_COMMENTS_JSON' => (string) json_encode([
            'comments' => [
                ['author' => 'Pavel Manda', 'body' => ' děkuju za dotazy: ad   .  AC7 — „open (human)“', 'id' => '116641'],
                ['author' => 'Maintainer', 'body' => 'Flattened text only.', 'id' => '116700'],
            ],
        ]),
        'FAKE_ACLI_VIEW_JSON' => (string) json_encode($view),
    ];
}

test('a comment body is rendered from the view ADF so a decision written as a bullet survives', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234', jiraLoaderDecisionEnvironment());

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'comments.0.id'))->toBe('116641');
    expect(decodedJsonField($output, 'comments.0.author'))->toBe('Pavel Manda');
    expect(decodedJsonField($output, 'comments.0.body'))->toBe(
        "@Dominik Vondra děkuju za dotazy:\n"
        . "ad :1_one_circle_red: . AC7 — „open (human)“\n"
        . "-  tuhle část měření v rámci F1 úplně vynecháme. To by mělo být součástí až F2\n"
        . "- v F1 půjde čistě o rozdělování kontaktů\n"
        . "  1. plán F2 (https://example.com/f2)\n"
        . "---\n"
        . 'https://acme.atlassian.net/browse/ACME-1',
    );
});

test('a comment the view does not embed keeps the flattened comment-list text as its fallback', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234', jiraLoaderDecisionEnvironment());

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect(decodedJsonField($output, 'comments.1.id'))->toBe('116700');
    expect(decodedJsonField($output, 'comments.1.body'))->toBe('Flattened text only.');
});

test('a view embedding fewer comments than the issue carries is disclosed on stderr', function (): void {
    $fixture = createJiraLoaderFixture();
    $environment = jiraLoaderDecisionEnvironment();
    $environment['FAKE_ACLI_VIEW_JSON'] = str_replace('"total":1', '"total":2', $environment['FAKE_ACLI_VIEW_JSON']);

    $process = runJiraLoader($fixture, 'ACME-1234', $environment);

    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect($process->getErrorOutput())->toContain('embedded 1 fewer comments than ACME-1234 carries');
});

test('parse-comments exposes the comment id and the ADF-rendered body', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234', jiraLoaderDecisionEnvironment(), 'parse-comments.sh');

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, '0.id'))->toBe('116641');
    expect(decodedJsonField($output, '0.body'))->toContain('v rámci F1 úplně vynecháme');
    expect(decodedJsonField($output, '1.id'))->toBe('116700');
});

test('a date node renders its day, and an unrenderable timestamp degrades to its raw value', function (): void {
    $fixture = createJiraLoaderFixture();
    $view = json_decode(jiraLoaderViewJson(), associative: true);
    assert(is_array($view) && is_array($view['fields']));
    $dates = [];

    foreach (['1700000000000', '1e25', '-99999999999999999999'] as $timestamp) {
        $dates[] = ['attrs' => ['timestamp' => $timestamp], 'type' => 'date'];
    }

    $body = ['content' => [jiraLoaderParagraph($dates)], 'type' => 'doc', 'version' => 1];
    $view['fields']['comment'] = ['comments' => [['body' => $body, 'id' => '10001']]];

    $process = runJiraLoader($fixture, 'ACME-1234', ['FAKE_ACLI_VIEW_JSON' => (string) json_encode($view)]);

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'comments.0.body'))->toBe('2023-11-141e25-99999999999999999999');
});

/**
 * The shallow subtask reference the parent view embeds; the loader fetches the subtask itself by key.
 *
 * @return array<string, mixed>
 */
function jiraLoaderSubtaskReference(): array
{
    return ['fields' => ['issuetype' => ['name' => 'Sub-task'], 'status' => ['name' => 'Open'], 'summary' => 'Sub'], 'key' => 'ACME-2'];
}

/**
 * @return array<string, mixed>
 */
function jiraLoaderMediaNode(string $alt): array
{
    return ['attrs' => ['alt' => $alt, 'id' => 'media-' . $alt, 'type' => 'file'], 'type' => 'media'];
}

/**
 * @param list<string> $alts
 * @return array<string, mixed>
 */
function jiraLoaderMediaDoc(array $alts): array
{
    return [
        'content' => array_map(
            static fn (string $alt): array => ['content' => [jiraLoaderMediaNode($alt)], 'type' => 'mediaSingle'],
            $alts,
        ),
        'type' => 'doc',
        'version' => 1,
    ];
}

/**
 * @return array{content: string, filename: string, id: int, mimeType: string}
 */
function jiraLoaderAttachment(int $id, string $filename): array
{
    return [
        'content' => 'https://acme.atlassian.net/rest/api/3/attachment/content/' . $id,
        'filename' => $filename,
        'id' => $id,
        'mimeType' => 'text/plain',
    ];
}

/**
 * The issue view the pasted-file tests share: a comment carrying a screenshot and a log, where the
 * log filename belongs to two attachments and so joins to none.
 *
 * @return array<mixed, mixed>
 */
function jiraLoaderPastedFileView(): array
{
    $view = json_decode(jiraLoaderViewJson(), associative: true);
    assert(is_array($view) && is_array($view['fields']));
    $view['fields']['attachment'] = [
        jiraLoaderAttachment(10_500, 'error.png'),
        jiraLoaderAttachment(10_501, 'log.txt'),
        jiraLoaderAttachment(10_502, 'log.txt'),
    ];
    $body = [
        'content' => [
            jiraLoaderParagraph([['text' => 'The upload fails:', 'type' => 'text']]),
            ['content' => [jiraLoaderMediaNode('error.png')], 'type' => 'mediaSingle'],
            ['content' => [jiraLoaderMediaNode('log.txt')], 'type' => 'mediaGroup'],
        ],
        'type' => 'doc',
        'version' => 1,
    ];
    $view['fields']['comment'] = ['comments' => [['body' => $body, 'id' => '10001']]];

    return $view;
}

test('a file pasted into a comment renders in place and joins to its issue attachment', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234', ['FAKE_ACLI_VIEW_JSON' => (string) json_encode(jiraLoaderPastedFileView())]);

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'comments.0.body'))->toBe("The upload fails:\n[attachment: error.png]\n[attachment: log.txt]");
    expect(decodedJsonField($output, 'comments.0.mediaRefs'))->toBe([
        ['adfId' => 'media-error.png', 'altText' => 'error.png', 'attachmentId' => '10500'],
        ['adfId' => 'media-log.txt', 'altText' => 'log.txt', 'attachmentId' => null],
    ]);
});

test('parse-comments passes the media references of a comment through', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader(
        $fixture,
        'ACME-1234',
        ['FAKE_ACLI_VIEW_JSON' => (string) json_encode(jiraLoaderPastedFileView())],
        'parse-comments.sh',
    );

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, '0.mediaRefs'))->toBe([
        ['adfId' => 'media-error.png', 'altText' => 'error.png', 'attachmentId' => '10500'],
        ['adfId' => 'media-log.txt', 'altText' => 'log.txt', 'attachmentId' => null],
    ]);
});

test('a file pasted into a subtask description joins to the subtask attachment', function (): void {
    $fixture = createJiraLoaderFixture();
    $view = json_decode(jiraLoaderViewJson(), associative: true);
    assert(is_array($view) && is_array($view['fields']));
    $view['fields']['subtasks'] = [jiraLoaderSubtaskReference()];
    $subtask = ['fields' => [
        'attachment' => [jiraLoaderAttachment(10_600, 'sub-notes.txt')],
        'description' => jiraLoaderMediaDoc(['sub-notes.txt']),
    ],
    ];

    $process = runJiraLoader($fixture, 'ACME-1234', [
        'FAKE_ACLI_VIEW_JSON' => (string) json_encode($view),
        'FAKE_ACLI_VIEW_JSON_ACME_2' => (string) json_encode($subtask),
    ]);

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'subtasks.0.descriptionMediaRefs'))->toBe([
        ['adfId' => 'media-sub-notes.txt', 'altText' => 'sub-notes.txt', 'attachmentId' => '10600'],
    ]);
});

test('the download manifest names the description, comment, or subtask description that references each file', function (): void {
    $fixture = createJiraLoaderFixture();
    $scratch = sys_get_temp_dir() . '/ai-olympus-jira-scratch-' . bin2hex(random_bytes(6));
    // The download is the one network call; a fake curl writes the file instead of fetching it.
    file_put_contents($fixture['bin'] . '/curl', <<<'BASH'
        #!/usr/bin/env bash
        while [[ $# -gt 0 ]]; do
          if [[ "$1" == "--output" ]]; then printf 'plain text' > "$2"; fi
          shift
        done
        BASH);
    chmod($fixture['bin'] . '/curl', 0o700);

    $view = json_decode(jiraLoaderViewJson(), associative: true);
    assert(is_array($view) && is_array($view['fields']));
    $view['fields']['attachment'] = [
        jiraLoaderAttachment(10_500, 'notes.txt'),
        jiraLoaderAttachment(10_501, 'log.txt'),
        jiraLoaderAttachment(10_502, 'other.txt'),
    ];
    $view['fields']['description'] = jiraLoaderMediaDoc(['notes.txt']);
    $view['fields']['comment'] = ['comments' => [['body' => jiraLoaderMediaDoc(['log.txt']), 'id' => '10001']]];
    $view['fields']['subtasks'] = [jiraLoaderSubtaskReference()];
    $subtask = ['fields' => [
        'attachment' => [jiraLoaderAttachment(10_600, 'sub-notes.txt')],
        'description' => jiraLoaderMediaDoc(['sub-notes.txt']),
    ],
    ];

    $process = runJiraLoader($fixture, 'ACME-1234', [
        'CLAUDE_SCRATCHPAD_DIR' => $scratch,
        'FAKE_ACLI_VIEW_JSON' => (string) json_encode($view),
        'FAKE_ACLI_VIEW_JSON_ACME_2' => (string) json_encode($subtask),
        'JIRA_API_EMAIL' => 'bot@acme.test',
        'JIRA_API_TOKEN' => 'secret',
    ], 'download-attachments.sh');

    $manifest = (string) file_get_contents($scratch . '/attachments/jira/attachments-manifest.json');
    (new Process(['rm', '-rf', $scratch]))->run();
    unlink($fixture['bin'] . '/curl');
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);

    $entries = array_map(
        static fn (int $index): array => [
            decodedJsonField($manifest, 'attachments.' . $index . '.name'),
            decodedJsonField($manifest, 'attachments.' . $index . '.referencedBy'),
        ],
        range(0, 3),
    );

    expect($entries)->toBe([
        ['notes.txt', ['description']],
        ['log.txt', ['comment:10001']],
        ['other.txt', []],
        ['sub-notes.txt', ['ACME-2:description']],
    ]);
});
