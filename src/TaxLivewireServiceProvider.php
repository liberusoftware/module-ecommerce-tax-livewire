<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Tax\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Tax\Livewire\Components\TaxBreakdown;
use Livewire\Component;
use Livewire\Livewire as LivewireFacade;

/**
 * Registers nothing globally and boots only when the host enables the module.
 *
 * The package ships no `extra.laravel.providers`, so Composer installing it
 * boots nothing: the host's ModuleManagerServiceProvider globs
 * `config('modules.paths')` for each package's `module.json` and registers only
 * the modules named in `MODULES_ENABLED`.
 *
 * The facade is imported **under an alias** on purpose. This file's own
 * namespace ends in `Livewire`, so an unaliased `use Livewire\Livewire;` leaves
 * a bare `Livewire::` that a reader has to resolve by hand, and any
 * partially-qualified name written below it — `Livewire\Attributes\Locked`, say
 * — silently resolves inside this package instead of inside the framework. The
 * alias removes the ambiguity rather than relying on nobody tripping over it.
 */
class TaxLivewireServiceProvider extends ServiceProvider
{
    /**
     * Every component this package registers, by the name a host renders.
     *
     * Public because it is the registry: `tests/Feature/ComponentContractTest.php`
     * walks it to prove each name resolves and each component locks every public
     * property it has. A registry a test can enumerate is what makes "every
     * registered component" an assertion rather than a hope.
     *
     * @var array<string, class-string<Component>>
     */
    public const COMPONENTS = [
        'ecommerce-tax::breakdown' => TaxBreakdown::class,
    ];

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ecommerce-tax');

        // `resolveMissingComponent()` rather than `addNamespace()`, for two
        // reasons that both bite. Livewire 4's Finder::resolveClassComponentClassName()
        // returns null for any name carrying a `::` before it ever consults the
        // registry that `Livewire::component()` writes to, so a namespaced name
        // is only ever reached through a missing-component resolver. And
        // addNamespace() maps one component namespace onto exactly one class
        // namespace, which would make the names this package publishes a
        // function of where its classes happen to live.
        LivewireFacade::resolveMissingComponent(
            static fn (string $name): ?string => self::COMPONENTS[$name] ?? null,
        );
    }
}
