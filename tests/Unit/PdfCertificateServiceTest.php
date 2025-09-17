<?php

namespace Tests\Unit;

use App\Services\PdfCertificateService;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class PdfCertificateServiceTest extends TestCase
{
    private function makeTmpCsv(string $contents): string
    {
        $fs = new Filesystem;
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'htv_tests';
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $path = $dir.DIRECTORY_SEPARATOR.uniqid('csv_').'.csv';
        $fs->put($path, $contents);

        return $path;
    }

    public function test_single_results_compute_points_and_shared_ranking_with_header_and_comma_delimiter(): void
    {
        $csv = "firstname,name,club,team,ageGroup,vault,unevenBars,balanceBeam,floor\n".
            "Alice,Alpha,Club A,Team A,10,10,10,10,10\n".
            "Bob,Beta,Club B,Team A,10,12,8,10,10\n".
            // Charlie has same total as Alice (40)
            "Charlie,Gamma,Club C,Team A,10,9,11,10,10\n";
        $path = $this->makeTmpCsv($csv);

        $service = app(PdfCertificateService::class);
        $results = $service->computeSingleResultsFromCsv($path);

        // Sort by ranking asc for easy assertions
        usort($results, fn ($a, $b) => ($a['ranking'] <=> $b['ranking']) ?: strcmp($a['name'], $b['name']));

        $this->assertCount(3, $results);
        $this->assertSame('AK 10', $results[0]['ageGroup']);
        $this->assertSame('40,00', $results[0]['points']);
        $this->assertSame(1, $results[0]['ranking']);
        $this->assertSame(1, $results[1]['ranking'], 'Tie should share rank 1');
        $this->assertSame(1, $results[2]['ranking'], 'All three totals are equal, so all share rank 1');
    }

    public function test_single_results_without_header_and_semicolon_delimiter(): void
    {
        $csv = "Alice;Alpha;Club A;Team A;10;10;10;10;10\n".
            "Bob;Beta;Club B;Team B;10;12;8;10;10\n";
        $path = $this->makeTmpCsv($csv);

        $service = app(PdfCertificateService::class);
        $results = $service->computeSingleResultsFromCsv($path);

        $this->assertCount(2, $results);
        $this->assertSame('AK 10', $results[0]['ageGroup']);
        $this->assertSame('40,00', $results[0]['points']);
    }

    public function test_team_results_group_by_team_only_with_best_three_per_discipline_and_shared_ranking(): void
    {
        // Build three teams via the 'team' column; include placeholder teams to be ignored
        $rows = [
            // Team X 10 (four members, best 3 count)
            ['Ann', 'A', 'Club X', 'Team X 10', '10', '10', '9', '8', '7'],
            ['Ben', 'B', 'Club X', 'Team X 10', '10', '9', '9', '9', '9'],
            ['Cat', 'C', 'Club X', 'Team X 10', '10', '8', '8', '8', '8'],
            ['Dan', 'D', 'Club X', 'Team X 10', '10', '7', '7', '7', '7'],
            // Placeholders (ignored due to empty/placeholder team)
            ['Eve', 'E', 'Club Z', '0', '10', '10', '10', '10', '10'],
            ['Finn', 'F', 'Club Z', '-', '10', '10', '10', '10', '10'],
            // Team X 12 (different age)
            ['Gwen', 'G', 'Club X', 'Team X 12', '12', '6', '6', '6', '6'],
            // Team Y 10 (three strong + one weak)
            ['Hank', 'H', 'Club Y', 'Team Y 10', '10', '9', '9', '9', '9'],
            ['Ivy', 'I', 'Club Y', 'Team Y 10', '10', '9', '9', '9', '9'],
            ['Jay', 'J', 'Club Y', 'Team Y 10', '10', '9', '9', '9', '9'],
            ['Kim', 'K', 'Club Y', 'Team Y 10', '10', '1', '1', '1', '1'],
        ];
        $csv = "firstname,name,club,team,ageGroup,vault,unevenBars,balanceBeam,floor\n";
        foreach ($rows as $r) {
            $csv .= implode(',', $r)."\n";
        }
        $path = $this->makeTmpCsv($csv);

        $service = app(PdfCertificateService::class);
        $teams = $service->computeTeamResultsFromCsv($path);

        // Expect three teams: Team X 10, Team X 12, Team Y 10
        $this->assertCount(3, $teams);

        // Map for easier checks
        $map = [];
        foreach ($teams as $t) {
            $map[$t['team'].'|'.$t['ageGroup']] = $t;
        }
        $this->assertArrayHasKey('Team X 10|AK 9-11', $map);
        $this->assertArrayHasKey('Team X 12|AK 12', $map);
        $this->assertArrayHasKey('Team Y 10|AK 9-11', $map);

        // Team X 10: best 3 per discipline from first 4 athletes -> 27 + 26 + 26 + 26 = 105
        $this->assertSame('102,00', $map['Team X 10|AK 9-11']['points']);

        // Team Y 10: three times 9 in all four -> 27 per discipline -> total=108 should beat Team X 10
        $this->assertSame('108,00', $map['Team Y 10|AK 9-11']['points']);

        // Rankings should reflect ordering with highest points = rank 1
        $top = $teams[0];
        $this->assertSame(1, $top['ranking']);

        // Names should include team members and be a comma-separated string
        $this->assertStringContainsString('Ann A', $map['Team X 10|AK 9-11']['names']);
        $this->assertStringContainsString('Ben B', $map['Team X 10|AK 9-11']['names']);
    }
}
