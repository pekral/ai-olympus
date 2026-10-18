<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use PhpToken;

trait GraphLaravelExtractors
{

    use GraphLaravelRoutes;

    private const string FACADES = 'Illuminate\Support\Facades\\';

    private const array SCHEMA_CHANGES = ['create', 'table', 'drop', 'dropifexists'];

    private const array DISPATCHING_FACADES = ['Bus', 'Event'];

    private const array ELOQUENT_MODELS = [
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Foundation\Auth\User',
        'Illuminate\Database\Eloquent\Relations\Pivot',
    ];

    private const array ELOQUENT_RELATIONS = [
        'hasone',
        'hasmany',
        'hasonethrough',
        'hasmanythrough',
        'belongsto',
        'belongstomany',
        'morphone',
        'morphmany',
        'morphto',
        'morphtomany',
        'morphedbymany',
    ];

    private const array DIRECTORY_KINDS = ['Http/Controllers' => 'controller', 'Jobs' => 'job', 'Events' => 'event', 'Listeners' => 'listener'];

    private const array INJECTED_KINDS = ['controller', 'job'];

    private const array SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'iterable', 'object', 'mixed', 'null', 'false', 'true'];

    private const array PARAMETER_NAMES = [T_VARIABLE, T_ELLIPSIS, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, '|'];

    private string $classKind = 'class';

    /**
     * @var array{string, int}|null
     */
    private ?array $modelTable = null;

    private function declarationKind(string $declared, int $line): string
    {
        $this->modelTable = null;

        if ($declared !== 'class' || $this->class === null) {
            return $declared;
        }

        if (in_array($this->parent, self::ELOQUENT_MODELS, strict: true)) {
            $this->modelTable = [$this->conventionTable($this->class), $line];

            return 'model';
        }

        $pattern = '~[:/](' . implode('|', array_keys(self::DIRECTORY_KINDS)) . ')/~';

        return $this->fileKind === 'file' && preg_match($pattern, $this->fileId, $matches) === 1 ? self::DIRECTORY_KINDS[$matches[1]] : 'class';
    }

    private function conventionTable(string $class): string
    {
        $table = strtolower((string) preg_replace('~(?<=[a-z0-9])[A-Z]~', '_$0', GraphNameResolver::shortName($class)));

        return match (true) {
            preg_match('~[^aeiou]y$~', $table) === 1 => substr($table, 0, -1) . 'ies',
            preg_match('~(s|x|z|ch|sh)$~', $table) === 1 => $table . 'es',
            default => $table . 's',
        };
    }

    private function closeModel(): void
    {
        if ($this->class !== null && $this->modelTable !== null) {
            $this->addEdge($this->class, 'USES_TABLE', 'table:' . $this->modelTable[0], $this->modelTable[1]);
        }

        $this->modelTable = null;
    }

    private function readProperty(PhpToken $token, int $index): int
    {
        if (!$this->tokenIs($index + 1, '=')) {
            return $index;
        }

        $value = $this->literal($index + 2);

        if ($token->text === '$table' && $this->modelTable !== null && $value !== null) {
            $this->modelTable = [$value, $token->line];
        }

        if ($token->text === '$listen') {
            $this->readListeners($index + 2);
        }

        return $index;
    }

    private function readListeners(int $index): void
    {
        for ($event = null; $this->isBefore($index, ';'); $index++) {
            $class = $this->classReference($index);

            if ($class !== null && $this->tokenIs($index + 3, T_DOUBLE_ARROW)) {
                $event = $class;
            } elseif ($class !== null && $event !== null) {
                $this->addEdge($class, 'LISTENS_TO', $event, $this->tokens[$index]->line);
            }
        }
    }

    private function readSignature(string $name, int $open): void
    {
        $parameters = $this->parameterTypes($open);

        if ($this->method !== null && in_array($this->classKind, self::INJECTED_KINDS, strict: true)) {
            $this->addTypeEdges($this->method, 'INJECTS', array_merge(...$parameters));
        }

        if ($this->class !== null && $this->classKind === 'listener' && (str_starts_with($name, 'handle') || $name === '__invoke')) {
            $this->addTypeEdges($this->class, 'LISTENS_TO', $parameters[0] ?? []);
        }
    }

    /**
     * @param list<\PhpToken> $types
     */
    private function addTypeEdges(string $from, string $relation, array $types): void
    {
        foreach ($types as $type) {
            $this->addEdge($from, $relation, $this->resolve($type->text), $type->line);
        }
    }

    /**
     * @return array<int, list<\PhpToken>>
     */
    private function parameterTypes(int $open): array
    {
        $parameters = [];
        $position = 0;

        for ($index = $open + 1, $depth = 1; $depth > 0 && isset($this->tokens[$index]); $index++) {
            $token = $this->tokens[$index];
            $depth += $this->nesting($token);

            if ($depth === 1 && $token->is(',')) {
                $position++;
            } elseif ($depth === 1 && $this->isParameterType($index)) {
                $parameters[$position][] = $token;
            }
        }

        return $parameters;
    }

    private function isParameterType(int $index): bool
    {
        $token = $this->tokens[$index];

        return $token->is(self::NAME_TOKENS)
            && $this->tokenIs($index + 1, self::PARAMETER_NAMES)
            && !in_array(strtolower($token->text), self::SCALAR_TYPES, strict: true);
    }

    private function readEloquentRelation(PhpToken $member, int $open): void
    {
        $relation = strtolower($member->text);

        if (!in_array($relation, self::ELOQUENT_RELATIONS, strict: true)) {
            return;
        }

        $related = $relation === 'morphto'
            ? 'morph:' . ($this->literal($open + 1) ?? substr((string) strrchr($this->scope(), ':'), 1))
            : $this->classReference($open + 1);

        if ($related !== null) {
            $this->addEdge($this->scope(), 'HAS_RELATION', $related, $member->line);
        }
    }

    private function readStaticCall(string $class, PhpToken $member, int $index): void
    {
        $facade = str_replace(self::FACADES, '', $class);
        $method = strtolower($member->text);

        if ($facade === 'Route') {
            $this->readRoute($index + 1);
        } elseif ($facade === 'Schema' && in_array($method, self::SCHEMA_CHANGES, strict: true)) {
            $this->readSchema($method, $index + 3);
        } elseif ($facade === 'Event' && $method === 'listen') {
            $this->readListen($index + 3);
        } elseif (str_starts_with($method, 'dispatch') && !in_array($facade, self::DISPATCHING_FACADES, strict: true)) {
            $this->addEdge($this->scope(), 'DISPATCHES', $class, $member->line);
        }
    }

    private function readDispatch(PhpToken $token, int $index): void
    {
        $name = strtolower($token->text);
        $dispatches = $token->is(T_STRING) && ($name === 'event' || str_starts_with($name, 'dispatch'));

        if ($dispatches && $this->tokenIs($index + 1, '(') && $this->tokenIs($index + 2, T_NEW) && $this->tokenIs($index + 3, self::NAME_TOKENS)) {
            $this->addEdge($this->scope(), 'DISPATCHES', $this->resolve($this->tokens[$index + 3]->text), $token->line);
        }
    }

    private function addCall(string $target, int $line): void
    {
        $this->addEdge($this->scope(), 'CALLS', $target, $line);

        $class = explode('::', $target)[0];

        if ($this->fileKind === 'test' && $class !== $this->class && $class !== $this->parent) {
            $this->addEdge($this->scope(), 'TESTS', $class, $line);
        }
    }

    private function readSchema(string $method, int $open): void
    {
        $table = $this->literal($open + 1);

        if ($table === null) {
            return;
        }

        if ($method === 'create') {
            $this->addNode('table:' . $table, 'table', $this->tokens[$open]->line);
        }

        $this->addEdge($this->scope(), 'MIGRATES_TABLE', 'table:' . $table, $this->tokens[$open]->line);
    }

    private function readListen(int $open): void
    {
        $event = $this->classReference($open + 1);
        $listener = $this->tokenIs($open + 4, ',') ? $this->readHandler($open + 5) : null;

        if ($event !== null && $listener !== null) {
            $this->addEdge($listener[0], 'LISTENS_TO', $event, $this->tokens[$open]->line);
        }
    }

    /**
     * @return array{string, ?string}|null
     */
    private function readHandler(int $index): ?array
    {
        $class = $this->classReference($this->tokenIs($index, '[') ? $index + 1 : $index);

        if ($class === null) {
            return null;
        }

        return [$class, $this->tokenIs($index, '[') ? $this->literal($index + 5) : null];
    }

    private function classReference(int $index): ?string
    {
        if (!$this->tokenIs($index, self::NAME_TOKENS) || !$this->tokenIs($index + 1, T_DOUBLE_COLON) || !$this->tokenIs($index + 2, 'class')) {
            return null;
        }

        return $this->resolve($this->tokens[$index]->text);
    }

    private function literal(int $index): ?string
    {
        return $this->tokenIs($index, T_CONSTANT_ENCAPSED_STRING) ? trim($this->tokens[$index]->text, '\'"') : null;
    }

    private function closingParenthesis(int $open): int
    {
        $index = $open;
        $depth = $this->nesting($this->tokens[$open]);

        while ($depth > 0 && isset($this->tokens[$index + 1])) {
            $index++;
            $depth += $this->nesting($this->tokens[$index]);
        }

        return $index;
    }

    private function nesting(PhpToken $token): int
    {
        return match (true) {
            $token->is(['(', '[', T_ATTRIBUTE]) => 1,
            $token->is([')', ']']) => -1,
            default => 0,
        };
    }

    private static function laravelFileKind(string $path): string
    {
        return str_starts_with($path, 'database/migrations/') ? 'migration' : 'file';
    }

}
