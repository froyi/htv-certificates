<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                'mimes:csv',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Bitte wählen Sie eine CSV-Datei aus.',
            'file.mimes' => 'Nur CSV-Dateien sind erlaubt.',
            'file.mimetypes' => 'Nur CSV-Dateien sind erlaubt.',
        ];
    }
}
