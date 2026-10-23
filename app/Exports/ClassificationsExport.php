<?php

namespace App\Exports;

use App\Models\Classification;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ClassificationsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function query(): Builder
    {
        return Classification::query()->orderBy('name');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['id', 'name', 'slug', 'description', 'color_badge', 'is_active', 'created_at', 'updated_at'];
    }

    /**
     * @param  Classification  $classification
     * @return array<int, mixed>
     */
    public function map($classification): array
    {
        return [
            $classification->id,
            $classification->name,
            $classification->slug,
            $classification->description,
            $classification->color_badge,
            (int) $classification->is_active,
            $classification->created_at->format('d/m/Y H:i'),
            $classification->updated_at->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DDDDDD']],
            ],
        ];
    }
}
