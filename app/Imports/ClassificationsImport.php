<?php

namespace App\Imports;

use App\Actions\SaveClassification;
use App\Http\Requests\ClassificationRequest;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ClassificationsImport implements ToModel, WithHeadingRow, WithValidation
{
    public function __construct(private SaveClassification $save) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function model(array $row): null
    {
        $this->save->handle([
            'name' => $row['name'],
            'description' => $row['description'] ?? null,
            'color_badge' => $row['color_badge'],
            'is_active' => (bool) ($row['is_active'] ?? true),
        ]);

        return null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (ClassificationRequest::baseRules() as $field => $fieldRules) {
            $rules["*.$field"] = $fieldRules;
        }

        $rules['*.name'][] = 'distinct';
        $rules['*.is_active'][] = 'nullable';

        return $rules;
    }
}
