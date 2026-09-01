<?php

declare(strict_types = 1);

test('SECURITY.md install commands resolve on minimum-stability: stable (issue #73)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/SECURITY.md');

    expect($content)->toContain('When you `composer require pekral/ai-olympus:dev-master --dev`, Composer may ask:');
    expect($content)->toContain('composer require pekral/ai-olympus:dev-master --dev --no-plugins   # skip the plugin during install');

    // No untagged occurrence: without :dev-master, the command fails on the default
    // minimum-stability: stable, since the package ships no tagged release yet.
    expect($content)->not->toContain('composer require pekral/ai-olympus`,');
    expect($content)->not->toContain('composer require pekral/ai-olympus --dev --no-plugins');
});
