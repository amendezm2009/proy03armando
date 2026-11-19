<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Relación N:M entre productos y clasificaciones. Al eliminar definitivamente un producto o una
     * clasificación se borran sus vínculos (cascada); la baja lógica de un producto los conserva.
     */
    public function up(): void
    {
        Schema::create('classification_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classification_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'classification_id']);
            $table->index('classification_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classification_product');
    }
};
