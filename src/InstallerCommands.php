<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final class InstallerCommands
{

    /**
     * @return list<array{string, array<int, string>, array<int, string>}>
     */
    public static function payloads(string $root, ?string $source, bool $global = false): array
    {
        if ($source === null) {
            return [];
        }

        $files = InstallerPruner::listSourceFiles($source);
        $public = array_values(array_filter(
            $files,
            static fn (string $path): bool => dirname($path) === '.' && pathinfo($path, PATHINFO_EXTENSION) === 'md',
        ));
        $support = array_values(array_filter($files, static fn (string $path): bool => str_starts_with($path, '_shared/')));
        $payloads = [
            [$source, InstallerPath::resolveCommandsTargetDirectories($root), $public],
            [$source, InstallerPath::resolveCommandSupportTargetDirectories($root), $support],
            [$source, InstallerPath::resolveCodexCommandsTargetDirectories($root), $files],
        ];

        if ($global) {
            foreach (InstallerPath::resolveHomeSkillsDirectories() as $homeSkills) {
                $payloads[] = [$source, [dirname($homeSkills) . '/ai-olympus-commands'], $files];
            }
        }

        return $payloads;
    }

}
