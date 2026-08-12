<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Tax\Livewire\Enums\BreakdownState;
use Liberu\Ecommerce\Tax\Livewire\Enums\ClaimOutcome;
use Liberu\Ecommerce\Tax\Models\IdempotencyKey;
use Liberu\Ecommerce\Tax\Models\Quote;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire as LivewireFacade;

/*
 * The write half, and the reason this package was hard to get right.
 *
 * Validation is an outbound call to an external authority. It is slow, it is
 * frequently unavailable, and when it fails the exemption is refused and tax is
 * charged. So the failure states here are not edge cases to be swept into a
 * generic "something went wrong" — they are the ordinary path, and each one has
 * to leave the shopper certain that tax was charged.
 */

beforeEach(function (): void {
    Carbon::setTestNow(at());
    registeredJurisdiction();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** The surface, loaded and showing a taxed quote. */
function surface(?Quote $quote = null, bool $permitted = true): Testable
{
    $quote ??= aQuote();

    return LivewireFacade::test('ecommerce-tax::breakdown', [
        'tenantId' => TENANT,
        'reference' => $quote->reference,
        'permitted' => $permitted,
    ])->call('load');
}

it('applies an exemption the authority confirms', function () {
    bindValidator(valid: true);

    surface()
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Granted)
        ->assertSet('state', BreakdownState::Ready)
        ->assertSee('the exemption has been applied')
        ->assertSee('No tax has been charged on this order.')
        ->assertDispatched('tax-quote-changed');

    // A claim produces a new quote. The old one is not corrected and not
    // deleted: both are evidence, and which is in force is the host's to record.
    expect(Quote::query()->count())->toBe(2);
});

it('refuses the exemption and charges tax when the authority says no', function () {
    bindValidator(valid: false);

    surface()
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Refused)
        ->assertSee('We could not confirm that registration number')
        ->assertSee('tax has been charged')
        ->assertDontSee('No tax has been charged on this order.')
        ->assertSee('2.00 GBP');
});

it('refuses the exemption and charges tax when the authority cannot be reached', function () {
    // The ordinary case. A timeout is not an error state to apologise for — it
    // is the answer "we could not verify this", and the answer to that is tax.
    bindUnreachableValidator();

    surface()
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Refused)
        ->assertSee('tax has been charged')
        ->assertSee('2.00 GBP');

    $latest = Quote::query()->latest('id')->firstOrFail();

    expect($latest->validation_outcome)->toBe('refused')
        ->and($latest->exemption_reason)->toBe('exemption_refused:unreachable')
        ->and($latest->tax_total_minor)->toBe(200);
});

it('refuses the exemption and charges tax when nothing can validate a claim at all', function () {
    // ValidatesTaxRegistration has no default binding and never will. A host
    // that accepts claims without binding one gets a refusal, not a free pass.
    surface()
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Unavailable)
        ->assertSee('Registration numbers cannot be checked at the moment')
        ->assertSee('tax has been charged');

    expect(Quote::query()->count())->toBe(1);
});

it('refuses the exemption and charges tax when the calculation itself fails', function () {
    bindValidator(valid: true);

    $component = surface();

    // Bound after the quote on screen was produced: the failure under test is
    // the one that happens while the shopper is waiting, not at set-up time.
    bindBrokenCalculator();

    $component
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Failed)
        ->assertSee('Something went wrong while checking that registration number')
        ->assertSee('tax has been charged');

    expect(Quote::query()->count())->toBe(1);
});

it('asks for a registration number rather than guessing at one', function (string $entered) {
    bindValidator(valid: true);

    surface()
        ->call('claimExemption', $entered)
        ->assertSet('claim', ClaimOutcome::Empty)
        ->assertSee('Enter a registration number to claim an exemption.')
        ->assertSee('tax has been charged');

    expect(Quote::query()->count())->toBe(1);
})->with([
    'nothing at all' => '',
    'whitespace' => '   ',
    'longer than any registration number' => [str_repeat('G', 65)],
    'markup' => '<script>alert(1)</script>',
]);

it('will not let an unpermitted viewer claim anything', function () {
    bindValidator(valid: true);

    surface(permitted: false)
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Unauthorised)
        ->assertSet('state', BreakdownState::Unauthorised)
        ->assertSee('tax has been charged');

    expect(Quote::query()->count())->toBe(1);
});

it('refuses a claim against an expired quote rather than quoting a fresh one', function () {
    bindValidator(valid: true);

    $component = surface();

    Carbon::setTestNow(at('2026-03-01 14:00:00'));

    $component
        ->call('claimExemption', 'GB123456789')
        ->assertSet('state', BreakdownState::Expired)
        ->assertSee('Nothing has been recalculated.');

    expect(Quote::query()->count())->toBe(1);
});

it('sends the same key for the same claim, so a double submit is one quote', function () {
    bindValidator(valid: true);

    $quote = aQuote();
    $component = surface($quote);

    // Exactly the state a first submit leaves behind while it is still waiting
    // on the authority: the key claimed, the payload agreed, no quote yet.
    IdempotencyKey::query()->create([
        'tenant_id' => TENANT,
        'idempotency_key' => derivedKey((string) $component->get('nonce'), $quote->reference, 'GB123456789'),
        'fingerprint' => claimFingerprint($quote, 'GB123456789'),
        'quote_id' => null,
    ]);

    $component
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::InFlight)
        ->assertSee('still being checked')
        ->assertSee('tax has been charged');

    expect(Quote::query()->count())->toBe(1);
});

it('refuses a claim whose key already carries a different payload', function () {
    bindValidator(valid: true);

    $quote = aQuote();
    $component = surface($quote);

    IdempotencyKey::query()->create([
        'tenant_id' => TENANT,
        'idempotency_key' => derivedKey((string) $component->get('nonce'), $quote->reference, 'GB123456789'),
        'fingerprint' => str_repeat('0', 64),
        'quote_id' => null,
    ]);

    $component
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Conflict)
        ->assertSee('A different claim is already recorded against this order')
        ->assertSee('tax has been charged');

    expect(Quote::query()->count())->toBe(1);
});

it('mints its key once, at mount, and derives every claim from it', function () {
    bindValidator(valid: true);

    $quote = aQuote();
    $component = surface($quote);
    $nonce = (string) $component->get('nonce');

    $component->call('claimExemption', 'GB123456789');

    expect(IdempotencyKey::query()->pluck('idempotency_key')->all())
        ->toBe([derivedKey($nonce, $quote->reference, 'GB123456789')])
        // The nonce does not move when the component re-renders, which is what
        // makes a retry the same key rather than a fresh one.
        ->and($component->get('nonce'))->toBe($nonce);
});

it('does not put a confirmed claim to the authority a second time', function () {
    bindValidator(valid: true);

    surface()
        ->call('claimExemption', 'GB123456789')
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Granted);

    expect(Quote::query()->count())->toBe(2);
});

it('lets a shopper retry after a refusal, because the refusal is usually the network', function () {
    bindUnreachableValidator();

    $component = surface()->call('claimExemption', 'GB123456789');

    expect(Quote::query()->count())->toBe(2);

    bindValidator(valid: true);

    $component
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Granted);

    expect(Quote::query()->count())->toBe(3);
});

it('lets a shopper correct a mistyped number instead of locking the step out', function () {
    bindValidator(valid: true);

    $component = surface()->call('claimExemption', 'GB000000000');

    $component
        ->call('claimExemption', 'GB123456789')
        ->assertSet('claim', ClaimOutcome::Granted)
        ->assertDontSee('A different claim is already recorded');

    expect(Quote::query()->count())->toBe(3);
});
