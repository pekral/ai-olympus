<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\JoinStringConcatRector;
use Rector\CodeQuality\Rector\Switch_\SwitchTrueToIfRector;
use Rector\Config\RectorConfig;
use Rector\Php81\Rector\ClassMethod\NewInInitializerRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictStringParamConcatRector;

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
    ]);

    $rectorConfig->import(__DIR__ . '/vendor/pekral/rector-rules/rector.php');
};
