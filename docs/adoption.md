# Adoption

## Installing

`liberusoftware/ecommerce-tax` is not on Packagist, and Composer honours
`repositories` **only from the root manifest**. This package declares the VCS
entry for its own CI, where it is root; a host installing it needs the same
entry in its own `composer.json`:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-tax" },
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-tax-livewire" }
]
```

Then:

```bash
composer require liberusoftware/ecommerce-tax-livewire
```

The package ships no `extra.laravel.providers`, so Composer installing it boots
nothing. The host's `ModuleManagerServiceProvider` globs the configured module
paths for each package's `module.json` and registers only the modules named in
`MODULES_ENABLED`:

```dotenv
MODULES_ENABLED=...,ecommerce-tax,ecommerce-tax-livewire
```

Both. This package renders quotes; the domain package produces and migrates
them, and enabling the surface without the domain gives a page with no ledger
behind it.

## No migrations

This package ships none and owns no table. Run the domain package's:

```bash
php artisan migrate
```

## Rendering it

```blade
<livewire:ecommerce-tax::breakdown
    :tenant-id="$store->id"
    :reference="$order->tax_quote_reference"
    :permitted="$viewer->owns($order)"
/>
```

Three parameters and every one of them matters.

- **`tenantId`** scopes every read. It is locked, so a client cannot re-point the
  component at another storefront's ledger.
- **`reference`** is the quote's opaque reference and the *only* thing the client
  holds. Never pass a base, a rate or a tax amount: the server prices from the
  reference on every request that acts, which is what stops a figure arriving
  from a browser and being trusted.
- **`permitted`** is your authorization decision. **It defaults to `false`.** A
  presentation package cannot know whether the person on the other end owns this
  basket, so it does not guess — omit this and the surface renders its
  unauthorised state. Pass the same decision you would put in a policy.

## Reacting to a claim

A granted or refused exemption produces a **new** quote — the domain has no
update path — and the component dispatches its reference:

```js
Livewire.on('tax-quote-changed', ({ reference }) => { /* … */ })
```

Record it against your order. This package does not write to an order and does
not know one exists. If you ignore the event, your order still references the
quote it always did, which is a correct figure and not the one now in force.

## Binding the validator

An exemption claim goes to `ValidatesTaxRegistration`, which has **no default
binding** and never will — the domain package names no authority and ships no
HTTP client.

```php
$this->app->bind(ValidatesTaxRegistration::class, YourVatValidator::class);
```

Until it is bound, a claim renders `unavailable` and tax is charged. Everything
that claims nothing carries on working: the module is not broken, it simply
cannot verify a claim nobody made.

**Keep your implementation fail-closed.** If it cannot reach the authority, let
it throw. The domain catches that, refuses the exemption, charges tax and
records `exemption_refused:unreachable`, and this surface says so. An
implementation that returns `valid: true` on a timeout would hand out exemptions
whenever the network hiccupped, and the evidence would say the authority
confirmed it.

**Do not make it slow on purpose, and do not make the shopper wait on a
retry loop inside it.** The call is already the slowest thing on the page; the
loading state is targeted at `claimExemption` and says the check can take a
while, but a validator that hangs for thirty seconds is a page nobody finishes.

## Styling it

The view ships unstyled markup with stable hooks: `data-tax-breakdown`,
`data-state`, `data-claim`, `data-breakdown`, `data-exemption-form`,
`data-claim-message`. Style those and you never touch the package.

If you need to change the markup itself, the view is namespaced, so Laravel's
own override path applies and this package ships no publish command to
duplicate it — put your version at
`resources/views/vendor/ecommerce-tax/breakdown.blade.php` and it wins.

Whatever you do to it, do not add the things it deliberately leaves out: the
rate, the jurisdiction, the line's treatment or the reason a line carried no
tax. `docs/domain.md` explains why each is absent, and the suite fails if any
of them reappears.

## Requirements

- PHP 8.5, Laravel 13, Livewire 4.
- `liberusoftware/ecommerce-tax` `^0.1.0`, which this package requires and is
  useless without.
