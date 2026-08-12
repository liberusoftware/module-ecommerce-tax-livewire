<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Tax\Livewire\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View as ViewFactory;
use Liberu\Ecommerce\Tax\Actions\QuoteTax;
use Liberu\Ecommerce\Tax\Data\ExemptionClaim;
use Liberu\Ecommerce\Tax\Data\QuoteLineRequest;
use Liberu\Ecommerce\Tax\Data\QuoteRequest;
use Liberu\Ecommerce\Tax\Exceptions\IdempotencyConflict;
use Liberu\Ecommerce\Tax\Exceptions\IdempotencyInFlight;
use Liberu\Ecommerce\Tax\Exceptions\QuoteExpired;
use Liberu\Ecommerce\Tax\Exceptions\QuoteNotFound;
use Liberu\Ecommerce\Tax\Exceptions\QuoteSuperseded;
use Liberu\Ecommerce\Tax\Exceptions\RegistrationValidationUnavailable;
use Liberu\Ecommerce\Tax\Livewire\Enums\BreakdownState;
use Liberu\Ecommerce\Tax\Livewire\Enums\ClaimOutcome;
use Liberu\Ecommerce\Tax\Models\Quote;
use Liberu\Ecommerce\Tax\Models\QuoteLine;
use Liberu\Ecommerce\Tax\Queries\QuoteReader;
use Liberu\Ecommerce\Tax\Support\Money;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * What a shopper is shown about tax, and the one thing they may do about it.
 *
 * Two actions, `load()` and `claimExemption()`, each with a loading, an empty,
 * an unauthorised and a failure state. Everything else about this class follows
 * from four rules.
 *
 * **It discloses no rate and no registration footprint.** The breakdown renders
 * money and nothing else: no basis points, no rate label, no jurisdiction code,
 * no line treatment and no `no_tax_reason`. A zero-tax quote reads as "no tax
 * charged" — never as "we have no nexus here", which is an operator fact and
 * not a shopper-facing one. `tests/Boundary/DisclosureTest.php` renders a quote
 * whose reason is `no_registration` and asserts every one of those tokens is
 * absent from the response.
 *
 * **Nothing here is a client input.** Every public property carries `#[Locked]`,
 * enforced by reflection over the whole registry. No money value is a property
 * at all: the figures are read from the quote inside `render()` and never enter
 * the component's state, so there is no snapshot for a client to tamper with.
 * The one genuinely hostile input, a registration number typed by a stranger, is
 * an argument to `claimExemption()` — validated on arrival and never held.
 *
 * **Expiry is refusal, never a silent recalculation.** Both actions go through
 * `QuoteReader::forConsumption()`, which raises on an expired or superseded
 * quote, and each raise renders as its own state. The surface never fetches a
 * fresh figure under the shopper's feet.
 *
 * **A claim that cannot be validated charges tax.** See {@see ClaimOutcome}.
 */
class TaxBreakdown extends Component
{
    /**
     * The tenant this surface was mounted for.
     *
     * Locked, so a client cannot re-point the component at another storefront's
     * ledger. Every read below is scoped by it.
     */
    #[Locked]
    public int $tenantId = 0;

    /**
     * The quote being shown: an opaque reference and nothing else.
     *
     * This is the whole of what the client holds. A base, a rate or a tax amount
     * arriving from a browser would be a price the shopper chose, so the server
     * prices from this reference on every request that acts.
     *
     * The server does move it — a granted or refused claim produces a *new*
     * quote, and this points at it afterwards — which is not the same as a
     * client moving it.
     */
    #[Locked]
    public string $reference = '';

    /**
     * Whether the host has decided this viewer may see and act on this order.
     *
     * A presentation package owns no authorization decision: it cannot know
     * whether the person on the other end owns this basket. So the host says,
     * at mount, and **the default is no** — a host that forgets renders the
     * unauthorised state rather than somebody else's tax.
     */
    #[Locked]
    public bool $permitted = false;

    /**
     * The entropy behind this step's idempotency key, minted once at mount.
     *
     * Minted here rather than at click time, which is what makes the mechanism
     * work at all: a key minted when the button is pressed is a different key on
     * every press, and deduplicates nothing.
     *
     * The key sent to the domain is derived from this nonce, the quote being
     * claimed against and the number claimed — see {@see self::idempotencyKey()}
     * for why that derivation, rather than one flat key, is the right answer for
     * this domain.
     */
    #[Locked]
    public string $nonce = '';

    #[Locked]
    public BreakdownState $state = BreakdownState::Loading;

    #[Locked]
    public ClaimOutcome $claim = ClaimOutcome::Idle;

    public function mount(int $tenantId, string $reference = '', bool $permitted = false): void
    {
        $this->tenantId = $tenantId;
        $this->reference = $reference;
        $this->permitted = $permitted;
        $this->nonce = bin2hex(random_bytes(16));
    }

    /**
     * Fetch the breakdown.
     *
     * Deferred behind `wire:init` so the loading state is a state the shopper
     * actually sees, rather than a branch nothing ever renders.
     *
     * A quote that is not this tenant's reads exactly like a quote that does not
     * exist, because `QuoteReader` scopes by tenant and both arrive here as
     * `QuoteNotFound`. Telling those two apart is a disclosure and there is
     * nothing a shopper could do with it.
     */
    public function load(): void
    {
        if (! $this->permitted) {
            $this->state = BreakdownState::Unauthorised;

            return;
        }

        if (trim($this->reference) === '') {
            $this->state = BreakdownState::Empty;

            return;
        }

        try {
            App::make(QuoteReader::class)->forConsumption($this->tenantId, $this->reference, Carbon::now());

            $this->state = BreakdownState::Ready;
        } catch (QuoteNotFound) {
            $this->state = BreakdownState::Empty;
        } catch (QuoteExpired) {
            $this->state = BreakdownState::Expired;
        } catch (QuoteSuperseded) {
            $this->state = BreakdownState::Superseded;
        } catch (Throwable) {
            $this->state = BreakdownState::Failed;
        }
    }

    /**
     * Claim an exemption against the quote on screen.
     *
     * The number is an argument rather than a bound property on purpose. It is
     * the only hostile input this surface takes, and a public property would put
     * it in the snapshot for the life of the component; as an argument it is
     * checked on arrival, used once, and gone.
     *
     * There is no update path in the domain and none is wanted: a claim produces
     * a **new** quote, and this component then points at it. Both quotes remain
     * in the ledger, which is what lets an auditor see that a claim was made and
     * what was decided about it.
     */
    public function claimExemption(string $registrationNumber = ''): void
    {
        if (! $this->permitted) {
            $this->claim = ClaimOutcome::Unauthorised;
            $this->state = BreakdownState::Unauthorised;

            return;
        }

        $number = trim($registrationNumber);

        // A trust boundary, so the check is here and not left to the column
        // width. Blank and 500 characters of paste are the same answer to the
        // shopper: nothing usable was entered, and tax stands.
        if ($number === '' || mb_strlen($number) > 64 || preg_match('/^[A-Za-z0-9 .\-]+$/', $number) !== 1) {
            $this->claim = ClaimOutcome::Empty;

            return;
        }

        try {
            $this->applyClaim($number);
        } catch (QuoteNotFound) {
            $this->state = BreakdownState::Empty;
            $this->claim = ClaimOutcome::Failed;
        } catch (QuoteExpired) {
            $this->state = BreakdownState::Expired;
            $this->claim = ClaimOutcome::Failed;
        } catch (QuoteSuperseded) {
            $this->state = BreakdownState::Superseded;
            $this->claim = ClaimOutcome::Failed;
        } catch (RegistrationValidationUnavailable) {
            $this->claim = ClaimOutcome::Unavailable;
        } catch (IdempotencyInFlight) {
            $this->claim = ClaimOutcome::InFlight;
        } catch (IdempotencyConflict) {
            $this->claim = ClaimOutcome::Conflict;
        } catch (Throwable) {
            $this->claim = ClaimOutcome::Failed;
        }
    }

    public function render(): View
    {
        $quote = $this->state->showsFigures() ? $this->quote() : null;

        return ViewFactory::make('ecommerce-tax::breakdown', [
            'state' => $this->state,
            'claim' => $this->claim,
            'breakdown' => $quote instanceof Quote ? $this->breakdown($quote) : null,
        ]);
    }

    /**
     * Re-quote with the claim attached, and adopt whatever came back.
     *
     * `forConsumption()` first, so a quote that expired while the page sat open
     * refuses here instead of being silently re-priced. The request is rebuilt
     * from the quote's own record — the bases, the currency, the rounding
     * strategy and the addresses are all on it — so no figure the client holds
     * takes part in it.
     */
    private function applyClaim(string $number): void
    {
        $now = Carbon::now();
        $original = App::make(QuoteReader::class)->forConsumption($this->tenantId, $this->reference, $now);

        // A confirmed claim is not re-put to the authority. A refused one is:
        // the authority being unreachable is the ordinary case, and a shopper
        // who cannot retry after a timeout has been told to give up.
        if ($original->claimed_registration_number === $number && $original->validation_outcome === 'valid') {
            $this->claim = ClaimOutcome::Granted;

            return;
        }

        $quoted = App::make(QuoteTax::class)($this->request($original, $number, $now));

        $this->reference = $quoted->reference;
        $this->state = BreakdownState::Ready;
        $this->claim = $quoted->validation_outcome === 'valid' ? ClaimOutcome::Granted : ClaimOutcome::Refused;

        // The host owns the order and has to record which quote is in force.
        // Dispatching the reference is the whole of this package's coordination
        // role: it does not write to an order, and does not know one exists.
        $this->dispatch('tax-quote-changed', reference: $quoted->reference);
    }

    private function request(Quote $original, string $number, Carbon $now): QuoteRequest
    {
        // The validity window the operator chose, carried forward rather than
        // reinvented here. Seconds off two timestamps, because Carbon's diff
        // helpers return floats and this package computes nothing in floats.
        $window = $original->expires_at->getTimestamp() - $original->quoted_at->getTimestamp();

        return new QuoteRequest(
            tenantId: $this->tenantId,
            currency: $original->currency,
            originCode: (string) $original->origin_code,
            destinationCode: (string) $original->destination_code,
            lines: array_map(
                static fn (QuoteLine $line): QuoteLineRequest => new QuoteLineRequest(
                    reference: $line->line_reference,
                    baseMinor: $line->base_minor,
                    inclusive: $line->base_inclusive,
                    taxClass: $line->tax_class,
                ),
                array_values($original->lines->all()),
            ),
            quotedAt: $now,
            expiresAt: $now->copy()->addSeconds(max(1, $window)),
            rounding: $original->rounding_strategy,
            exponent: $original->exponent,
            exemption: new ExemptionClaim($number),
            idempotencyKey: $this->idempotencyKey($number),
        );
    }

    /**
     * The key this claim is made under.
     *
     * One key per (step, quote, number) rather than one flat key for the step,
     * and the difference is the domain's, not a preference. A conflict in Tax
     * means a quote already exists under the key with a different claim on it;
     * unlike a payment, a second quote authorizes nothing and costs nobody
     * anything, so refusing outright would be wrong — but so would minting a
     * fresh key at click time, which deduplicates nothing at all.
     *
     * Deriving the key from a nonce minted once at mount keeps both properties.
     * The same number resubmitted is byte-for-byte the same key, so a
     * double-click is one quote; a corrected typo is a different key, so a
     * shopper who mistyped is not locked out of ever claiming again. The nonce
     * is unguessable, so no other surface can collide with this one.
     */
    private function idempotencyKey(string $number): string
    {
        return hash('sha256', $this->nonce.'|'.$this->reference.'|'.$number);
    }

    private function quote(): ?Quote
    {
        try {
            return App::make(QuoteReader::class)->byReference($this->tenantId, $this->reference);
        } catch (QuoteNotFound) {
            return null;
        }
    }

    /**
     * Money, and only money.
     *
     * Everything else on a quote line — its treatment, the reason behind it, the
     * rate applications underneath it — is evidence for an operator and an
     * auditor. A shopper gets the figures and the reference, which is what a
     * receipt has always been.
     *
     * @return array{
     *     reference: string,
     *     lines: list<array{reference: string, net: array{minor: int, currency: string, exponent: int, decimal: string}, tax: array{minor: int, currency: string, exponent: int, decimal: string}, gross: array{minor: int, currency: string, exponent: int, decimal: string}}>,
     *     net: array{minor: int, currency: string, exponent: int, decimal: string},
     *     tax: array{minor: int, currency: string, exponent: int, decimal: string},
     *     gross: array{minor: int, currency: string, exponent: int, decimal: string},
     *     taxed: bool,
     * }
     */
    private function breakdown(Quote $quote): array
    {
        $currency = $quote->currency;
        $exponent = $quote->exponent;

        return [
            'reference' => $quote->reference,
            'lines' => array_map(
                static fn (QuoteLine $line): array => [
                    'reference' => $line->line_reference,
                    'net' => (new Money($line->net_minor, $currency, $exponent))->toArray(),
                    'tax' => (new Money($line->tax_minor, $currency, $exponent))->toArray(),
                    'gross' => (new Money($line->gross_minor, $currency, $exponent))->toArray(),
                ],
                array_values($quote->lines->all()),
            ),
            'net' => $quote->netTotal()->toArray(),
            'tax' => $quote->taxTotal()->toArray(),
            'gross' => $quote->grossTotal()->toArray(),
            'taxed' => $quote->tax_total_minor > 0,
        ];
    }
}
