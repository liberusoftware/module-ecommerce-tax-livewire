<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Tax\Livewire\Tests;

use Illuminate\Foundation\Application;
use Liberu\PackageTestbench\PackageTestCase;

/**
 * The package's own base case.
 *
 * `defineEnvironment()` calls the parent deliberately: the application key
 * PackageTestCase sets lands there, and a Livewire component that cannot
 * encrypt its snapshot dies on the missing key rather than on anything this
 * package did.
 */
abstract class TestCase extends PackageTestCase
{
    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
