<?php

declare(strict_types = 1);

test('front-matter is readable for every shipped skill (issue #104)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $entries = scandir($packageDir . '/skills');
    assert($entries !== false);

    $skillDirectories = array_values(array_filter(
        $entries,
        static fn (string $entry): bool => is_file($packageDir . '/skills/' . $entry . '/SKILL.md'),
    ));

    // Without this, a skill whose front-matter stops parsing drops out of the expected
    // set and out of the catalog at the same time — and every other test here still
    // passes, because they compare the catalog against that same shrunken set.
    expect(array_keys(skillFrontMatterDescriptions()))->toEqualCanonicalizing($skillDirectories);
});

test('readme skill catalog lists every shipped skill exactly once (issue #104)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $catalog = installerDocsSection((string) file_get_contents($packageDir . '/README.md'), '## Skill Catalog');

    preg_match_all('/^\| \[`([^`]+)`\]\(skills\/([^)]+)\/\) \|/m', $catalog, $rows, PREG_SET_ORDER);
    $listed = array_map(static fn (array $row): string => $row[1], $rows);

    expect($listed)->toEqualCanonicalizing(array_keys(skillFrontMatterDescriptions()));
    expect(array_unique($listed))->toHaveCount(count($listed));
});

test('readme skill catalog links resolve to a real skill directory holding a SKILL.md (issue #104)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $catalog = installerDocsSection((string) file_get_contents($packageDir . '/README.md'), '## Skill Catalog');

    preg_match_all('/^\| \[`([^`]+)`\]\(skills\/([^)]+)\/\) \|/m', $catalog, $rows, PREG_SET_ORDER);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        // The label and the href must name the same skill, or a rename silently
        // relabels a row while still pointing at the old directory.
        expect($row[2])->toBe($row[1]);
        expect(is_file($packageDir . '/skills/' . $row[2] . '/SKILL.md'))->toBeTrue('catalog links to a non-skill: ' . $row[2]);
    }
});

test('every readme skill catalog description is derived from that skill front-matter (issue #104)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $catalog = installerDocsSection((string) file_get_contents($packageDir . '/README.md'), '## Skill Catalog');
    $descriptions = skillFrontMatterDescriptions();

    preg_match_all('/^\| \[`([^`]+)`\]\(skills\/[^)]+\/\) \| (.+?) \|$/m', $catalog, $rows, PREG_SET_ORDER);

    expect($rows)->toHaveCount(count($descriptions));

    foreach ($rows as $row) {
        expect($row[2])->toBe(skillCatalogDescription($descriptions[$row[1]]));
    }
});

test('readme skill count matches the number of catalog rows (issue #104)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($packageDir . '/README.md');
    $catalog = installerDocsSection($readme, '## Skill Catalog');

    $rowCount = preg_match_all('/^\| \[`[^`]+`\]\(skills\/[^)]+\/\) \|/m', $catalog);

    expect($readme)->toContain($rowCount . ' comprehensive Agent skills');
    expect($catalog)->toContain('All ' . $rowCount . ' skills');
});

test('every catalog description stays a single line (issue #104)', function (): void {
    foreach (skillFrontMatterDescriptions() as $skill => $description) {
        $line = skillCatalogDescription($description);

        expect($line)->not->toContain("\n");
        expect($line)->not->toBe('', $skill . ' derives an empty catalog description');
    }
});
