<?php

namespace App\Http\Livewire;

use App\Settings\GeneralSettings;
use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class DashboardPage extends Component
{
    public $showArchived = false;

    public function render(GeneralSettings $settings)
    {
        $teams = $this->showArchived ? \App\Models\Team::withArchived()->with('members')->get() : \App\Models\Team::with('members')->get();
        $isChatActive = $settings->chat_active;
        $teamsLocked = $teams->every(function ($team) {
            return $team->locked;
        });
        return view('livewire.dashboard-page', compact('teams', 'teamsLocked', 'isChatActive'));
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
        $pattern = config('app.github_team_pattern');

        $teams = $paginator->fetchAll($client->teams(), 'all', [$organization]);

        $teams = array_filter($teams, function ($team) use($pattern) {
            return preg_match($pattern, $team['name']);
        });

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
    }

    /**
     * Locks a team by removing their members on GitHub.
     */
    public function lockTeam($teamId)
    {
        [$client] = $this->getApiClientAndPaginator();
        $organization = config('app.github_organization');

        /** @var \App\Models\Team $team */
        $team = \App\Models\Team::with('members')->where('id', $teamId)->first();

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
        $team = \App\Models\Team::with('members')->where('id', $teamId)->first();

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
        $team = \App\Models\Team::with('members')->where('id', $teamId)->first();

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

        $team->locked = $team->members->count() !== count($membersData);
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
            return redirect()->route('dashboard')->with('error', 'Team has members, cannot delete.');
        }

        /** @var \App\Models\Team $team */
        $team = \App\Models\Team::where('id', $teamId)->first();
        $team->delete();
    }

    /**
     * Lock the GPT chat.
     */
    public function lockChat(GeneralSettings $settings)
    {
        $settings->chat_active = false;
        $settings->save();
    }

    /**
     * Unlock the GPT chat.
     */
    public function unlockChat(GeneralSettings $settings)
    {
        $settings->chat_active = true;
        $settings->save();
    }
}
