# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-12

### Added

- `ecommerce-tax::breakdown`, the shopper-facing Livewire 4 surface for
  `liberusoftware/ecommerce-tax`: a tax breakdown rendered from a quote, and an
  exemption claim made against it.
- Seven breakdown states and nine claim outcomes, each with copy a shopper can
  act on. Every claim outcome except a granted one says, in the same words, that
  tax has been charged.
- Fail-closed exemption claims: a negative answer, an unreachable authority and
  an unbound validator all refuse the exemption and charge tax, and the surface
  says so rather than implying the claim succeeded.
- Expiry and supersession rendered as their own states. Nothing is recalculated
  under the shopper's feet.
- Idempotency derived from a nonce minted once at mount, so a double submit is
  one quote and a corrected typo is not locked out.
- `#[Locked]` on every public property, with a reflection test over the whole
  component registry, and no money value held in component state at all.
- Disclosure tests asserting that no rate, rate label, jurisdiction code, line
  treatment or "no registration" reason reaches the rendered markup.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-tax-livewire/releases/tag/0.1.0
