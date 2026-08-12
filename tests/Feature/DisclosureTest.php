<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Tax\Enums\Sourcing;
use Liberu\Ecommerce\Tax\Enums\Treatment;
use Liberu\Ecommerce\Tax\Models\Jurisdiction;
use Liberu\Ecommerce\Tax\Models\Quote;
use Livewire\Livewire as LivewireFacade;

/*
 * What a shopper must never be told.
 *
 * Two facts, and they are not the same kind of secret. A rate table is
 * commercial detail nobody outside the operator needs. A *registration
 * footprint* is worse: "we charge no tax here" and "we are not registered here"
 * look identical from the outside and are not the same statement, and the
 * second one is an operator fact that a shopper-facing page has no business
 * publishing. So a zero-tax quote reads as "no tax has been charged" and never
 * carries the reason behind it.
 *
 * The assertions below scrub the quote's own reference out of the markup before
 * looking for numbers. The reference is 32 hex characters and any four-digit
 * needle can occur inside one by chance; a test that fails one run in a
 * thousand is worse than no test.
 */

beforeEach(function (): void {
    Carbon::setTestNow(at());
});

/** The rendered markup, with the identifiers that legitimately appear removed. */
function scrubbed(Quote $quote): string
{
    $component = LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])->call('load');

    // The component id is twenty random alphanumerics and the reference is
    // thirty-two hex ones. Either can contain a four-digit needle by chance,
    // and a test that fails one run in a thousand is worse than no test.
    return str_replace([$quote->reference, $component->id()], '', $component->html(true));
}

it('says no tax was charged without saying why', function () {
    // A jurisdiction that matches, with no registration behind it. The domain
    // records `no_registration` as the reason, which is exactly the fact that
    // must not reach the page.
    Jurisdiction::query()->create([
        'tenant_id' => TENANT,
        'code' => 'ZZ',
        'name' => 'Somewhere the seller does not collect',
        'sourcing' => Sourcing::Destination,
    ]);

    $quote = aQuote(destination: 'ZZ');

    expect($quote->no_tax_reason)->toBe('no_registration');

    $html = scrubbed($quote);

    expect($html)->toContain('No tax has been charged on this order.')
        ->and($html)->not->toContain('no_registration')
        ->and($html)->not->toContain('registered')
        ->and($html)->not->toContain('nexus')
        ->and($html)->not->toContain('jurisdiction')
        ->and($html)->not->toContain('ZZ');
});

it('renders no rate, no rate label and no jurisdiction on a taxed quote', function () {
    registeredJurisdiction(code: 'GB', basisPoints: 1750, label: 'Standard rate');

    $quote = aQuote(baseMinor: 4000);

    expect($quote->tax_total_minor)->toBe(700);

    $html = scrubbed($quote);

    expect($html)->toContain('7.00 GBP')
        // The figure, never the arithmetic behind it.
        ->and($html)->not->toContain('1750')
        ->and($html)->not->toContain('17.5')
        ->and($html)->not->toContain('basis')
        ->and($html)->not->toContain('Standard rate')
        ->and($html)->not->toContain('SELLER-REG-GB')
        ->and($html)->not->toContain('jurisdiction');
});

it('renders no line treatment, which is a legal classification and not a receipt', function () {
    // A rate version is never revised; a different one is declared. This is a
    // zero-rated supply in scope, which is not the same thing as an exempt one
    // and not the same thing as no rate at all — and the shopper is told none
    // of those three, only the figure.
    registeredJurisdiction(basisPoints: 0, treatment: Treatment::ZeroRated);

    $quote = aQuote();

    expect($quote->lines->first()?->treatment)->toBe(Treatment::ZeroRated);

    $html = scrubbed($quote);

    expect($html)->toContain('No tax has been charged on this order.')
        ->and($html)->not->toContain('zero_rated')
        ->and($html)->not->toContain('taxable')
        ->and($html)->not->toContain('treatment');
});

it('keeps money out of the snapshot the client round-trips', function () {
    registeredJurisdiction();

    $quote = aQuote();

    $component = LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => true,
    ])->call('load');

    // Everything the client holds, in full. A base or a tax amount in here would
    // be a figure the shopper could edit and the server would have to trust.
    expect(array_keys($component->snapshot['data']))
        ->toBe(['tenantId', 'reference', 'permitted', 'nonce', 'state', 'claim']);
});
