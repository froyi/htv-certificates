<?php

namespace Tests\Feature;

use App\Services\PdfCertificateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class UploadControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function fakePdf(string $path, string $disk = 'local'): string
    {
        Storage::disk($disk)->put($path, '%PDF-1.4\n%fake');

        return Storage::disk($disk)->path($path);
    }

    private function fakeCsvUpload(): UploadedFile
    {
        $csv = "firstname,name,club,ageGroup,vault,unevenBars,balanceBeam,floor\nA,B,C,10,1,1,1,1\n";

        return UploadedFile::fake()->createWithContent('data.csv', $csv);
    }

    public function test_process_single_only_calls_generate_from_csv_and_returns_pdf(): void
    {
        $service = Mockery::mock(PdfCertificateService::class);
        $pdfPath = $this->fakePdf('test_single.pdf');
        $service->shouldReceive('generateFromCsv')->once()->andReturn($pdfPath);
        $this->app->instance(PdfCertificateService::class, $service);

        $response = $this->post(route('upload.process'), [
            'file' => $this->fakeCsvUpload(),
            'single' => '1',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $service->shouldHaveReceived('generateFromCsv');
    }

    public function test_process_team_only_calls_generate_team_from_csv_and_returns_pdf(): void
    {
        $service = Mockery::mock(PdfCertificateService::class);
        $pdfPath = $this->fakePdf('test_team.pdf');
        $service->shouldReceive('generateTeamFromCsv')->once()->andReturn($pdfPath);
        $this->app->instance(PdfCertificateService::class, $service);

        $response = $this->post(route('upload.process'), [
            'file' => $this->fakeCsvUpload(),
            'team' => '1',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $service->shouldHaveReceived('generateTeamFromCsv');
    }

    public function test_process_both_calls_both_and_merges(): void
    {
        $service = Mockery::mock(PdfCertificateService::class);
        $single = $this->fakePdf('single.pdf');
        $team = $this->fakePdf('team.pdf');
        $merged = $this->fakePdf('merged.pdf');

        $service->shouldReceive('generateFromCsv')->once()->andReturn($single);
        $service->shouldReceive('generateTeamFromCsv')->once()->andReturn($team);
        $service->shouldReceive('mergePdfs')->once()->andReturn($merged);
        $this->app->instance(PdfCertificateService::class, $service);

        $response = $this->post(route('upload.process'), [
            'file' => $this->fakeCsvUpload(),
            'single' => '1',
            'team' => '1',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $service->shouldHaveReceived('mergePdfs');
    }
}
