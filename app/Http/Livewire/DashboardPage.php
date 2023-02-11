<?php

namespace App\Http\Livewire;

use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use Livewire\Component;

class DashboardPage extends Component
{
    public function render()
    {
        dd($this->getTeamsAndMembers());
        return view('livewire.dashboard-page');
    }

    // Gets the teams and members from the GitHub API, using an access token from the .env file
    public function getTeamsAndMembers()
    {
        $client = new Client();
        $client->authenticate(config('app.github_token'), AuthMethod::ACCESS_TOKEN);

        $organization = 'curio-studenten';

        $paginator  = new ResultPager($client);
        $teams = $paginator->fetchAll($client->teams(), 'all', [$organization]);

        $descriptionStartsWith = 'created by GitHub Classroom';
        $filter = function ($team) use($descriptionStartsWith) {
            return strpos($team['description'], $descriptionStartsWith) > -1
                && strpos($team['name'], 'pvb-') === 0;
        };

        $teams = array_filter($teams, $filter);

        echo '<pre>';
        foreach ($teams as $team) {
            var_dump($team);
            echo '<br><br>';
        }
        exit;
    }
}
