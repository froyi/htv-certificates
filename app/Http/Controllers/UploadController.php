<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadCsvRequest;
use App\Services\PdfCertificateService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Handle the upload form and dispatch certificate generation.
 *
 * - Validates CSV uploads and checkbox selections via UploadCsvRequest.
 * - Calls PdfCertificateService to generate single and/or team PDFs.
 * - Merges PDFs if both are selected and responds with a download.
 */
class UploadController extends Controller
{
    public function __construct(public PdfCertificateService $service) {}

    /**
     * Show the upload form.
     */
    public function form(): View
    {
        return view('upload.form');
    }

    /**
     * Handle the upload submission and return the generated PDF as a download.
     *
     * Flow:
     * - Store uploaded CSV temporarily.
     * - Generate selected PDFs (single/team) via PdfCertificateService.
     * - If both are selected, merge PDFs and delete intermediates.
     * - Stream the final PDF and delete temp files.
     *
     * @throws RuntimeException
     */
    public function process(UploadCsvRequest $request): HttpResponse
    {
        $uploaded = $request->file('file');
        if ($uploaded === null) {
            abort(400, 'Keine Datei hochgeladen.');
        }

        $path = $uploaded->store('uploads');
        $absolutePath = Storage::path($path);

        try {
            $paths = [];
            $generateSingle = $request->boolean('single');
            $generateTeam = $request->boolean('team');

            if ($generateSingle) {
                $paths[] = $this->service->generateFromCsv($absolutePath);
            }
            if ($generateTeam) {
                $paths[] = $this->service->generateTeamFromCsv($absolutePath);
            }

            // If both selected, merge into one file for a single download
            if (count($paths) === 1) {
                $finalPdf = $paths[0];
            } else {
                $finalPdf = $this->service->mergePdfs($paths);
                // Cleanup the individual PDFs after merge
                foreach ($paths as $p) {
                    @unlink($p);
                }
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        } finally {
            // Cleanup uploaded CSV
            @unlink($absolutePath);
        }

        // Return as download
        $filename = basename($finalPdf);
        $content = file_get_contents($finalPdf);
        if ($content === false) {
            throw new FileNotFoundException("Konnte erzeugte PDF nicht lesen: {$filename}");
        }

        // Optionally delete after read
        @unlink($finalPdf);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
