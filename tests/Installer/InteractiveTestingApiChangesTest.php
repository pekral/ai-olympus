<?php

declare(strict_types = 1);

test('the interactive-testing skill exercises a backend API change through a safe HTTP client', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/interactive-testing/SKILL.md');

    expect($skill)->toContain('## Backend API changes')
        ->toContain('The diff changes a backend API when it adds, changes, or removes an HTTP route, a controller action, request validation, a response shape, or a status code.')
        ->toContain('Exercise each changed endpoint directly with `curl` or the project\'s own HTTP client, against the running instance.')
        ->toContain('When no page of the application calls the endpoint, the HTTP requests are the walkthrough')
        ->toContain('- **Local or test target only.**')
        ->toContain('`@rules/security/general.md` *Production is never touched*')
        ->toContain('`@rules/security/general.md` *Environment values are never read*')
        ->toContain('- **Test credentials only.** Take an account, a password, or a token only from the project\'s seed or example configuration')
        ->toContain('- **TLS verification stays on.**')
        ->toContain('**Record each request and response.**')
        ->toContain('10. When the diff changes a backend API, exercise each changed endpoint through a safe HTTP client, as *Backend API changes* states.');
});

test('raphael points to the safety limits of the interactive-testing API section', function (): void {
    $raphael = (string) file_get_contents(dirname(__DIR__, 2) . '/agents/raphael.md');

    expect($raphael)->toContain('Every request stays inside the safety limits of `@skills/interactive-testing/SKILL.md` *Backend API changes*');
});
