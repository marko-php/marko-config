<?php

declare(strict_types=1);

use Marko\Config\Env;

return [
    'enabled' => Env::bool('MARKO_CONFIG_LOADER_TEST_BOOL', true),
];
