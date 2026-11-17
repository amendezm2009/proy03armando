<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * invited_at: cuándo se envió la última invitación (cuentas creadas por un administrador).
     * invitation_accepted_at: cuándo la persona definió su contraseña con el enlace. Invitación
     * pendiente = invited_at con valor y invitation_accepted_at vacío.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('is_active');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invited_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'invitation_accepted_at']);
        });
    }
};
