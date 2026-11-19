<?php

declare(strict_types = 1);

test('the interactive-testing skill exercises a backend API change through a safe HTTP client', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/interactive-testing/SKILL.md');

    expect($skill)->toContain('## Backend API changes')
        ->toContain('The diff changes a backend API when it adds, changes, or removes an HTTP route, a controller action, request validation, a response shape, or a status code.')
        ->toContain('with the cases and the request and response record that `agents/raphael.md` step 4 *API surface → a real HTTP client against the running instance* defines')
        ->toContain('When no page calls the endpoint, the HTTP requests are the whole walkthrough')
        ->toContain('The one exception to the browser walkthrough is a backend API change that no page of the application reaches.')
        ->toContain('- For every change that reaches a page, the agent itself operated the interactive browser and observed the results.')
        ->toContain('- **Local or test target only.**')
        ->toContain('`@rules/security/general.md` *Production is never touched*')
        ->toContain('`@rules/security/general.md` *Environment values are never read*')
        ->toContain('- **Test credentials only.** Take an account, a password, or a token only from the project\'s seed or example configuration')
        ->toContain('- **TLS verification stays on.**')
        ->toContain('**Redact the record.**')
        ->toContain('Replace every credential and secret in the request and the response with `<redacted>`')
        ->toContain('Never record a debug or error page body verbatim: record the status code and the exception class only.')
        ->toContain('trust the local development CA with `--cacert` first')
        ->toContain('10. When the diff changes a backend API, exercise each changed endpoint through a safe HTTP client, as *Backend API changes* states.');
});

test('raphael points to the safety limits of the interactive-testing API section', function (): void {
    $raphael = (string) file_get_contents(dirname(__DIR__, 2) . '/agents/raphael.md');

    expect($raphael)->toContain('Every request stays inside the safety limits, and every record follows the redaction, of `@skills/interactive-testing/SKILL.md` *Backend API changes*.')
        ->toContain('**API surface → a real HTTP client against the running instance.**')
        ->toContain('**Skip the browser only when the change has no path to any page**');
});
