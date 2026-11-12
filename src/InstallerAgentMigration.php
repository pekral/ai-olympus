<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * Removes only unchanged definitions of renamed agents after a replacement is installed.
 * Unknown versions and user edits remain orphans for manual review.
 */
final class InstallerAgentMigration
{

    private const array RENAMES = [
        'apollo' => 'michelangelo',
        'argus' => 'raphael',
        'athena' => 'leonardo',
        'daedalus' => 'krang',
        'hephaestus' => 'donatello',
        'hermes' => 'april',
        'metalhead' => 'shredder',
        'splinter' => 'krang',
    ];

    /**
     * Digests of every released copy of a definition whose name a current agent reuses for a different agent.
     * `splinter` was the orchestrator from 0.1.3 to 0.3.1, so each of those copies must be replaced even without `--force`.
     */
    private const array SUPERSEDED_DIGESTS = [
        'splinter.md' => [
            '26de45cf6452c93dd1d6c3409bf03042dfba56c7b23a5fb70a666e4c0d700abe',
            'f33d1a9cc60f5247346575ab9ad359f492efc11a4f7ca7573f9a68848ea98544',
            '097f44b0ae0b3e5667d1c938de9da6940aae8069b6c876a496ebe80eafc959d5',
            '1160e1f4d01ddc15011fbd361889aa84f612aa8413de52551bf224387eb962d5',
            '54e06017b94077799683dcbfed35f63d714bf3417cea1552976b9b59cfd3f5ce',
            'fa629261c902dee2f652baf90ee516eb82e3c086d1bf0f4115bca958e306dde0',
        ],
        'splinter.toml' => [
            'a86ae35b5cd8995d48600b8b19d932333a98b4de8585472f552ea3ffa4c5f659',
            'a8e1cfdd1174399845ca6415d4e1fa2a9852701317fb47f92162d56c45dc4cf5',
            '4c20df3a50d6f0091ba90338dce299d6c9cd19db41445b602da6a179841c4cdf',
        ],
    ];

    private const array ORIGINAL_DIGESTS = [
        'apollo.md' => '13658fa9f6fe5291ff34a8ac7bc5bd5ff4a5716173b4beaa4c09938c063f1230',
        'apollo.toml' => '709f2eaeb7b38b23cbc3e3fe8079d29233b32d8ceb5e7e31fb6550e44db72a7f',
        'argus.md' => 'c9dd31780e7d2f6fc73516be6488698593846354cdb5905fe7c91131cd8f709d',
        'argus.toml' => '49cd0e87188baffca0be9685db7704edc18c7aaf4b67f001884e256d7aa3beef',
        'athena.md' => '208412289d4f70cd969aa03dc6af845bd396e947901b4841e1e863392e5c5feb',
        'athena.toml' => '3dc8c5409fc7adae9a11fa379a37cea2afee790fcb34f73fe1a4554092570f07',
        'daedalus.md' => '6463a4884ba58b2f4e79fe6d5803840865ada6cbe59cfbc648b993ff756f0fbe',
        'daedalus.toml' => '83c93902d783aff3584f47de4f47b70a9146d09ca778ba614ccf09a32967ed64',
        'hephaestus.md' => 'fdd183e10fefd071285f37ff1c41e82209be168b036970e19df9de9020c8d0d0',
        'hephaestus.toml' => 'eee9858bcae47dff44dca2b40f1f1dc08e4aae1e9e7cb1434a6b7a62a261a127',
        'hermes.md' => 'c0d34a62c4a45cbc68beb17384c090fc52a4cec4867be9a718f213fbb03d0ab9',
        'hermes.toml' => '8fa6c6dedc52f922a84a0ad67e84867ee7abdc9791c422577e1fb3830ce0f64b',
        'metalhead.md' => '67eff12a0d4ef0453baeadb9c09b7f9b7fe416d6425615624e2be73cfaf55b14',
        'metalhead.toml' => 'e220e83564abc6b413ae696f27dd4f49a3a4311552a780cda6e0e371ac7be0a0',
        'splinter.md' => 'fa629261c902dee2f652baf90ee516eb82e3c086d1bf0f4115bca958e306dde0',
        'splinter.toml' => '4c20df3a50d6f0091ba90338dce299d6c9cd19db41445b602da6a179841c4cdf',
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

            if (self::isRemovableCopy($source, $target, $filename, $current . '.' . $extension)) {
                $removed += (int) unlink($oldTarget);
            }
        }

        return $removed;
    }

    /**
     * True when `$target` is an unmodified copy of a superseded definition whose name a current agent reuses.
     * A symlink or a user-edited file is never superseded, so it stays.
     */
    public static function isSupersededCopy(string $relativePath, string $target): bool
    {
        if (!self::isReusedName($relativePath)) {
            return false;
        }

        if (is_link($target) || !is_file($target)) {
            return false;
        }

        return in_array(self::digest($target), self::SUPERSEDED_DIGESTS[basename($relativePath)], strict: true);
    }

    public static function isReusedName(string $relativePath): bool
    {
        return isset(self::SUPERSEDED_DIGESTS[basename($relativePath)]);
    }

    private static function isRemovableCopy(string $source, string $target, string $filename, string $replacement): bool
    {
        if (!is_file($target . '/' . $replacement) || is_file($source . '/' . $filename)) {
            return false;
        }

        return self::isUnmodifiedPackageFile($target . '/' . $filename, $source . '/' . $filename, self::ORIGINAL_DIGESTS[$filename]);
    }

    private static function isUnmodifiedPackageFile(string $target, string $previousSource, string $digest): bool
    {
        if (is_link($target)) {
            return readlink($target) === $previousSource;
        }

        if (!is_file($target)) {
            return false;
        }

        return self::matchesDigest($target, $digest);
    }

    private static function matchesDigest(string $target, string $digest): bool
    {
        return self::digest($target) === $digest;
    }

    private static function digest(string $target): string
    {
        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($target)));
    }

}
