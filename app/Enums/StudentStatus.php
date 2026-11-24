<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Imported = 'imported';
    case Invited = 'invited';
    case Joined = 'joined';
    case Failed = 'failed';
    case Offboarded = 'offboarded';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'Nog niet ingelogd',
            self::Invited => 'Uitgenodigd',
            self::Joined => 'Toegevoegd',
            self::Failed => 'Mislukt',
            self::Offboarded => 'Afgemeld',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Imported => 'badge-secondary',
            self::Invited => 'badge-warning',
            self::Joined => 'badge-success',
            self::Failed => 'badge-danger',
            self::Offboarded => 'badge-secondary',
        };
    }
}
