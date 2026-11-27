<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use Closure;
use stdClass;

final class InstallerHookHandlers
{

    /**
     * @param \Closure(mixed): bool $isTarget
     */
    public static function strip(stdClass $settings, Closure $isTarget): int
    {
        $hooks = $settings->hooks ?? null;

        if (!$hooks instanceof stdClass) {
            return 0;
        }

        $removed = 0;

        foreach (get_object_vars($hooks) as $event => $groups) {
            $removed += is_array($groups) ? self::stripEventGroups($hooks, $event, $groups, $isTarget) : 0;
        }

        if ($removed > 0 && get_object_vars($hooks) === []) {
            unset($settings->hooks);
        }

        return $removed;
    }

    /**
     * @param \Closure(mixed): bool $isTarget
     * @return list<array{event: string, matcher: mixed, handler: mixed}>
     */
    public static function find(stdClass $settings, Closure $isTarget): array
    {
        $hooks = $settings->hooks ?? null;
        $matches = [];

        if (!$hooks instanceof stdClass) {
            return $matches;
        }

        foreach (get_object_vars($hooks) as $event => $groups) {
            foreach (is_array($groups) ? $groups : [] as $group) {
                array_push($matches, ...self::matchingHandlers((string) $event, $group, $isTarget));
            }
        }

        return $matches;
    }

    public static function appendGroup(stdClass $settings, string $event, stdClass $group): void
    {
        $hooks = $settings->hooks ?? null;

        if (!$hooks instanceof stdClass) {
            $hooks = new stdClass();
        }

        $groups = $hooks->{$event} ?? null;
        $hooks->{$event} = [...(is_array($groups) ? array_values($groups) : []), $group];
        $settings->hooks = $hooks;
    }

    /**
     * @param array<array-key, mixed> $groups
     * @param \Closure(mixed): bool $isTarget
     */
    private static function stripEventGroups(stdClass $hooks, string $event, array $groups, Closure $isTarget): int
    {
        $removed = 0;
        $retained = [];

        foreach ($groups as $group) {
            [$keepGroup, $removedFromGroup] = self::stripGroupHandlers($group, $isTarget);
            $removed += $removedFromGroup;
            $retained = $keepGroup ? [...$retained, $group] : $retained;
        }

        if ($removed === 0) {
            return 0;
        }

        if ($retained === []) {
            unset($hooks->{$event});

            return $removed;
        }

        $hooks->{$event} = $retained;

        return $removed;
    }

    /**
     * @param \Closure(mixed): bool $isTarget
     * @return array{0: bool, 1: int}
     */
    private static function stripGroupHandlers(mixed $group, Closure $isTarget): array
    {
        if (!$group instanceof stdClass) {
            return [true, 0];
        }

        $handlers = $group->hooks ?? null;

        if (!is_array($handlers)) {
            return [true, 0];
        }

        $retained = array_values(array_filter($handlers, static fn (mixed $handler): bool => !$isTarget($handler)));
        $removed = count($handlers) - count($retained);

        if ($removed === 0) {
            return [true, 0];
        }

        if ($retained === []) {
            return [false, $removed];
        }

        $group->hooks = $retained;

        return [true, $removed];
    }

    /**
     * @param \Closure(mixed): bool $isTarget
     * @return list<array{event: string, matcher: mixed, handler: mixed}>
     */
    private static function matchingHandlers(string $event, mixed $group, Closure $isTarget): array
    {
        if (!$group instanceof stdClass || !is_array($group->hooks ?? null)) {
            return [];
        }

        $matcher = $group->matcher ?? null;

        return array_map(
            static fn (mixed $handler): array => ['event' => $event, 'matcher' => $matcher, 'handler' => $handler],
            array_values(array_filter($group->hooks, $isTarget)),
        );
    }

}
