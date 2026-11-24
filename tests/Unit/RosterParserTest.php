<?php

namespace Tests\Unit;

use App\Services\Roster\RosterParser;
use PHPUnit\Framework\TestCase;

class RosterParserTest extends TestCase
{
    public function test_parses_comma_separated_roster(): void
    {
        $result = (new RosterParser())->parse("github_username,group,name\noctocat,Groep 1,Octo Cat\n@monalisa, Groep  2 ,Mona\n");

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            ['line' => 2, 'github_username' => 'octocat', 'group' => 'Groep 1'],
            ['line' => 3, 'github_username' => 'monalisa', 'group' => 'Groep 2'],
        ], $result['rows']);
    }

    public function test_parses_excel_semicolon_export_with_bom_and_aliases(): void
    {
        $csv = "\xEF\xBB\xBFGitHub Username;Groep;Naam\r\noctocat;A;Octo\r\n;;\r\nmonalisa;B;\r\n";

        $result = (new RosterParser())->parse($csv);

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame(['line' => 2, 'github_username' => 'octocat', 'group' => 'A'], $result['rows'][0]);
        $this->assertSame(4, $result['rows'][1]['line']);
    }

    public function test_parses_tab_separated_roster(): void
    {
        $result = (new RosterParser())->parse("github_username\tgroup\noctocat\tGroep 1\n");

        $this->assertSame('Groep 1', $result['rows'][0]['group']);
    }

    public function test_reports_missing_columns(): void
    {
        $result = (new RosterParser())->parse("username,email\noctocat,o@example.test\n");

        $this->assertSame([], $result['rows']);
        $this->assertStringContainsString('group', $result['errors'][0]['message']);
    }

    public function test_reports_empty_file(): void
    {
        $result = (new RosterParser())->parse("\n\n");

        $this->assertSame('Het bestand is leeg.', $result['errors'][0]['message']);
    }
}
