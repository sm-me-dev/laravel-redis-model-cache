<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache;

use SMDev\RedisModelCache\Support\RedisModelServiceCore;

/**
 * Public model-cache service façade.
 *
 * The implementation lives in RedisModelServiceCore and the extracted support
 * collaborators. Keeping this stable entry point preserves the package API.
 */
class RedisModelService extends RedisModelServiceCore {}
