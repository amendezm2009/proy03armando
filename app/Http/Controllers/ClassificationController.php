<?php

namespace App\Http\Controllers;

use App\Actions\SaveClassification;
use App\Exports\ClassificationsExport;
use App\Http\Requests\ClassificationRequest;
use App\Imports\ClassificationsImport;
use App\Models\Classification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ClassificationController extends Controller
{
    public function index(): View
    {
        return view('classifications.index', [
            'classifications' => Classification::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('classifications.create');
    }

    public function store(ClassificationRequest $request, SaveClassification $save): RedirectResponse
    {
        $save->handle($request->validated());

        return to_route('classifications.index')->with('success', 'Clasificación creada.');
    }

    public function edit(Classification $classification): View
    {
        return view('classifications.edit', compact('classification'));
    }

    public function update(ClassificationRequest $request, Classification $classification, SaveClassification $save): RedirectResponse
    {
        $save->handle($request->validated(), $classification);

        return to_route('classifications.index')->with('success', 'Clasificación actualizada.');
    }

    public function destroy(Classification $classification): RedirectResponse
    {
        $classification->delete();

        return to_route('classifications.index')->with('success', 'Clasificación eliminada.');
    }

    public function export(): BinaryFileResponse
    {
        return Excel::download(new ClassificationsExport, 'clasificaciones.xlsx');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv', 'max:2048']]);

        try {
            Excel::import(app(ClassificationsImport::class), $request->file('file'));
        } catch (ValidationException $e) {
            return back()->withErrors(collect($e->failures())->map(
                fn ($failure) => "Fila {$failure->row()}: ".implode(', ', $failure->errors())
            )->all());
        }

        return back()->with('success', 'Importación completada.');
    }
}
