<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use PDO;
use PDOException;

final readonly class MemoryStore
{

    private const string COLUMNS = 'slug, role, "trigger", lesson, rule, evidence, paths, example, source, added';

    private const string SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS memory_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE, role TEXT NOT NULL, "trigger" TEXT NOT NULL, lesson TEXT NOT NULL,
            rule TEXT NOT NULL, evidence TEXT NOT NULL, paths TEXT NOT NULL, example TEXT NOT NULL,
            source TEXT NOT NULL, added TEXT NOT NULL
        );
        CREATE VIRTUAL TABLE IF NOT EXISTS memory_fts USING fts5("trigger", lesson, rule, evidence, paths, example, tokenize = 'porter unicode61');
        SQL;

    private function __construct(private PDO $pdo)
    {
    }

    public static function open(string $path): self
    {
        self::assertDriver('sqlite');

        try {
            $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            $pdo->exec(self::SCHEMA);
        } catch (PDOException $exception) {
            throw MemoryUnavailable::database($exception->getMessage());
        }

        return new self($pdo);
    }

    /**
     * @param list<\Pekral\AiOlympus\MemoryEntry> $entries
     */
    public function insertAll(array $entries): int
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO memory_entries (' . self::COLUMNS . ')'
            . ' VALUES (:slug, :role, :trigger, :lesson, :rule, :evidence, :paths, :example, :source, :added) ON CONFLICT(slug) DO NOTHING',
        );
        $index = $this->pdo->prepare(
            'INSERT INTO memory_fts (rowid, "trigger", lesson, rule, evidence, paths, example)'
            . ' VALUES (:id, :trigger, :lesson, :rule, :evidence, :paths, :example)',
        );
        $inserted = 0;

        $this->pdo->exec('BEGIN IMMEDIATE');

        foreach ($entries as $entry) {
            $values = [
                ':evidence' => $entry->evidence,
                ':example' => $entry->example,
                ':lesson' => $entry->lesson,
                ':paths' => implode("\n", $entry->paths),
                ':rule' => $entry->rule,
                ':trigger' => $entry->trigger,
            ];
            $insert->execute($values + [':slug' => $entry->slug, ':role' => $entry->role, ':source' => $entry->source, ':added' => $entry->added]);

            if ($insert->rowCount() !== 1) {
                continue;
            }

            $index->execute($values + [':id' => $this->pdo->lastInsertId()]);
            ++$inserted;
        }

        $this->pdo->exec('COMMIT');

        return $inserted;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $symbols
     * @return list<\Pekral\AiOlympus\MemoryEntry>
     */
    public function search(string $role, array $paths, array $symbols, int $limit): array
    {
        $match = array_map(static fn (string $term): string => '"' . str_replace('"', '""', $term) . '"', [...$paths, ...$symbols]);
        $params = [':role' => $role, ':match' => implode(' OR ', $match)];
        $hits = ['0'];

        foreach ($paths as $index => $path) {
            $hits[] = 'instr(char(10) || e.paths || char(10), char(10) || :p' . $index . ' || char(10)) > 0';
            $params[':p' . $index] = $path;
        }

        $statement = $this->pdo->prepare(
            'WITH ranked AS (SELECT rowid AS id, bm25(memory_fts) AS score FROM memory_fts WHERE memory_fts MATCH :match) '
            . 'SELECT ' . $this->columns('e.') . ', (' . implode(' OR ', $hits) . ') AS path_hit FROM memory_entries e LEFT JOIN ranked r ON r.id = e.id '
            . 'WHERE (e.role = :role OR e.role = \'shared\') AND (path_hit = 1 OR r.id IS NOT NULL) '
            . 'ORDER BY path_hit DESC, r.score ASC, e.slug ASC LIMIT ' . $limit,
        );
        $statement->execute($params);

        /** @var list<array<string, string>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(MemoryEntry::fromRow(...), $rows);
    }

    public static function assertDriver(string $driver): void
    {
        if (!in_array($driver, PDO::getAvailableDrivers(), strict: true)) {
            throw MemoryUnavailable::driverMissing($driver);
        }
    }

    private function columns(string $prefix): string
    {
        return $prefix . str_replace(', ', ', ' . $prefix, self::COLUMNS);
    }

}
