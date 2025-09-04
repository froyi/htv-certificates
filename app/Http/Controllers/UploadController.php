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

class UploadController extends Controller
{
    public function __construct(public PdfCertificateService $service) {}

    public function form(): View
    {
        return view('upload.form');
    }

    public function process(UploadCsvRequest $request): HttpResponse
    {
        $uploaded = $request->file('file');
        if ($uploaded === null) {
            abort(400, 'Keine Datei hochgeladen.');
        }

        $path = $uploaded->store('uploads');
        $absolutePath = Storage::path($path);

        try {
            $finalPdf = $this->service->generateFromCsv($absolutePath);
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
