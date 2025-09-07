<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use mikehaertl\pdftk\Pdf as PdfTk;
use RuntimeException;

class PdfCertificateService
{
    /**
     * Expected CSV headers in their required order.
     *
     * @var array<int,string>
     */
    private array $expectedHeaders = [
        'Vorname',
        'Name',
        'Verein',
        'Altersklasse',
        'Punkte',
        'Platz',
    ];

    /**
     * Mapping from CSV header names (excluding Vorname/Name) to PDF form field names.
     * Vorname and Name are combined into the PDF field "name".
     *
     * @var array<string,string>
     */
    private array $fieldMap = [
        'Verein' => 'club',
        'Altersklasse' => 'ageGroup',
        'Punkte' => 'points',
        'Platz' => 'ranking',
    ];

    /**
     * Generate a combined PDF from CSV file contents.
     *
     * @param  string  $csvPath  Absolute path to the uploaded CSV file.
     * @return string Absolute path to the generated PDF file.
     */
    public function generateFromCsv(string $csvPath): string
    {
        // Ensure the template exists (try multiple common locations)
        $candidates = [
            // Public disk (storage/app/public)
            \Illuminate\Support\Facades\Storage::disk('public')->path('template_brass.pdf'),
            // Local/private disk (storage/app/private)
            \Illuminate\Support\Facades\Storage::disk('local')->path('template_brass.pdf'),
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
        $readerWithHeader = Reader::createFromPath($csvPath, 'r');
        $readerWithHeader->setDelimiter($delimiter);
        $readerWithHeader->setHeaderOffset(0);

        $expectedHeaders = $this->expectedHeaders;
        $headers = $readerWithHeader->getHeader();

        $useHeaderMode = true;
        if ($headers === null || empty($headers)) {
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

        foreach ($records as $index => $row) {
            // Normalize encoding to UTF-8
            $data = [];

            // Combine first and last name to single PDF field "name"
            $first = trim((string) ($row['Vorname'] ?? ''));
            $last = trim((string) ($row['Name'] ?? ''));
            $fullName = trim($first.' '.$last);

            $data['name'] = $this->toUtf8($fullName);

            // Map remaining CSV columns via fieldMap
            foreach ($this->fieldMap as $csvColumn => $pdfField) {
                $value = (string) ($row[$csvColumn] ?? '');
                $data[$pdfField] = $this->toUtf8($value);
            }

            empty($data['club']) && $data['club'] = '';

            $normalized = str_replace(',', '.', $data['ranking']);
            $float = (float) $normalized;
            $data['ranking'] = (int) ceil($float) . '.';

            $data['ageGroup'] = 'AK ' . $data['ageGroup'];

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

    private function toUtf8(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
        }

        return $value;
    }

    private function detectDelimiter(string $path): string
    {
        $sample = file_get_contents($path, false, null, 0, 2048) ?: '';
        $comma = substr_count($sample, ',');
        $semicolon = substr_count($sample, ';');

        return $semicolon > $comma ? ';' : ',';
    }

    /**
     * Resolve the pdftk binary to use, trying environment, PATH, and common locations.
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
}
