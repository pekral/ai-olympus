<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\JoinStringConcatRector;
use Rector\CodeQuality\Rector\Switch_\SwitchTrueToIfRector;
use Rector\Config\RectorConfig;
use Rector\Php81\Rector\ClassMethod\NewInInitializerRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictStringParamConcatRector;
use Rector\TypeDeclarationDocblocks\Rector\Class_\AddReturnDocblockDataProviderRector;
use Rector\TypeDeclarationDocblocks\Rector\ClassMethod\AddParamArrayDocblockFromDataProviderRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/bin',
    ]);

    $rectorConfig->skip([
        __DIR__ . '/vendor',
        __DIR__ . '/src/ComposerPlugin.php',
        NewInInitializerRector::class,
        StrictStringParamConcatRector::class,
        JoinStringConcatRector::class,
        SwitchTrueToIfRector::class,
        // Both registered by vendor/pekral/rector-rules; rector/rector >= 2.5.9
        // turns these two data-provider docblock rules into a hard per-file error
        // ("is deprecated, as data provider docblock typing is not relevant to
        // code quality") instead of a no-op, breaking `rector process --dry-run`
        // on every file regardless of whether it touches a data provider.
        AddParamArrayDocblockFromDataProviderRector::class,
        AddReturnDocblockDataProviderRector::class,
    ]);

    $rectorConfig->import(__DIR__ . '/vendor/pekral/rector-rules/rector.php');
};
