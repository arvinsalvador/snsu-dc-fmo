<?php

namespace App\Enums;

enum FuelLevel: string
{
    case Empty = 'EMPTY';
    case Quarter = 'QUARTER';
    case Half = 'HALF';
    case ThreeQuarters = 'THREE_QUARTERS';
    case Full = 'FULL';

    public function label(): string
    {
        return match ($this) {
            self::Empty => 'Empty',
            self::Quarter => '1/4',
            self::Half => '1/2',
            self::ThreeQuarters => '3/4',
            self::Full => 'Full',
        };
    }

    /** Share of a full tank, used for the visual fuel bar. */
    public function percent(): int
    {
        return match ($this) {
            self::Empty => 0,
            self::Quarter => 25,
            self::Half => 50,
            self::ThreeQuarters => 75,
            self::Full => 100,
        };
    }
}
