# Ecommerce: Tax Livewire

> This optional Livewire 4 presentation package provides interactive server-driven components for exactly one independent domain module. Components coordinate public queries/actions and presentation state; they do not own persistence, authorization decisions, tenancy, business rules, or theme identity. The package has no dependency on application `App\` classes.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-4-FB70A9)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-tax-livewire?sort=semver)](https://github.com/liberusoftware/module-ecommerce-tax-livewire/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-tax-livewire/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-tax-livewire/actions/workflows/tests.yml)

## What this is

The shopper's view of tax, and the one thing a shopper may do about it: see what
was charged, and claim an exemption with a VAT or tax registration number.

Every figure comes from a quote that `liberusoftware/ecommerce-tax` produced.
This package computes nothing, holds no rate, knows no jurisdiction, writes to
no order and files nothing. It renders evidence and dispatches an event.

```blade
<livewire:ecommerce-tax::breakdown
    :tenant-id="$store->id"
    :reference="$order->tax_quote_reference"
    :permitted="$viewer->owns($order)"
/>
```

## Features

- A tax breakdown rendered from a quote, in the fleet's settled money envelope —
  integer minor units in, a decimal **string** out, never a float.
- An exemption claim that **fails closed**: a negative answer, an unreachable
  authority and an unbound validator all refuse the exemption and charge tax.
- Seven breakdown states and nine claim outcomes, each with copy a shopper can
  act on. Every claim outcome but a granted one says, in the same words, that
  tax has been charged.
- Loading, empty, unauthorised and failure states for **every** action.
- Expiry and supersession rendered as their own states. Nothing is ever
  recalculated under the shopper's feet.
- Idempotency derived from a nonce minted once when the step is entered, so a
  double submit is one quote and a mistyped digit is still correctable.
- `#[Locked]` on every public property, enforced by reflection over the whole
  component registry, and **no money value in component state at all**.
- Laravel 13, PHP 8.5, Livewire 4, Pest 5.

## The failure state is the normal one

Validating a registration number means calling an external authority. It is
slow and it is frequently unavailable — which is why the host put that call in
the middle of its checkout path and why that was the wrong place for it.

So this surface treats refusal as the ordinary outcome rather than as an
exception to apologise for. When the number cannot be confirmed, for any reason,
the exemption is not applied, tax **is** charged, and the page says so in a
sentence. It never implies the claim succeeded, and it never leaves a shopper
unsure which of the two happened.

## What a shopper is never told

- **No rate table.** No basis points, no percentage, no rate label, no rate
  version id. The figure, never the arithmetic behind it.
- **No registration footprint.** "We charge no tax here" and "we are not
  registered here" look identical from outside and are not the same statement.
  A zero-tax quote reads as *"no tax has been charged on this order"* and never
  carries the reason the domain recorded — not `no_registration`, not
  `no_jurisdiction`, not the jurisdiction code, and not the line's treatment,
  which is a legal classification rather than anything a receipt has ever shown.

`tests/Feature/DisclosureTest.php` builds a quote whose reason is
`no_registration` and asserts every one of those tokens is absent from the
rendered markup.

## What this replaces

Two of the twelve host faults `liberusoftware/ecommerce-tax` was built to
replace are presentation faults, and this package is where they are fixed.

**`ViesService` puts a live outbound HTTP call to `ec.europa.eu` inside the
checkout path** (fault 12). Its fail-closed instinct was right and is kept; its
placement was not. Validation is a seam the host binds, the call happens on an
explicit shopper action rather than inside a checkout the shopper cannot get out
of, and it has a loading state, a timeout that is an ordinary outcome, and a
retry.

**Inclusive pricing does not exist in the host; only inclusive *display* does**
(fault 8). `config('ecommerce.display_prices_with_tax')` makes `displayPrice()`
add tax computed at the **store's** location while checkout charges tax at the
**destination**, so the price shown and the price charged are two different
numbers by construction and nothing discloses it. There is no display-tax
setting here. This surface renders the quote that was actually made, or it says
plainly that it cannot.

## Requirements

- **PHP 8.5**, **Laravel 13**, **Livewire 4**
- **Composer 2**
- `liberusoftware/ecommerce-tax` `^0.1.0`

## Quick start

`liberusoftware/ecommerce-tax` is not on Packagist, and Composer honours
`repositories` only from the root manifest, so the host needs both entries:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-tax" },
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-tax-livewire" }
]
```

```bash
composer require liberusoftware/ecommerce-tax-livewire
```

The package ships no `extra.laravel.providers`; installing it boots nothing.
Enable both modules:

```dotenv
MODULES_ENABLED=...,ecommerce-tax,ecommerce-tax-livewire
```

`docs/adoption.md` covers the three mount parameters — including `permitted`,
which is your authorization decision and **defaults to no**.

## Documentation

- [The domain](docs/domain.md) — every state, and why the copy is part of the contract
- [Adoption](docs/adoption.md) — installing, mounting, and binding the validator
- [Runbook](docs/runbook.md) — what to do when a shopper says they are exempt
- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/boilerplate-laravel) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-tax-livewire/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-tax-livewire" alt="Contributors to liberusoftware/module-ecommerce-tax-livewire">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-tax-livewire/graphs/contributors).
