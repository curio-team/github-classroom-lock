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
            self::Imported => 'bg-gray-100 text-gray-700',
            self::Invited => 'bg-amber-100 text-amber-800',
            self::Joined => 'bg-green-100 text-green-800',
            self::Failed => 'bg-red-100 text-red-800',
            self::Offboarded => 'bg-slate-200 text-slate-600',
        };
    }
}
