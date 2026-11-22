<?php

namespace App\Actions;

use App\Models\Classification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordClassificationSearches
{
    /** Cuántas clasificaciones recientes se conservan por usuario. */
    public const int KEEP = 10;

    private const string TABLE = 'classification_users_searches';

    /**
     * Anota las clasificaciones usadas por el usuario como las más recientes. Ignora los ids que no
     * existen y borra las más antiguas hasta dejar solo KEEP.
     *
     * @param  array<int, mixed>  $classificationIds
     */
    public function handle(User $user, array $classificationIds): void
    {
        $ids = array_slice(array_unique(array_filter(array_map('intval', $classificationIds))), 0, self::KEEP);

        $existing = Classification::whereIn('id', $ids)->pluck('id');

        if ($existing->isEmpty()) {
            return;
        }

        $now = now();

        DB::transaction(function () use ($user, $existing, $now): void {
            DB::table(self::TABLE)->upsert(
                $existing->map(fn (int $id): array => [
                    'user_id' => $user->id,
                    'classification_id' => $id,
                    'last_used_at' => $now,
                ])->all(),
                ['user_id', 'classification_id'],
                ['last_used_at'],
            );

            $keep = DB::table(self::TABLE)->where('user_id', $user->id)
                ->orderByDesc('last_used_at')->orderByDesc('id')
                ->limit(self::KEEP)->pluck('id');

            DB::table(self::TABLE)->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
        });
    }
}
