<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

enum AgentGuardOutcome: string
{

    case Registered = 'registered';
    case Unchanged = 'unchanged';
    case SelfTestFailed = 'self-test-failed';
    case Removed = 'removed';
    case Absent = 'absent';

    public function installMessage(): string
    {
        return match ($this) {
            self::Registered => 'Registered the runtime agent guard (PreToolUse hook) in .claude/settings.local.json. '
                . 'Undo with --no-agent-guard.' . PHP_EOL,
            self::SelfTestFailed => 'The runtime agent guard was not registered: its self-test failed. '
                . 'Read-only agents run without runtime protection - see SECURITY.md.' . PHP_EOL,
            self::Removed => 'Removed the runtime agent guard hook from .claude/settings.local.json (--no-agent-guard).' . PHP_EOL,
            self::Unchanged, self::Absent => '',
        };
    }

}
