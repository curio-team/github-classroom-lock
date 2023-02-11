<?php

namespace App\Http\Livewire;

use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class DashboardPage extends Component
{
    public function render()
    {
        $teams = \App\Models\Team::with('members')->get();
        $teamsLocked = $teams->every(function ($team) {
            return $team->locked;
        });
        return view('livewire.dashboard-page', compact('teams', 'teamsLocked'));
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

            $team = \App\Models\Team::firstOrNew(['id' => $teamData['id']]);
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

        $teams = \App\Models\Team::with('members')->where('locked', false)->get();

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

        foreach ($teams as $team) {
            foreach ($team->members as $member) {
                $client->teams()->addMember($team->slug, $member->login, $organization);
            }

            $team->locked = false;
            $team->save();
        }
    }
}
