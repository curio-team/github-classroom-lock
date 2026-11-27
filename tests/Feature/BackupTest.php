<?php

namespace Tests\Feature;

use App\Jobs\BackupCohort;
use App\Livewire\ExamPage;
use App\Models\Cohort;
use App\Models\User;
use App\Services\Offboarding\GitHubCleanup;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\FakeGitHub;
use Tests\TestCase;
use ZipArchive;

/**
 * Backups clone with the real git binary, from local repositories that stand
 * in for github.com (config github.git_url).
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    private string $remote;

    protected function setUp(): void
    {
        parent::setUp();

        if (Process::run(['git', '--version'])->failed()) {
            $this->markTestSkipped('git is not installed.');
        }

        $this->github = $this->fakeGitHub();
        $this->remote = sys_get_temp_dir() . '/backup-test-' . Str::random(8);
        mkdir($this->remote . '/test-org', 0755, true);
        mkdir($this->remote . '/storage/app', 0755, true);

        // Never touch the real storage/app/backups.
        $this->app->useStoragePath($this->remote . '/storage');

        $path = str_replace('\\', '/', $this->remote);
        config(['github.git_url' => 'file://' . (str_starts_with($path, '/') ? '' : '/') . $path]);
    }

    protected function tearDown(): void
    {
        if (isset($this->remote) && is_dir($this->remote)) {
            $this->deleteDirectory($this->remote);
        }

        parent::tearDown();
    }

    /**
     * A local repository with two commits on main and a feature branch.
     */
    private function makeRemoteRepository(string $name): void
    {
        $dir = "{$this->remote}/test-org/{$name}.git";
        $git = fn (...$args) => $this->assertTrue(
            Process::path($dir)->run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', ...$args])->successful(),
            'git ' . implode(' ', $args) . ' failed',
        );

        mkdir($dir, 0755, true);
        $git('init', '--quiet', '--initial-branch=main');
        file_put_contents("{$dir}/README.md", "# {$name}\n");
        $git('add', '.');
        $git('commit', '--quiet', '-m', 'First commit');
        file_put_contents("{$dir}/index.php", "<?php echo 'hi';\n");
        $git('add', '.');
        $git('commit', '--quiet', '-m', 'Second commit');
        $git('branch', 'feature');
    }

    private function zipEntries(Cohort $cohort): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($cohort->backupFile()));
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = str_ends_with($name, '/') ? null : $zip->getFromIndex($i);
        }

        $zip->close();

        return $entries;
    }

    public function test_the_backup_contains_a_full_clone_of_every_repository(): void
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1', 'bob' => 'Groep 1', 'carol' => 'Groep 2']);
        $this->makeRemoteRepository('sd1a-2026-groep-1');
        $this->makeRemoteRepository('sd1a-2026-groep-2');

        BackupCohort::dispatchSync($cohort);

        $cohort->refresh();
        $this->assertSame('ready', $cohort->backup_status);
        $this->assertNull($cohort->backup_error);
        $this->assertNotNull($cohort->backupFile());
        $this->assertSame(filesize($cohort->backupFile()), $cohort->backup_size);

        $entries = $this->zipEntries($cohort);
        $root = basename($cohort->backup_path, '.zip');

        foreach (['sd1a-2026-groep-1', 'sd1a-2026-groep-2'] as $repository) {
            // (core.autocrlf may turn \n into \r\n on checkout.)
            $this->assertSame("# {$repository}", trim($entries["{$root}/{$repository}/README.md"]));
            $this->assertArrayHasKey("{$root}/{$repository}/index.php", $entries);
            $this->assertArrayHasKey("{$root}/{$repository}/.git/HEAD", $entries, 'The git history is included');
        }

        // The overview lists every group with its students.
        $overview = $entries["{$root}/overzicht.csv"];
        $this->assertStringContainsString('"Groep 1";sd1a-2026-groep-1;"alice bob"', $overview);
        $this->assertStringContainsString('carol', $overview);

        // The token is never stored in the clone.
        foreach ($entries as $name => $contents) {
            if ($contents !== null) {
                $this->assertStringNotContainsString(FakeGitHub::ORG_TOKEN, $contents, "{$name} contains the token");
                $this->assertStringNotContainsString(base64_encode('x-access-token:' . FakeGitHub::ORG_TOKEN), $contents, "{$name} contains the token");
            }
        }

        // No temporary files are left behind.
        $this->assertSame([basename($cohort->backupFile())], array_values(array_diff(scandir(storage_path('app/backups')), ['.', '..'])));
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.created']);
    }

    public function test_the_clone_has_the_whole_history(): void
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1']);
        $this->makeRemoteRepository('sd1a-2026-groep-1');

        BackupCohort::dispatchSync($cohort);

        $extracted = $this->remote . '/extracted';
        $zip = new ZipArchive();
        $zip->open($cohort->fresh()->backupFile());
        $zip->extractTo($extracted);
        $zip->close();

        $repository = $extracted . '/' . basename($cohort->fresh()->backup_path, '.zip') . '/sd1a-2026-groep-1';
        $log = Process::path($repository)->run(['git', 'log', '--format=%s', 'main']);
        $branches = Process::path($repository)->run(['git', 'branch', '-r']);

        $this->assertSame("Second commit\nFirst commit", trim($log->output()));
        $this->assertStringContainsString('origin/feature', $branches->output());
    }

    public function test_a_repository_that_cannot_be_cloned_is_reported_and_the_rest_is_kept(): void
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1', 'bob' => 'Groep 2']);
        $this->makeRemoteRepository('sd1a-2026-groep-1');

        BackupCohort::dispatchSync($cohort);

        $cohort->refresh();
        $this->assertSame('ready', $cohort->backup_status);
        $this->assertStringStartsWith('Niet in de backup: sd1a-2026-groep-2', $cohort->backup_error);

        $entries = $this->zipEntries($cohort);
        $root = basename($cohort->backup_path, '.zip');
        $this->assertArrayHasKey("{$root}/sd1a-2026-groep-1/README.md", $entries);
        $this->assertEmpty(array_filter(array_keys($entries), fn ($name) => str_contains($name, 'sd1a-2026-groep-2/')));
        $this->assertStringContainsString('sd1a-2026-groep-2;bob;https://github.com/test-org/sd1a-2026-groep-2;nee', $entries["{$root}/overzicht.csv"]);
    }

    public function test_a_backup_without_any_repository_fails(): void
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1']);

        try {
            BackupCohort::dispatchSync($cohort);
            $this->fail('The backup should fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Geen enkele repository kon worden gedownload', $e->getMessage());
        }

        $cohort->refresh();
        $this->assertSame('failed', $cohort->backup_status);
        $this->assertNull($cohort->backupFile());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.failed']);
    }

    public function test_a_new_backup_replaces_the_previous_one(): void
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1']);
        $this->makeRemoteRepository('sd1a-2026-groep-1');

        BackupCohort::dispatchSync($cohort);
        $first = $cohort->fresh()->backupFile();
        $this->travel(1)->minute();
        BackupCohort::dispatchSync($cohort->fresh());
        $second = $cohort->fresh()->backupFile();

        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($first);
        $this->assertFileExists($second);
    }

    public function test_examinators_make_and_download_the_backup_from_the_exam_page(): void
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1']);
        $this->makeRemoteRepository('sd1a-2026-groep-1');
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get(route('exams.backup', $cohort))->assertNotFound();

        Livewire::actingAs($teacher)
            ->test(ExamPage::class, ['cohort' => $cohort, 'step' => 'finish'])
            ->assertSee('Backup maken')
            ->assertDontSee('Backup downloaden')
            ->call('createBackup')
            ->assertSee('Backup downloaden')
            ->assertSee('Nieuwe backup maken');

        $response = $this->actingAs($teacher)->get(route('exams.backup', $cohort));
        $response->assertOk()->assertDownload(basename($cohort->fresh()->backupFile()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.downloaded']);

        // The cleanup preview no longer warns about a missing backup.
        $plan = $this->app->make(GitHubCleanup::class)->plan($cohort->fresh(), GitHubCleanup::OPTIONS);
        $this->assertNotContains('Er is nog geen backup van de repositories gemaakt (zie hierboven).', $plan['warnings']);

        $student = User::create(['id' => 's1', 'name' => 'Student', 'email' => 's@example.test', 'type' => 'student']);
        $this->actingAs($student)->get(route('exams.backup', $cohort))->assertForbidden();
    }

    private function deleteDirectory(string $path): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0666);
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
