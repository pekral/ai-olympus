<?php

declare(strict_types = 1);

function securityAssessmentSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the security review owns the four security verdicts of every code change', function (): void {
    $skill = securityAssessmentSource('skills/security-review/SKILL.md');

    expect(substr_count($skill, '## Change Security Assessment (issue #236)'))->toBe(1)
        ->and(substr_count($skill, '### Network Exposure of New Services and Ports (issue #236)'))->toBe(1);

    $assessment = substr($skill, (int) strpos($skill, '## Change Security Assessment (issue #236)'));

    $verdicts = ['**Untrusted data, deserialization, dependency.**', '**Public exposure.**', '**Security regression test.**', '**Automatic rule.**'];

    foreach ($verdicts as $verdict) {
        expect(substr_count($assessment, $verdict))->toBe(1);
    }

    expect($assessment)->toContain('`@skills/code-review/references/prevention-proposal.md` *The mechanism hierarchy*');
});

test('the network exposure walk names its sinks, its severity split, and its scope boundary', function (): void {
    $skill = securityAssessmentSource('skills/security-review/SKILL.md');
    $start = (int) strpos($skill, '### Network Exposure of New Services and Ports (issue #236)');
    $walk = substr($skill, $start, (int) strpos($skill, '### Dependencies & Configuration') - $start);

    expect($walk)->toContain('a compose `ports:` entry')
        ->and($walk)->toContain('`php artisan serve --host=0.0.0.0`')
        ->and($walk)->toContain('`127.0.0.1:3306:3306`')
        ->and($walk)->toContain('**Critical** when a data store, queue, admin, or debug surface is reachable from a public interface')
        ->and($walk)->toContain('`@skills/docker-patterns/SKILL.md` owns the shape of the image and its services');
});

test('the unsafe deserialization check names the PHP sinks', function (): void {
    $skill = securityAssessmentSource('skills/security-review/SKILL.md');

    expect(
        substr_count(
            $skill,
            'a cache or queue payload whose store an attacker can write) without `allowed_classes` set to `false` or an explicit class list',
        ),
    )->toBe(
        1,
    )
        ->and(
            substr_count(
                $skill,
                '`Phar::getMetadata()` / `PharFile::getMetadata()` without `allowed_classes`, and on PHP below 8.0 a `phar://` path built from input',
            ),
        )->toBe(
            1,
        )
        ->and(
            substr_count(
                $skill,
                '`yaml_parse*()` with `yaml.decode_php` enabled and Symfony `Yaml::parse()` with `Yaml::PARSE_OBJECT` on untrusted input',
            ),
        )->toBe(
            1,
        )
        ->and(substr_count($skill, 'and `yaml_parse` with callbacks'))->toBe(0)
        ->and(substr_count($skill, '`[\'allowed_classes\' => false]`'))->toBe(0);
});

test('every review comment carries the security assessment header line, defined once', function (): void {
    $rule = securityAssessmentSource('rules/code-review/general.md');
    $contract = securityAssessmentSource('skills/code-review-github/references/cr-wrapper-contract.md');
    $agent = securityAssessmentSource('agents/leonardo.md');

    expect(substr_count($rule, '**Security assessment — four questions every code change answers.**'))->toBe(1)
        ->and($rule)->toContain('`Merge verdict:`, `Input consistency:`, `Reuse (DRY):`, `Security assessment:`')
        ->and($rule)->toContain('`not applicable — the diff changes no code`')
        ->and(substr_count($contract, '- **`Security assessment:` header line.**'))->toBe(1)
        ->and(substr_count($agent, '**Security-assessment agenda:**'))->toBe(1);

    foreach (['untrusted data:', 'public exposure:', 'regression test:', 'automatic rule:'] as $answer) {
        expect($rule)->toContain('`' . $answer . '`');
    }

    foreach (['skills/code-review-github/templates/pr-comment-output.md', 'skills/code-review/templates/review-output.md'] as $template) {
        $content = securityAssessmentSource($template);

        expect(substr_count($content, '**Security assessment:** untrusted data: '))->toBe(1)
            ->and($content)->toContain(' · public exposure: ')
            ->and($content)->toContain(' · regression test: ')
            ->and($content)->toContain(' · automatic rule: ');
    }
});
