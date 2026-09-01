<?php

declare(strict_types = 1);

test('launch-article.cs.md install command resolves on minimum-stability: stable (issue #73)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/docs/marketing/launch-article.cs.md');

    expect($content)->toContain('composer require pekral/ai-olympus:dev-master --dev');

    // Without :dev-master, the command fails on the default minimum-stability: stable,
    // since the package ships no tagged release yet.
    expect($content)->not->toContain('composer require pekral/ai-olympus --dev');
});

test('launch-article.en.md install command resolves on minimum-stability: stable (issue #73)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/docs/marketing/launch-article.en.md');

    expect($content)->toContain('composer require pekral/ai-olympus:dev-master --dev');

    // Without :dev-master, the command fails on the default minimum-stability: stable,
    // since the package ships no tagged release yet.
    expect($content)->not->toContain('composer require pekral/ai-olympus --dev');
});
