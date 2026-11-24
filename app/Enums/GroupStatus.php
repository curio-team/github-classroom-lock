<?php

namespace App\Enums;

enum GroupStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Provisioned = 'provisioned';
    case Failed = 'failed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Nog niet klaargezet',
            self::Provisioning => 'Bezig…',
            self::Provisioned => 'Klaar',
            self::Failed => 'Mislukt',
            self::Archived => 'Gearchiveerd',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'bg-gray-100 text-gray-700',
            self::Provisioning => 'bg-blue-100 text-blue-800',
            self::Provisioned => 'bg-green-100 text-green-800',
            self::Failed => 'bg-red-100 text-red-800',
            self::Archived => 'bg-slate-200 text-slate-600',
        };
    }
}
