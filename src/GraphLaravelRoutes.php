<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

trait GraphLaravelRoutes
{

    private const array ROUTE_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

    private const array RESOURCE_ROUTES = ['resource', 'apiresource'];

    /**
     * @var array<int, string>
     */
    private array $routeGroups = [];

    private ?string $routeGroup = null;

    private function readRoute(int $index): void
    {
        $prefix = [];

        while ($this->tokenIs($index, [T_DOUBLE_COLON, T_OBJECT_OPERATOR]) && $this->tokenIs($index + 1, T_STRING) && $this->tokenIs($index + 2, '(')) {
            $method = strtolower($this->tokens[$index + 1]->text);

            if ($method === 'group') {
                $this->readRouteGroup($prefix, $index + 2);

                return;
            }

            if ($method === 'prefix') {
                $prefix[] = $this->literal($index + 3) ?? '';
            } else {
                $this->addRoute($method, $prefix, $index + 2);
            }

            $index = $this->closingParenthesis($index + 2) + 1;
        }
    }

    /**
     * @param list<string> $prefix
     */
    private function readRouteGroup(array $prefix, int $index): void
    {
        $closure = false;

        for (; $this->isBefore($index, ['{', ';']); $index++) {
            $closure = $closure || $this->tokenIs($index, T_FUNCTION);

            if ($this->literal($index) === 'prefix' && $this->tokenIs($index + 1, T_DOUBLE_ARROW)) {
                $prefix[] = $this->literal($index + 2) ?? '';
            }
        }

        $this->routeGroup = $closure ? $this->routePath($prefix) : null;
    }

    private function openRouteGroup(): void
    {
        if ($this->routeGroup !== null) {
            $this->routeGroups[$this->depth] = $this->routeGroup;
            $this->routeGroup = null;
        }
    }

    /**
     * @param list<string> $prefix
     */
    private function addRoute(string $method, array $prefix, int $open): void
    {
        $uri = $this->literal($open + 1);
        $resource = in_array($method, self::RESOURCE_ROUTES, strict: true);

        if ($uri === null || (!$resource && !in_array($method, self::ROUTE_VERBS, strict: true))) {
            return;
        }

        $path = $this->routePath([...array_values($this->routeGroups), ...$prefix, $uri]);
        $route = 'route:' . ($resource ? 'RESOURCE' : strtoupper($method)) . ':' . $path;
        $line = $this->tokens[$open]->line;
        $this->addNode($route, 'route', $line);
        $handler = $this->tokenIs($open + 2, ',') ? $this->readHandler($open + 3) : null;

        if ($handler !== null) {
            $this->addEdge($route, 'ROUTE_HANDLED_BY', $resource ? $handler[0] : $handler[0] . '::' . ($handler[1] ?? '__invoke'), $line);
        }
    }

    /**
     * @param list<string> $segments
     */
    private function routePath(array $segments): string
    {
        $segments = array_map(static fn (string $segment): string => trim($segment, '/'), $segments);

        return '/' . implode('/', array_filter($segments, static fn (string $segment): bool => $segment !== ''));
    }

}
