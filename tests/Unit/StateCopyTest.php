<?php

declare(strict_types=1);

use Liberu\Ecommerce\Tax\Livewire\Enums\BreakdownState;
use Liberu\Ecommerce\Tax\Livewire\Enums\ClaimOutcome;

/*
 * The copy is part of the contract here, not decoration.
 *
 * "The failure state is the normal one" is only true if the failure states read
 * like ordinary outcomes rather than like apologies, and only useful if every
 * one of them leaves the shopper certain of the same thing: tax was charged.
 * These assertions run over every case of both enums, so a state added later
 * without that sentence fails on the day it is added rather than on the day
 * somebody is charged tax they believed they had been excused.
 */

it('tells a shopper tax was charged on every outcome except the one that was granted', function () {
    foreach (ClaimOutcome::cases() as $outcome) {
        if ($outcome === ClaimOutcome::Idle || $outcome === ClaimOutcome::Granted) {
            continue;
        }

        expect(mb_strtolower($outcome->message()))->toContain('tax has been charged');
    }
});

it('never implies tax was charged when the exemption was applied', function () {
    expect(mb_strtolower(ClaimOutcome::Granted->message()))->not->toContain('tax has been charged');
});

it('says nothing at all until a claim has been made', function () {
    expect(ClaimOutcome::Idle->message())->toBe('')
        ->and(ClaimOutcome::Idle->applied())->toBeFalse();
});

it('treats exactly one outcome as an applied exemption', function () {
    $applied = array_values(array_filter(ClaimOutcome::cases(), static fn (ClaimOutcome $o): bool => $o->applied()));

    expect($applied)->toBe([ClaimOutcome::Granted]);
});

it('gives every claim outcome a message a shopper could act on', function () {
    foreach (ClaimOutcome::cases() as $outcome) {
        if ($outcome === ClaimOutcome::Idle) {
            expect($outcome->message())->toBe('');

            continue;
        }

        expect(mb_strlen($outcome->message()))->toBeGreaterThan(30)
            ->and($outcome->message())->toEndWith('.');
    }
});

it('mentions no rate, jurisdiction or registration footprint in any message', function (string $forbidden) {
    $messages = array_merge(
        array_map(static fn (BreakdownState $state): string => $state->message(), BreakdownState::cases()),
        array_map(static fn (ClaimOutcome $outcome): string => $outcome->message(), ClaimOutcome::cases()),
    );

    foreach ($messages as $message) {
        // `toContain` is variadic: it takes the needle and nothing else, so a
        // failure message passed alongside would be read as a second needle.
        expect(mb_strtolower($message))->not->toContain($forbidden);
    }
})->with(['jurisdiction', 'nexus', 'basis point', 'rate table', 'not registered', 'no registration']);

it('shows figures in exactly one breakdown state', function () {
    $showing = array_values(array_filter(
        BreakdownState::cases(),
        static fn (BreakdownState $state): bool => $state->showsFigures(),
    ));

    expect($showing)->toBe([BreakdownState::Ready])
        ->and(BreakdownState::Ready->message())->toBe('');
});

it('says plainly that nothing was recalculated when a quote is no longer current', function () {
    // Expiry is refusal, not a silent recalculation. A surface that quietly
    // re-priced would show a different figure and no explanation for it.
    foreach ([BreakdownState::Expired, BreakdownState::Superseded] as $state) {
        expect($state->message())->toContain('Nothing has been recalculated');
    }
});

it('gives every breakdown state but the ready one something to say', function () {
    expect(BreakdownState::Ready->message())->toBe('');

    foreach (BreakdownState::cases() as $state) {
        if ($state === BreakdownState::Ready) {
            continue;
        }

        expect(mb_strlen($state->message()))->toBeGreaterThan(20)
            ->and($state->message())->toEndWith('.');
    }
});
