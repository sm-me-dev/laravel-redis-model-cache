<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\NameSpace\RenameNamespaceRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/app',
        __DIR__.'/tests',
        __DIR__.'/database',
        __DIR__.'/config',
    ]);

    $rectorConfig->skip([
        __DIR__.'/vendor',
        __DIR__.'/storage',
        __DIR__.'/bootstrap',
    ]);

    // Handle both old namespace variants users might have
    $rectorConfig->rule(RenameNamespaceRector::class, [
        'old_namespace' => 'Sm_mE\RedisModelCache',
        'new_namespace' => 'SMDev\RedisModelCache',
    ]);

    $rectorConfig->rule(RenameNamespaceRector::class, [
        'old_namespace' => 'SmmE\RedisModelCache',
        'new_namespace' => 'SMDev\RedisModelCache',
    ]);

    $rectorConfig->rule(RenameNamespaceRector::class, [
        'old_namespace' => 'SmMe\RedisModelCache',
        'new_namespace' => 'SMDev\RedisModelCache',
    ]);
};
