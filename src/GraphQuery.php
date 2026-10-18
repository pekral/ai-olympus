<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * @phpstan-import-type GraphRecord from \Pekral\AiOlympus\GraphParser
 * @phpstan-type SliceEntity array{id: string, kind: string, line: int, file: string}
 * @phpstan-type SliceEdge array{from: string, type: string, to: string, line: int, file: string}
 */
final class GraphQuery
{

    /**
     * @param array<string, GraphRecord> $files
     * @return array{query: string, entities: list<SliceEntity>, outgoing: list<SliceEdge>, incoming: list<SliceEdge>, files: list<string>}
     */
    public static function slice(array $files, string $query): array
    {
        $entities = self::match($files, ltrim($query, '\\'));
        $ids = array_column($entities, 'id');
        $edges = self::locateEdges($files);

        $outgoing = array_values(array_filter($edges, static fn (array $edge): bool => self::touches($edge['from'], $ids)));
        $incoming = array_values(array_filter($edges, static fn (array $edge): bool => self::touches($edge['to'], $ids)));

        $users = array_values(array_unique(array_column($incoming, 'file')));
        sort($users, SORT_STRING);

        return ['query' => $query, 'entities' => $entities, 'outgoing' => $outgoing, 'incoming' => $incoming, 'files' => $users];
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
                if ($path === $query || $node['id'] === $query || self::shortName($node['id']) === $query) {
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

        return $edges;
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

    private static function shortName(string $id): string
    {
        $separator = strrpos($id, '\\');

        return $separator === false ? $id : substr($id, $separator + 1);
    }

}
