<?php

namespace App\Services\Roster;

/**
 * Parses a roster file (CSV with comma, semicolon or tab delimiter, as
 * exported by Excel or Google Sheets).
 *
 * Required columns: github_username, group. Other columns (name, email, ...)
 * are ignored.
 */
class RosterParser
{
    public const REQUIRED = ['github_username', 'group'];

    private const ALIASES = [
        'github' => 'github_username',
        'username' => 'github_username',
        'github_login' => 'github_username',
        'login' => 'github_username',
        'groep' => 'group',
        'team' => 'group',
    ];

    /**
     * @return array{rows: array<int, array{line: int, github_username: string, group: string}>, errors: array<int, array{line: ?int, message: string}>}
     */
    public function parse(string $contents): array
    {
        // Strip a UTF-8 BOM and normalize line endings.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $lines = explode("\n", $contents);

        $headerLine = null;
        $lineNumber = 0;

        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $headerLine = $line;
                $lineNumber = $index + 1;
                break;
            }
        }

        if ($headerLine === null) {
            return ['rows' => [], 'errors' => [['line' => null, 'message' => 'Het bestand is leeg.']]];
        }

        $delimiter = $this->detectDelimiter($headerLine);
        $headers = array_map(fn ($header) => $this->normalizeHeader($header), str_getcsv($headerLine, $delimiter, '"', ''));

        $missing = array_diff(self::REQUIRED, $headers);

        if ($missing) {
            return ['rows' => [], 'errors' => [[
                'line' => $lineNumber,
                'message' => 'Ontbrekende kolom(men): ' . implode(', ', $missing) . '. Gevonden: ' . implode(', ', array_filter($headers)) . '.',
            ]]];
        }

        $columns = array_flip($headers);
        $rows = [];

        foreach (array_slice($lines, $lineNumber, null, true) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line, $delimiter, '"', '');

            $value = fn (string $column) => trim((string) ($cells[$columns[$column] ?? -1] ?? ''));

            // Entirely empty rows (e.g. trailing ";;;" from Excel) are skipped.
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }

            $rows[] = [
                'line' => $index + 1,
                'github_username' => ltrim($value('github_username'), '@'),
                'group' => preg_replace('/\s+/', ' ', $value('group')),
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }

    private function detectDelimiter(string $header): string
    {
        $counts = [];

        foreach ([',', ';', "\t"] as $delimiter) {
            $counts[$delimiter] = substr_count($header, $delimiter);
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header, " \t\"'"));
        $header = preg_replace('/[\s\-]+/', '_', $header);

        return self::ALIASES[$header] ?? $header;
    }
}
