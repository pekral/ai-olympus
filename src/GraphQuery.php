<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * @phpstan-import-type GraphRecord from \Pekral\AiOlympus\GraphParser
 * @phpstan-type SliceEntity array{id: string, kind: string, line: int, file: string}
 * @phpstan-type SliceEdge array{from: string, type: string, to: string, line: int, file: string}
 * @phpstan-type SliceRelated array{file: string, entity: string, role: string, distance: int}
 * @phpstan-type Slice array{query: string, entities: list<array{id: string, kind: string, line: int, file: string}>, outgoing: list<array{from: string, type: string, to: string, line: int, file: string}>, incoming: list<array{from: string, type: string, to: string, line: int, file: string}>, files: list<string>, related: list<array{file: string, entity: string, role: string, distance: int}>, omitted?: array{files: int, incoming: int, outgoing: int, related: int}}
 */
final class GraphQuery
{

    /**
     * @param array<string, GraphRecord> $files
     * @return Slice
     */
    public static function slice(array $files, string $query, ?int $limit = null): array
    {
        $entities = self::match($files, ltrim($query, '\\'));
        $ids = array_column($entities, 'id');
        $edges = self::locateEdges($files);

        $outgoing = array_values(array_filter($edges, static fn (array $edge): bool => self::touches($edge['from'], $ids)));
        $incoming = array_values(array_filter($edges, static fn (array $edge): bool => self::touches($edge['to'], $ids)));

        $users = array_values(array_unique(array_column($incoming, 'file')));
        sort($users, SORT_STRING);

        $related = self::related($files, $entities, $outgoing, $incoming);
        $slice = ['query' => $query, 'entities' => $entities, 'outgoing' => $outgoing, 'incoming' => $incoming, 'files' => $users, 'related' => $related];

        return $limit === null ? $slice : self::limit($slice, $limit);
    }

    /**
     * @param Slice $slice
     * @return Slice
     */
    private static function limit(array $slice, int $limit): array
    {
        $omitted = [
            'files' => max(0, count($slice['files']) - $limit),
            'incoming' => max(0, count($slice['incoming']) - $limit),
            'outgoing' => max(0, count($slice['outgoing']) - $limit),
            'related' => max(0, count($slice['related']) - $limit),
        ];

        return array_merge($slice, [
            'files' => array_slice($slice['files'], 0, $limit),
            'incoming' => array_slice($slice['incoming'], 0, $limit),
            'omitted' => $omitted,
            'outgoing' => array_slice($slice['outgoing'], 0, $limit),
            'related' => array_slice($slice['related'], 0, $limit),
        ]);
    }

    /**
     * @param array<string, GraphRecord> $files
     * @param list<SliceEntity> $entities
     * @param list<SliceEdge> $outgoing
     * @param list<SliceEdge> $incoming
     * @return list<SliceRelated>
     */
    private static function related(array $files, array $entities, array $outgoing, array $incoming): array
    {
        $ids = array_column($entities, 'id');
        $own = array_map(static fn (array $entity): array => ['file' => $entity['file'], 'entity' => $entity['id'], 'distance' => 0], $entities);
        $callers = array_map(
            static fn (array $edge): array => ['file' => $edge['file'], 'entity' => $edge['from'], 'distance' => 1],
            array_filter($incoming, static fn (array $edge): bool => !self::touches($edge['from'], $ids)),
        );
        $testFiles = array_column(array_filter($incoming, static fn (array $edge): bool => $edge['type'] === 'TESTS'), 'file');

        return self::nearestPerFile([...$own, ...$callers, ...self::callees($files, $outgoing, $ids)], $testFiles);
    }

    /**
     * @param array<string, GraphRecord> $files
     * @param list<SliceEdge> $outgoing
     * @param list<string> $ids
     * @return list<array{file: string, entity: string, distance: int}>
     */
    private static function callees(array $files, array $outgoing, array $ids): array
    {
        $nodeFiles = [];

        foreach ($files as $path => $record) {
            foreach ($record['nodes'] as $node) {
                $nodeFiles[$node['id']] = $path;
            }
        }

        $callees = [];

        foreach ($outgoing as $edge) {
            $target = self::nodeOf($edge['to'], $nodeFiles);

            if ($target !== null && !self::touches($target, $ids)) {
                $callees[] = ['file' => $nodeFiles[$target], 'entity' => $target, 'distance' => 1];
            }
        }

        return $callees;
    }

    /**
     * @param array<string, string> $nodeFiles
     */
    private static function nodeOf(string $id, array $nodeFiles): ?string
    {
        foreach ([$id, strstr($id, '::', before_needle: true)] as $candidate) {
            if (is_string($candidate) && array_key_exists($candidate, $nodeFiles)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<array{file: string, entity: string, distance: int}> $entries
     * @param list<string> $testFiles
     * @return list<SliceRelated>
     */
    private static function nearestPerFile(array $entries, array $testFiles): array
    {
        usort($entries, static fn (array $first, array $second): int => self::nearness($first) <=> self::nearness($second));

        $nearest = [];

        foreach ($entries as $entry) {
            $role = in_array($entry['file'], $testFiles, strict: true) ? 'test' : 'code';
            $nearest[$entry['file']] ??= ['file' => $entry['file'], 'entity' => $entry['entity'], 'role' => $role, 'distance' => $entry['distance']];
        }

        return array_values($nearest);
    }

    /**
     * @param array{file: string, entity: string, distance: int} $entry
     * @return array{int, string, bool, string}
     */
    private static function nearness(array $entry): array
    {
        return [$entry['distance'], $entry['file'], str_starts_with($entry['entity'], 'file:'), $entry['entity']];
    }

    /**
     * @param array<string, GraphRecord> $files
     * @return list<SliceEntity>
     */
    private static function match(array $files, string $query): array
    {
        $entities = [];

        foreach ($files as $path => $record) {
            foreach ($record['nodes'] as $node) {
                if ($path === $query || $node['id'] === $query || GraphNameResolver::shortName($node['id']) === $query) {
                    $entities[] = $node + ['file' => $path];
                }
            }
        }

        return $entities;
    }

    /**
     * @param array<string, GraphRecord> $files
     * @return list<SliceEdge>
     */
    private static function locateEdges(array $files): array
    {
        $edges = [];

        foreach ($files as $path => $record) {
            foreach ($record['edges'] as $edge) {
                $edges[] = $edge + ['file' => $path];
            }
        }

        return [...$edges, ...self::attributeTraitRelations($edges)];
    }

    /**
     * @param list<SliceEdge> $edges
     * @return list<SliceEdge>
     */
    private static function attributeTraitRelations(array $edges): array
    {
        $relations = array_filter($edges, static fn (array $edge): bool => $edge['type'] === 'HAS_RELATION');
        $attributed = [];

        foreach ($edges as $use) {
            foreach ($relations as $relation) {
                if ($use['type'] === 'USES' && !str_starts_with($use['from'], 'file:') && self::touches($relation['from'], [$use['to']])) {
                    $attributed[] = ['from' => $use['from'] . substr($relation['from'], strlen($use['to']))] + $relation;
                }
            }
        }

        return $attributed;
    }

    /**
     * @param list<string> $ids
     */
    private static function touches(string $endpoint, array $ids): bool
    {
        foreach ($ids as $id) {
            if ($endpoint === $id || str_starts_with($endpoint, $id . '::')) {
                return true;
            }
        }

        return false;
    }

}
