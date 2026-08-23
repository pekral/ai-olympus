<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

/**
 * @codeCoverageIgnore
 */
final class ComposerPlugin implements EventSubscriberInterface, PluginInterface
{

    private ?Composer $composer = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Required by PluginInterface
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Required by PluginInterface
    }

    public function runInstaller(): void
    {
        if (!$this->isAutoInstallEnabled()) {
            return;
        }

        Installer::run(['ai-olympus', 'install', '--force']);
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'runInstaller',
            ScriptEvents::POST_UPDATE_CMD => 'runInstaller',
        ];
    }

    private function isAutoInstallEnabled(): bool
    {
        $config = $this->getAiOlympusConfig();

        return ($config['auto-install'] ?? false) === true;
    }

    /**
     * @return array<mixed>
     */
    private function getAiOlympusConfig(): array
    {
        if ($this->composer === null) {
            return [];
        }

        $extra = $this->composer->getPackage()->getExtra();
        $config = $extra['ai-olympus'] ?? [];

        return is_array($config) ? array_change_key_case($config, CASE_LOWER) : [];
    }

}
