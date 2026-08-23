<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final readonly class InstallOptions
{

    public function __construct(
        public bool $force,
        public bool $symlink,
        public bool $prune,
        public bool $allowBundledScripts,
        public bool $allowSubagentWrites,
        public bool $denyNetworkBash,
        public bool $global,
        public bool $pruneGlobal,
    ) {
    }

    /**
     * @param array<int, string> $argv
     */
    public static function fromArgv(array $argv): self
    {
        return new self(
            force: in_array('--force', $argv, strict: true),
            symlink: in_array('--symlink', $argv, strict: true),
            prune: in_array('--prune', $argv, strict: true),
            allowBundledScripts: in_array('--allow-bundled-scripts', $argv, strict: true),
            allowSubagentWrites: in_array('--allow-subagent-writes', $argv, strict: true),
            denyNetworkBash: in_array('--deny-network-bash', $argv, strict: true),
            global: in_array('--global', $argv, strict: true),
            pruneGlobal: in_array('--prune-global', $argv, strict: true),
        );
    }

}
