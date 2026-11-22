<?php

use App\Actions\RecordClassificationSearches;
use App\Models\Classification;
use App\Models\Product;
use App\Models\User;
use App\RoleName;
use Illuminate\Support\Facades\DB;

function recentIdsFor(User $user): array
{
    return DB::table('classification_users_searches')
        ->where('user_id', $user->id)
        ->orderByDesc('last_used_at')->orderByDesc('id')
        ->pluck('classification_id')->all();
}

/**
 * @return list<int>
 */
function idsInGroup(array $data, string $group): array
{
    return collect($data)->where('group', $group)->pluck('id')->values()->all();
}

describe('acceso', function () {
    it('responde 401 sin sesión', function () {
        $this->getJson(route('classifications.lookup'))->assertUnauthorized();
    });

    it('responde 403 a un usuario sin permiso de ver clasificaciones', function () {
        $this->actingAs(User::factory()->create())
            ->getJson(route('classifications.lookup', ['q' => 'abc']))
            ->assertForbidden();
    });

    it('permite buscar al cliente', function () {
        $this->actingAs(userWithRole(RoleName::Cliente))
            ->getJson(route('classifications.lookup', ['q' => 'abc']))
            ->assertOk();
    });
});

describe('búsqueda', function () {
    beforeEach(fn () => $this->actingAs(userWithRole(RoleName::Admin)));

    it('no devuelve nada con uno o dos caracteres', function (string $term) {
        Classification::factory()->create(['name' => 'Iluminación']);

        $this->getJson(route('classifications.lookup', ['q' => $term]))
            ->assertOk()
            ->assertJsonPath('data', []);
    })->with(['una letra' => ['I'], 'dos letras' => ['Il']]);

    it('busca por nombre o slug desde tres caracteres, ordenado por nombre', function () {
        Classification::factory()->create(['name' => 'Iluminación solar', 'slug' => 'iluminacion-solar']);
        Classification::factory()->create(['name' => 'Ilusiones ópticas', 'slug' => 'ilusiones']);
        Classification::factory()->create(['name' => 'Cocina', 'slug' => 'cocina']);

        $this->getJson(route('classifications.lookup', ['q' => 'Ilu']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Iluminación solar')
            ->assertJsonPath('data.0.group', 'result')
            ->assertJsonPath('data.1.name', 'Ilusiones ópticas')
            ->assertJsonStructure(['data' => [['id', 'name', 'color_badge', 'group']]]);

        $this->getJson(route('classifications.lookup', ['q' => 'coc']))->assertJsonPath('data.0.name', 'Cocina');
    });

    it('omite las inactivas solo si se pide', function () {
        Classification::factory()->create(['name' => 'Verano activo', 'is_active' => true]);
        Classification::factory()->create(['name' => 'Verano inactivo', 'is_active' => false]);

        $this->getJson(route('classifications.lookup', ['q' => 'Vera']))->assertJsonCount(2, 'data');
        $this->getJson(route('classifications.lookup', ['q' => 'Vera', 'only_active' => 1]))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Verano activo');
    });

    it('limita los resultados a 20', function () {
        Classification::factory()->count(25)->sequence(fn ($sequence) => ['name' => "Prueba {$sequence->index}"])->create();

        $this->getJson(route('classifications.lookup', ['q' => 'Prue']))->assertJsonCount(20, 'data');
    });

    it('rechaza un texto demasiado largo', function () {
        $this->getJson(route('classifications.lookup', ['q' => str_repeat('a', 101)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    });
});

describe('lista inicial', function () {
    it('sugiere las primeras por nombre cuando no hay historial, sin contar productos', function () {
        $this->actingAs(userWithRole(RoleName::Admin));
        $zeta = Classification::factory()->create(['name' => 'Zeta']);
        $alfa = Classification::factory()->create(['name' => 'Alfa']);
        $media = Classification::factory()->create(['name' => 'Media']);
        Product::factory()->count(3)->create()->each(fn (Product $product) => $product->classifications()->attach($zeta));

        DB::enableQueryLog();
        $response = $this->getJson(route('classifications.lookup'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');

        expect(idsInGroup($response->json('data'), 'recent'))->toBe([])
            ->and(idsInGroup($response->json('data'), 'suggested'))->toBe([$alfa->id, $media->id, $zeta->id])
            ->and($queries)->not->toContain('classification_product');
    });

    it('limita las sugeridas a 10 y respeta only_active', function () {
        $this->actingAs(userWithRole(RoleName::Admin));
        Classification::factory()->count(12)->create(['is_active' => true]);
        Classification::factory()->create(['is_active' => false]);

        expect(idsInGroup($this->getJson(route('classifications.lookup'))->json('data'), 'suggested'))->toHaveCount(10);

        $inactive = Classification::where('is_active', false)->firstOrFail();
        $this->getJson(route('classifications.lookup', ['only_active' => 1, 'q' => '']))
            ->assertJsonMissing(['id' => $inactive->id]);
    });

    it('pone primero las recientes del usuario, la más reciente primero, y no las repite entre las sugeridas', function () {
        $user = userWithRole(RoleName::Admin);
        [$a, $b, $other] = Classification::factory()->count(3)->create();
        $record = app(RecordClassificationSearches::class);

        $this->travelTo(now());
        $record->handle($user, [$a->id]);
        $this->travel(1)->minutes();
        $record->handle($user, [$b->id]);

        $data = $this->actingAs($user)->getJson(route('classifications.lookup'))->assertOk()->json('data');

        expect(idsInGroup($data, 'recent'))->toBe([$b->id, $a->id])
            ->and(idsInGroup($data, 'suggested'))->toBe([$other->id])
            ->and(array_column($data, 'group'))->toBe(['recent', 'recent', 'suggested']);
    });

    it('no mezcla el historial de otro usuario ni las inactivas cuando se piden activas', function () {
        $user = userWithRole(RoleName::Admin);
        $other = User::factory()->create();
        $active = Classification::factory()->create(['is_active' => true]);
        $inactive = Classification::factory()->create(['is_active' => false]);
        $othersOnly = Classification::factory()->create();
        $record = app(RecordClassificationSearches::class);

        $record->handle($user, [$active->id, $inactive->id]);
        $record->handle($other, [$othersOnly->id]);

        $this->actingAs($user);

        $all = $this->getJson(route('classifications.lookup'))->json('data');
        expect(collect(idsInGroup($all, 'recent'))->sort()->values()->all())
            ->toBe(collect([$active->id, $inactive->id])->sort()->values()->all())
            ->and(idsInGroup($all, 'suggested'))->toBe([$othersOnly->id]);

        $onlyActive = $this->getJson(route('classifications.lookup', ['only_active' => 1]))->json('data');
        expect(idsInGroup($onlyActive, 'recent'))->toBe([$active->id]);
    });
});

describe('historial', function () {
    it('anota la clasificación elegida y la deja como la más reciente', function () {
        $user = userWithRole(RoleName::Cliente);
        [$a, $b] = Classification::factory()->count(2)->create();

        $this->actingAs($user);
        $this->travelTo(now());
        $this->postJson(route('classifications.recent.store', $a))->assertNoContent();
        $this->travel(1)->minutes();
        $this->postJson(route('classifications.recent.store', $b))->assertNoContent();
        $this->travel(1)->minutes();
        $this->postJson(route('classifications.recent.store', $a))->assertNoContent();

        expect(recentIdsFor($user))->toBe([$a->id, $b->id]);
    });

    it('exige sesión y permiso para anotar, y responde 404 si la clasificación no existe', function () {
        $classification = Classification::factory()->create();

        $this->postJson(route('classifications.recent.store', $classification))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson(route('classifications.recent.store', $classification))
            ->assertForbidden();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->postJson(route('classifications.recent.store', 999999))
            ->assertNotFound();
    });

    it('no anota nada solo por filtrar el listado ni por guardar un producto', function () {
        $user = userWithRole(RoleName::Admin);
        $classification = Classification::factory()->create();

        $this->actingAs($user)->get(route('products.index', ['classifications' => [$classification->id]]))->assertOk();
        $this->post(route('products.store'), productPayload(['classification_ids' => [$classification->id]]))
            ->assertSessionHasNoErrors();

        expect(recentIdsFor($user))->toBe([]);
    });

    it('conserva solo las 10 más recientes por usuario', function () {
        $user = User::factory()->create();
        $classifications = Classification::factory()->count(12)->create();
        $record = app(RecordClassificationSearches::class);

        $this->travelTo(now());
        foreach ($classifications as $classification) {
            $record->handle($user, [$classification->id]);
            $this->travel(1)->minutes();
        }

        expect(recentIdsFor($user))->toBe($classifications->skip(2)->reverse()->pluck('id')->values()->all());
    });

    it('actualiza la fecha de una clasificación repetida sin duplicarla, e ignora ids inexistentes', function () {
        $user = User::factory()->create();
        [$a, $b] = Classification::factory()->count(2)->create();
        $record = app(RecordClassificationSearches::class);

        $this->travelTo(now());
        $record->handle($user, [$a->id, 999999]);
        $this->travel(1)->minutes();
        $record->handle($user, [$b->id]);
        $this->travel(1)->minutes();
        $record->handle($user, [$a->id, $a->id]);

        expect(recentIdsFor($user))->toBe([$a->id, $b->id]);
    });

    it('quita una clasificación del historial propio sin tocar la de otros ni la clasificación', function () {
        $user = userWithRole(RoleName::Admin);
        $other = User::factory()->create();
        [$a, $b] = Classification::factory()->count(2)->create();
        $record = app(RecordClassificationSearches::class);
        $record->handle($user, [$a->id, $b->id]);
        $record->handle($other, [$a->id]);

        $this->actingAs($user)
            ->deleteJson(route('classifications.recent.destroy', $a))
            ->assertNoContent();

        expect(recentIdsFor($user))->toBe([$b->id])
            ->and(recentIdsFor($other))->toBe([$a->id]);
        $this->assertModelExists($a);

        expect(idsInGroup($this->getJson(route('classifications.lookup'))->json('data'), 'recent'))->toBe([$b->id]);
    });

    it('responde 204 aunque la clasificación no estuviera en el historial y 404 si no existe', function () {
        $classification = Classification::factory()->create();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->deleteJson(route('classifications.recent.destroy', $classification))->assertNoContent();
        $this->deleteJson(route('classifications.recent.destroy', 999999))->assertNotFound();
    });

    it('exige sesión y permiso para quitar del historial', function () {
        $classification = Classification::factory()->create();

        $this->deleteJson(route('classifications.recent.destroy', $classification))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->deleteJson(route('classifications.recent.destroy', $classification))
            ->assertForbidden();
    });

    it('borra el historial al eliminar la clasificación o el usuario', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$a, $b] = Classification::factory()->count(2)->create();
        $record = app(RecordClassificationSearches::class);
        $record->handle($user, [$a->id, $b->id]);
        $record->handle($other, [$a->id]);

        $a->delete();
        expect(DB::table('classification_users_searches')->count())->toBe(1);

        $user->delete();
        expect(DB::table('classification_users_searches')->count())->toBe(0);
    });
});

describe('selector en las pantallas', function () {
    it('no vuelca el catálogo completo: solo pinta las clasificaciones ya elegidas', function () {
        $this->actingAs(userWithRole(RoleName::Admin));
        $selected = Classification::factory()->create(['name' => 'Iluminación']);
        Classification::factory()->create(['name' => 'Zzz oculta']);
        $product = Product::factory()->create();
        $product->classifications()->attach($selected);

        $this->get(route('products.create'))
            ->assertOk()
            ->assertSee('data-classification-picker', false)
            ->assertDontSee('Zzz oculta');

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Iluminación')
            ->assertDontSee('Zzz oculta');

        $this->get(route('products.index', ['classifications' => [$selected->id]]))
            ->assertOk()
            ->assertSee('data-classification-picker', false)
            ->assertDontSee('Zzz oculta');
    });
});
