<?php

namespace Tests;

use App\Jobs\ProvisionCohort;
use App\Models\Cohort;
use App\Models\User;
use App\Services\Roster\RosterImporter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGitHub;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Any request that isn't faked fails the test instead of reaching the network.
        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function fakeGitHub(): FakeGitHub
    {
        return FakeGitHub::install();
    }

    protected function teacher(): User
    {
        return User::create(['id' => 't' . random_int(1, PHP_INT_MAX), 'name' => 'Docent', 'email' => 'docent@example.test', 'type' => 'teacher']);
    }

    protected function makeCohort(array $attributes = []): Cohort
    {
        return Cohort::create($attributes + [
            'name' => 'SD1A 2026',
            'slug' => 'sd1a-2026',
            'organization' => 'test-org',
        ]);
    }

    /**
     * Imports a roster (CSV text) and asserts it has no errors.
     */
    protected function importRoster(Cohort $cohort, string $csv): void
    {
        $importer = $this->app->make(RosterImporter::class);
        $preview = $importer->preview($cohort, $csv);

        $this->assertSame([], $preview->errors, 'Roster has errors: ' . json_encode($preview->errors));

        $importer->apply($cohort, $preview);
    }

    /**
     * A cohort with users on GitHub, an imported roster and provisioned groups.
     *
     * @param  array<string, string>  $students  login => group
     */
    protected function provisionedCohort(FakeGitHub $github, array $students, array $attributes = []): Cohort
    {
        $csv = "github_username,group\n";

        foreach ($students as $login => $group) {
            if ($github->user($login) === null) {
                $github->addUser($login);
            }

            $csv .= "{$login},{$group}\n";
        }

        $cohort = $this->makeCohort($attributes);
        $this->importRoster($cohort, $csv);

        ProvisionCohort::dispatchSync($cohort);

        foreach ($cohort->groups()->get() as $group) {
            $this->assertSame('provisioned', $group->status->value, "Group {$group->name}: {$group->last_error}");
        }

        return $cohort->fresh();
    }
}
