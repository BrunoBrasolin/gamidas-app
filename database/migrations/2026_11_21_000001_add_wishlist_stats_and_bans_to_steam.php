<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * More of each user's Steam account: the bans on the account, each
     * game's own stats (kills, distance, wins… as the game names them) and
     * the wishlist with the store's price, discount, release and reviews.
     * Prices are integer cents in the store's currency. Only additions, so
     * existing accounts keep their data; games get their stats on the next
     * fetch of their achievements.
     */
    public function up(): void
    {
        Schema::table('steam_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('vac_bans')->nullable()->after('badges_count');
            $table->unsignedSmallInteger('game_bans')->nullable()->after('vac_bans');
            $table->unsignedInteger('days_since_last_ban')->nullable()->after('game_bans');
            $table->boolean('community_banned')->nullable()->after('days_since_last_ban');
            $table->boolean('trade_banned')->nullable()->after('community_banned');
        });

        Schema::table('steam_games', function (Blueprint $table) {
            $table->json('stats')->nullable()->after('achievements_playtime_minutes');
        });

        Schema::create('steam_wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('steam_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('app_id');
            $table->string('name', 255);
            $table->unsignedInteger('priority')->default(0);
            $table->dateTime('added_at')->nullable();
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->unsignedBigInteger('original_price_cents')->nullable();
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->string('price_formatted', 32)->nullable();
            $table->string('original_price_formatted', 32)->nullable();
            $table->boolean('is_free')->default(false);
            $table->dateTime('release_at')->nullable();
            $table->boolean('coming_soon')->default(false);
            $table->string('review_label', 64)->nullable();
            $table->unsignedTinyInteger('review_percent')->nullable();
            $table->unsignedInteger('review_count')->nullable();
            $table->string('header_url', 255)->nullable();
            $table->timestamps();

            $table->unique(['steam_account_id', 'app_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('steam_wishlist_items');

        Schema::table('steam_games', function (Blueprint $table) {
            $table->dropColumn('stats');
        });

        Schema::table('steam_accounts', function (Blueprint $table) {
            $table->dropColumn(['vac_bans', 'game_bans', 'days_since_last_ban', 'community_banned', 'trade_banned']);
        });
    }
};
