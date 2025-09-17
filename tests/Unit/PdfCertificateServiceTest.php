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
        $csv = "firstname,name,club,ageGroup,vault,unevenBars,balanceBeam,floor\n".
            "Alice,Alpha,Club A,10,10,10,10,10\n".
            "Bob,Beta,Club B,10,12,8,10,10\n".
            // Charlie has same total as Alice (40)
            "Charlie,Gamma,Club C,10,9,11,10,10\n";
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
        $csv = "Alice;Alpha;Club A;10;10;10;10;10\n".
            "Bob;Beta;Club B;10;12;8;10;10\n";
        $path = $this->makeTmpCsv($csv);

        $service = app(PdfCertificateService::class);
        $results = $service->computeSingleResultsFromCsv($path);

        $this->assertCount(2, $results);
        $this->assertSame('AK 10', $results[0]['ageGroup']);
        $this->assertSame('40,00', $results[0]['points']);
    }

    public function test_team_results_excludes_empty_clubs_and_groups_by_age_with_best_three_per_discipline_and_shared_ranking(): void
    {
        // Two age groups for Club X; include placeholders for club to be ignored
        $rows = [
            ['Ann', 'A', 'Club X', '10', '10', '9', '8', '7'],
            ['Ben', 'B', 'Club X', '10', '9', '9', '9', '9'],
            ['Cat', 'C', 'Club X', '10', '8', '8', '8', '8'],
            ['Dan', 'D', 'Club X', '10', '7', '7', '7', '7'], // fourth member should be ignored by best-3 rule
            ['Eve', 'E', '0', '10', '10', '10', '10', '10'],  // ignored: club placeholder "0"
            ['Finn', 'F', '-', '10', '10', '10', '10', '10'],  // ignored: club placeholder "-"
            ['Gwen', 'G', 'Club X', '12', '6', '6', '6', '6'], // different ageGroup -> separate team
            ['Hank', 'H', 'Club Y', '10', '9', '9', '9', '9'],
            ['Ivy', 'I', 'Club Y', '10', '9', '9', '9', '9'],
            ['Jay', 'J', 'Club Y', '10', '9', '9', '9', '9'],
            ['Kim', 'K', 'Club Y', '10', '1', '1', '1', '1'], // 4th member ignored by best-3
        ];
        $csv = "firstname,name,club,ageGroup,vault,unevenBars,balanceBeam,floor\n";
        foreach ($rows as $r) {
            $csv .= implode(',', $r)."\n";
        }
        $path = $this->makeTmpCsv($csv);

        $service = app(PdfCertificateService::class);
        $teams = $service->computeTeamResultsFromCsv($path);

        // Expect three teams: Club X AK10, Club X AK12, Club Y AK10
        $this->assertCount(3, $teams);

        // Map for easier checks
        $map = [];
        foreach ($teams as $t) {
            $map[$t['club'].'|'.$t['ageGroup']] = $t;
        }
        $this->assertArrayHasKey('Club X|AK 10', $map);
        $this->assertArrayHasKey('Club X|AK 12', $map);
        $this->assertArrayHasKey('Club Y|AK 10', $map);

        // Club X AK10: take best 3 per discipline from first 4 athletes: (10,9,8),(9,9,8),(9,9,8),(7,7,7) -> top3 sums
        // vault: 10+9+8=27; uneven: 9+9+8=26; beam: 9+9+8=26; floor: 9+9+8=26; total=105
        $this->assertSame('102,00', $map['Club X|AK 10']['points']);

        // Club Y AK10: three times 9 in all four -> 27 per discipline -> total=108 should beat Club X AK10
        $this->assertSame('108,00', $map['Club Y|AK 10']['points']);

        // Rankings should reflect ordering with highest points = rank 1
        // Ensure top team has rank 1
        $top = $teams[0];
        $this->assertSame(1, $top['ranking']);

        // Names should include team members and be a comma-separated string
        $this->assertStringContainsString('Ann A', $map['Club X|AK 10']['names']);
        $this->assertStringContainsString('Ben B', $map['Club X|AK 10']['names']);
    }
}
