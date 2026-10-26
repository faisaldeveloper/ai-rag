<?php

/**
 * PHPUnit bootstrap.
 *
 * Aborts the whole run unless the resolved default database is the dedicated
 * test database, so migrations and RefreshDatabase can never wipe the
 * development database (for example through a stale config cache that
 * ignores the overrides in phpunit.xml).
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

require __DIR__.'/../vendor/autoload.php';

const TestDatabaseName = 'knowledge_testing';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class);
$app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);

$connection = $app['config']->get('database.default');
$database = $app['config']->get("database.connections.{$connection}.database");

$app->flush();

if ($database !== TestDatabaseName) {
    fwrite(STDERR, sprintf(
        "\nRefusing to run tests: the [%s] connection uses database [%s], expected [%s].\n"
        ."Check phpunit.xml and run `php artisan config:clear`.\n\n",
        $connection,
        is_string($database) ? $database : var_export($database, true),
        TestDatabaseName,
    ));

    exit(1);
}
