<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Tax\Actions\QuoteTax;
use Liberu\Ecommerce\Tax\Contracts\CalculatesTax;
use Liberu\Ecommerce\Tax\Contracts\ValidatesTaxRegistration;
use Liberu\Ecommerce\Tax\Data\CalculationInput;
use Liberu\Ecommerce\Tax\Data\CalculationResult;
use Liberu\Ecommerce\Tax\Data\ExemptionClaim;
use Liberu\Ecommerce\Tax\Data\QuoteLineRequest;
use Liberu\Ecommerce\Tax\Data\QuoteRequest;
use Liberu\Ecommerce\Tax\Data\RegistrationValidation;
use Liberu\Ecommerce\Tax\Enums\Sourcing;
use Liberu\Ecommerce\Tax\Enums\Treatment;
use Liberu\Ecommerce\Tax\Livewire\Tests\TestCase;
use Liberu\Ecommerce\Tax\Models\Jurisdiction;
use Liberu\Ecommerce\Tax\Models\Quote;
use Liberu\Ecommerce\Tax\Models\QuoteLine;
use Liberu\Ecommerce\Tax\Models\RateVersion;
use Liberu\Ecommerce\Tax\Models\Registration;

/*
 * Every class is imported in full above, including Livewire's own in the test
 * files below. `use Livewire\Livewire;` aliases the *first* segment of the path,
 * and this package's namespace ends in `Livewire` — so a partially-qualified
 * `Livewire\Attributes\Locked` written under that import resolves somewhere
 * nobody intended. Import the leaf, never a prefix.
 */

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
// The boundary rules here are properties of the source, not of a run, so they
// need the application booted and no database at all.
uses(TestCase::class)->in('Boundary');
uses(TestCase::class)->in('Unit');

/** The tenant every helper below writes under. */
const TENANT = 7;

/** The quotes this surface renders all belong to somebody else's ledger. */
const OTHER_TENANT = 8;

function at(string $time = '2026-03-01 12:00:00'): Carbon
{
    return Carbon::parse($time);
}

/**
 * A jurisdiction the seller is registered in, with one standard rate.
 *
 * The registration is what makes tax chargeable at all — and it is exactly the
 * fact this surface must never disclose, so it is set up here and asserted
 * absent from every render.
 */
function registeredJurisdiction(
    string $code = 'GB',
    int $basisPoints = 2000,
    int $tenantId = TENANT,
    string $label = 'Standard rate',
    Treatment $treatment = Treatment::Taxable,
): Jurisdiction {
    $jurisdiction = Jurisdiction::query()->create([
        'tenant_id' => $tenantId,
        'code' => $code,
        'name' => $code.' jurisdiction',
        'sourcing' => Sourcing::Destination,
    ]);

    Registration::query()->create([
        'tenant_id' => $tenantId,
        'jurisdiction_id' => $jurisdiction->id,
        'registration_number' => 'SELLER-REG-'.$code,
        'effective_from' => at('2020-01-01 00:00:00'),
        'effective_to' => null,
    ]);

    RateVersion::query()->create([
        'tenant_id' => $tenantId,
        'jurisdiction_id' => $jurisdiction->id,
        'tax_class' => 'standard',
        'label' => $label,
        'treatment' => $treatment,
        'basis_points' => $basisPoints,
        'sequence' => 1,
        'compound' => false,
        'effective_from' => at('2020-01-01 00:00:00'),
    ]);

    return $jurisdiction;
}

/** A quote over one line, taxed at whatever the given jurisdiction charges. */
function aQuote(
    int $baseMinor = 1000,
    string $destination = 'GB',
    ?Carbon $quotedAt = null,
    int $tenantId = TENANT,
): Quote {
    $quotedAt ??= at();

    return app(QuoteTax::class)(new QuoteRequest(
        tenantId: $tenantId,
        currency: 'GBP',
        originCode: 'GB',
        destinationCode: $destination,
        lines: [new QuoteLineRequest('line-1', $baseMinor, false, 'standard')],
        quotedAt: $quotedAt,
        expiresAt: $quotedAt->copy()->addHour(),
    ));
}

/**
 * The idempotency fingerprint a claim against this quote produces.
 *
 * Rebuilt from the quote the same way the component rebuilds it, which is the
 * point: if the two ever disagree, the in-flight test below reports a conflict
 * instead of an in-flight duplicate, and says so loudly.
 */
function claimFingerprint(Quote $quote, string $number): string
{
    $now = Carbon::now();

    return (new QuoteRequest(
        tenantId: $quote->tenant_id,
        currency: $quote->currency,
        originCode: (string) $quote->origin_code,
        destinationCode: (string) $quote->destination_code,
        lines: array_map(
            static fn (QuoteLine $line): QuoteLineRequest => new QuoteLineRequest(
                $line->line_reference,
                $line->base_minor,
                $line->base_inclusive,
                $line->tax_class,
            ),
            array_values($quote->lines->all()),
        ),
        quotedAt: $now,
        expiresAt: $now->copy()->addHour(),
        rounding: $quote->rounding_strategy,
        exponent: $quote->exponent,
        exemption: new ExemptionClaim($number),
    ))->fingerprint();
}

/** The key the surface derives for a claim, which a test can only check by mirroring it. */
function derivedKey(string $nonce, string $reference, string $number): string
{
    return hash('sha256', $nonce.'|'.$reference.'|'.$number);
}

/** An authority that answers the same way every time. */
function bindValidator(bool $valid): void
{
    app()->bind(ValidatesTaxRegistration::class, fn () => new class($valid) implements ValidatesTaxRegistration
    {
        public function __construct(private readonly bool $valid) {}

        public function validate(string $jurisdictionCode, string $registrationNumber): RegistrationValidation
        {
            return new RegistrationValidation(
                valid: $this->valid,
                authority: 'test-authority',
                response: $this->valid ? 'registered' : 'not registered',
                validatedAt: Carbon::now(),
            );
        }
    });
}

/**
 * An authority that cannot be reached.
 *
 * The ordinary case, not the exotic one: an outbound call to a public VAT
 * service is slow and frequently down. The domain catches it, refuses the
 * exemption and charges tax, and this surface has to say so.
 */
function bindUnreachableValidator(): void
{
    app()->bind(ValidatesTaxRegistration::class, fn () => new class() implements ValidatesTaxRegistration
    {
        public function validate(string $jurisdictionCode, string $registrationNumber): RegistrationValidation
        {
            throw new RuntimeException('Connection to the validation authority timed out.');
        }
    });
}

/** A calculator that cannot produce a figure at all. */
function bindBrokenCalculator(): void
{
    app()->bind(CalculatesTax::class, fn () => new class() implements CalculatesTax
    {
        public function calculate(CalculationInput $input): CalculationResult
        {
            throw new RuntimeException('The calculator is unavailable.');
        }
    });
}
