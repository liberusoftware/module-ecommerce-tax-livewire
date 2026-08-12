<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Tax\Livewire\Enums;

/**
 * What happened to an exemption claim.
 *
 * The failure states are the normal ones here, and they are the reason this
 * enum exists at all. `ValidatesTaxRegistration` is an outbound call to an
 * external authority: slow, and frequently unavailable. When it answers no, or
 * cannot be reached, or is not bound at all, the exemption is refused and tax
 * **is** charged — fail closed, which is the host's one correct instinct in
 * this area.
 *
 * So every outcome of an actual claim except {@see self::Granted} says, in the
 * same words, that tax has been charged — {@see self::Idle} is the absence of a
 * claim rather than an outcome of one, and says nothing at all.
 * `tests/Unit/ClaimOutcomeTest.php` asserts that of every
 * case rather than of the handful somebody remembered to check: a surface that
 * leaves a shopper unsure whether their claim worked is the failure this module
 * exists to stop, and "unsure" is what vague copy produces.
 */
enum ClaimOutcome: string
{
    case Idle = 'idle';
    case Empty = 'empty';
    case Unauthorised = 'unauthorised';
    case Granted = 'granted';
    case Refused = 'refused';
    case Unavailable = 'unavailable';
    case Conflict = 'conflict';
    case InFlight = 'in_flight';
    case Failed = 'failed';

    public function message(): string
    {
        return match ($this) {
            self::Idle => '',
            self::Empty => 'Enter a registration number to claim an exemption. '.
                'Until you do, tax has been charged.',
            self::Unauthorised => 'You do not have access to claim an exemption on this order, '.
                'so tax has been charged.',
            self::Granted => 'That registration number was confirmed and the exemption has been applied. '.
                'The figures below are the ones now in force.',
            self::Refused => 'We could not confirm that registration number, so the exemption has not been '.
                'applied and tax has been charged. You can check the number and try again.',
            self::Unavailable => 'Registration numbers cannot be checked at the moment, so the exemption has '.
                'not been applied and tax has been charged. You can try again later.',
            self::Conflict => 'A different claim is already recorded against this order, so this one has not '.
                'been applied and tax has been charged.',
            self::InFlight => 'That registration number is still being checked, and tax has been charged in '.
                'the meantime. Try again in a moment.',
            self::Failed => 'Something went wrong while checking that registration number, so the exemption '.
                'has not been applied and tax has been charged. You can try again.',
        };
    }

    /** Whether an exemption was actually applied. Exactly one case says yes. */
    public function applied(): bool
    {
        return $this === self::Granted;
    }
}
