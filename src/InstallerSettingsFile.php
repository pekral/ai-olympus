<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use JsonException;
use stdClass;

/**
 * The read/write round-trip for a Claude Code settings file.
 *
 * Extracted from `InstallerClaudeSettings` unchanged so a second writer — the `PreToolUse` hook
 * entry `--enforce-agent-bash-boundary` registers — can reuse it instead of duplicating it. That
 * matters more here than anywhere else in the installer: both writers touch security-relevant
 * configuration, and a second copy of the `stdClass` handling below is exactly the kind of drift
 * that silently corrupts a settings file on one path and not the other. Splitting the remaining
 * concerns of `InstallerClaudeSettings` is issue #202 and is not attempted here.
 */
final class InstallerSettingsFile
{

    /**
     * Decodes settings into a `stdClass` object (not an associative array) so that
     * empty JSON objects (`{}`) elsewhere in the file survive the read/write round-trip.
     * `json_decode(..., true)` would turn `{}` into `[]`, which `json_encode` then writes
     * back as a JSON array — corrupting object-typed keys such as Claude Code's
     * `attribution` and tripping `/doctor`'s schema validation.
     */
    public static function read(string $path): stdClass
    {
        if (!is_file($path)) {
            return new stdClass();
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return new stdClass();
        }

        try {
            $data = json_decode($contents, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InstallerFailure::settingsJsonInvalid($path, $exception->getMessage());
        }

        if (!$data instanceof stdClass) {
            throw InstallerFailure::settingsJsonInvalid($path, 'top-level value is not an object');
        }

        return $data;
    }

    public static function write(string $path, stdClass $data): void
    {
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            // @codeCoverageIgnoreStart
        } catch (JsonException $exception) {
            throw InstallerFailure::settingsJsonWriteFailed($path, $exception->getMessage());
        }

        // @codeCoverageIgnoreEnd

        set_error_handler(static fn (): bool => true);
        $written = file_put_contents($path, $json . "\n");
        restore_error_handler();

        if ($written === false) {
            throw InstallerFailure::settingsJsonWriteFailed($path, 'file_put_contents returned false');
        }
    }

}
