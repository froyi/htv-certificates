<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadCsvRequestTest extends TestCase
{
    public function test_requires_csv_extension_and_mimetype(): void
    {
        $this->withoutMiddleware();

        Storage::fake('local');
        $file = UploadedFile::fake()->create('data.txt', 1, 'text/plain');

        $response = $this->post(route('upload.process'), [
            'file' => $file,
            'single' => '1',
        ]);

        $response->assertSessionHasErrors(['file']);
        $this->assertStringContainsString('Nur CSV-Dateien sind erlaubt.', collect(session('errors')->all())->join(' '));
    }

    public function test_accepts_csv_and_requires_at_least_one_checkbox(): void
    {
        $this->withoutMiddleware();

        Storage::fake('local');
        $csvContent = "firstname,name,club,ageGroup,vault,unevenBars,balanceBeam,floor\nA,B,C,10,1,1,1,1\n";
        $file = UploadedFile::fake()->createWithContent('data.csv', $csvContent);

        // Neither single nor team selected
        $response = $this->post(route('upload.process'), [
            'file' => $file,
        ]);

        $response->assertSessionHasErrors(['single']);
        $this->assertStringContainsString('Bitte wählen Sie Einzel- und/oder Mannschafts-Urkunden.', collect(session('errors')->all())->join(' '));

    }
}
