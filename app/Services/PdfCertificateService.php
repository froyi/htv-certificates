<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\SyntaxError;
use mikehaertl\pdftk\Pdf as PdfTk;
use RuntimeException;

/**
 * Service responsible for generating certificate PDFs from CSV data.
 *
 * Responsibilities:
 * - Read CSV files (with or without header) in the expected column order.
 * - Compute single total points (sum of four disciplines) and apply standard competition ranking (ties share place).
 * - Compute team totals by club and age group (top 3 scores per discipline) and apply shared ranking.
 * - Fill PDF form templates (single and team) via pdftk and merge results into a single PDF.
 * - Provide pure-computation helpers (computeSingleResultsFromCsv/computeTeamResultsFromCsv) used by tests and for diagnostics.
 *
 * Key rules:
 * - Expected CSV headers: firstname, name, club, ageGroup, vault, unevenBars, balanceBeam, floor.
 * - Decimal separator in inputs may be ',' or '.'; outputs always use comma with two decimals (e.g., 51,50).
 * - Clubs deemed empty or placeholder (e.g., "0", "-", "ohne verein") are excluded from team scoring.
 */
class PdfCertificateService
{
    /**
     * Expected CSV headers in their required order.
     *
     * @var array<int,string>
     */
    private array $expectedHeaders = [
        'firstname',
        'name',
        'club',
        'ageGroup',
        'vault',
        'unevenBars',
        'balanceBeam',
        'floor',
    ];

    /**
     * Mapping from CSV header names (excluding firstname/name) to PDF form field names.
     * firstname and name are combined into the PDF field "name".
     *
     * @var array<string,string>
     */
    private array $fieldMap = [
        'club' => 'club',
        'ageGroup' => 'ageGroup',
        // points and ranking are computed from discipline scores
    ];

    /**
     * Generate a combined PDF from CSV file contents.
     *
     * @param  string  $csvPath  Absolute path to the uploaded CSV file.
     * @return string Absolute path to the generated PDF file.
     */
    /**
     * Generate the single (individual) certificates PDF from a CSV file.
     *
     * Processing steps:
     * 1) Locate the single certificate PDF template (template_brass.pdf) from common locations.
     * 2) Detect CSV delimiter (comma/semicolon) and load records (header-aware or positional fallback).
     * 3) Compute each athlete's total = vault + unevenBars + balanceBeam + floor.
     * 4) Rank athletes using standard competition ranking (ties share the same rank, next rank is skipped).
     * 5) Fill the PDF form for each athlete and save temporary PDFs.
     * 6) Merge all temporary PDFs into a final single PDF and clean up temp files.
     *
     * @param  string  $csvPath  Absolute path to the uploaded CSV file.
     * @return string Absolute path to the generated merged PDF file.
     *
     * @throws RuntimeException When template is missing or pdftk fails.
     * @throws SyntaxError
     * @throws Exception
     */
    public function generateFromCsv(string $csvPath): string
    {
        // Ensure the template exists (try multiple common locations)
        $candidates = [
            // Public disk (storage/app/public)
            Storage::disk('public')->path('template_brass.pdf'),
            // Local/private disk (storage/app/private)
            Storage::disk('local')->path('template_brass.pdf'),
            // Legacy location (storage/app)
            storage_path('app/template_brass.pdf'),
            // Repository location
            resource_path('templates/template_brass.pdf'),
        ];
        $templatePath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $templatePath = $candidate;
                break;
            }
        }
        if ($templatePath === null) {
            $paths = implode(', ', $candidates);
            throw new RuntimeException('Die PDF-Vorlage template_brass.pdf wurde nicht gefunden. Versuchte Pfade: '.$paths);
        }

        // Read the CSV and detect delimiter (',' or ';')
        $delimiter = $this->detectDelimiter($csvPath);

        // Attempt header-based parsing first
        $readerWithHeader = Reader::createFromPath($csvPath);
        $readerWithHeader->setDelimiter($delimiter);
        $readerWithHeader->setHeaderOffset(0);

        $expectedHeaders = $this->expectedHeaders;
        $headers = $readerWithHeader->getHeader();

        $useHeaderMode = true;
        if (empty($headers)) {
            $useHeaderMode = false;
        } else {
            // Determine if expected headers are present (case-sensitive match)
            $missing = array_diff($expectedHeaders, $headers);
            if (! empty($missing)) {
                $useHeaderMode = false;
            }
        }

        if ($useHeaderMode) {
            // Header present and valid
            $records = iterator_to_array($readerWithHeader->getRecords());
        } else {
            // Fallback: no header row, map by positional order according to expected headers
            $readerNoHeader = Reader::createFromPath($csvPath, 'r');
            $readerNoHeader->setDelimiter($delimiter);

            $rows = iterator_to_array($readerNoHeader->getRecords());
            $records = [];
            foreach ($rows as $row) {
                // $row is a numerically indexed array in no-header mode
                $assoc = [];
                $i = 0;
                foreach ($expectedHeaders as $headerName) {
                    $assoc[$headerName] = (string) ($row[$i] ?? '');
                    $i++;
                }
                $records[] = $assoc;
            }
        }

        // Prepare temporary directory for individual filled PDFs
        $tmpDir = storage_path('app/tmp_certificates_'.uniqid());
        if (! @mkdir($tmpDir) && ! is_dir($tmpDir)) {
            throw new RuntimeException('Konnte temporären Ordner nicht erstellen.');
        }

        $today = now()->format('d.m.Y');
        $generatedFiles = [];

        // Compute total points per record and derive rankings by sorted totals
        $totals = [];
        foreach ($records as $i => $row) {
            $v = $this->parseScore((string) ($row['vault'] ?? '0'));
            $u = $this->parseScore((string) ($row['unevenBars'] ?? '0'));
            $b = $this->parseScore((string) ($row['balanceBeam'] ?? '0'));
            $f = $this->parseScore((string) ($row['floor'] ?? '0'));
            $totals[$i] = $v + $u + $b + $f;
        }
        $indices = array_keys($records);
        usort($indices, function (int $a, int $b) use ($totals): int {
            if ($totals[$a] === $totals[$b]) {
                return 0;
            }

            return $totals[$a] > $totals[$b] ? -1 : 1;
        });
        // Standard competition ranking ("1224" style):
        // Equal totals share the same rank; the next rank skips accordingly.
        $rankByIndex = [];
        $lastTotal = null;
        $lastRank = 0;
        foreach ($indices as $pos => $originalIndex) {
            $total = $totals[$originalIndex];
            if ($lastTotal === null || $total < $lastTotal) {
                // First time we see this (lower) total: rank equals its 0-based position + 1
                $lastRank = $pos + 1;
                $lastTotal = $total;
            }
            $rankByIndex[$originalIndex] = $lastRank;
        }

        foreach ($records as $index => $row) {
            // Normalize encoding to UTF-8
            $data = [];

            // Combine first and last name to single PDF field "name"
            $first = trim((string) ($row['firstname'] ?? ''));
            $last = trim((string) ($row['name'] ?? ''));
            $fullName = trim($first.' '.$last);

            $data['name'] = $this->toUtf8($fullName);

            // Map remaining CSV columns via fieldMap
            foreach ($this->fieldMap as $csvColumn => $pdfField) {
                $value = (string) ($row[$csvColumn] ?? '');
                $data[$pdfField] = $this->toUtf8($value);
            }

            if ($this->isEmptyClub($data['club'] ?? '')) {
                $data['club'] = '';
            }

            // Computed fields
            $total = $totals[$index] ?? 0.0;
            $data['points'] = $this->formatPoints($total);
            $data['ranking'] = ($rankByIndex[$index] ?? 0).'.';

            $data['ageGroup'] = 'AK '.($data['ageGroup'] ?? '');

            $data['today'] = $today;

            $outputFile = $tmpDir.'/certificate_'.($index + 1).'.pdf';

            $options = ['command' => $this->resolvePdftkBinary()];
            $pdf = new PdfTk($templatePath, $options);
            $ok = $pdf->fillForm($data)
                ->needAppearances()
                ->flatten()
                ->saveAs($outputFile);

            if (! $ok) {
                $err = (string) $pdf->getError();
                if ((str_contains($err, 'pdftk') || str_contains($err, 'pdftk-java')) && (str_contains($err, 'not found') || str_contains($err, 'No such file') || str_contains($err, 'could not be found'))) {
                    throw new RuntimeException('PDF-Erstellung fehlgeschlagen: Das pdftk-Binary wurde nicht gefunden. Bitte installieren Sie pdftk (z. B. macOS: "brew install pdftk-java", Debian/Ubuntu: "sudo apt-get install pdftk-java") oder setzen Sie PDFTK_PATH in der .env auf den absoluten Pfad zum Binary. Ursprünglicher Fehler: '.$err);
                }
                throw new RuntimeException('PDF-Erstellung fehlgeschlagen: '.$err);
            }

            $generatedFiles[] = $outputFile;
        }

        // Merge all PDFs into one
        $finalPath = storage_path('app/certificates_'.date('Ymd_His').'.pdf');
        $options = ['command' => $this->resolvePdftkBinary()];
        $merge = new PdfTk($generatedFiles, $options);
        if (! $merge->cat()->saveAs($finalPath)) {
            $err = (string) $merge->getError();
            if ((str_contains($err, 'pdftk') || str_contains($err, 'pdftk-java')) && (str_contains($err, 'not found') || str_contains($err, 'No such file') || str_contains($err, 'could not be found'))) {
                throw new RuntimeException('Zusammenführen fehlgeschlagen: Das pdftk-Binary wurde nicht gefunden. Bitte installieren Sie pdftk (z. B. macOS: "brew install pdftk-java", Debian/Ubuntu: "sudo apt-get install pdftk-java") oder setzen Sie PDFTK_PATH in der .env auf den absoluten Pfad zum Binary. Ursprünglicher Fehler: '.$err);
            }
            throw new RuntimeException('Zusammenführen der PDFs fehlgeschlagen: '.$err);
        }

        // Cleanup temp files
        foreach ($generatedFiles as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);

        return $finalPath;
    }

    /**
     * Generate team certificates PDF from CSV file.
     *
     * Processing steps:
     * 1) Locate the team certificate PDF template (template_brass_team.pdf).
     * 2) Detect CSV delimiter and load records (header-aware or positional fallback) using expected columns.
     * 3) Exclude rows with empty/placeholder clubs.
     * 4) Group rows by club and ageGroup; collect discipline scores and member names.
     * 5) For each group: sort scores per discipline, sum the top 3 per discipline, sum across disciplines to get totals.
     * 6) Rank groups with shared-ranking (ties share the same rank).
     * 7) Fill the PDF per group including club, ageGroup (prefixed with "AK "), names (comma-separated), total points, and rank.
     * 8) Merge all generated PDFs and clean up.
     *
     * @param  string  $csvPath  Absolute path to the uploaded CSV file.
     * @return string Absolute path to the generated merged team PDF file.
     *
     * @throws RuntimeException When template is missing or pdftk fails.
     */
    public function generateTeamFromCsv(string $csvPath): string
    {
        // Ensure the team template exists (try multiple common locations)
        $candidates = [
            Storage::disk('public')->path('template_brass_team.pdf'),
            Storage::disk('local')->path('template_brass_team.pdf'),
            storage_path('app/template_brass_team.pdf'),
            resource_path('templates/template_brass_team.pdf'),
        ];
        $templatePath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $templatePath = $candidate;
                break;
            }
        }
        if ($templatePath === null) {
            $paths = implode(', ', $candidates);
            throw new RuntimeException('Die PDF-Vorlage template_brass_team.pdf wurde nicht gefunden. Versuchte Pfade: '.$paths);
        }

        // Read CSV records similar to single generation
        $delimiter = $this->detectDelimiter($csvPath);
        $readerWithHeader = Reader::createFromPath($csvPath, 'r');
        $readerWithHeader->setDelimiter($delimiter);
        $readerWithHeader->setHeaderOffset(0);

        $expectedHeaders = $this->expectedHeaders;
        $headers = $readerWithHeader->getHeader();

        $useHeaderMode = true;
        if ($headers === null || empty($headers)) {
            $useHeaderMode = false;
        } else {
            $missing = array_diff($expectedHeaders, $headers);
            if (! empty($missing)) {
                $useHeaderMode = false;
            }
        }

        if ($useHeaderMode) {
            $records = iterator_to_array($readerWithHeader->getRecords());
        } else {
            $readerNoHeader = Reader::createFromPath($csvPath, 'r');
            $readerNoHeader->setDelimiter($delimiter);
            $rows = iterator_to_array($readerNoHeader->getRecords());
            $records = [];
            foreach ($rows as $row) {
                $assoc = [];
                $i = 0;
                foreach ($expectedHeaders as $headerName) {
                    $assoc[$headerName] = (string) ($row[$i] ?? '');
                    $i++;
                }
                $records[] = $assoc;
            }
        }

        // Aggregate per club and ageGroup
        $groups = [];
        foreach ($records as $row) {
            $club = trim((string) ($row['club'] ?? ''));
            if ($this->isEmptyClub($club)) {
                continue; // exclude persons without a meaningful club
            }
            $age = trim((string) ($row['ageGroup'] ?? ''));
            $key = $club.'|'.$age;

            // Initialize structure
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'club' => $club,
                    'ageGroup' => $age,
                    'vault' => [],
                    'unevenBars' => [],
                    'balanceBeam' => [],
                    'floor' => [],
                    'names' => [],
                ];
            }

            // Collect scores
            $groups[$key]['vault'][] = $this->parseScore((string) ($row['vault'] ?? '0'));
            $groups[$key]['unevenBars'][] = $this->parseScore((string) ($row['unevenBars'] ?? '0'));
            $groups[$key]['balanceBeam'][] = $this->parseScore((string) ($row['balanceBeam'] ?? '0'));
            $groups[$key]['floor'][] = $this->parseScore((string) ($row['floor'] ?? '0'));

            // Collect member full names
            $first = trim((string) ($row['firstname'] ?? ''));
            $last = trim((string) ($row['name'] ?? ''));
            $full = trim($first.' '.$last);
            if ($full !== '') {
                $groups[$key]['names'][] = $full;
            }
        }

        // Compute per-group totals (best 3 per discipline)
        $groupTotals = [];
        foreach ($groups as $key => $disc) {
            $sum = 0.0;
            foreach (['vault', 'unevenBars', 'balanceBeam', 'floor'] as $d) {
                $scores = $disc[$d] ?? [];
                rsort($scores, SORT_NUMERIC);
                $top3 = array_slice($scores, 0, 3);
                $sum += array_sum($top3);
            }
            $groupTotals[$key] = $sum;
        }

        // Rank groups with tie-sharing ranking
        if (empty($groupTotals)) {
            throw new RuntimeException('Es konnten keine Mannschaften ermittelt werden. Stellen Sie sicher, dass in der CSV Vereine (club) gepflegt sind.');
        }
        $keys = array_keys($groupTotals);
        usort($keys, function (string $a, string $b) use ($groupTotals): int {
            if ($groupTotals[$a] === $groupTotals[$b]) {
                return 0;
            }

            return $groupTotals[$a] > $groupTotals[$b] ? -1 : 1;
        });
        $rankByKey = [];
        $lastTotal = null;
        $lastRank = 0;
        foreach ($keys as $pos => $key) {
            $total = $groupTotals[$key];
            if ($lastTotal === null || $total < $lastTotal) {
                $lastRank = $pos + 1;
                $lastTotal = $total;
            }
            $rankByKey[$key] = $lastRank;
        }

        // Generate PDFs (in sorted order)
        $tmpDir = storage_path('app/tmp_team_certificates_'.uniqid());
        if (! @mkdir($tmpDir) && ! is_dir($tmpDir)) {
            throw new RuntimeException('Konnte temporären Ordner für Mannschaften nicht erstellen.');
        }
        $today = now()->format('d.m.Y');
        $generatedFiles = [];

        foreach ($keys as $i => $key) {
            $group = $groups[$key];
            $data = [];
            $data['club'] = $this->toUtf8($group['club']);
            $data['ageGroup'] = 'AK '.($group['ageGroup'] !== '' ? $group['ageGroup'] : '');
            // Unique, stable ordered names (alphabetical by last name then first name)
            $names = array_values(array_unique($group['names']));
            // Try to sort by last name (word after last space)
            usort($names, function (string $a, string $b): int {
                $al = trim(strrchr(' '.$a, ' ') ?: $a);
                $bl = trim(strrchr(' '.$b, ' ') ?: $b);
                $c = strcasecmp($al, $bl);
                if ($c !== 0) {
                    return $c;
                }

                return strcasecmp($a, $b);
            });
            $namesStr = implode(', ', array_map(fn ($n) => $this->toUtf8($n), $names));
            // Fill both names and name to be robust with template field naming
            $data['names'] = $namesStr;
            $data['name'] = $namesStr;

            $data['points'] = $this->formatPoints($groupTotals[$key] ?? 0.0);
            $data['ranking'] = ($rankByKey[$key] ?? 0).'.';
            $data['today'] = $today;

            $outputFile = $tmpDir.'/team_certificate_'.($i + 1).'.pdf';
            $options = ['command' => $this->resolvePdftkBinary()];
            $pdf = new PdfTk($templatePath, $options);
            $ok = $pdf->fillForm($data)
                ->needAppearances()
                ->flatten()
                ->saveAs($outputFile);

            if (! $ok) {
                $err = (string) $pdf->getError();
                if ((str_contains($err, 'pdftk') || str_contains($err, 'pdftk-java')) && (str_contains($err, 'not found') || str_contains($err, 'No such file') || str_contains($err, 'could not be found'))) {
                    throw new RuntimeException('PDF-Erstellung fehlgeschlagen: Das pdftk-Binary wurde nicht gefunden. Bitte installieren Sie pdftk (z. B. macOS: "brew install pdftk-java", Debian/Ubuntu: "sudo apt-get install pdftk-java") oder setzen Sie PDFTK_PATH in der .env auf den absoluten Pfad zum Binary. Ursprünglicher Fehler: '.$err);
                }
                throw new RuntimeException('PDF-Erstellung fehlgeschlagen: '.$err);
            }
            $generatedFiles[] = $outputFile;
        }

        // Merge
        $finalPath = storage_path('app/team_certificates_'.date('Ymd_His').'.pdf');
        $options = ['command' => $this->resolvePdftkBinary()];
        $merge = new PdfTk($generatedFiles, $options);
        if (! $merge->cat()->saveAs($finalPath)) {
            $err = (string) $merge->getError();
            if ((str_contains($err, 'pdftk') || str_contains($err, 'pdftk-java')) && (str_contains($err, 'not found') || str_contains($err, 'No such file') || str_contains($err, 'could not be found'))) {
                throw new RuntimeException('Zusammenführen fehlgeschlagen: Das pdftk-Binary wurde nicht gefunden. Bitte installieren Sie pdftk (z. B. macOS: "brew install pdftk-java", Debian/Ubuntu: "sudo apt-get install pdftk-java") oder setzen Sie PDFTK_PATH in der .env auf den absoluten Pfad zum Binary. Ursprünglicher Fehler: '.$err);
            }
            throw new RuntimeException('Zusammenführen der PDFs fehlgeschlagen: '.$err);
        }

        // Cleanup temp files
        foreach ($generatedFiles as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);

        return $finalPath;
    }

    /**
     * Merge PDFs into a single file and return its absolute path.
     * Public to reuse pdftk resolution in controllers.
     *
     * @param  array<int,string>  $pdfPaths
     */
    public function mergePdfs(array $pdfPaths): string
    {
        if (empty($pdfPaths)) {
            throw new RuntimeException('Keine PDFs zum Zusammenführen angegeben.');
        }
        $finalPath = storage_path('app/certificates_'.date('Ymd_His').'.pdf');
        $options = ['command' => $this->resolvePdftkBinary()];
        $merge = new PdfTk($pdfPaths, $options);
        if (! $merge->cat()->saveAs($finalPath)) {
            $err = (string) $merge->getError();
            if ((str_contains($err, 'pdftk') || str_contains($err, 'pdftk-java')) && (str_contains($err, 'not found') || str_contains($err, 'No such file') || str_contains($err, 'could not be found'))) {
                throw new RuntimeException('Zusammenführen fehlgeschlagen: Das pdftk-Binary wurde nicht gefunden. Bitte installieren Sie pdftk (z. B. macOS: "brew install pdftk-java", Debian/Ubuntu: "sudo apt-get install pdftk-java") oder setzen Sie PDFTK_PATH in der .env auf den absoluten Pfad zum Binary. Ursprünglicher Fehler: '.$err);
            }
            throw new RuntimeException('Zusammenführen der PDFs fehlgeschlagen: '.$err);
        }

        return $finalPath;
    }

    /**
     * Ensure a string is valid UTF-8. If not, attempt a best-effort conversion.
     */
    private function toUtf8(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
        }

        return $value;
    }

    /**
     * Heuristically detect whether the CSV uses ',' or ';' as delimiter by sampling the first ~2KB.
     */
    private function detectDelimiter(string $path): string
    {
        $sample = file_get_contents($path, false, null, 0, 2048) ?: '';
        $comma = substr_count($sample, ',');
        $semicolon = substr_count($sample, ';');

        return $semicolon > $comma ? ';' : ',';
    }

    /**
     * Parse a discipline score string into a float. Accepts ',' or '.' as decimal separator.
     */
    /**
     * Parse a discipline score string into a float.
     * - Accepts ',' or '.' as decimal separator.
     * - Ignores extraneous characters.
     * - Empty or invalid values return 0.0.
     */
    private function parseScore(string $raw): float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0.0;
        }
        // Replace comma with dot and remove any unwanted characters
        $normalized = str_replace(',', '.', $raw);
        // Keep digits, dot, minus
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.') {
            return 0.0;
        }

        return (float) $normalized;
    }

    /**
     * Format total points with a comma as decimal separator and no thousands separator.
     * Always show exactly two decimals (e.g., 51,50; 51,00; 51,55).
     */
    /**
     * Format total points with a comma as decimal separator and no thousands separator.
     * Always show exactly two decimals (e.g., 51,50; 51,00; 51,55).
     */
    private function formatPoints(float $total): string
    {
        return number_format($total, 2, ',', '');
    }

    /**
     * Determine if a club value should be treated as empty/missing.
     * This filters placeholders like "0", "-", "null", "ohne verein", etc.
     */
    /**
     * Determine if a club value should be treated as empty/missing.
     * Filters placeholders like "0", "-", "null", "ohne verein", etc.
     */
    private function isEmptyClub(string $club): bool
    {
        $value = trim($club);
        if ($value === '') {
            return true;
        }

        $lower = mb_strtolower($value, 'UTF-8');
        // Common placeholders indicating no club
        $placeholders = [
            '0', '0.0', '0,0', '-', '--', '—', 'n/a', 'na', 'null', 'none',
            'kein', 'keiner', 'ohne', 'ohne verein', 'kein verein', 'k. a.', 'k.a.', 'k.a',
        ];
        if (in_array($lower, $placeholders, true)) {
            return true;
        }

        // If value contains only punctuation or digits zeros
        $stripped = preg_replace('/[\p{L}\p{N}]+/u', '', $lower) ?? '';
        $onlyZeros = preg_replace('/[0]/', '', $lower) === '';
        if ($lower === $stripped || $onlyZeros) {
            return true;
        }

        return false;
    }

    /**
     * Resolve the pdftk binary to use, trying environment, PATH, and common locations.
     */
    /**
     * Resolve the pdftk binary to use.
     * Order: configured value -> known absolute paths -> PATH lookup for aliases (pdftk, pdftk-java).
     */
    private function resolvePdftkBinary(): string
    {
        $configured = (string) config('pdftk.binary', 'pdftk');

        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        // Also consider typical names and locations
        $candidates = array_merge($candidates, [
            'pdftk',
            'pdftk-java',
            '/opt/homebrew/bin/pdftk-java', // macOS Homebrew (Apple Silicon)
            '/usr/local/bin/pdftk-java',
            '/usr/local/bin/pdftk',
            '/usr/bin/pdftk',
            '/usr/bin/pdftk-java',
        ]);

        // First pass: absolute paths that exist and are executable
        foreach ($candidates as $cmd) {
            if (str_contains($cmd, DIRECTORY_SEPARATOR) && is_file($cmd) && is_executable($cmd)) {
                return $cmd;
            }
        }

        // Second pass: try to locate in PATH using `command -v`
        foreach ($candidates as $cmd) {
            if (! str_contains($cmd, DIRECTORY_SEPARATOR)) {
                $found = $this->findInPath($cmd);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        // Fall back to configured value; downstream error handling will show a helpful message.
        return $configured !== '' ? $configured : 'pdftk';
    }

    /**
     * Try to resolve a command from PATH using `command -v`.
     */
    /**
     * Attempt to resolve a binary from the system PATH using `command -v`.
     */
    private function findInPath(string $cmd): ?string
    {
        try {
            $which = @shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null');
            $which = is_string($which) ? trim($which) : '';
            if ($which !== '' && is_file($which) && is_executable($which)) {
                return $which;
            }
        } catch (\Throwable $e) {
            // Ignore and fall through
        }

        return null;
    }

    /**
     * Compute single results (without generating PDFs) from a CSV file.
     * Returns an array of rows with: name, club, ageGroup (prefixed with AK ), points (formatted), ranking (number), and raw totals.
     *
     * @return array<int,array<string,mixed>>
     */
    public function computeSingleResultsFromCsv(string $csvPath): array
    {
        $delimiter = $this->detectDelimiter($csvPath);

        $readerWithHeader = Reader::createFromPath($csvPath, 'r');
        $readerWithHeader->setDelimiter($delimiter);
        $readerWithHeader->setHeaderOffset(0);

        $expectedHeaders = $this->expectedHeaders;
        $headers = $readerWithHeader->getHeader();

        $useHeaderMode = true;
        if ($headers === null || empty($headers)) {
            $useHeaderMode = false;
        } else {
            $missing = array_diff($expectedHeaders, $headers);
            if (! empty($missing)) {
                $useHeaderMode = false;
            }
        }

        if ($useHeaderMode) {
            $records = iterator_to_array($readerWithHeader->getRecords());
        } else {
            $readerNoHeader = Reader::createFromPath($csvPath, 'r');
            $readerNoHeader->setDelimiter($delimiter);
            $rows = iterator_to_array($readerNoHeader->getRecords());
            $records = [];
            foreach ($rows as $row) {
                $assoc = [];
                $i = 0;
                foreach ($expectedHeaders as $headerName) {
                    $assoc[$headerName] = (string) ($row[$i] ?? '');
                    $i++;
                }
                $records[] = $assoc;
            }
        }

        // Totals and ranks
        $totals = [];
        foreach ($records as $i => $row) {
            $v = $this->parseScore((string) ($row['vault'] ?? '0'));
            $u = $this->parseScore((string) ($row['unevenBars'] ?? '0'));
            $b = $this->parseScore((string) ($row['balanceBeam'] ?? '0'));
            $f = $this->parseScore((string) ($row['floor'] ?? '0'));
            $totals[$i] = $v + $u + $b + $f;
        }
        $indices = array_keys($records);
        usort($indices, function (int $a, int $b) use ($totals): int {
            if ($totals[$a] === $totals[$b]) {
                return 0;
            }

            return $totals[$a] > $totals[$b] ? -1 : 1;
        });
        $rankByIndex = [];
        $lastTotal = null;
        $lastRank = 0;
        foreach ($indices as $pos => $originalIndex) {
            $total = $totals[$originalIndex];
            if ($lastTotal === null || $total < $lastTotal) {
                $lastRank = $pos + 1;
                $lastTotal = $total;
            }
            $rankByIndex[$originalIndex] = $lastRank;
        }

        $out = [];
        foreach ($records as $i => $row) {
            $first = trim((string) ($row['firstname'] ?? ''));
            $last = trim((string) ($row['name'] ?? ''));
            $fullName = trim($first.' '.$last);
            $club = (string) ($row['club'] ?? '');
            if ($this->isEmptyClub($club)) {
                $club = '';
            }
            $age = (string) ($row['ageGroup'] ?? '');
            $total = $totals[$i] ?? 0.0;
            $out[] = [
                'name' => $this->toUtf8($fullName),
                'club' => $this->toUtf8($club),
                'ageGroup' => 'AK '.($age !== '' ? $age : ''),
                'points' => $this->formatPoints($total),
                'ranking' => $rankByIndex[$i] ?? 0,
                'total' => $total,
            ];
        }

        return $out;
    }

    /**
     * Compute team results (without generating PDFs) from a CSV file.
     * Groups by club+ageGroup, excludes empty/placeholder clubs, sums top 3 per discipline, and ranks with tie-sharing.
     * Returns array of groups in ranked order with keys: club, ageGroup (prefixed), names (csv string), total (float), points (formatted), ranking (int).
     *
     * @return array<int,array<string,mixed>>
     */
    public function computeTeamResultsFromCsv(string $csvPath): array
    {
        $delimiter = $this->detectDelimiter($csvPath);
        $readerWithHeader = Reader::createFromPath($csvPath, 'r');
        $readerWithHeader->setDelimiter($delimiter);
        $readerWithHeader->setHeaderOffset(0);

        $expectedHeaders = $this->expectedHeaders;
        $headers = $readerWithHeader->getHeader();

        $useHeaderMode = true;
        if ($headers === null || empty($headers)) {
            $useHeaderMode = false;
        } else {
            $missing = array_diff($expectedHeaders, $headers);
            if (! empty($missing)) {
                $useHeaderMode = false;
            }
        }

        if ($useHeaderMode) {
            $records = iterator_to_array($readerWithHeader->getRecords());
        } else {
            $readerNoHeader = Reader::createFromPath($csvPath, 'r');
            $readerNoHeader->setDelimiter($delimiter);
            $rows = iterator_to_array($readerNoHeader->getRecords());
            $records = [];
            foreach ($rows as $row) {
                $assoc = [];
                $i = 0;
                foreach ($expectedHeaders as $headerName) {
                    $assoc[$headerName] = (string) ($row[$i] ?? '');
                    $i++;
                }
                $records[] = $assoc;
            }
        }

        $groups = [];
        foreach ($records as $row) {
            $club = trim((string) ($row['club'] ?? ''));
            if ($this->isEmptyClub($club)) {
                continue;
            }
            $age = trim((string) ($row['ageGroup'] ?? ''));
            $key = $club.'|'.$age;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'club' => $club,
                    'ageGroup' => $age,
                    'vault' => [],
                    'unevenBars' => [],
                    'balanceBeam' => [],
                    'floor' => [],
                    'names' => [],
                ];
            }
            $groups[$key]['vault'][] = $this->parseScore((string) ($row['vault'] ?? '0'));
            $groups[$key]['unevenBars'][] = $this->parseScore((string) ($row['unevenBars'] ?? '0'));
            $groups[$key]['balanceBeam'][] = $this->parseScore((string) ($row['balanceBeam'] ?? '0'));
            $groups[$key]['floor'][] = $this->parseScore((string) ($row['floor'] ?? '0'));

            $first = trim((string) ($row['firstname'] ?? ''));
            $last = trim((string) ($row['name'] ?? ''));
            $full = trim($first.' '.$last);
            if ($full !== '') {
                $groups[$key]['names'][] = $full;
            }
        }

        $groupTotals = [];
        foreach ($groups as $key => $disc) {
            $sum = 0.0;
            foreach (['vault', 'unevenBars', 'balanceBeam', 'floor'] as $d) {
                $scores = $disc[$d] ?? [];
                rsort($scores, SORT_NUMERIC);
                $top3 = array_slice($scores, 0, 3);
                $sum += array_sum($top3);
            }
            $groupTotals[$key] = $sum;
        }

        if (empty($groupTotals)) {
            return [];
        }

        $keys = array_keys($groupTotals);
        usort($keys, function (string $a, string $b) use ($groupTotals): int {
            if ($groupTotals[$a] === $groupTotals[$b]) {
                return 0;
            }

            return $groupTotals[$a] > $groupTotals[$b] ? -1 : 1;
        });
        $rankByKey = [];
        $lastTotal = null;
        $lastRank = 0;
        foreach ($keys as $pos => $key) {
            $total = $groupTotals[$key];
            if ($lastTotal === null || $total < $lastTotal) {
                $lastRank = $pos + 1;
                $lastTotal = $total;
            }
            $rankByKey[$key] = $lastRank;
        }

        $out = [];
        foreach ($keys as $key) {
            $g = $groups[$key];
            $names = array_values(array_unique($g['names']));
            usort($names, function (string $a, string $b): int {
                $al = trim(strrchr(' '.$a, ' ') ?: $a);
                $bl = trim(strrchr(' '.$b, ' ') ?: $b);
                $c = strcasecmp($al, $bl);
                if ($c !== 0) {
                    return $c;
                }

                return strcasecmp($a, $b);
            });
            $namesStr = implode(', ', array_map(fn ($n) => $this->toUtf8($n), $names));

            $out[] = [
                'club' => $this->toUtf8($g['club']),
                'ageGroup' => 'AK '.($g['ageGroup'] !== '' ? $g['ageGroup'] : ''),
                'names' => $namesStr,
                'total' => $groupTotals[$key],
                'points' => $this->formatPoints($groupTotals[$key]),
                'ranking' => $rankByKey[$key],
            ];
        }

        return $out;
    }
}
