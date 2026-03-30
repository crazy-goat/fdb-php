<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\ExplicitReturnNullRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\Php70\Rector\StaticCall\StaticCallOnNonStaticToInstanceCallRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddMethodCallBasedStrictParamTypeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(\Rector\ValueObject\PhpVersion::PHP_82)
    ->withSets([
        LevelSetList::UP_TO_PHP_82,
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        SetList::EARLY_RETURN,
    ])
    ->withSkip([
        RemoveUnusedPromotedPropertyRector::class,
        ExplicitReturnNullRector::class,
        AddMethodCallBasedStrictParamTypeRector::class,
        StaticCallOnNonStaticToInstanceCallRector::class,
        ReadOnlyClassRector::class => [
            __DIR__ . '/src/Database.php',
            __DIR__ . '/src/Transaction.php',
            __DIR__ . '/src/NativeClient.php',
        ],
    ]);
