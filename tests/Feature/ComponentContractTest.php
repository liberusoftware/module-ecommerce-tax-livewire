<?php

declare(strict_types=1);

use Liberu\Ecommerce\Tax\Livewire\TaxLivewireServiceProvider;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire as LivewireFacade;

/*
 * The contract every component in this package keeps, asserted over the
 * registry rather than over the one class somebody remembered.
 *
 * `TaxLivewireServiceProvider::COMPONENTS` is that registry. Walking it is what
 * makes "every registered component locks every public property" a fact rather
 * than a habit: a component added later without `#[Locked]` fails here on the
 * day it is added.
 *
 * The Livewire facade is imported under an alias because this package's own
 * namespace ends in `Livewire`. An unaliased import puts a bare `Livewire` in
 * scope, and any partially-qualified name written afterwards resolves against
 * it rather than against the framework.
 */

/** @return list<array{string, class-string}> */
function registeredComponents(): array
{
    $rows = [];

    foreach (TaxLivewireServiceProvider::COMPONENTS as $name => $class) {
        $rows[] = [$name, $class];
    }

    return $rows;
}

it('publishes at least one component, so nothing below passes vacuously', function () {
    expect(registeredComponents())->not->toBeEmpty();
});

it('resolves every component it publishes', function (string $name, string $class) {
    expect(LivewireFacade::exists($name))->toBeTrue()
        ->and(LivewireFacade::new($name))->toBeInstanceOf($class)
        ->and(is_subclass_of($class, Component::class))->toBeTrue();
})->with(registeredComponents(...));

it('resolves nothing it did not publish', function () {
    // The resolver answers for the names in the registry and null for anything
    // else. One that answered for any name under this prefix would make the
    // registry decorative.
    expect(LivewireFacade::exists('ecommerce-tax::not-a-component'))->toBeFalse();
});

it('locks every public property on every registered component', function (string $name, string $class) {
    $properties = array_filter(
        (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC),
        static fn (ReflectionProperty $property): bool => ! $property->isStatic()
            && $property->getDeclaringClass()->getName() === $class,
    );

    // Asserted non-empty as well, because a component holding no state at all
    // would satisfy the loop below without the loop ever running.
    expect($properties)->not->toBeEmpty();

    foreach ($properties as $property) {
        expect($property->getAttributes(Locked::class))->not->toBeEmpty();
    }
})->with(registeredComponents(...));

it('holds no money and no rate on any component property', function (string $name, string $class) {
    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        // A money value on a public property is a money value in the snapshot,
        // and a snapshot is a round trip through the client. The server prices
        // from an opaque reference on every request that acts.
        foreach (['minor', 'amount', 'total', 'price', 'rate', 'basis'] as $needle) {
            expect(mb_strtolower($property->getName()))->not->toContain($needle);
        }
    }
})->with(registeredComponents(...));
