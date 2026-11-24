<?php

declare(strict_types = 1);

function agentGuardPackageFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the manifest table documents the strict-mode key and that only the default branch sets it', function (): void {
    $manifest = installerDocsSection(agentGuardPackageFile('rules/general/general.md'), '## Project manifest');

    expect($manifest)->toContain('| `security.strict-agent-guard` | bool |')
        ->and($manifest)->toContain('The guard reads it from the default branch only, through `skills/_shared/read-manifest.sh`.');
});
