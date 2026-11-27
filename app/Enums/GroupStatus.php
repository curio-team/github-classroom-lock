<?php

namespace App\Enums;

enum GroupStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Provisioned = 'provisioned';
    case Failed = 'failed';
    case Archived = 'archived';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Nog niet klaargezet',
            self::Provisioning => 'Bezig…',
            self::Provisioned => 'Klaar',
            self::Failed => 'Mislukt',
            self::Archived => 'Gearchiveerd',
            self::Deleted => 'Verwijderd van GitHub',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'badge-secondary',
            self::Provisioning => 'badge-warning',
            self::Provisioned => 'badge-success',
            self::Failed => 'badge-danger',
            self::Archived, self::Deleted => 'badge-secondary',
        };
    }
}
