<?php

declare(strict_types=1);

use Marko\Config\Env;

return [
    'ttl' => Env::int('MARKO_CONFIG_LOADER_TEST_INT', 3600, min: 0),
];
