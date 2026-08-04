<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use RuntimeException;

final class InstallerFailure extends RuntimeException
{

    public static function missingSource(string $developmentPath, string $vendorPath): self
    {
        return new self(sprintf('Source not found. Checked %s and %s.', $developmentPath, $vendorPath));
    }

    public static function directoryCreationFailed(string $directory): self
    {
        return new self(sprintf('Cannot create directory: %s', $directory));
    }

    public static function fileCopyFailed(string $source, string $destination): self
    {
        return new self(sprintf('Unable to copy %s to %s.', $source, $destination));
    }

    public static function removalFailed(string $path): self
    {
        return new self(sprintf('Cannot remove: %s', $path));
    }

    public static function settingsJsonInvalid(string $path, string $reason): self
    {
        return new self(sprintf('Cannot parse Claude settings file %s: %s.', $path, $reason));
    }

    public static function settingsJsonWriteFailed(string $path, string $reason): self
    {
        return new self(sprintf('Cannot write Claude settings file %s: %s.', $path, $reason));
    }

    public static function settingsSubagentWritesInvalid(string $path, string $reason): self
    {
        return new self(sprintf('Invalid subagent-write permissions for %s: %s.', $path, $reason));
    }

    public static function settingsNetworkBashDenyInvalid(string $path, string $reason): self
    {
        return new self(sprintf('Invalid network-Bash deny permissions for %s: %s.', $path, $reason));
    }

    public static function settingsAgentBashBoundaryHookInvalid(string $path, string $reason): self
    {
        return new self(sprintf('Invalid agent Bash boundary hook for %s: %s.', $path, $reason));
    }

    public static function bashGuardBinaryMissing(string $projectCandidate, string $packageCandidate): self
    {
        return new self(sprintf(
            'Cannot enforce the agent Bash boundary: no agent-skills binary found. Checked %s and %s.',
            $projectCandidate,
            $packageCandidate,
        ));
    }

    public static function bashGuardCommandUnquotable(string $binary): self
    {
        return new self(sprintf(
            'Cannot enforce the agent Bash boundary: the path %s holds a quote or a newline and cannot be written as a hook command.',
            $binary,
        ));
    }

    public static function bashGuardUnverifiable(string $binary): self
    {
        return new self(sprintf(
            'Cannot enforce the agent Bash boundary: %s could not be test-run, so the hook would report a protection it may not provide.',
            $binary,
        ));
    }

    public static function bashGuardSmokeTestFailed(string $binary, string $reason): self
    {
        return new self(sprintf('The agent Bash boundary validator %s failed its install-time check: %s.', $binary, $reason));
    }

    public static function issueListUnparsable(string $reason): self
    {
        return new self(sprintf('The GitHub CLI returned an issue list that could not be read: %s.', $reason));
    }

}
