<?php

namespace App\Enums;

/**
 * The provisioning steps for a group, in execution order.
 */
enum ProvisioningStep: string
{
    case Team = 'team';
    case Repository = 'repository';
    case RepositoryAccess = 'repository_access';
    case Project = 'project';
    case ProjectLinks = 'project_links';
    case ProjectAccess = 'project_access';

    public function label(): string
    {
        return match ($this) {
            self::Team => 'Team',
            self::Repository => 'Repository',
            self::RepositoryAccess => 'Team → repository',
            self::Project => 'Project',
            self::ProjectLinks => 'Project koppelen',
            self::ProjectAccess => 'Team → project',
        };
    }
}
