<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

enum HelperAgentsDefaultOutcome
{

    case Written;
    case Unchanged;
    case FormatKept;
    case WriteFailed;

    private const LOCK_NOTE = ' composer.lock is now out of date, because its content-hash covers extra:'
        . ' run `composer update --lock` to refresh the hash without updating a package.';

    public function message(bool $lockFileExists): string
    {
        $missingKey = 'composer.json has no extra.ai-olympus.helper-agents key';
        $lock = $lockFileExists ? self::LOCK_NOTE : '';

        return match ($this) {
            self::Written => 'Added "helper-agents": true to extra.ai-olympus in composer.json, so helper agents stay allowed;'
                . ' set it to false to forbid them. Commit the change.' . $lock . PHP_EOL,
            self::FormatKept => $missingKey . '. The installer kept the file unchanged to keep its formatting;'
                . ' add "helper-agents": true there yourself.' . PHP_EOL,
            self::WriteFailed => $missingKey . ', and the installer cannot write the file; add "helper-agents": true there yourself.' . PHP_EOL,
            self::Unchanged => '',
        };
    }

}
