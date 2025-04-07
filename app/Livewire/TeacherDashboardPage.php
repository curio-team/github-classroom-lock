<?php

namespace App\Livewire;

use App\Settings\ChatSettings;
use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use Livewire\Component;

class TeacherDashboardPage extends Component
{
    public $showArchived = false;

    public $chatPassword;
    public $isProjectsEnabled;

    public $teamProjects;

    public function mount(ChatSettings $settings)
    {
        $this->chatPassword = $settings->chat_password;

        $organization = $this->getOrganization();
        $this->isProjectsEnabled = $organization['has_organization_projects'] ?? false;

        $this->teamProjects = $this->getProjects();
    }

    private function getOrganization()
    {
        $client = new Client();
        $client->authenticate(config('app.github_token'), AuthMethod::ACCESS_TOKEN);
        $organization = config('app.github_organization');

        return $client->organizations()->show($organization);
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
     * Creates a Project for the given team and links it to the team. This will ensure that
     * when the teams are locked, the members lose access to the project.
     */
    public function createProject($teamId)
    {
        $team = \App\Models\Team::withArchived()
            ->where('id', $teamId)
            ->first();

        $organization = config('app.github_organization');
        $client = new Client();
        $client->authenticate(config('app.github_token'), AuthMethod::ACCESS_TOKEN);

        // First get the organization ID for the GraphQL query
        $organizationInfoQuery = <<<QUERY
        query{
            organization(login: "$organization"){
                id
            }
        }
        QUERY;

        $result = $client->api('graphql')->execute($organizationInfoQuery);
        $organizationId = $result['data']['organization']['id'];

        // Find the team ID for GraphQL by name
        $organizationTeamsQuery = <<<QUERY
        {
            organization(login: "curio-summatief") {
                teams(first: 100) {
                    nodes {
                        id
                        databaseId
                    }
                }
            }
        }
        QUERY;

        $result = $client->api('graphql')->execute($organizationTeamsQuery);

        if (count($result['data']['organization']['teams']['nodes']) > 90) {
            // TODO: To keep things simple for now, we return an error on too many teams.
            // Ideally we should implement pagination and get all teams that way. But for
            // now I'm just hacking this together. -luttje
            return redirect()
                ->route('dashboard.teacher')
                ->with('error', 'Too many teams, please contact TL10.');
        }

        $queryTeamId = null;

        foreach ($result['data']['organization']['teams']['nodes'] as $teamNode) {
            if ($teamNode['databaseId'] == (int)$teamId) {
                $queryTeamId = $teamNode['id'];
                break;
            }
        }

        if ($queryTeamId === null) {
            return redirect()
                ->route('dashboard.teacher')
                ->with('error', 'Team not found.');
        }

        // Create the project
        $teamName = $team->name;

        $createProjectQuery = <<<QUERY
        mutation {
            createProjectV2(input: {title: "$teamName", ownerId: "{$organizationId}"}) {
                projectV2 {
                    id
                }
            }
        }
        QUERY;

        $result = $client->api('graphql')->execute($createProjectQuery);

        // Link the project to the team
        $projectId = $result['data']['createProjectV2']['projectV2']['id'];
        $linkProjectQuery = <<<QUERY
        mutation {
            updateProjectV2Collaborators(input: {
                projectId: "$projectId",
                collaborators: {
                    teamId: "$queryTeamId",
                    role: WRITER
                }
            }) {
                clientMutationId
            }
        }
        QUERY;

        $result = $client->api('graphql')->execute($linkProjectQuery);

        // Check for errors
        if (isset($result['errors'])) {
            $errorMessage = $result['errors'][0]['message'];
            return redirect()
                ->route('dashboard.teacher')
                ->with('error', "Error creating project: $errorMessage");
        }

        return redirect()
            ->route('dashboard.teacher')
            ->with('success', 'Project created and linked to team successfully.');
    }

    /**
     * Gets all the projects for all teams, so we can list them in the UI.
     */
    public function getProjects()
    {
        $client = new Client();
        $client->authenticate(config('app.github_token'), AuthMethod::ACCESS_TOKEN);
        $organization = config('app.github_organization');

        // Get all projects for the organization
        $projectsQuery = <<<QUERY
        query{
            organization(login: "$organization") {
                projectsV2(first: 100) {
                    nodes {
                        id
                        title
                        url
                        teams(first: 10) {
                            nodes {
                                databaseId
                            }
                        }
                    }
                }
            }
        }
        QUERY;

        $result = $client->api('graphql')->execute($projectsQuery);

        // We flatten it grouping the projects by team ID
        $teamProjects = [];

        foreach ($result['data']['organization']['projectsV2']['nodes'] as $project) {
            $projectData = (object) [
                'id' => $project['id'],
                'title' => $project['title'],
                'url' => $project['url'],
            ];

            foreach ($project['teams']['nodes'] as $team) {
                $teamProjects[$team['databaseId']][] = $projectData;
            }
        }

        return $teamProjects;
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
