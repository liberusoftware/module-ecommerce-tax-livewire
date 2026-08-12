# The domain

This package owns no domain. `liberusoftware/ecommerce-tax` owns rate
determination, the tax arithmetic and the evidence that both happened; this one
renders a shopper's slice of that evidence and lets them make one claim about
it. Every figure on the page came from a quote the domain produced, and nothing
here computes, adjusts, stores or files anything.

What follows is therefore not a domain model. It is the set of decisions that
turn a domain package into a page a stranger can look at without being told
things they should not be told, or led to believe things that are not true.

## The one component

| Name | Class |
|---|---|
| `ecommerce-tax::breakdown` | `Liberu\Ecommerce\Tax\Livewire\Components\TaxBreakdown` |

It is registered with `Livewire::resolveMissingComponent()` rather than
`Livewire::component()` or `addNamespace()`. Livewire 4's
`Finder::resolveClassComponentClassName()` returns null for any name carrying a
`::` *before* it consults the registry `component()` writes to, so a namespaced
name is only ever reachable through a missing-component resolver. And
`addNamespace()` maps one component namespace onto exactly one class namespace,
which would make the names this package publishes a function of where its
classes happen to sit.

`TaxLivewireServiceProvider::COMPONENTS` is public because it is the registry
the suite walks: "every registered component locks every public property" is an
assertion over that constant, not a habit.

## Two actions, and every state each of them can be in

`load()` and `claimExemption()`. Each has a loading, an empty, an unauthorised
and a failure state, and the surface never has an unnamed state.

| `BreakdownState` | When |
|---|---|
| `loading` | Before the deferred first read. `wire:init` defers it so this is a state a shopper sees rather than a branch nothing renders. |
| `ready` | Figures on screen. |
| `empty` | No quote given, or none this shopper can be shown. |
| `unauthorised` | The host did not permit this viewer. |
| `expired` | The quote's validity window has passed. |
| `superseded` | The quote was replaced by another. |
| `failed` | The ledger could not be read. |

| `ClaimOutcome` | When | Tax |
|---|---|---|
| `idle` | No claim made yet. | as quoted |
| `empty` | Nothing usable was entered. | charged |
| `unauthorised` | The host did not permit this viewer. | charged |
| `granted` | The authority confirmed the number. | not charged |
| `refused` | The authority said no, **or could not be reached**. | charged |
| `unavailable` | Nothing is bound to validate a claim. | charged |
| `conflict` | The key already carries a different claim. | charged |
| `in_flight` | The same claim is still being checked. | charged |
| `failed` | The claim could not be completed. | charged |

Every one of those except `granted` says, in the same words, that tax has been
charged. `tests/Unit/StateCopyTest.php` asserts that over every case rather than
over the ones somebody remembered, because a state added later without the
sentence is a shopper who thinks they were excused tax they were charged.

## The failure state is the normal one

`ValidatesTaxRegistration` is an outbound call to an external authority — the
VIES role, and the host put exactly that call inside its checkout path. It is
slow and it is frequently unavailable. So a refusal is not an exceptional
condition to apologise for; it is the ordinary answer, and the ordinary answer
is that tax is charged.

The domain does the failing-closed: an implementation that throws is caught
there and recorded as `exemption_refused:unreachable`, and the resulting quote
charges tax. This package's job is to say so, plainly, and never to leave the
shopper unsure which of the two happened. Hence one word — `refused` — for both
"the authority said no" and "the authority could not be reached": they differ in
cause, they do not differ in what was charged, and the shopper's next action is
the same either way.

## What a shopper is never told

Two things, and they are not the same kind of secret.

**A rate table** is commercial detail. The breakdown renders money and nothing
else: no basis points, no rate label, no percentage, no rate version id.

**A registration footprint** is worse. "We charge no tax here" and "we are not
registered here" look identical from outside and are not the same statement, and
the second is an operator fact with legal weight. A zero-tax quote therefore
reads as "no tax has been charged on this order" and never carries the reason
the domain recorded — not `no_registration`, not `no_jurisdiction`, not the
jurisdiction code, and not the line's treatment, which is a legal classification
rather than anything a receipt has ever shown.

`tests/Feature/DisclosureTest.php` builds a quote whose reason is
`no_registration` and asserts every one of those tokens is absent from the
rendered markup. It scrubs the quote reference and the component id out first:
both are random strings, either can contain a four-digit needle by chance, and a
test that fails one run in a thousand is worse than no test.

## Nothing on this surface is a client input

Every public property carries `#[Locked]`, enforced by reflection over the whole
registry. Beyond that, **no money value is a property at all**: the figures are
read from the quote inside `render()` and never enter the component's state, so
the snapshot the client round-trips holds `tenantId`, `reference`, `permitted`,
`nonce`, `state` and `claim` — and a test asserts exactly that list.

The one genuinely hostile input is the registration number a stranger types. It
is an **argument** to `claimExemption()` rather than a bound property: Alpine
holds it in the browser while it is being typed, the server checks it on arrival
and never keeps it. A `wire:model` property would have been the obvious thing
and is the wrong one — it is writable by definition, which is what `#[Locked]`
exists to prevent.

## Authorisation is the host's, and the default is no

A presentation package owns no authorization decision. It cannot know whether
the person on the other end owns this basket, and inventing a policy for
somebody else's actor model would be worse than useless. So the host decides at
mount, via `permitted`, and the default is `false`: a host that forgets renders
the unauthorised state rather than somebody else's tax.

## Idempotency: minted once, derived per claim

The nonce is minted in `mount()` — when the step is entered, not when the button
is pressed. A key minted at click time is a different key on every click and
deduplicates nothing.

The key sent to the domain is `sha256(nonce | quote reference | number)`. That
derivation is the wave-9 answer to a question the presentation brief insists is
domain-specific, and Tax answers it differently from both Checkout and Payment
Operations:

- In Payment Operations a conflict means a payment already exists under the key,
  so a fresh key would authorize a **second** payment. It refuses and mints
  nothing.
- In Checkout a conflict means nothing was committed, so a fresh key is safe.
- In Tax, a conflict means a *quote* exists under the key with a different claim
  on it. A second quote authorizes nothing, moves no money and costs nobody
  anything — quotes are append-only evidence and coexist by design. So refusing
  outright would strand a shopper who mistyped a digit, with no way ever to
  claim again.

Deriving from a stable nonce keeps both halves. The same number resubmitted is
byte-for-byte the same key, so a double-click is one quote — proved by seeding
the exact row a first submit leaves behind and watching the second be refused as
in-flight. A corrected number is a different key, so a typo is recoverable.
The nonce is unguessable, so nothing else can collide with it.

Two further consequences, both tested:

- A claim the authority **confirmed** is not put to it again; the recorded
  outcome stands.
- A claim it **refused** *is* retried, because the refusal is usually the
  network. A surface that treated a timeout as final would be telling a shopper
  to give up over a dropped packet.

## Expiry is refusal, here too

Both actions go through `QuoteReader::forConsumption()`, which raises on an
expired or a superseded quote. Each raise renders as its own state and says that
nothing has been recalculated. The surface never fetches a fresh figure under
the shopper's feet — that is a different number, arrived at under different
rates, with nobody having decided to change what they are charged.

A claim produces a **new** quote rather than updating one, because the domain has
no update path and wants none. The component then points at the new quote and
dispatches `tax-quote-changed` with its reference. Recording which quote is in
force is the host's job: this package does not write to an order and does not
know one exists.
