<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final class GraphNameResolver
{

    private string $namespace = '';

    /**
     * @var array<string, string>
     */
    private array $imports = [];

    public function enterNamespace(string $namespace): void
    {
        $this->namespace = $namespace;
        $this->imports = [];
    }

    public function import(string $name, string $alias): void
    {
        $this->imports[strtolower($alias)] = $name;
    }

    public function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return $this->qualify(substr($name, strlen('namespace\\')));
        }

        $segments = explode('\\', $name, 2);
        $import = $this->imports[strtolower($segments[0])] ?? null;

        if ($import !== null) {
            return $import . (isset($segments[1]) ? '\\' . $segments[1] : '');
        }

        return $this->qualify($name);
    }

    public function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }

    public static function shortName(string $name): string
    {
        return substr((string) strrchr('\\' . $name, '\\'), 1);
    }

}
