<?php

declare(strict_types=1);

use Marko\Config\ConfigLoader;
use Marko\Config\Env;
use Marko\Config\Exceptions\ConfigException;
use Marko\Config\Exceptions\ConfigLoadException;
use Marko\Core\Exceptions\DiscoveryCacheException;

const CONFIG_LOADER_TEST_VARIABLES = [
    'MARKO_CONFIG_LOADER_TEST_INT',
    'MARKO_CONFIG_LOADER_TEST_BOOL',
    'DISCOVERY_CACHE_ENABLED',
];

/**
 * Load a config file and return the ConfigLoadException it throws.
 */
function captureConfigLoadException(
    string $filePath,
): ConfigLoadException {
    try {
        new ConfigLoader()->load($filePath);
    } catch (ConfigLoadException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a ConfigLoadException to be thrown');
}

/**
 * Run a callback and return the ConfigException it throws.
 */
function captureConfigException(
    callable $callback,
): ConfigException {
    try {
        $callback();
    } catch (ConfigException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a ConfigException to be thrown');
}

/**
 * Clear the environment variables the fixture config files read.
 */
function clearConfigLoaderTestVariables(): void
{
    foreach (CONFIG_LOADER_TEST_VARIABLES as $name) {
        unset($_ENV[$name]);
        putenv($name);
    }
}

it('loads a valid config file and returns the array', function () {
    $loader = new ConfigLoader();

    $configPath = __DIR__ . '/fixtures/valid-config.php';

    $result = $loader->load($configPath);

    expect($result)->toBe([
        'database' => [
            'host' => 'localhost',
            'port' => 3306,
        ],
    ]);
});

it('throws ConfigLoadException when file does not exist', function () {
    $loader = new ConfigLoader();

    $loader->load('/non/existent/path/config.php');
})->throws(ConfigLoadException::class);

it('throws ConfigLoadException when file returns non-array', function () {
    $loader = new ConfigLoader();

    $configPath = __DIR__ . '/fixtures/returns-string.php';

    $loader->load($configPath);
})->throws(ConfigLoadException::class);

it('throws ConfigLoadException with helpful message for PHP syntax errors', function () {
    $loader = new ConfigLoader();

    $configPath = __DIR__ . '/fixtures/syntax-error.php';

    $loader->load($configPath);
})->throws(ConfigLoadException::class);

it('loadIfExists returns array when file exists', function () {
    $loader = new ConfigLoader();

    $configPath = __DIR__ . '/fixtures/valid-config.php';

    $result = $loader->loadIfExists($configPath);

    expect($result)->toBe([
        'database' => [
            'host' => 'localhost',
            'port' => 3306,
        ],
    ]);
});

it('loadIfExists returns null when file does not exist', function () {
    $loader = new ConfigLoader();

    $result = $loader->loadIfExists('/non/existent/path/config.php');

    expect($result)->toBeNull();
});

it('keeps the default suggestion and parse-error context for existing ConfigLoadException callers', function () {
    $exception = new ConfigLoadException(
        filePath: '/config/app.php',
        parseError: 'syntax error, unexpected end of file',
        message: 'Configuration file contains invalid PHP syntax',
    );

    expect($exception->getMessage())
        ->toBe('Configuration file contains invalid PHP syntax [file: /config/app.php]')
        ->and($exception->getContext())->toBe('Parse error: syntax error, unexpected end of file')
        ->and($exception->getSuggestion())
        ->toBe('Verify the file exists and contains valid PHP syntax returning an array.');
});

describe('exceptions thrown while a config file runs', function (): void {
    beforeEach(function (): void {
        clearConfigLoaderTestVariables();
    });

    afterEach(function (): void {
        clearConfigLoaderTestVariables();
    });

    it('names the config file when Env rejects an integer while loading', function (): void {
        $_ENV['MARKO_CONFIG_LOADER_TEST_INT'] = '1h';
        $configPath = __DIR__ . '/fixtures/env-invalid-int.php';

        $exception = captureConfigLoadException($configPath);

        expect($exception)->toBeInstanceOf(ConfigException::class)
            ->and($exception->getMessage())
            ->toBe(sprintf(
                'Environment variable "MARKO_CONFIG_LOADER_TEST_INT" must be an integer [file: %s]',
                $configPath,
            ))
            ->and($exception->getFilePath())->toBe($configPath);
    });

    it('keeps the Env context and suggestion when wrapping the rejection', function (): void {
        $_ENV['MARKO_CONFIG_LOADER_TEST_INT'] = '1h';

        $exception = captureConfigLoadException(__DIR__ . '/fixtures/env-invalid-int.php');
        $original = captureConfigException(fn () => Env::int('MARKO_CONFIG_LOADER_TEST_INT', 3600, min: 0));

        expect($exception->getContext())->toBe('Got "1h"')
            ->and($exception->getContext())->toBe($original->getContext())
            ->and($exception->getSuggestion())->toBe($original->getSuggestion())
            ->and($exception->getSuggestion())->toContain('remove it to use the default (3600)');
    });

    it('keeps the original Env exception as the previous exception', function (): void {
        $_ENV['MARKO_CONFIG_LOADER_TEST_INT'] = '1h';

        $exception = captureConfigLoadException(__DIR__ . '/fixtures/env-invalid-int.php');
        $previous = $exception->getPrevious();

        expect($previous)->toBeInstanceOf(ConfigException::class)
            ->not->toBeInstanceOf(ConfigLoadException::class)
            ->and($previous?->getMessage())->toBe(
                'Environment variable "MARKO_CONFIG_LOADER_TEST_INT" must be an integer',
            )
            ->and($previous?->getFile())->toEndWith('Env.php');
    });

    it('names the config file when Env rejects a boolean while loading', function (): void {
        $_ENV['MARKO_CONFIG_LOADER_TEST_BOOL'] = 'ture';
        $configPath = __DIR__ . '/fixtures/env-invalid-bool.php';

        $exception = captureConfigLoadException($configPath);

        expect($exception->getMessage())
            ->toBe(sprintf(
                'Environment variable "MARKO_CONFIG_LOADER_TEST_BOOL" must be a boolean [file: %s]',
                $configPath,
            ))
            ->and($exception->getContext())->toBe('Got "ture"')
            ->and($exception->getPrevious())->toBeInstanceOf(ConfigException::class);
    });

    it('names the config file when the discovery config rejects DISCOVERY_CACHE_ENABLED', function (): void {
        $_ENV['DISCOVERY_CACHE_ENABLED'] = 'maybe';
        $configPath = dirname(__DIR__, 3) . '/core/config/discovery.php';

        $exception = captureConfigLoadException($configPath);
        $previous = $exception->getPrevious();

        expect($exception->getMessage())
            ->toBe(sprintf(
                'Environment variable "DISCOVERY_CACHE_ENABLED" must be a boolean [file: %s]',
                $configPath,
            ))
            ->and($previous)->toBeInstanceOf(DiscoveryCacheException::class)
            ->and($exception->getContext())->toBe(
                'Got "maybe" while deciding whether boot may use the discovery cache',
            )
            ->and($exception->getSuggestion())->toContain('Set DISCOVERY_CACHE_ENABLED to one of');
    });

    it('lets non-Marko errors thrown by a config file pass through unchanged', function (): void {
        $loader = new ConfigLoader();

        expect(fn () => $loader->load(__DIR__ . '/fixtures/throws-runtime-exception.php'))
            ->toThrow(RuntimeException::class, 'Config file failed outside Marko');
    });

    it('leaves Env exceptions thrown outside the loader unchanged', function (): void {
        $_ENV['MARKO_CONFIG_LOADER_TEST_INT'] = '1h';

        $exception = captureConfigException(fn () => Env::int('MARKO_CONFIG_LOADER_TEST_INT', 3600));

        expect($exception)->not->toBeInstanceOf(ConfigLoadException::class)
            ->and($exception->getMessage())->toBe(
                'Environment variable "MARKO_CONFIG_LOADER_TEST_INT" must be an integer',
            )
            ->and($exception->getPrevious())->toBeNull();
    });
});
