<?php

namespace App\Services\Backup;

use App\GitHub\GitHub;
use App\Models\Cohort;
use App\Models\Group;
use App\Services\Audit;
use FilesystemIterator;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Makes one zip with a full `git clone` (all history and branches) of every
 * group repository of an exam that is still on GitHub, plus an overview of
 * the groups and their students.
 *
 * The server token is passed to git through environment variables, so it
 * never ends up in the command line or in the cloned repository's config.
 */
class RepositoryBackup
{
    public const DIRECTORY = 'backups';

    public function __construct(private readonly GitHub $github)
    {
    }

    /**
     * @return int the number of repositories in the backup
     */
    public function create(Cohort $cohort): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('De PHP-extensie zip ontbreekt op de server.');
        }

        $groups = $cohort->groups()->with('students')->get()->filter->hasRepositoryOnGitHub()->values();

        if ($groups->isEmpty()) {
            throw new RuntimeException('Er staan geen repositories van dit examen op GitHub.');
        }

        $directory = storage_path('app/' . self::DIRECTORY);
        $workDir = $directory . '/tmp-' . $cohort->id . '-' . Str::random(8);
        $name = $cohort->slug . '-backup-' . now()->format('Y-m-d-His');
        $failures = [];

        $this->makeDirectory($workDir . '/' . $name);

        try {
            foreach ($groups as $group) {
                try {
                    $this->clone($cohort, $group, $workDir . '/' . $name . '/' . $group->repository_name);
                } catch (Throwable $e) {
                    $failures[$group->repository_name] = $e->getMessage();
                    $this->deleteDirectory($workDir . '/' . $name . '/' . $group->repository_name);
                }
            }

            if (count($failures) === $groups->count()) {
                throw new RuntimeException('Geen enkele repository kon worden gedownload. ' . $this->describeFailures($failures));
            }

            file_put_contents($workDir . '/' . $name . '/overzicht.csv', $this->overview($groups, $failures));

            $relativePath = self::DIRECTORY . '/' . $name . '.zip';
            $this->zip($workDir, storage_path('app/' . $relativePath));
        } finally {
            $this->deleteDirectory($workDir);
        }

        $previous = $cohort->backupFile();

        $cohort->forceFill([
            'backup_status' => 'ready',
            'backup_path' => $relativePath,
            'backup_size' => filesize(storage_path('app/' . $relativePath)),
            'backup_created_at' => now(),
            'backup_error' => $failures ? 'Niet in de backup: ' . $this->describeFailures($failures) : null,
        ])->save();

        if ($previous && $previous !== storage_path('app/' . $relativePath)) {
            @unlink($previous);
        }

        $count = $groups->count() - count($failures);

        Audit::record('backup.created', $cohort, ['repositories' => $count, 'failed' => array_keys($failures)]);

        return $count;
    }

    private function clone(Cohort $cohort, Group $group, string $target): void
    {
        $base = rtrim(config('github.git_url'), '/');
        $url = $base . '/' . rawurlencode($cohort->organization) . '/' . rawurlencode($group->repository_name) . '.git';
        $token = $this->github->orgTokenSource()->token();

        $result = Process::timeout(900)
            ->env([
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'http.' . $base . '/.extraHeader',
                'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode('x-access-token:' . $token),
            ])
            ->run(['git', 'clone', '--quiet', '--no-hardlinks', $url, $target]);

        if ($result->failed()) {
            $output = trim($result->errorOutput() ?: $result->output());

            throw new RuntimeException($output !== '' ? Str::limit($output, 300) : 'git clone mislukt (exitcode ' . $result->exitCode() . ')');
        }
    }

    private function overview(iterable $groups, array $failures): string
    {
        $handle = fopen('php://temp', 'r+');

        // A BOM and semicolons, so Excel opens it correctly.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['groep', 'repository', 'studenten', 'url', 'in backup'], ';', escape: '');

        foreach ($groups as $group) {
            fputcsv($handle, [
                $group->name,
                $group->repository_name,
                $group->students->pluck('github_login')->implode(' '),
                $group->repository_url,
                isset($failures[$group->repository_name]) ? 'nee' : 'ja',
            ], ';', escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function zip(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Kan {$zipPath} niet aanmaken.");
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $prefixLength = strlen(rtrim($sourceDir, '/\\')) + 1;

        foreach ($files as $file) {
            $localName = str_replace('\\', '/', substr($file->getPathname(), $prefixLength));

            if ($file->isDir()) {
                $zip->addEmptyDir($localName);
            } else {
                $zip->addFile($file->getPathname(), $localName);
            }
        }

        if (! $zip->close()) {
            @unlink($zipPath);

            throw new RuntimeException('Het zip-bestand kon niet worden opgeslagen.');
        }
    }

    private function describeFailures(array $failures): string
    {
        return collect($failures)->map(fn ($reason, $repository) => "{$repository} ({$reason})")->implode('; ');
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Kan map {$path} niet aanmaken.");
        }
    }

    /**
     * Also removes read-only files, which git creates for its objects (and
     * which unlink() refuses on Windows).
     */
    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0666);
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
