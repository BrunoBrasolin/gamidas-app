<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The watch history belongs to the household instead of each user: one
     * Jellyfin connection per household (one server serves the whole house)
     * and one history shared by every member, with the Jellyfin user who
     * watched each item. The tables are rebuilt and swapped, which works the
     * same on MySQL and SQLite. Existing entries move to their user's
     * household (an item watched by both on the same day is kept once, with
     * the earliest viewer), and the most recently updated token of each
     * household keeps working.
     */
    public function up(): void
    {
        Schema::create('jellyfin_connections_next', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique('jellyfin_connections_next_token_unique');
            $table->dateTime('last_received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('watched_items_next', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('viewer', 100)->nullable();
            $this->itemColumns($table);

            $table->unique(['household_id', 'jellyfin_item_id', 'watched_on'], 'watched_items_next_item_day_unique');
            $table->index(['household_id', 'watched_at'], 'watched_items_next_watched_at_index');
        });

        $connections = DB::table('jellyfin_connections')
            ->join('household_members', 'household_members.user_id', '=', 'jellyfin_connections.user_id')
            ->orderByDesc('jellyfin_connections.updated_at')
            ->orderByDesc('jellyfin_connections.id')
            ->get(['household_members.household_id', 'jellyfin_connections.token_hash', 'jellyfin_connections.last_received_at', 'jellyfin_connections.created_at', 'jellyfin_connections.updated_at'])
            ->unique('household_id');

        $this->insert('jellyfin_connections_next', $connections);

        $items = DB::table('watched_items')
            ->join('household_members', 'household_members.user_id', '=', 'watched_items.user_id')
            ->join('users', 'users.id', '=', 'watched_items.user_id')
            ->orderBy('watched_items.watched_at')
            ->orderBy('watched_items.id')
            ->get(['household_members.household_id', 'users.name as viewer', ...$this->itemFields('watched_items')])
            ->map(function (object $item) {
                $item->viewer = explode(' ', trim((string) $item->viewer))[0];

                return $item;
            });

        $this->insert('watched_items_next', $items);

        Schema::drop('watched_items');
        Schema::drop('jellyfin_connections');
        Schema::rename('watched_items_next', 'watched_items');
        Schema::rename('jellyfin_connections_next', 'jellyfin_connections');
    }

    /**
     * Reverse the migrations.
     *
     * Entries and the token go back to the first member of each household.
     */
    public function down(): void
    {
        Schema::create('jellyfin_connections_prev', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique('jellyfin_connections_prev_token_unique');
            $table->dateTime('last_received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('watched_items_prev', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $this->itemColumns($table);

            $table->unique(['user_id', 'jellyfin_item_id', 'watched_on'], 'watched_items_prev_item_day_unique');
            $table->index(['user_id', 'watched_at'], 'watched_items_prev_watched_at_index');
        });

        $owners = DB::table('household_members')->orderBy('id')->get(['household_id', 'user_id'])
            ->unique('household_id')->pluck('user_id', 'household_id');

        $connections = DB::table('jellyfin_connections')->get()
            ->filter(fn (object $row) => $owners->has($row->household_id))
            ->map(fn (object $row) => (object) [
                'user_id' => $owners[$row->household_id],
                'token_hash' => $row->token_hash,
                'last_received_at' => $row->last_received_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

        $this->insert('jellyfin_connections_prev', $connections);

        $items = DB::table('watched_items')->orderBy('id')->get(['household_id', ...$this->itemFields('watched_items')])
            ->filter(fn (object $row) => $owners->has($row->household_id))
            ->map(function (object $row) use ($owners) {
                $row->user_id = $owners[$row->household_id];
                unset($row->household_id);

                return $row;
            });

        $this->insert('watched_items_prev', $items);

        Schema::drop('watched_items');
        Schema::drop('jellyfin_connections');
        Schema::rename('watched_items_prev', 'watched_items');
        Schema::rename('jellyfin_connections_prev', 'jellyfin_connections');
    }

    /**
     * The columns that describe the item, the same before and after.
     */
    private function itemColumns(Blueprint $table): void
    {
        $table->string('type', 16);
        $table->string('title', 255);
        $table->string('series_name', 255)->nullable();
        $table->unsignedSmallInteger('season_number')->nullable();
        $table->unsignedSmallInteger('episode_number')->nullable();
        $table->unsignedSmallInteger('year')->nullable();
        $table->unsignedInteger('runtime_seconds')->nullable();
        $table->string('jellyfin_item_id', 64)->nullable();
        $table->dateTime('watched_at');
        $table->date('watched_on');
        $table->timestamps();
    }

    /**
     * @return list<string>
     */
    private function itemFields(string $table): array
    {
        return array_map(fn (string $column) => "{$table}.{$column}", [
            'type', 'title', 'series_name', 'season_number', 'episode_number', 'year',
            'runtime_seconds', 'jellyfin_item_id', 'watched_at', 'watched_on', 'created_at', 'updated_at',
        ]);
    }

    /**
     * Copy rows, skipping any that would repeat a unique key.
     *
     * @template TRow of object
     *
     * @param  Collection<int, TRow>  $rows
     */
    private function insert(string $table, Collection $rows): void
    {
        foreach ($rows->map(fn (object $row) => (array) $row)->chunk(200) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk->values()->all());
        }
    }
};
