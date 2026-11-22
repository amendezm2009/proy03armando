<?php

namespace App\Http\Controllers;

use App\Actions\RecordClassificationSearches;
use App\Http\Requests\ClassificationLookupRequest;
use App\Models\Classification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Alimenta el selector de clasificaciones (<x-classification-picker>). Cada elemento lleva un "group":
 *
 * - Sin texto: las "recent" del usuario (tabla classification_users_searches) y, debajo, las "suggested":
 *   las primeras por nombre, para dar una idea de qué clasificaciones existen. No se cuentan productos:
 *   con decenas de miles de registros ese conteo haría lenta la primera apertura.
 * - Con menos de ClassificationLookupRequest::MIN_CHARS letras: nada (el selector conserva la lista inicial).
 * - Con esas letras o más: hasta 20 coincidencias por nombre o slug, todas "result".
 * only_active=1 excluye las inactivas (formulario de producto); el filtro del listado las incluye.
 */
class ClassificationLookupController extends Controller
{
    private const int LIMIT = 20;

    private const int SUGGESTED = 10;

    public function __invoke(ClassificationLookupRequest $request): JsonResponse
    {
        $term = trim((string) $request->validated('q', ''));
        $onlyActive = $request->boolean('only_active');

        $items = match (true) {
            $term === '' => $this->initial($request, $onlyActive),
            mb_strlen($term) < ClassificationLookupRequest::MIN_CHARS => collect(),
            default => $this->present($this->search($term, $onlyActive), 'result'),
        };

        return response()->json(['data' => $items->values()->all()]);
    }

    /**
     * @return Collection<int, array{id: int, name: string, color_badge: string, group: string}>
     */
    private function initial(ClassificationLookupRequest $request, bool $onlyActive): Collection
    {
        $recent = $request->user()->recentClassifications()
            ->when($onlyActive, fn ($query) => $query->where('classifications.is_active', true))
            ->orderByPivot('last_used_at', 'desc')
            ->limit(RecordClassificationSearches::KEEP)
            ->get();

        $suggested = Classification::query()
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->whereNotIn('id', $recent->modelKeys())
            ->orderBy('name')
            ->limit(self::SUGGESTED)
            ->get();

        return $this->present($recent, 'recent')->concat($this->present($suggested, 'suggested'));
    }

    /**
     * @return Collection<int, Classification>
     */
    private function search(string $term, bool $onlyActive): Collection
    {
        return Classification::filter(['search' => $term] + ($onlyActive ? ['is_active' => true] : []))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @param  Collection<int, Classification>  $classifications
     * @return Collection<int, array{id: int, name: string, color_badge: string, group: string}>
     */
    private function present(Collection $classifications, string $group): Collection
    {
        return $classifications->map(fn (Classification $classification): array => [
            'id' => $classification->id,
            'name' => $classification->name,
            'color_badge' => $classification->color_badge,
            'group' => $group,
        ]);
    }
}
