<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonException;
use stdClass;

final class InstallerHelperAgentsDefault
{

    private const KEY = 'helper-agents';

    private const MANIFEST = 'ai-olympus';

    public static function apply(string $projectRoot): HelperAgentsDefaultOutcome
    {
        $path = $projectRoot . '/composer.json';
        $contents = is_file($path) ? file_get_contents($path) : false;
        $composer = $contents === false ? null : self::decode($contents);
        $manifest = $composer instanceof stdClass ? self::manifestOf($composer) : null;

        if ($contents === false || !$composer instanceof stdClass || !$manifest instanceof stdClass || property_exists($manifest, self::KEY)) {
            return HelperAgentsDefaultOutcome::Unchanged;
        }

        if (self::encode($composer, $contents) !== $contents) {
            return HelperAgentsDefaultOutcome::FormatKept;
        }

        return self::write($path, self::encode(self::withDefault($composer, $manifest), $contents))
            ? HelperAgentsDefaultOutcome::Written
            : HelperAgentsDefaultOutcome::WriteFailed;
    }

    private static function manifestOf(stdClass $composer): ?stdClass
    {
        $extra = $composer->extra ?? new stdClass();

        if (!$extra instanceof stdClass || !self::isObjectOrAbsent($composer, 'extra') || !self::isObjectOrAbsent($extra, self::MANIFEST)) {
            return null;
        }

        $manifest = $extra->{self::MANIFEST} ?? new stdClass();

        return $manifest instanceof stdClass ? $manifest : null;
    }

    private static function withDefault(stdClass $composer, stdClass $manifest): stdClass
    {
        $extra = $composer->extra ?? null;
        $extra = $extra instanceof stdClass ? $extra : new stdClass();
        $manifest->{self::KEY} = true;
        $extra->{self::MANIFEST} = $manifest;
        $composer->extra = $extra;

        return $composer;
    }

    private static function decode(string $contents): ?stdClass
    {
        try {
            $data = json_decode($contents, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $data instanceof stdClass ? $data : null;
    }

    private static function isObjectOrAbsent(stdClass $parent, string $key): bool
    {
        return !property_exists($parent, $key) || $parent->{$key} instanceof stdClass;
    }

    private static function encode(stdClass $composer, string $original): string
    {
        $json = json_encode(
            $composer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
        $unit = preg_match('/^\{\R([ \t]+)"/', $original, $matches) === 1 ? $matches[1] : '    ';
        $indented = preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $groups): string => str_repeat($unit, intdiv(strlen($groups[0]), 4)),
            $json,
        );

        return ($indented ?? '') . (str_ends_with($original, "\n") ? "\n" : '');
    }

    private static function write(string $path, string $contents): bool
    {
        set_error_handler(static fn (): bool => true);
        $written = file_put_contents($path, $contents, LOCK_EX);
        restore_error_handler();

        return $written !== false;
    }

}
