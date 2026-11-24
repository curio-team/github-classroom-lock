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
            self::Pending => 'badge-secondary',
            self::Provisioning => 'badge-warning',
            self::Provisioned => 'badge-success',
            self::Failed => 'badge-danger',
            self::Archived => 'badge-secondary',
        };
    }
}
