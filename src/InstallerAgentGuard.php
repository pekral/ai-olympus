<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use stdClass;

final class InstallerAgentGuard
{

    public const HOOK_COMMAND = '"$CLAUDE_PROJECT_DIR"/.claude/skills/_shared/agent-guard.sh hook';

    public const INSTALLED_SCRIPT = '.claude/skills/_shared/agent-guard.sh';

    private const HOOK_EVENT = 'PreToolUse';

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
        $own = InstallerHookHandlers::find($existing, self::HOOK_EVENT, self::isOwnHandler(...));

        if (count($own) === 1 && self::isCurrent($own[0])) {
            return false;
        }

        InstallerHookHandlers::strip($existing, self::isOwnHandler(...));
        InstallerHookHandlers::appendGroup($existing, self::HOOK_EVENT, self::group());
        InstallerPath::ensureDirectory(dirname($settingsPath));
        InstallerSettingsFile::write($settingsPath, $existing);

        self::validateRegistered(InstallerSettingsFile::read($settingsPath), $settingsPath);

        return true;
    }

    public static function validateRegistered(stdClass $settings, string $path): void
    {
        $written = InstallerHookHandlers::find($settings, self::HOOK_EVENT, self::isOwnHandler(...));

        if (count($written) !== 1 || !self::isCurrent($written[0])) {
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
        $group->matcher = 'Bash';
        $group->hooks = [self::handler()];

        return $group;
    }

    private static function isCurrent(mixed $handler): bool
    {
        return json_encode($handler, JSON_THROW_ON_ERROR) === json_encode(self::handler(), JSON_THROW_ON_ERROR);
    }

    private static function isOwnHandler(mixed $handler): bool
    {
        $command = $handler instanceof stdClass ? $handler->command ?? null : null;

        return is_string($command) && preg_match(self::OWN_HANDLER_PATTERN, trim($command)) === 1;
    }

}
