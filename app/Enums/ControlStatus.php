<?php

namespace App\Enums;

enum ControlStatus: string
{
    case InPlace = 'in_place';
    case Partly = 'partly';
    case NotInPlace = 'not_in_place';

    /**
     * Get the name of the status as shown in the report.
     */
    public function label(): string
    {
        return match ($this) {
            self::InPlace => 'In place',
            self::Partly => 'Partly',
            self::NotInPlace => 'Not in place',
        };
    }
}
