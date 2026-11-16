<?php

declare(strict_types = 1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

final readonly class HorizonAuditRun
{

    public const FIXTURES = __DIR__ . '/../Fixtures/laravel-horizon-audit/';

    public const EVALUATOR = __DIR__ . '/../../skills/laravel-horizon-audit/scripts/evaluate.php';

    public function __construct(public string $output, public string $errorOutput, public int $exitCode)
    {
    }

    public static function over(string $project, string ...$arguments): self
    {
        $process = new Process(['php', self::EVALUATOR, $project, ...$arguments]);
        $process->run();

        return new self($process->getOutput(), $process->getErrorOutput(), (int) $process->getExitCode());
    }

    public static function fixture(string $name, string ...$arguments): self
    {
        return self::over(self::FIXTURES . $name, ...$arguments);
    }

    /**
     * @return list<string>
     */
    public function outcomes(): array
    {
        return $this->stringList('outcomes');
    }

    /**
     * @return list<string>
     */
    public function stringList(string $path): array
    {
        return self::strings(decodedJsonField($this->output, $path));
    }

    public function string(string $path): ?string
    {
        $value = decodedJsonField($this->output, $path);

        return is_string($value) ? $value : null;
    }

    public function flag(string $path): bool
    {
        return decodedJsonField($this->output, $path) === true;
    }

    /**
     * @return list<int|string|null>
     */
    public function consumerField(string $field): array
    {
        $consumers = decodedJsonField($this->output, 'consumers');
        $values = [];

        foreach (is_array($consumers) ? $consumers : [] as $consumer) {
            $value = is_array($consumer) ? ($consumer[$field] ?? null) : null;
            $values[] = is_int($value) || is_string($value) ? $value : null;
        }

        return $values;
    }

    public function topSeverity(): ?string
    {
        return $this->string('findings.0.severity');
    }

    public function outcomeOf(string $rule): ?string
    {
        foreach ($this->outcomes() as $outcome) {
            if (str_starts_with($outcome, $rule . ':')) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function files(string $pattern): array
    {
        $files = glob(self::FIXTURES . $pattern);

        return $files === false ? [] : $files;
    }

    public static function digest(string $fixture): string
    {
        return md5(implode('|', array_map(md5_file(...), self::files($fixture . '/*'))));
    }

    /**
     * @return list<string>
     */
    public static function expectedList(string $fixture, string $field): array
    {
        return self::strings(decodedJsonField((string) file_get_contents(self::FIXTURES . $fixture . '/expected.json'), $field));
    }

    public static function expectedString(string $fixture, string $field): ?string
    {
        $value = decodedJsonField((string) file_get_contents(self::FIXTURES . $fixture . '/expected.json'), $field);

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

}
