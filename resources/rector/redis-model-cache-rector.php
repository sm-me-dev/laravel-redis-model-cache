<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\NameSpace\RenameNamespaceRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        base_path('app'),
        base_path('tests'),
        base_path('database'),
        base_path('config'),
    ]);

    $rectorConfig->skip([
        base_path('vendor'),
        base_path('storage'),
        base_path('bootstrap'),
    ]);

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
