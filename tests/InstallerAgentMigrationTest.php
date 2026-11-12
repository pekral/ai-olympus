<?php

declare(strict_types = 1);

use Pekral\AiOlympus\InstallerAgentMigration;
use Pekral\AiOlympus\InstallerPath;

function agentMigrationFixtureDirectory(): string
{
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/agents/donatello.md', 'replacement');

    return $root;
}

test('agent migration recognizes unchanged definitions with Windows line endings', function (): void {
    $root = agentMigrationFixtureDirectory();
    installerWriteFile(
        $root . '/agents/hephaestus.md',
        str_replace("\n", "\r\n", (string) file_get_contents(__DIR__ . '/Fixtures/legacy-agents/hephaestus.md')),
    );

    try {
        $removed = InstallerAgentMigration::removeKnownCopies((string) InstallerPath::resolveAgentsSource(), $root . '/agents');

        expect($removed)->toBe(1);
        expect(file_exists($root . '/agents/hephaestus.md'))->toBeFalse();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('agent migration removes a dangling link only when it points at the previous package definition', function (): void {
    $root = agentMigrationFixtureDirectory();
    symlink((string) InstallerPath::resolveAgentsSource() . '/hephaestus.md', $root . '/agents/hephaestus.md');

    try {
        $removed = InstallerAgentMigration::removeKnownCopies((string) InstallerPath::resolveAgentsSource(), $root . '/agents');

        expect($removed)->toBe(1);
        expect(is_link($root . '/agents/hephaestus.md'))->toBeFalse();
    } finally {
        installerRemoveDirectory($root);
    }
})->skip(installerSymlinkUnsupported(), 'Symlinks are not supported on this platform');

test('agent migration preserves foreign symlinks even when their content matches a package definition', function (): void {
    $root = agentMigrationFixtureDirectory();
    symlink(__DIR__ . '/Fixtures/legacy-agents/hephaestus.md', $root . '/agents/hephaestus.md');

    try {
        $removed = InstallerAgentMigration::removeKnownCopies((string) InstallerPath::resolveAgentsSource(), $root . '/agents');

        expect($removed)->toBe(0);
        expect(is_link($root . '/agents/hephaestus.md'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
})->skip(installerSymlinkUnsupported(), 'Symlinks are not supported on this platform');

test('agent migration leaves a known old copy when its replacement has not been installed', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/agents/hephaestus.md', (string) file_get_contents(__DIR__ . '/Fixtures/legacy-agents/hephaestus.md'));

    try {
        $removed = InstallerAgentMigration::removeKnownCopies((string) InstallerPath::resolveAgentsSource(), $root . '/agents');

        expect($removed)->toBe(0);
        expect(is_file($root . '/agents/hephaestus.md'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('agent migration does not cross a symlinked target or its parent', function (string $target): void {
    $root = agentMigrationFixtureDirectory();
    installerWriteFile($root . '/agents/hephaestus.md', (string) file_get_contents(__DIR__ . '/Fixtures/legacy-agents/hephaestus.md'));
    symlink($root, $root . '/linked');

    try {
        $removed = InstallerAgentMigration::removeKnownCopies((string) InstallerPath::resolveAgentsSource(), $root . '/' . $target);

        expect($removed)->toBe(0);
        expect(is_file($root . '/agents/hephaestus.md'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
})->with(['linked', 'linked/agents'])->skip(installerSymlinkUnsupported(), 'Symlinks are not supported on this platform');

test('agent migration ignores sources other than the package agent directories', function (): void {
    $root = agentMigrationFixtureDirectory();
    installerWriteFile($root . '/agents/hephaestus.md', (string) file_get_contents(__DIR__ . '/Fixtures/legacy-agents/hephaestus.md'));

    try {
        $removed = InstallerAgentMigration::removeKnownCopies($root, $root . '/agents');

        expect($removed)->toBe(0);
        expect(is_file($root . '/agents/hephaestus.md'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('agent migration never removes the splinter security agent, old or new, because the package ships it again', function (string $content): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/agents/krang.md', 'replacement');
    installerWriteFile($root . '/agents/splinter.md', $content);

    try {
        $removed = InstallerAgentMigration::removeKnownCopies((string) InstallerPath::resolveAgentsSource(), $root . '/agents');

        expect($removed)->toBe(0);
        expect(is_file($root . '/agents/splinter.md'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
})->with([
    'the security agent definition of this package' => fn (): string => (string) file_get_contents(__DIR__ . '/../agents/splinter.md'),
    'the unmodified 0.3.1 orchestrator definition' => fn (): string => (string) file_get_contents(__DIR__ . '/Fixtures/legacy-agents/splinter.md'),
]);
