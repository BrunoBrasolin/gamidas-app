<?php

namespace Tests\Feature\Watched;

use App\Models\JellyfinConnection;
use App\Models\WatchedItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

/**
 * Runs outside a transaction (DatabaseMigrations), since the migration drops
 * and renames tables.
 */
class WatchHistoryMigrationTest extends TestCase
{
    use CreatesHousehold, DatabaseMigrations;

    public function test_the_history_of_each_user_moves_to_their_household(): void
    {
        $this->createHousehold();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_28_000002_move_watch_history_to_household.php');
        /** @var Migration $later */
        $later = require database_path('migrations/2026_10_28_000003_create_watch_progress_table.php');
        $later->down();
        $migration->down();
        $this->assertTrue(Schema::hasColumn('watched_items', 'user_id'));

        $item = fn (int $userId, string $itemId, string $at, string $title) => [
            'user_id' => $userId, 'type' => 'movie', 'title' => $title, 'jellyfin_item_id' => $itemId,
            'watched_at' => $at, 'watched_on' => substr($at, 0, 10), 'created_at' => $at, 'updated_at' => $at,
        ];
        DB::table('watched_items')->insert([
            $item($this->bruno->id, 'm1', '2026-10-20 21:00:00', 'Interestelar'),
            // Letícia watched the same movie later that day: kept once, for Bruno.
            $item($this->leticia->id, 'm1', '2026-10-20 23:00:00', 'Interestelar'),
            $item($this->leticia->id, 'm2', '2026-10-21 20:00:00', 'Duna'),
        ]);
        DB::table('jellyfin_connections')->insert([
            ['user_id' => $this->bruno->id, 'token_hash' => str_repeat('a', 64), 'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00'],
            ['user_id' => $this->leticia->id, 'token_hash' => str_repeat('b', 64), 'created_at' => '2026-10-21 10:00:00', 'updated_at' => '2026-10-21 10:00:00'],
        ]);

        $migration->up();
        $later->up();

        $this->assertFalse(Schema::hasColumn('watched_items', 'user_id'));
        $items = WatchedItem::query()->orderBy('watched_at')->get();
        $this->assertSame(['Interestelar', 'Duna'], $items->pluck('title')->all());
        $this->assertSame(['Bruno', 'Letícia'], $items->pluck('viewer')->all());
        $this->assertSame([$this->household->id], $items->pluck('household_id')->unique()->values()->all());

        // The most recently updated token keeps working for the household.
        $connection = JellyfinConnection::query()->sole();
        $this->assertSame([$this->household->id, str_repeat('b', 64)], [$connection->household_id, $connection->token_hash]);
    }
}
