# Runbook

## The page says tax was charged and the shopper insists they are exempt

Almost always the validator, and almost always the network. Look at the quote
the surface is pointing at:

```php
$quote = app(QuoteReader::class)->byReference($tenantId, $reference);

[$quote->claimed_registration_number, $quote->validation_authority, $quote->validation_outcome, $quote->exemption_reason];
```

- `validation_outcome` is `refused` and `validation_authority` is `unreachable`
  — the call threw. The shopper can simply try again; the surface allows it,
  because a refusal is usually a dropped packet rather than a verdict.
- `validation_outcome` is `refused` with a real authority name — the authority
  said no. `validation_response` holds what it said.
- `exemption_reason` is null and `claimed_registration_number` is null — no claim
  reached the domain at all. Either the shopper typed something the surface
  rejected (blank, over 64 characters, or characters outside
  `A-Za-z0-9 .-`), or `permitted` was false.

There is nothing to fix in the ledger in any of those cases. A claim is
re-made, not repaired: the shopper claims again and a new quote is written.

## Every claim comes back `unavailable`

Nothing is bound to `ValidatesTaxRegistration`. That is not a fault in this
package — the contract has no default binding and never will. Bind one:

```php
$this->app->bind(ValidatesTaxRegistration::class, YourVatValidator::class);
```

Reads keep working throughout; only claims refuse.

## The surface shows the unauthorised state for everybody

`permitted` defaults to `false`. Check what the host passes at mount. This is
working as designed: the package owns no authorization decision and fails
closed rather than guessing.

## The surface shows the empty state for a quote that exists

`QuoteReader` scopes by tenant, and a quote belonging to another tenant arrives
as `QuoteNotFound` — which this surface renders as empty, deliberately, because
"that exists but is not yours" is a disclosure. Check `tenantId` at the mount
site before you go looking for a missing row.

## The surface shows expired and the shopper wants the current figure

Quote again. Do not reach into `tax_quotes` and move `expires_at`: the row is
append-only, the domain will refuse the write, and the figure was correct under
the rates in force when it was made. A new quote is the supported answer and
both rows coexist.

## Two quotes appeared for one shopper

Expected. A claim produces a new quote rather than updating one, because there
is no update path. If you are seeing *many* per shopper, look for a host that
re-mounts the component on every render — a fresh mount mints a fresh nonce, and
the deduplication that stops a double-click is scoped to the life of one mount.
Mount once per checkout step.

## A quote reference stopped matching the order

The host is not listening for `tax-quote-changed`. A granted or refused claim
moves the surface onto a new quote and dispatches its reference; an order that
still points at the old one carries a figure that was correct and is no longer
the one in force.

## Diagnosing what the shopper actually saw

The rendered state is on the markup, not only in the logs:

```
data-state="expired" data-claim="refused"
```

Those two attributes are the whole of the surface's state machine, and a
screenshot from a shopper carries both.

## What this package will not do for you

It does not compute tax, hold a rate, know a jurisdiction, write to an order,
reverse anything, or file anything. If the figure is wrong, the answer is in
`liberusoftware/ecommerce-tax` — start with its own `docs/runbook.md` and its
`ReproduceQuote`, which recomputes a quote's total from the evidence recorded on
it with the rate tables emptied.
