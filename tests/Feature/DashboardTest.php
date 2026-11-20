<?php

use App\Actions\BuildAdminDashboard;
use App\Actions\BuildClientDashboard;
use App\Models\Classification;
use App\Models\Product;
use App\Models\User;
use App\RoleName;
use Illuminate\Support\Facades\Cache;

describe('redirección al iniciar sesión', function () {
    it('lleva a cada persona al panel de su rol', function (?RoleName $role, string $route) {
        $user = $role ? userWithRole($role) : User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route($route));

        expect($user->homeRoute())->toBe($route);
    })->with([
        'administrador' => [RoleName::Admin, 'admin.dashboard'],
        'cliente' => [RoleName::Cliente, 'client.dashboard'],
        'sin rol' => [null, 'home'],
    ]);

    it('vuelve a la página protegida que se intentó abrir antes de iniciar sesión', function () {
        $user = userWithRole(RoleName::Cliente);

        $this->get(route('products.index'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('products.index'));
    });
});

describe('acceso a los paneles', function () {
    it('manda a iniciar sesión a quien no la tiene', function (string $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    })->with(['administrador' => ['admin.dashboard'], 'cliente' => ['client.dashboard']]);

    it('rechaza al cliente en el panel del administrador y a quien no tiene rol en ambos', function () {
        $this->actingAs(userWithRole(RoleName::Cliente))->get(route('admin.dashboard'))->assertForbidden();

        $this->actingAs(User::factory()->create());
        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('client.dashboard'))->assertForbidden();
    });

    it('deja entrar a cada rol a su panel', function () {
        $this->actingAs(userWithRole(RoleName::Admin))->get(route('admin.dashboard'))->assertOk()->assertSee('Panel de administración');
        $this->actingAs(userWithRole(RoleName::Cliente))->get(route('client.dashboard'))->assertOk()->assertSee('Novedades');
    });

    it('muestra "Mi panel" solo a quien tiene uno, y el catálogo a todos', function () {
        $this->get(route('home'))->assertOk()->assertSee(route('catalog.index'))->assertDontSee('Mi panel');

        $this->actingAs(User::factory()->create())->get(route('home'))->assertDontSee('Mi panel');

        $this->actingAs(userWithRole(RoleName::Admin))->get(route('home'))
            ->assertSee('Mi panel')->assertSee(route('admin.dashboard'));

        $this->actingAs(userWithRole(RoleName::Cliente))->get(route('home'))
            ->assertSee('Mi panel')->assertSee(route('client.dashboard'));
    });
});

describe('panel del administrador', function () {
    it('calcula los totales, el valor del inventario y las listas', function () {
        $admin = userWithRole(RoleName::Admin);
        Product::factory()->create(['name' => 'Agotado uno', 'stock' => 0, 'min_stock' => 5, 'purchase_price' => 10, 'sale_price' => 20]);
        Product::factory()->create(['name' => 'Bajo uno', 'stock' => 3, 'min_stock' => 5, 'purchase_price' => 100, 'sale_price' => 150]);
        Product::factory()->inactive()->create(['name' => 'Normal uno', 'stock' => 50, 'min_stock' => 5, 'purchase_price' => 2, 'sale_price' => 3]);
        Product::factory()->create(['name' => 'Eliminado', 'stock' => 0, 'purchase_price' => 999, 'sale_price' => 1000])->delete();
        Classification::factory()->create(['is_active' => true]);
        Classification::factory()->create(['is_active' => false]);
        User::factory()->create()->forceFill(['is_active' => false])->save();
        User::factory()->create()->forceFill(['invited_at' => now()])->save();

        $stats = app(BuildAdminDashboard::class)->handle();

        expect($stats['products'])->toMatchArray([
            'total' => 3, 'active' => 2, 'inactive' => 1, 'out_of_stock' => 1, 'low_stock' => 1,
            'without_cover' => 3, 'without_barcode' => 3,
        ])
            ->and($stats['inventory_value'])->toBe(400.0)
            ->and($stats['classifications'])->toBe(['total' => 2, 'active' => 1])
            ->and($stats['users'])->toBe(['active' => 2, 'inactive' => 1, 'pending_invitations' => 1])
            ->and($stats['attention']->pluck('name')->all())->toBe(['Agotado uno', 'Bajo uno'])
            ->and($stats['latest_products'])->toHaveCount(3);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('$400.00')
            ->assertSee('Agotado uno')
            ->assertSee('Stock bajo')
            ->assertDontSee('Eliminado')
            ->assertSee(route('products.index', ['stock_status' => 'out']), false);
    });

    it('funciona sin datos', function () {
        $this->actingAs(userWithRole(RoleName::Admin))->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Todos los productos tienen existencias suficientes.')
            ->assertSee('Aún no hay productos.');
    });

    it('ofrece los accesos rápidos de creación', function () {
        $this->actingAs(userWithRole(RoleName::Admin))->get(route('admin.dashboard'))
            ->assertSee(route('products.create'))
            ->assertSee(route('classifications.create'))
            ->assertSee(route('users.create'));
    });
});

describe('panel del cliente', function () {
    it('muestra solo productos activos, sin datos internos', function () {
        Product::factory()->create(['name' => 'Producto visible', 'purchase_price' => 77.77, 'sale_price' => 99, 'stock' => 5]);
        Product::factory()->inactive()->create(['name' => 'Producto inactivo']);
        Product::factory()->outOfStock()->create(['name' => 'Producto agotado']);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Producto visible')
            ->assertSee('Producto agotado')
            ->assertSee('Agotado')
            ->assertDontSee('Producto inactivo')
            ->assertDontSee('77.77')
            ->assertDontSee('Valor del inventario')
            ->assertSee(route('profile.edit'));
    });

    it('lista las clasificaciones con más productos activos y las guarda en la caché', function () {
        [$muchos, $pocos, $inactiva, $sinActivos] = Classification::factory()->count(4)->sequence(
            ['name' => 'Muchos', 'is_active' => true],
            ['name' => 'Pocos', 'is_active' => true],
            ['name' => 'Inactiva', 'is_active' => false],
            ['name' => 'Sin activos', 'is_active' => true],
        )->create();
        Product::factory()->count(3)->create()->each(fn (Product $product) => $product->classifications()->attach([$muchos->id, $inactiva->id]));
        Product::factory()->create()->classifications()->attach($pocos);
        Product::factory()->inactive()->create()->classifications()->attach($sinActivos);
        Cache::forget(BuildClientDashboard::CACHE_KEY);

        $first = app(BuildClientDashboard::class)->handle()['classifications'];

        expect(array_column($first, 'name'))->toBe(['Muchos', 'Pocos'])
            ->and(array_column($first, 'total'))->toBe([3, 1]);

        Product::factory()->count(5)->create()->each(fn (Product $product) => $product->classifications()->attach($pocos));

        expect(app(BuildClientDashboard::class)->handle()['classifications'])->toBe($first);

        Cache::forget(BuildClientDashboard::CACHE_KEY);
        expect(array_column(app(BuildClientDashboard::class)->handle()['classifications'], 'name'))->toBe(['Pocos', 'Muchos']);
    });

    it('lleva las clasificaciones al listado ya filtrado y el buscador al listado de productos', function () {
        $classification = Classification::factory()->create(['is_active' => true]);
        Product::factory()->create()->classifications()->attach($classification);
        Cache::forget(BuildClientDashboard::CACHE_KEY);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('client.dashboard'))
            ->assertSee(route('products.index', ['classifications' => [$classification->id]]), false)
            ->assertSee('action="'.route('products.index').'"', false);
    });

    it('funciona sin productos', function () {
        $this->actingAs(userWithRole(RoleName::Cliente))->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Todavía no hay productos disponibles.');
    });
});

describe('paginación de las listas de los paneles', function () {
    it('pagina "Novedades" del cliente de 5 en 5 por defecto y respeta el tamaño elegido', function () {
        Product::factory()->count(12)->create();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $first = $this->get(route('client.dashboard'))->assertOk()->assertSee('page=2', false)->assertSee('#novedades', false);
        $third = $this->get(route('client.dashboard', ['page' => 3]))->assertOk();
        $ten = $this->get(route('client.dashboard', ['per_page' => 10]))->assertOk();

        expect($first->viewData('newest')->count())->toBe(5)
            ->and($first->viewData('newest')->perPage())->toBe(5)
            ->and($first->viewData('newest')->total())->toBe(12)
            ->and($third->viewData('newest')->count())->toBe(2)
            ->and($third->viewData('newest')->currentPage())->toBe(3)
            ->and($ten->viewData('newest')->count())->toBe(10);
    });

    it('ofrece el selector de tamaño con 5, 10, 20 y 50, el actual marcado y sin arrastrar la página', function () {
        Product::factory()->count(12)->create();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $html = $this->get(route('client.dashboard', ['page' => 3]))->assertOk()->assertSee('Mostrar')->getContent();

        foreach ([5, 10, 20, 50] as $size) {
            expect($html)->toContain("per_page={$size}#novedades");
        }

        expect($html)->toMatch('~per_page=5#novedades"\s+selected~')
            ->and($html)->toContain('/cliente/dashboard?per_page=10#novedades');
    });

    it('rechaza en "Novedades" un tamaño que no sea 5, 10, 20 o 50', function () {
        $this->actingAs(userWithRole(RoleName::Cliente))
            ->get(route('client.dashboard', ['per_page' => 7]))
            ->assertSessionHasErrors('per_page');
    });

    it('no pagina "Novedades" cuando caben en una página', function () {
        Product::factory()->count(3)->create();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('client.dashboard'))->assertOk()->assertDontSee('page=2', false);
    });

    it('pagina de 5 en 5 por defecto cada lista del administrador con su propio parámetro', function () {
        Product::factory()->count(12)->outOfStock()->create();
        User::factory()->count(11)->create();
        $this->actingAs(userWithRole(RoleName::Admin));

        $page = fn (array $query = []) => $this->get(route('admin.dashboard', $query))->assertOk()->viewData('stats');

        $first = $page();
        expect($first['attention']->count())->toBe(5)->and($first['attention']->total())->toBe(12)
            ->and($first['latest_products']->count())->toBe(5)
            ->and($first['latest_users']->count())->toBe(5)->and($first['latest_users']->total())->toBe(12);

        $second = $page(['attention_page' => 3]);
        expect($second['attention']->count())->toBe(2)->and($second['attention']->currentPage())->toBe(3)
            ->and($second['latest_products']->currentPage())->toBe(1)
            ->and($second['latest_users']->currentPage())->toBe(1);

        $both = $page(['attention_page' => 2, 'users_page' => 3]);
        expect($both['attention']->currentPage())->toBe(2)
            ->and($both['latest_users']->currentPage())->toBe(3)->and($both['latest_users']->count())->toBe(2)
            ->and($both['latest_products']->currentPage())->toBe(1);
    });

    it('cada lista del administrador tiene su propio tamaño', function () {
        Product::factory()->count(12)->outOfStock()->create();
        User::factory()->count(11)->create();
        $this->actingAs(userWithRole(RoleName::Admin));

        $stats = $this->get(route('admin.dashboard', ['attention_per_page' => 10, 'users_per_page' => 20]))
            ->assertOk()->viewData('stats');

        expect($stats['attention']->count())->toBe(10)->and($stats['attention']->perPage())->toBe(10)
            ->and($stats['latest_users']->count())->toBe(12)->and($stats['latest_users']->hasPages())->toBeFalse()
            ->and($stats['latest_products']->count())->toBe(5)->and($stats['latest_products']->perPage())->toBe(5);
    });

    it('ofrece un selector por lista, con el tamaño actual marcado y la lista de vuelta en su primera página', function () {
        Product::factory()->count(12)->outOfStock()->create();
        User::factory()->count(11)->create();
        $this->actingAs(userWithRole(RoleName::Admin));

        $html = $this->get(route('admin.dashboard', ['attention_page' => 3, 'attention_per_page' => 10]))
            ->assertOk()->assertSee('Mostrar')->getContent();

        foreach (['attention' => 'atencion', 'products' => 'ultimos-productos', 'users' => 'usuarios-recientes'] as $key => $anchor) {
            foreach ([5, 10, 20, 50] as $size) {
                expect($html)->toContain("{$key}_per_page={$size}#{$anchor}");
            }
        }

        expect($html)->toMatch('~attention_per_page=10#atencion"\s+selected~')
            ->and($html)->toMatch('~products_per_page=5#ultimos-productos"\s+selected~')
            ->and($html)->toContain('/admin/dashboard?attention_per_page=20#atencion');
    });

    it('rechaza en las listas del administrador un tamaño que no sea 5, 10, 20 o 50', function (string $key) {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->get(route('admin.dashboard', [$key => 7]))
            ->assertSessionHasErrors($key);
    })->with(['attention_per_page', 'products_per_page', 'users_per_page']);

    it('los enlaces de una lista conservan la página de las otras y saltan a su sección', function () {
        Product::factory()->count(12)->outOfStock()->create();
        User::factory()->count(11)->create();
        $this->actingAs(userWithRole(RoleName::Admin));

        $html = $this->get(route('admin.dashboard', ['attention_page' => 2]))->assertOk()->getContent();

        expect($html)->toContain('attention_page=2&amp;users_page=2#usuarios-recientes')
            ->toContain('attention_page=1#atencion')
            ->toContain('attention_page=2&amp;products_page=2#ultimos-productos');
    });

    it('no muestra paginación en el panel del administrador cuando todo cabe', function () {
        Product::factory()->count(2)->create();
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('attention_page', false)
            ->assertDontSee('products_page', false)
            ->assertDontSee('users_page', false)
            ->assertDontSee('Mostrar');
    });
});
