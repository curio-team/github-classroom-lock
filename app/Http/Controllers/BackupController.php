<?php

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Services\Audit;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function download(Cohort $cohort): BinaryFileResponse
    {
        $path = $cohort->backupFile();

        abort_if($path === null, 404, 'Er is (nog) geen backup van dit examen.');

        Audit::record('backup.downloaded', $cohort, ['file' => basename($path)]);

        return response()->download($path, basename($path), ['Content-Type' => 'application/zip']);
    }
}
