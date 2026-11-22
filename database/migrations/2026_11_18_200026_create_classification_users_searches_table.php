<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Historial de las clasificaciones que cada usuario ha usado al filtrar o al editar productos
     * (alimenta la lista de "recientes" del selector). Una fila por usuario y clasificación;
     * App\Actions\RecordClassificationSearches conserva solo las últimas de cada usuario.
     */
    public function up(): void
    {
        Schema::create('classification_users_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classification_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_used_at');

            $table->unique(['user_id', 'classification_id']);
            $table->index(['user_id', 'last_used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classification_users_searches');
    }
};
