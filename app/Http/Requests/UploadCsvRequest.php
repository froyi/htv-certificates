<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the upload form submission for generating certificates.
 *
 * Rules:
 * - file: required CSV file (by mimetype and extension), max 10MB.
 * - single/team: optional checkboxes, but at least one must be selected (checked in withValidator).
 */
class UploadCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Define validation rules for the upload form.
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // Accept common CSV MIME types across browsers/OSes
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                // Ensure the filename has a .csv extension (case-insensitive)
                'extensions:csv',
                'max:10240',
            ],
            'single' => ['nullable', 'boolean'],
            'team' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Customize validation error messages (German localization).
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Bitte wählen Sie eine CSV-Datei aus.',
            'file.extensions' => 'Nur CSV-Dateien sind erlaubt.',
            'file.mimetypes' => 'Nur CSV-Dateien sind erlaubt.',
        ];
    }

    /**
     * Post-validation hook to ensure that at least one of the checkboxes (single/team) is selected.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $single = filter_var($this->input('single'), FILTER_VALIDATE_BOOLEAN);
            $team = filter_var($this->input('team'), FILTER_VALIDATE_BOOLEAN);
            if (! $single && ! $team) {
                $validator->errors()->add('single', 'Bitte wählen Sie Einzel- und/oder Mannschafts-Urkunden.');
            }
        });
    }
}
