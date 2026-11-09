<?php

namespace App\Actions;

use App\Models\Classification;
use Illuminate\Support\Str;

class SaveClassification
{
    /**
     * @param  array<string, mixed>  $data  Datos ya validados.
     */
    public function handle(array $data, ?Classification $classification = null): Classification
    {
        $classification ??= new Classification;
        // dd($data);
        // dd($classification);
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $classification->fill($data)->save();
        // dd($classification);
        return $classification;
    }
}
