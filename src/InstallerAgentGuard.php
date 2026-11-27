<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use stdClass;

final class InstallerAgentGuard
{

    public const HOOK_COMMAND = '"$CLAUDE_PROJECT_DIR"/.claude/skills/_shared/agent-guard.sh hook';

    public const INSTALLED_SCRIPT = '.claude/skills/_shared/agent-guard.sh';

    private const HOOK_EVENTS = ['PreToolUse', 'SessionEnd'];

    private const HOOK_MATCHER = '*';

    private const HOOK_TIMEOUT_SECONDS = 10;

    private const OWN_HANDLER_PATTERN = '~/\.claude/skills/_shared/agent-guard\.sh hook$~';

    public static function apply(bool $disabled, string $projectRoot): AgentGuardOutcome
    {
        if ($disabled) {
            return self::remove($projectRoot) > 0 ? AgentGuardOutcome::Removed : AgentGuardOutcome::Absent;
        }

        if (!self::selfTestPasses($projectRoot . '/' . self::INSTALLED_SCRIPT)) {
            return AgentGuardOutcome::SelfTestFailed;
        }

        return self::register($projectRoot) ? AgentGuardOutcome::Registered : AgentGuardOutcome::Unchanged;
    }

    public static function selfTestPasses(string $script): bool
    {
        if (!is_file($script)) {
            return false;
        }

        $output = [];
        exec('bash ' . escapeshellarg($script) . ' --self-test 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }

    public static function register(string $projectRoot): bool
    {
        $settingsPath = InstallerProjectSettings::resolveProjectLocalSettingsPath($projectRoot);
        $existing = InstallerSettingsFile::read($settingsPath);

        if (self::isCurrent($existing)) {
            return false;
        }

        InstallerHookHandlers::strip($existing, self::isOwnHandler(...));

        foreach (self::HOOK_EVENTS as $event) {
            InstallerHookHandlers::appendGroup($existing, $event, self::group());
        }

        InstallerPath::ensureDirectory(dirname($settingsPath));
        InstallerSettingsFile::write($settingsPath, $existing);

        self::validateRegistered(InstallerSettingsFile::read($settingsPath), $settingsPath);

        return true;
    }

    public static function validateRegistered(stdClass $settings, string $path): void
    {
        if (!self::isCurrent($settings)) {
            throw InstallerFailure::settingsAgentGuardInvalid($path);
        }
    }

    public static function remove(string $projectRoot): int
    {
        $settingsPath = InstallerProjectSettings::resolveProjectLocalSettingsPath($projectRoot);
        $existing = InstallerSettingsFile::read($settingsPath);
        $removed = InstallerHookHandlers::strip($existing, self::isOwnHandler(...));

        if ($removed > 0) {
            InstallerSettingsFile::write($settingsPath, $existing);
        }

        return $removed;
    }

    public static function handler(): stdClass
    {
        $handler = new stdClass();
        $handler->type = 'command';
        $handler->command = self::HOOK_COMMAND;
        $handler->timeout = self::HOOK_TIMEOUT_SECONDS;
        $handler->onFailure = 'block';

        return $handler;
    }

    private static function group(): stdClass
    {
        $group = new stdClass();
        $group->matcher = self::HOOK_MATCHER;
        $group->hooks = [self::handler()];

        return $group;
    }

    private static function isCurrent(stdClass $settings): bool
    {
        $events = [];

        foreach (InstallerHookHandlers::find($settings, self::isOwnHandler(...)) as $entry) {
            if ($entry['matcher'] !== self::HOOK_MATCHER
                || json_encode($entry['handler'], JSON_THROW_ON_ERROR) !== json_encode(self::handler(), JSON_THROW_ON_ERROR)
            ) {
                return false;
            }

            $events[] = $entry['event'];
        }

        sort($events);

        return $events === self::HOOK_EVENTS;
    }

    private static function isOwnHandler(mixed $handler): bool
    {
        $command = $handler instanceof stdClass ? $handler->command ?? null : null;

        return is_string($command) && preg_match(self::OWN_HANDLER_PATTERN, trim($command)) === 1;
    }

}
