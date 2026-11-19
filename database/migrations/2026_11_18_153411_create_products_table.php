<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Los precios son decimal (nunca float). sku, slug y barcode son únicos y NO se reutilizan al borrar
     * un producto (la baja es lógica: deleted_at). stock y sale_price llevan índice porque el listado
     * filtra por rangos. created_by / updated_by guardan quién hizo el alta o el último cambio.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 40)->unique();
            $table->string('name', 150)->index();
            $table->string('slug', 170)->unique();
            $table->text('description')->nullable();
            $table->string('barcode', 13)->nullable()->unique();
            $table->unsignedInteger('stock')->default(0)->index();
            $table->unsignedInteger('min_stock')->default(0);
            $table->decimal('purchase_price', 10, 2)->default(0);
            $table->decimal('sale_price', 10, 2)->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
