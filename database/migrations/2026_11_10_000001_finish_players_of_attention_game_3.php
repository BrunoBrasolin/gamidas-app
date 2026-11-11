<?php

use App\Models\AttentionGame;
use App\Services\Attention\AttentionReport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The room whose players lost their last answer to a database outage. */
    private const GAME_ID = 3;

    /**
     * Run the migrations.
     *
     * A database connection problem kept many players of room 3 from sending
     * their last answers, so they were left "playing" for good. Each of them
     * is marked as finished at the moment of their last recorded answer (their
     * play time then reads from joining to that answer). Scores are never
     * touched, and players who answered nothing are left alone: nobody has
     * played for them to finish.
     *
     * Ranking is by score first, so finishing players can only change the
     * order between players tied on score. If that ever moved the current
     * leader, the players who overtake them are put back to "playing", which
     * keeps the leader first.
     */
    public function up(): void
    {
        $game = AttentionGame::query()->find(self::GAME_ID);

        if ($game === null) {
            return;
        }

        $stranded = DB::table('attention_players')
            ->where('attention_game_id', self::GAME_ID)
            ->whereNull('finished_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('attention_rounds')
                ->whereColumn('attention_rounds.attention_player_id', 'attention_players.id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($stranded === []) {
            return;
        }

        $leaderBefore = $this->leaderId($game);

        DB::transaction(function () use ($game, $stranded, $leaderBefore) {
            DB::table('attention_players')->whereIn('id', $stranded)->update(['finished_at' => DB::raw('updated_at')]);

            if ($this->leaderId($game) === $leaderBefore) {
                return;
            }

            $overtaking = [];

            foreach ((new AttentionReport($game))->ranking() as $row) {
                if ($row['id'] === $leaderBefore) {
                    break;
                }

                if (in_array($row['id'], $stranded, true)) {
                    $overtaking[] = $row['id'];
                }
            }

            DB::table('attention_players')->whereIn('id', $overtaking)->update(['finished_at' => null]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * There is no telling which players were finished by this migration and
     * which finished on their own, so nothing is undone.
     */
    public function down(): void
    {
        //
    }

    private function leaderId(AttentionGame $game): ?int
    {
        return (new AttentionReport($game))->ranking(1)[0]['id'] ?? null;
    }
};
