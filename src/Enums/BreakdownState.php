<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Tax\Livewire\Enums;

use Liberu\Ecommerce\Tax\Livewire\Components\TaxBreakdown;

/**
 * What the breakdown is currently showing, and what it says while showing it.
 *
 * Seven states rather than "loaded or not", because four of them are things a
 * shopper has to be told plainly: nothing to show, not yours to see, expired,
 * and replaced. A surface that renders an expired quote as though it were
 * current, or quietly fetches a fresh figure to avoid saying so, changes what
 * somebody is charged without anyone deciding to.
 *
 * The copy lives here rather than in the view so a test can read it. Every
 * message is a sentence a shopper could act on, and none of them says anything
 * about where the seller is or is not registered — see the class docblock on
 * {@see TaxBreakdown}.
 */
enum BreakdownState: string
{
    case Loading = 'loading';
    case Ready = 'ready';
    case Empty = 'empty';
    case Unauthorised = 'unauthorised';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Failed = 'failed';

    public function message(): string
    {
        return match ($this) {
            self::Loading => 'Working out the tax on this order.',
            self::Ready => '',
            self::Empty => 'There is no tax breakdown to show for this order yet.',
            self::Unauthorised => 'You do not have access to the tax breakdown for this order.',
            self::Expired => 'This tax breakdown has expired, so the figures below are no longer current. '.
                'Nothing has been recalculated. Ask for a new quote to see what would be charged now.',
            self::Superseded => 'This tax breakdown has been replaced by a newer one. '.
                'Nothing has been recalculated here.',
            self::Failed => 'We could not show the tax breakdown just now. Please try again.',
        };
    }

    /** Whether there are figures worth rendering underneath the message. */
    public function showsFigures(): bool
    {
        return $this === self::Ready;
    }
}
