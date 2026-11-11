<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final readonly class MemoryEntry
{

    private const string PATH_PATTERN = '/^(?=.*[A-Za-z0-9])[\w.\/\\\\:@$-]{1,200}$/';

    /**
     * @param list<string> $paths
     */
    public function __construct(
        public string $slug,
        public string $role,
        public string $trigger,
        public string $lesson,
        public string $rule,
        public string $evidence,
        public array $paths,
        public string $example,
        public string $source,
        public string $added,
    ) {
    }

    /**
     * The one validating constructor of an entry. `memory add` and `memory migrate` both build entries here.
     * The legacy Markdown format has no evidence field and often no source or date, so `$legacy` makes those three optional.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, bool $legacy = false): self
    {
        $entry = new self(
            self::text($data, 'slug'),
            self::text($data, 'role'),
            self::text($data, 'trigger'),
            self::text($data, 'lesson'),
            self::text($data, 'rule'),
            self::text($data, 'evidence', required: !$legacy),
            self::paths($data['paths'] ?? []),
            self::text($data, 'example', required: false),
            self::text($data, 'source', required: !$legacy),
            self::text($data, 'added', required: !$legacy),
        );

        foreach (['slug' => '/^[a-z0-9][a-z0-9-]{0,99}$/', 'added' => '/^(\d{4}-\d{2}-\d{2})?$/'] as $field => $pattern) {
            if (preg_match($pattern, $entry->{$field}) !== 1) {
                throw InstallerFailure::memoryEntryInvalid(sprintf('"%s" has the wrong format', $field));
            }
        }

        if (!self::isRole($entry->role)) {
            throw InstallerFailure::memoryEntryInvalid('"role" has the wrong format');
        }

        return $entry;
    }

    /**
     * @param array<string, string> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['slug'],
            $row['role'],
            $row['trigger'],
            $row['lesson'],
            $row['rule'],
            $row['evidence'],
            $row['paths'] === '' ? [] : explode("\n", $row['paths']),
            $row['example'],
            $row['source'],
            $row['added'],
        );
    }

    public static function isPath(string $value): bool
    {
        return preg_match(self::PATH_PATTERN, $value) === 1;
    }

    public static function isRole(string $value): bool
    {
        return preg_match('/^[a-z][a-z-]{0,31}$/', $value) === 1;
    }

    /**
     * @return list<string>
     */
    private static function paths(mixed $value): array
    {
        $paths = [];

        foreach (is_array($value) ? $value : [null] as $path) {
            if (!is_string($path) || !self::isPath($path)) {
                throw InstallerFailure::memoryEntryInvalid('"paths" must be a list of file paths');
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $field, bool $required = true): string
    {
        $value = $data[$field] ?? '';

        if (!is_string($value) || ($required && trim($value) === '')) {
            throw InstallerFailure::memoryEntryInvalid(sprintf('"%s" must be a non-empty string', $field));
        }

        if (preg_match('//u', $value) !== 1) {
            throw InstallerFailure::memoryEntryInvalid(sprintf('"%s" must be valid UTF-8', $field));
        }

        return trim($value);
    }

}
