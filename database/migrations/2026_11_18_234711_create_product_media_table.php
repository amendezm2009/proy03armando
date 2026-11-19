<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fotos y videos de un producto (hasta config('catalog.media.max_files')). Los archivos están en el disco
     * privado, carpeta "products"; aquí solo se guarda su nombre aleatorio. La portada del producto se guarda
     * en products.cover_media_id (una sola por producto y siempre una foto, lo exige App\Actions\SetProductCover).
     */
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('path', 60)->unique();
            $table->string('thumbnail_path', 60)->nullable();
            $table->string('original_name', 150);
            $table->string('mime_type', 50);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('cover_media_id')->nullable()->constrained('product_media')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_media_id');
        });

        Schema::dropIfExists('product_media');
    }
};
