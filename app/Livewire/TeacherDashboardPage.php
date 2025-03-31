<?php

namespace App\Livewire;

use App\Settings\ChatSettings;
use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class TeacherDashboardPage extends Component
{
    public $showArchived = false;

    public $chatPassword;

    public function mount(ChatSettings $settings)
    {
        $this->chatPassword = $settings->chat_password;
    }

    public function render(ChatSettings $settings)
    {
        $teams = $this->showArchived
            ? \App\Models\Team::withArchived()
                ->with('members')
                ->get()
            : \App\Models\Team::with('members')
                ->get();
        $teamsLocked = $teams->every(function ($team) {
            return $team->locked;
        });

        $isChatActive = $settings->chat_active;

        return view('livewire.teacher-dashboard-page', compact('teams', 'teamsLocked', 'isChatActive'));
    }

    public function lockProjects($locked)
    {
        $client = new Client();
        $client->authenticate(config('app.github_token'), AuthMethod::ACCESS_TOKEN);
        $organization = config('app.github_organization');

        $client->organizations()->update($organization, [
            'has_organization_projects' => !$locked,
        ]);
    }

    public function updateChatPassword(ChatSettings $settings)
    {
        $settings->chat_password = $this->chatPassword;
        $settings->save();
    }

    private function getApiClientAndPaginator()
    {
        $client = new Client();
        $paginator  = new ResultPager($client);

        $client->authenticate(config('app.github_token'), AuthMethod::ACCESS_TOKEN);

        return [$client, $paginator];
    }

    /**
     * Calls the GitHub API to get the latest team data, stores it in the local database.
     */
    public function makeTeamSnapshot()
    {
        [$client, $paginator] = $this->getApiClientAndPaginator();
        $organization = config('app.github_organization');

        $teams = $paginator->fetchAll($client->teams(), 'all', [$organization]);

        foreach ($teams as $teamData) {
            $membersData = $paginator->fetchAll($client->teams(), 'members', [$teamData['slug'], $organization]);

            $team = \App\Models\Team::withArchived()->firstOrNew(['id' => $teamData['id']]);
            $team->name = $teamData['name'];
            $team->slug = $teamData['slug'];
            $team->save();

            $members = [];

            foreach ($membersData as $member) {
                $teamMember = \App\Models\TeamMember::firstOrNew([
                    'team_id' => $team->id,
                    'login' => $member['login'],
                ]);
                $teamMember->login = $member['login'];
                $teamMember->avatar_url = $member['avatar_url'];
                $teamMember->url = $member['url'];
                $teamMember->site_admin = $member['site_admin'];

                $members[] = $teamMember;
            }

            $team->members()->saveMany($members);
        }
    }

    /**
     * Removes the specified member from the given team. This is useful when
     * a member is removed from the team manually on GitHub, and we want to
     * keep the local database in sync.
     */
    public function removeFromTeamSnapshot($memberId)
    {
        \App\Models\TeamMember::findOrFail($memberId)->delete();
    }

    /**
     * Locks all teams by removing their members on GitHub.
     */
    public function lockAllTeams()
    {
        [$client] = $this->getApiClientAndPaginator();
        $organization = config('app.github_organization');

        $teams = \App\Models\Team::with('members')
            ->where('locked', false)
            ->get();

        /** @var \App\Models\Team $team */
        foreach ($teams as $team) {
            foreach ($team->members as $member) {
                $client->teams()->removeMember($team->slug, $member->login, $organization);
            }

            $team->locked = true;
            $team->save();
        }

        $this->lockProjects(true);
    }

    /**
     * Unlocks all teams by adding their members on GitHub.
     */
    public function unlockAllTeams()
    {
        [$client] = $this->getApiClientAndPaginator();
        $organization = config('app.github_organization');

        $teams = \App\Models\Team::with('members')->where('locked', true)->get();

        /** @var \App\Models\Team $team */
        foreach ($teams as $team) {
            foreach ($team->members as $member) {
                $client->teams()->addMember($team->slug, $member->login, $organization);
            }

            $team->locked = false;
            $team->save();
        }

        $this->lockProjects(false);
    }

    /**
     * Locks a team by removing their members on GitHub.
     */
    public function lockTeam($teamId)
    {
        [$client] = $this->getApiClientAndPaginator();
        $organization = config('app.github_organization');

        /** @var \App\Models\Team $team */
        $team = \App\Models\Team::withArchived()
            ->with('members')
            ->where('id', $teamId)
            ->first();

        foreach ($team->members as $member) {
            $client->teams()->removeMember($team->slug, $member->login, $organization);
        }

        $team->locked = true;
        $team->save();
    }

    /**
     * Unlocks a team by adding their members on GitHub.
     */
    public function unlockTeam($teamId)
    {
        [$client] = $this->getApiClientAndPaginator();
        $organization = config('app.github_organization');

        /** @var \App\Models\Team $team */
        $team = \App\Models\Team::withArchived()
            ->with('members')
            ->where('id', $teamId)
            ->first();

        foreach ($team->members as $member) {
            $client->teams()->addMember($team->slug, $member->login, $organization);
        }

        $team->locked = false;
        $team->save();
    }

    /**
     * Updates the team lock status by checking if the team members on GitHub match the local database.
     */
    public function refreshTeam($teamId)
    {
        [$client, $paginator] = $this->getApiClientAndPaginator();
        $organization = config('app.github_organization');

        /** @var \App\Models\Team $team */
        $team = \App\Models\Team::withArchived()
            ->with('members')
            ->where('id', $teamId)
            ->first();

        $membersData = $paginator->fetchAll($client->teams(), 'members', [$team->slug, $organization]);

        $members = [];

        foreach ($membersData as $member) {
            $teamMember = \App\Models\TeamMember::firstOrNew([
                'team_id' => $team->id,
                'login' => $member['login'],
            ]);
            $teamMember->login = $member['login'];
            $teamMember->avatar_url = $member['avatar_url'];
            $teamMember->url = $member['url'];
            $teamMember->site_admin = $member['site_admin'];

            $members[] = $teamMember;
        }

        $team->members()->saveMany($members);

        if ($team->members->count() === 0) {
            $team->locked = true;
        } else {
            $team->locked = $team->members->count() !== count($membersData);
        }
        $team->save();
    }

    /**
     * Sets the team as archived, which means it will be ignored by the (unlock) commands.
     */
    public function archiveTeam($teamId)
    {
        /** @var \App\Models\Team $team */
        $team = \App\Models\Team::where('id', $teamId)->first();
        $team->is_archived = true;
        $team->save();
    }

    /**
     * Sets the team as unarchived
     */
    public function unarchiveTeam($teamId)
    {
        /** @var \App\Models\Team $team */
        $team = \App\Models\Team::withArchived()->where('id', $teamId)->first();
        $team->is_archived = false;
        $team->save();
    }

    /**
     * Deletes a team from the local database.
     */
    public function deleteTeam($teamId)
    {
        if (\App\Models\TeamMember::where('team_id', $teamId)->count() > 0) {
            return redirect()->route('dashboard.teacher')->with('error', 'Team has members, cannot delete.');
        }

        /** @var \App\Models\Team $team */
        $team = \App\Models\Team::withArchived()
            ->where('id', $teamId)
            ->first();

        $team->delete();
    }

    /**
     * Lock the GPT chat.
     */
    public function lockChat(ChatSettings $settings)
    {
        $settings->chat_active = false;
        $settings->save();
    }

    /**
     * Unlock the GPT chat.
     */
    public function unlockChat(ChatSettings $settings)
    {
        $settings->chat_active = true;
        $settings->save();
    }
}
