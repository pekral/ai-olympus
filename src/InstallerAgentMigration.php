<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * Removes only unchanged definitions from the last Greek-named roster after a replacement is installed.
 * Unknown versions and user edits remain orphans for manual review.
 */
final class InstallerAgentMigration
{

    /** @var array<string, string> */
    private const array RENAMES = [
        'daedalus' => 'splinter',
        'hephaestus' => 'donatello',
        'athena' => 'leonardo',
        'argus' => 'raphael',
        'apollo' => 'michelangelo',
        'hermes' => 'april',
    ];

    /** @var array<string, string> SHA-256 of the shipped definitions at the rename boundary. */
    private const array ORIGINAL_DIGESTS = [
        'daedalus.md' => '6463a4884ba58b2f4e79fe6d5803840865ada6cbe59cfbc648b993ff756f0fbe',
        'hephaestus.md' => 'fdd183e10fefd071285f37ff1c41e82209be168b036970e19df9de9020c8d0d0',
        'athena.md' => '208412289d4f70cd969aa03dc6af845bd396e947901b4841e1e863392e5c5feb',
        'argus.md' => 'c9dd31780e7d2f6fc73516be6488698593846354cdb5905fe7c91131cd8f709d',
        'apollo.md' => '13658fa9f6fe5291ff34a8ac7bc5bd5ff4a5716173b4beaa4c09938c063f1230',
        'hermes.md' => 'c0d34a62c4a45cbc68beb17384c090fc52a4cec4867be9a718f213fbb03d0ab9',
        'daedalus.toml' => '83c93902d783aff3584f47de4f47b70a9146d09ca778ba614ccf09a32967ed64',
        'hephaestus.toml' => 'eee9858bcae47dff44dca2b40f1f1dc08e4aae1e9e7cb1434a6b7a62a261a127',
        'athena.toml' => '3dc8c5409fc7adae9a11fa379a37cea2afee790fcb34f73fe1a4554092570f07',
        'argus.toml' => '49cd0e87188baffca0be9685db7704edc18c7aaf4b67f001884e256d7aa3beef',
        'apollo.toml' => '709f2eaeb7b38b23cbc3e3fe8079d29233b32d8ceb5e7e31fb6550e44db72a7f',
        'hermes.toml' => '8fa6c6dedc52f922a84a0ad67e84867ee7abdc9791c422577e1fb3830ce0f64b',
    ];

    public static function removeKnownCopies(string $source, string $target): int
    {
        $extension = match ($source) {
            InstallerPath::resolveAgentsSource() => 'md',
            InstallerPath::resolveCodexAgentsSource() => 'toml',
            default => null,
        };

        if ($extension === null || is_link($target) || is_link(dirname($target))) {
            return 0;
        }

        $removed = 0;

        foreach (self::RENAMES as $previous => $current) {
            $filename = $previous . '.' . $extension;
            $oldTarget = $target . '/' . $filename;

            if (!is_file($target . '/' . $current . '.' . $extension)) {
                continue;
            }

            if (self::isUnmodifiedPackageFile($oldTarget, $source . '/' . $filename, self::ORIGINAL_DIGESTS[$filename])) {
                $removed += (int) unlink($oldTarget);
            }
        }

        return $removed;
    }

    private static function isUnmodifiedPackageFile(string $target, string $previousSource, string $digest): bool
    {
        if (is_link($target)) {
            return readlink($target) === $previousSource;
        }

        if (!is_file($target)) {
            return false;
        }

        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($target))) === $digest;
    }

}
