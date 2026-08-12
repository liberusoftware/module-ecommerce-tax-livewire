<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Tax\Actions\SupersedeQuote;
use Liberu\Ecommerce\Tax\Livewire\Enums\BreakdownState;
use Liberu\Ecommerce\Tax\Models\Quote;
use Livewire\Livewire as LivewireFacade;

/*
 * The read half of the surface: four states the shopper is told about plainly,
 * and two — expired and superseded — that exist because the alternative is
 * quietly producing a fresh figure under their feet.
 */

beforeEach(function (): void {
    Carbon::setTestNow(at());
    registeredJurisdiction();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('starts in the loading state and defers the first read', function () {
    $quote = aQuote();

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])
        ->assertSet('state', BreakdownState::Loading)
        ->assertSee('Working out the tax on this order.')
        ->assertSee('wire:init="load"', escape: false)
        ->assertDontSee($quote->reference);
});

it('renders the breakdown once loaded', function () {
    $quote = aQuote(baseMinor: 1000);

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])
        ->call('load')
        ->assertSet('state', BreakdownState::Ready)
        ->assertSee($quote->reference)
        ->assertSee('10.00 GBP')
        ->assertSee('2.00 GBP')
        ->assertSee('12.00 GBP');
});

it('renders money as the settled envelope and never as a float', function () {
    // 19.99 at 20% is the pair that catches a float: `(int) (19.99 * 100)` is
    // 1998, and 1999 * 2000 / 10000 rounds half-up to 400 rather than to 399.8.
    $quote = aQuote(baseMinor: 1999);

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])
        ->call('load')
        ->assertSee('19.99 GBP')
        ->assertSee('4.00 GBP')
        ->assertSee('23.99 GBP')
        ->assertDontSee('19.990000')
        ->assertDontSee('3.998');
});

it('shows the empty state when no quote has been given yet', function () {
    LivewireFacade::test('ecommerce-tax::breakdown', ['tenantId' => TENANT, 'permitted' => true])
        ->call('load')
        ->assertSet('state', BreakdownState::Empty)
        ->assertSee('There is no tax breakdown to show for this order yet.');
});

it('shows the empty state for a quote belonging to another storefront', function () {
    registeredJurisdiction(tenantId: OTHER_TENANT);
    $theirs = aQuote(tenantId: OTHER_TENANT);

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $theirs->reference,
        'permitted' => true,
    ])
        ->call('load')
        // Not "forbidden", which would confirm the reference exists. A quote
        // that is not yours reads exactly like a quote that is not there.
        ->assertSet('state', BreakdownState::Empty)
        ->assertDontSee($theirs->reference);
});

it('shows the unauthorised state when the host has permitted nobody', function () {
    $quote = aQuote();

    // `permitted` is not passed: a presentation package owns no authorization
    // decision, so its default has to be no.
    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
    ])
        ->call('load')
        ->assertSet('state', BreakdownState::Unauthorised)
        ->assertSee('You do not have access to the tax breakdown for this order.')
        ->assertDontSee($quote->reference);
});

it('shows the expired state rather than quoting again behind the shopper', function () {
    $quote = aQuote();

    Carbon::setTestNow(at('2026-03-01 14:00:00'));

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])
        ->call('load')
        ->assertSet('state', BreakdownState::Expired)
        ->assertSee('Nothing has been recalculated.');

    expect(Quote::query()->count())->toBe(1);
});

it('shows the superseded state and names no successor to the shopper', function () {
    $superseded = aQuote();
    $superseding = aQuote();

    app(SupersedeQuote::class)($superseded, $superseding, 'a corrected destination');

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $superseded->reference,
        'permitted' => true,
    ])
        ->call('load')
        ->assertSet('state', BreakdownState::Superseded)
        ->assertSee('This tax breakdown has been replaced by a newer one.')
        ->assertDontSee($superseding->reference);
});

it('shows the failure state when the ledger cannot be read at all', function () {
    $quote = aQuote();

    Schema::drop('tax_quotes');

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])
        ->call('load')
        ->assertSet('state', BreakdownState::Failed)
        ->assertSee('We could not show the tax breakdown just now.');
});

it('reads a zero-tax quote as no tax charged', function () {
    // No jurisdiction matches, so the domain records a reason. The shopper is
    // told the outcome and never the reason: whether the seller has a
    // registration here is not a shopper-facing fact.
    $quote = aQuote(destination: 'FR');

    expect($quote->no_tax_reason)->toBe('no_jurisdiction');

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])
        ->call('load')
        ->assertSee('No tax has been charged on this order.')
        ->assertSee('0.00 GBP');
});

it('gives each action a loading region of its own', function () {
    $quote = aQuote();

    LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])
        ->call('load')
        ->assertSee('wire:target="load"', escape: false)
        ->assertSee('wire:target="claimExemption"', escape: false)
        // The key is the guarantee and the button is a courtesy, but a courtesy
        // that is asserted stays.
        ->assertSee('wire:loading.attr="disabled"', escape: false);
});
