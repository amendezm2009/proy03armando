<?php

namespace App\Http\Controllers;

use App\Actions\RecordClassificationSearches;
use App\Models\Classification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Historial de clasificaciones del selector (<x-classification-picker>): anotar la que se acaba de
 * elegir y quitar una. Solo toca las filas de quien hace la petición; la clasificación no se modifica.
 */
class ClassificationRecentController extends Controller
{
    public function store(Request $request, Classification $classification, RecordClassificationSearches $record): Response
    {
        $record->handle($request->user(), [$classification->id]);

        return response()->noContent();
    }

    public function destroy(Request $request, Classification $classification): Response
    {
        $request->user()->recentClassifications()->detach($classification->id);

        return response()->noContent();
    }
}
