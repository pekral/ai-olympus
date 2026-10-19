<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use CompileError;
use PhpToken;

final class GraphParser
{

    use GraphLaravelExtractors;

    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC];

    private const array OPEN_BRACES = ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

    private const array DECLARATIONS = [T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait'];

    private const array NOT_A_CONFIG_CALL = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];

    private readonly GraphNameResolver $names;

    private int $depth = 0;

    private ?string $class = null;

    private string $parent = 'parent';

    private int $classDepth = 0;

    private ?string $method = null;

    /**
     * @var list<\Pekral\AiOlympus\GraphNode>
     */
    private array $nodes = [];

    /**
     * @var array<string, \Pekral\AiOlympus\GraphEdge>
     */
    private array $edges = [];

    /**
     * @param list<\PhpToken> $tokens
     */
    private function __construct(private readonly string $fileId, private readonly string $fileKind, private readonly array $tokens)
    {
        $this->names = new GraphNameResolver();
    }

    public static function parse(string $path, string $code): GraphRecord
    {
        $hash = self::hash($code);

        try {
            $tokens = PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (CompileError $error) {
            return new GraphRecord($hash, [], [], sprintf('%s on line %d', $error->getMessage(), $error->getLine()));
        }

        $kind = preg_match('~(^|/)tests/|Test\.php$~', $path) === 1 ? 'test' : self::laravelFileKind($path);
        $parser = new self('file:' . $path, $kind, array_values(array_filter($tokens, static fn (PhpToken $token): bool => !$token->isIgnorable())));
        $parser->addNode($parser->fileId, $kind, 1);

        if (preg_match('~^config/([^/]+)\.php$~', $path, $matches) === 1) {
            $parser->addNode('config:' . $matches[1], 'config', 1);
        }

        $parser->walk();

        return new GraphRecord($hash, $parser->nodes, array_values($parser->edges));
    }

    public static function hash(string $code): string
    {
        return hash('xxh128', $code);
    }

    private function walk(): void
    {
        $count = count($this->tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $this->tokens[$index];

            if ($token->is(self::OPEN_BRACES)) {
                $this->depth++;
                $this->openRouteGroup();

                continue;
            }

            if ($token->is('}')) {
                $this->closeBrace();

                continue;
            }

            $index = $this->handle($token, $index);
        }
    }

    private function closeBrace(): void
    {
        unset($this->routeGroups[$this->depth]);
        $this->depth--;

        if ($this->class === null || $this->depth >= $this->classDepth) {
            return;
        }

        $this->closeModel();
        $this->class = null;
    }

    private function handle(PhpToken $token, int $index): int
    {
        return match (true) {
            $token->is(T_NAMESPACE) => $this->readNamespace($index),
            $token->is(T_USE) => $this->readUse($index),
            isset(self::DECLARATIONS[$token->id]) => $this->readDeclaration($token, $index),
            $token->is(T_FUNCTION) => $this->readMethod($index),
            $token->is(T_NEW) => $this->readNew($index),
            $token->is(T_VARIABLE) && $this->class !== null && $this->depth === $this->classDepth => $this->readProperty($token, $index),
            default => $this->readCall($token, $index),
        };
    }

    private function readNamespace(int $index): int
    {
        if ($this->tokenIs($index + 1, [T_STRING, T_NAME_QUALIFIED])) {
            $this->names->enterNamespace($this->tokens[$index + 1]->text);
        }

        return $index;
    }

    private function readUse(int $index): int
    {
        if ($this->tokenIs($index + 1, '(')) {
            return $index;
        }

        if ($this->class !== null && $this->depth === $this->classDepth) {
            return $this->readTraitUse($this->class, $index + 1);
        }

        return $this->tokenIs($index + 1, [T_FUNCTION, T_CONST]) ? $this->skipTo($index, ';') : $this->readImports($index + 1);
    }

    private function readTraitUse(string $class, int $index): int
    {
        for (; $this->isBefore($index, [';', '{']); $index++) {
            $token = $this->tokens[$index];

            if ($token->is(self::NAME_TOKENS)) {
                $this->addEdge($class, 'USES', $this->resolve($token->text), $token->line);
            }
        }

        return $this->tokenIs($index, '{') ? $this->skipTo($index, '}') : $index;
    }

    private function readImports(int $index): int
    {
        $prefix = '';

        for (; $this->isBefore($index, ';'); $index++) {
            $token = $this->tokens[$index];

            if ($token->is(T_NS_SEPARATOR)) {
                $prefix = ltrim($this->tokens[$index - 1]->text, '\\') . '\\';
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && !$this->tokenIs($index + 1, T_NS_SEPARATOR)) {
                $index = $this->addImport($prefix . ltrim($token->text, '\\'), $index);
            }
        }

        return $index;
    }

    private function addImport(string $name, int $index): int
    {
        $alias = GraphNameResolver::shortName($name);

        if ($this->tokenIs($index + 1, T_AS)) {
            $index += 2;
            $alias = $this->tokens[$index]->text;
        }

        $this->names->import($name, $alias);
        $this->addEdge($this->fileId, 'USES', $name, $this->tokens[$index]->line);

        return $index;
    }

    private function readDeclaration(PhpToken $token, int $index): int
    {
        if ($this->tokenIs($index - 1, T_NEW) || !$this->tokenIs($index + 1, T_STRING)) {
            return $index;
        }

        $id = $this->names->qualify($this->tokens[$index + 1]->text);

        $this->class = $id;
        $this->parent = 'parent';
        $this->classDepth = $this->depth + 1;

        for ($index += 2; $this->isBefore($index, '{');) {
            $index = $this->readRelation($id, $this->tokenIs($index, T_EXTENDS) ? 'EXTENDS' : 'IMPLEMENTS', $index + 1);
        }

        $this->classKind = $this->declarationKind(self::DECLARATIONS[$token->id], $token->line);
        $this->addNode($id, $this->classKind, $token->line);

        return $index - 1;
    }

    private function readRelation(string $id, string $relation, int $index): int
    {
        for (; $this->isBefore($index, [T_IMPLEMENTS, '{']); $index++) {
            $token = $this->tokens[$index];

            if (!$token->is(self::NAME_TOKENS)) {
                continue;
            }

            $target = $this->resolve($token->text);
            $this->addEdge($id, $relation, $target, $token->line);
            $this->parent = $relation === 'EXTENDS' ? $target : $this->parent;
        }

        return $index;
    }

    private function readMethod(int $index): int
    {
        if ($this->class === null || $this->depth !== $this->classDepth) {
            return $index;
        }

        $name = $this->tokens[$this->tokenIs($index + 1, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) ? $index + 2 : $index + 1];

        if ($name->is(T_STRING)) {
            $this->method = $this->class . '::' . $name->text;
            $this->addNode($this->method, 'method', $name->line);
            $this->readSignature($name->text, $this->skipTo($index, '('));
        }

        return $index;
    }

    private function readNew(int $index): int
    {
        $name = $this->tokens[$index + 1];

        if ($name->is(self::NAME_TOKENS)) {
            $this->addCall($this->resolve($name->text), $name->line);
        }

        return $index;
    }

    private function readCall(PhpToken $token, int $index): int
    {
        $this->readDispatch($token, $index);

        if (!$this->tokenIs($index + 2, T_STRING) || !$this->tokenIs($index + 3, '(')) {
            return $this->readConfig($token, $index);
        }

        $member = $this->tokens[$index + 2];

        if ($token->is(self::NAME_TOKENS) && $this->tokenIs($index + 1, T_DOUBLE_COLON)) {
            $this->addCall($this->resolve($token->text) . '::' . $member->text, $member->line);
            $this->readStaticCall($this->resolve($token->text), $member, $index);
        }

        if ($this->class !== null && $token->text === '$this' && $this->tokenIs($index + 1, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $this->addEdge($this->scope(), 'CALLS', $this->class . '::' . $member->text, $member->line);
            $this->readEloquentRelation($member, $index + 3);
        }

        return $index;
    }

    private function readConfig(PhpToken $token, int $index): int
    {
        $isConfigCall = strtolower(ltrim($token->text, '\\')) === 'config' && $this->tokenIs($index + 1, '(') && $this->tokenIs(
            $index + 2,
            T_CONSTANT_ENCAPSED_STRING,
        );

        if (!$isConfigCall || $this->tokenIs($index - 1, self::NOT_A_CONFIG_CALL)) {
            return $index;
        }

        $name = strtok((string) $this->literal($index + 2), '.');

        if ($name !== false) {
            $this->addEdge($this->scope(), 'USES_CONFIG', 'config:' . $name, $token->line);
        }

        return $index;
    }

    /**
     * @param int|string|list<int|string> $kind
     */
    private function tokenIs(int $index, int|string|array $kind): bool
    {
        return isset($this->tokens[$index]) && $this->tokens[$index]->is($kind);
    }

    /**
     * @param int|string|list<int|string> $stop
     */
    private function isBefore(int $index, int|string|array $stop): bool
    {
        return isset($this->tokens[$index]) && !$this->tokens[$index]->is($stop);
    }

    private function skipTo(int $index, string $stop): int
    {
        while ($this->isBefore($index, $stop)) {
            $index++;
        }

        return $index;
    }

    private function scope(): string
    {
        if ($this->class === null) {
            return $this->fileId;
        }

        return $this->method !== null && $this->depth > $this->classDepth ? $this->method : $this->class;
    }

    private function resolve(string $name): string
    {
        return match (strtolower($name)) {
            'self', 'static' => $this->class ?? $name,
            'parent' => $this->parent,
            default => $this->names->resolve($name),
        };
    }

    private function addNode(string $id, string $kind, int $line): void
    {
        $this->nodes[] = new GraphNode($id, $kind, $line);
    }

    private function addEdge(string $from, string $type, string $to, int $line): void
    {
        $this->edges[$from . '|' . $type . '|' . $to] ??= new GraphEdge($from, $type, $to, $line);
    }

}
