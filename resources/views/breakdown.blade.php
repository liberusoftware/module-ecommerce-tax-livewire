{{--
    The shopper's view of tax.

    Money and nothing else. No rate, no jurisdiction, no treatment and no reason
    for one: those are evidence for an operator and an auditor, and a zero-tax
    line here reads as "no tax charged" rather than as a statement about where
    the seller is registered.

    Every action has a loading region of its own, targeted by name, so a shopper
    is never left looking at a stale figure wondering whether anything is
    happening. `wire:loading` is the client half; `BreakdownState::Loading` is
    the server half, and the surface starts there because `wire:init` defers the
    first read.
--}}
<div data-tax-breakdown data-state="{{ $state->value }}" data-claim="{{ $claim->value }}" wire:init="load">
    <h2>Tax</h2>

    <p data-loading="load" wire:loading wire:target="load" role="status">
        Working out the tax on this order.
    </p>

    @if ($state->message() !== '')
        <p data-state-message role="status" aria-live="polite">{{ $state->message() }}</p>
    @endif

    @if ($breakdown !== null)
        <table data-breakdown>
            <caption>Tax on order {{ $breakdown['reference'] }}</caption>
            <thead>
                <tr>
                    <th scope="col">Item</th>
                    <th scope="col">Before tax</th>
                    <th scope="col">Tax</th>
                    <th scope="col">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($breakdown['lines'] as $line)
                    <tr data-line="{{ $line['reference'] }}">
                        <th scope="row">{{ $line['reference'] }}</th>
                        <td>{{ $line['net']['decimal'] }} {{ $line['net']['currency'] }}</td>
                        <td>{{ $line['tax']['decimal'] }} {{ $line['tax']['currency'] }}</td>
                        <td>{{ $line['gross']['decimal'] }} {{ $line['gross']['currency'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr data-totals>
                    <th scope="row">Total</th>
                    <td>{{ $breakdown['net']['decimal'] }} {{ $breakdown['net']['currency'] }}</td>
                    <td>{{ $breakdown['tax']['decimal'] }} {{ $breakdown['tax']['currency'] }}</td>
                    <td>{{ $breakdown['gross']['decimal'] }} {{ $breakdown['gross']['currency'] }}</td>
                </tr>
            </tfoot>
        </table>

        @unless ($breakdown['taxed'])
            <p data-untaxed>No tax has been charged on this order.</p>
        @endunless

        {{--
            The number is never bound to a public property: it is passed as an
            argument to the action, checked on arrival and not kept. Alpine holds
            it in the browser for exactly as long as the shopper is typing it.
        --}}
        <form data-exemption-form x-data="{ number: '' }" x-on:submit.prevent="$wire.claimExemption(number)">
            <h3>Claiming an exemption</h3>

            <label for="tax-registration-number">VAT or tax registration number</label>
            <input
                id="tax-registration-number"
                name="registration_number"
                type="text"
                inputmode="text"
                maxlength="64"
                autocomplete="off"
                x-model="number"
                aria-describedby="tax-exemption-help"
            >

            <p id="tax-exemption-help">
                We check the number with the authority that issued it. Until it is confirmed,
                tax is charged.
            </p>

            <button type="submit" data-claim-button wire:loading.attr="disabled" wire:target="claimExemption">
                Claim exemption
            </button>

            <p data-loading="claimExemption" wire:loading wire:target="claimExemption" role="status">
                Checking that registration number with the issuing authority. This can take a while.
            </p>
        </form>
    @endif

    @if ($claim->message() !== '')
        <p data-claim-message role="status" aria-live="polite">{{ $claim->message() }}</p>
    @endif
</div>
