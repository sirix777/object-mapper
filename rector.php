<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function(RectorConfig $rectorConfig): void {
    $rectorConfig->disableParallel();

    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/test',
    ]);

    // "reason" is the documented public constructor parameter and accessor name.
    $rectorConfig->skip([
        RenameParamToMatchTypeRector::class    => [__DIR__ . '/src/Exception/MappingExecutionFailed.php'],
        RenamePropertyToMatchTypeRector::class => [__DIR__ . '/src/Exception/MappingExecutionFailed.php'],
    ]);

    $rectorConfig->sets([
        SetList::NAMING,
        SetList::CODE_QUALITY,
        SetList::PRIVATIZATION,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        LevelSetList::UP_TO_PHP_82,
    ]);
};
