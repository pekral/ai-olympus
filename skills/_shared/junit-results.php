#!/usr/bin/env php
<?php

declare(strict_types = 1);

const JUNIT_USAGE = <<<'TXT'
Usage: junit-results.php [--root <dir>] <junit.xml>

Prints one tab-separated line per <testcase> of a JUnit XML report, as Pest and PHPUnit write it
with --log-junit: <status> TAB <file> TAB <name> TAB <message>.

  status   passed, failed, errored, or skipped
  file     the test file. Pest writes `<file>::<name>` into the file attribute, so the part from
           `::` on is dropped. An absolute path below --root becomes relative to it.
  name     the name attribute: `it <description>` for a Pest it(), the description for a Pest
           test(), the method name for a PHPUnit test
  message  the first three lines of the failure or error text, joined by ` | `, at most 300
           characters; empty for a test that passed or was skipped

Every field is data from a test run. Control characters, tabs, and line breaks are removed from
each one, so a field never splits a line or reaches a terminal as an escape sequence. A report
with a document type declaration is refused in every encoding, so no entity is ever expanded.

exit: 0 the report was read, 1 usage error, 2 unreadable or malformed report

TXT;

const JUNIT_MESSAGE_LINES = 3;
const JUNIT_MESSAGE_LIMIT = 300;
const JUNIT_FIELD_LIMIT = 500;
const JUNIT_DOCTYPE_REFUSAL = 'the report carries a document type declaration';

final readonly class JunitCase
{

    public function __construct(public string $status, public string $file, public string $name, public string $message)
    {
    }

    public function line(): string
    {
        return implode("\t", [$this->status, $this->file, $this->name, $this->message]);
    }

}

final class UnreadableJunitReport extends RuntimeException
{

}

function junitClean(string $text, int $limit): string
{
    $text = mb_scrub($text, 'UTF-8');
    $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);

    return mb_substr(trim($text), 0, $limit);
}

function junitMessage(string $text): string
{
    $lines = array_filter(array_map(trim(...), explode("\n", $text)), static fn (string $line): bool => $line !== '');
    $lines = array_slice(array_values($lines), 0, JUNIT_MESSAGE_LINES);

    return junitClean(implode(' | ', $lines), JUNIT_MESSAGE_LIMIT);
}

function junitFile(string $attribute, string $root): string
{
    $separator = strpos($attribute, '::');
    $file = $separator === false ? $attribute : substr($attribute, 0, $separator);

    if ($root !== '' && str_starts_with($file, $root . '/')) {
        $file = substr($file, strlen($root) + 1);
    }

    return junitClean($file, JUNIT_FIELD_LIMIT);
}

function junitCase(DOMElement $testcase, string $root): JunitCase
{
    $status = 'passed';
    $message = '';

    foreach ($testcase->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $outcome = match ($child->tagName) {
            'failure' => 'failed',
            'error' => 'errored',
            'skipped' => 'skipped',
            default => null,
        };

        if ($outcome === null) {
            continue;
        }

        $status = $outcome;
        $message = $outcome === 'skipped' ? '' : junitMessage($child->getAttribute('message') . "\n" . $child->textContent);

        break;
    }

    return new JunitCase(
        $status,
        junitFile($testcase->getAttribute('file'), $root),
        junitClean($testcase->getAttribute('name'), JUNIT_FIELD_LIMIT),
        $message,
    );
}

/**
 * @return list<\JunitCase>
 */
function junitRead(string $path, string $root): array
{
    $xml = is_file($path) ? file_get_contents($path) : false;

    if (!is_string($xml) || trim($xml) === '') {
        throw new UnreadableJunitReport('the report is missing or empty');
    }

    if (stripos($xml, '<!DOCTYPE') !== false) {
        throw new UnreadableJunitReport(JUNIT_DOCTYPE_REFUSAL);
    }

    libxml_use_internal_errors(use_errors: true);
    $document = new DOMDocument();

    if (!$document->loadXML($xml, LIBXML_NONET)) {
        throw new UnreadableJunitReport('the report is not well-formed XML');
    }

    if ($document->doctype !== null) {
        throw new UnreadableJunitReport(JUNIT_DOCTYPE_REFUSAL);
    }

    $cases = [];

    foreach ($document->getElementsByTagName('testcase') as $testcase) {
        $cases[] = junitCase($testcase, $root);
    }

    return $cases;
}

/**
 * @param list<string> $arguments
 */
function junitMain(array $arguments): int
{
    $root = '';

    if (($arguments[0] ?? null) === '--root') {
        $root = rtrim($arguments[1] ?? '', '/');
        $arguments = array_slice($arguments, 2);
    }

    if (count($arguments) !== 1 || str_starts_with($arguments[0], '-')) {
        fwrite(STDERR, JUNIT_USAGE);

        return 1;
    }

    try {
        $cases = junitRead($arguments[0], $root);
    } catch (UnreadableJunitReport $error) {
        fwrite(STDERR, 'junit-results.php: ' . $error->getMessage() . "\n");

        return 2;
    }

    foreach ($cases as $case) {
        fwrite(STDOUT, $case->line() . "\n");
    }

    return 0;
}

exit(junitMain(array_values(array_slice($argv ?? [], 1))));
