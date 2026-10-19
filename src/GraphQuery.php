<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final class GraphQuery
{

    /**
     * @param array<string, \Pekral\AiOlympus\GraphRecord> $files
     */
    public static function slice(array $files, string $query, ?int $limit = null): Slice
    {
        $entities = self::match($files, ltrim($query, '\\'));
        $ids = array_column($entities, 'id');
        $edges = self::locateEdges($files);

        $outgoing = array_values(array_filter($edges, static fn (SliceEdge $edge): bool => self::touches($edge->from, $ids)));
        $incoming = array_values(array_filter($edges, static fn (SliceEdge $edge): bool => self::touches($edge->to, $ids)));

        $users = array_values(array_unique(array_column($incoming, 'file')));
        sort($users, SORT_STRING);

        $related = self::related($files, $entities, $outgoing, $incoming);
        $slice = new Slice($query, $entities, $outgoing, $incoming, $users, $related);

        return $limit === null ? $slice : self::limit($slice, $limit);
    }

    private static function limit(Slice $slice, int $limit): Slice
    {
        return new Slice(
            $slice->query,
            $slice->entities,
            array_slice($slice->outgoing, 0, $limit),
            array_slice($slice->incoming, 0, $limit),
            array_slice($slice->files, 0, $limit),
            array_slice($slice->related, 0, $limit),
            new SliceOmitted(
                files: max(0, count($slice->files) - $limit),
                incoming: max(0, count($slice->incoming) - $limit),
                outgoing: max(0, count($slice->outgoing) - $limit),
                related: max(0, count($slice->related) - $limit),
            ),
        );
    }

    /**
     * @param array<string, \Pekral\AiOlympus\GraphRecord> $files
     * @param list<\Pekral\AiOlympus\SliceEntity> $entities
     * @param list<\Pekral\AiOlympus\SliceEdge> $outgoing
     * @param list<\Pekral\AiOlympus\SliceEdge> $incoming
     * @return list<\Pekral\AiOlympus\SliceRelated>
     */
    private static function related(array $files, array $entities, array $outgoing, array $incoming): array
    {
        $ids = array_column($entities, 'id');
        $own = array_map(static fn (SliceEntity $entity): array => ['file' => $entity->file, 'entity' => $entity->id, 'distance' => 0], $entities);
        $callers = array_map(
            static fn (SliceEdge $edge): array => ['file' => $edge->file, 'entity' => $edge->from, 'distance' => 1],
            array_filter($incoming, static fn (SliceEdge $edge): bool => !self::touches($edge->from, $ids)),
        );
        $testFiles = array_column(array_filter($incoming, static fn (SliceEdge $edge): bool => $edge->type === 'TESTS'), 'file');

        return self::nearestPerFile([...$own, ...$callers, ...self::callees($files, $outgoing, $ids)], $testFiles);
    }

    /**
     * @param array<string, \Pekral\AiOlympus\GraphRecord> $files
     * @param list<\Pekral\AiOlympus\SliceEdge> $outgoing
     * @param list<string> $ids
     * @return list<array{file: string, entity: string, distance: int}>
     */
    private static function callees(array $files, array $outgoing, array $ids): array
    {
        $nodeFiles = [];

        foreach ($files as $path => $record) {
            foreach ($record->nodes as $node) {
                $nodeFiles[$node->id] = $path;
            }
        }

        $callees = [];

        foreach ($outgoing as $edge) {
            $target = self::nodeOf($edge->to, $nodeFiles);

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
     * @return list<\Pekral\AiOlympus\SliceRelated>
     */
    private static function nearestPerFile(array $entries, array $testFiles): array
    {
        usort($entries, static fn (array $first, array $second): int => self::nearness($first) <=> self::nearness($second));

        $nearest = [];

        foreach ($entries as $entry) {
            $role = in_array($entry['file'], $testFiles, strict: true) ? 'test' : 'code';
            $nearest[$entry['file']] ??= new SliceRelated($entry['file'], $entry['entity'], $role, $entry['distance']);
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
     * @param array<string, \Pekral\AiOlympus\GraphRecord> $files
     * @return list<\Pekral\AiOlympus\SliceEntity>
     */
    private static function match(array $files, string $query): array
    {
        $entities = [];

        foreach ($files as $path => $record) {
            foreach ($record->nodes as $node) {
                if ($path === $query || $node->id === $query || GraphNameResolver::shortName($node->id) === $query) {
                    $entities[] = SliceEntity::of($node, $path);
                }
            }
        }

        return $entities;
    }

    /**
     * @param array<string, \Pekral\AiOlympus\GraphRecord> $files
     * @return list<\Pekral\AiOlympus\SliceEdge>
     */
    private static function locateEdges(array $files): array
    {
        $edges = [];

        foreach ($files as $path => $record) {
            foreach ($record->edges as $edge) {
                $edges[] = SliceEdge::of($edge, $path);
            }
        }

        return [...$edges, ...self::attributeTraitRelations($edges)];
    }

    /**
     * @param list<\Pekral\AiOlympus\SliceEdge> $edges
     * @return list<\Pekral\AiOlympus\SliceEdge>
     */
    private static function attributeTraitRelations(array $edges): array
    {
        $relations = array_filter($edges, static fn (SliceEdge $edge): bool => $edge->type === 'HAS_RELATION');
        $attributed = [];

        foreach ($edges as $use) {
            foreach ($relations as $relation) {
                if ($use->type === 'USES' && !str_starts_with($use->from, 'file:') && self::touches($relation->from, [$use->to])) {
                    $attributed[] = $relation->withFrom($use->from . substr($relation->from, strlen($use->to)));
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
