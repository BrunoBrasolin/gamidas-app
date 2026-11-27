<?php

namespace Tests\Feature\TierLists;

use App\Enums\Tier;
use App\Enums\TierListKind;
use App\Models\Show;
use App\Models\ShowTierEntry;
use App\Models\SteamTierEntry;
use App\Models\TierListShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class PublicTierListTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    private function share(TierListKind $kind): TierListShare
    {
        $this->actingAs($this->bruno)
            ->post(route('tier-lists.share.store', $kind))
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $share = TierListShare::query()->where('user_id', $this->bruno->id)->where('kind', $kind->value)->sole();
        auth()->logout();

        return $share;
    }

    public function test_anyone_with_the_link_sees_the_games_tier_list_without_playtime(): void
    {
        SteamTierEntry::query()->create(['user_id' => $this->bruno->id, 'app_id' => 620, 'name' => 'Portal 2', 'tier' => Tier::S, 'position' => 0]);
        SteamTierEntry::query()->create(['user_id' => $this->leticia->id, 'app_id' => 504230, 'name' => 'Celeste', 'tier' => Tier::S, 'position' => 0]);
        $share = $this->share(TierListKind::Games);

        $this->get(route('tier-lists.public', $share->token))
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Tier list de jogos de Bruno">', false)
            ->assertSee('<meta property="og:image" content="https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/620/header.jpg">', false)
            ->assertSee('noindex', false)
            ->assertDontSee($this->household->name)
            ->assertDontSee($this->bruno->email)
            ->assertInertia(fn (Assert $page) => $page
                ->component('tier-list/public', false)
                ->where('title', 'Tier list de jogos de Bruno')
                ->where('kind', 'games')
                ->where('tiers.S', [['app_id' => 620, 'name' => 'Portal 2']])
                ->where('auth.user', null)
                ->where('household', null));
    }

    public function test_anyone_with_the_link_sees_the_shows_tier_list_without_private_links(): void
    {
        $show = Show::factory()->create([
            'household_id' => $this->household->id,
            'band' => 'Iron Maiden',
            'tour' => 'The Future Past',
            'venue' => 'Allianz Parque',
            'show_date' => '2024-12-06',
            'photos_url' => 'https://photos.example.test/album',
            'setlist' => ['Música secreta'],
        ]);
        ShowTierEntry::query()->create(['user_id' => $this->bruno->id, 'show_id' => $show->id, 'tier' => Tier::A, 'position' => 0]);
        $share = $this->share(TierListKind::Shows);

        $this->get(route('tier-lists.public', $share->token))
            ->assertOk()
            ->assertDontSee('photos.example.test', false)
            ->assertDontSee('secreta', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('title', 'Tier list de shows de Bruno')
                ->where('tiers.A', [['band' => 'Iron Maiden', 'tour' => 'The Future Past', 'venue' => 'Allianz Parque', 'date' => '2024-12-06']]));
    }

    public function test_each_list_is_shared_on_its_own_and_the_link_stays_until_removed(): void
    {
        $share = $this->share(TierListKind::Shows);

        $this->assertSame($share->token, $this->share(TierListKind::Shows)->token);
        $this->assertDatabaseCount('tier_list_shares', 1);
        $this->actingAs($this->bruno)->get(route('shows.tiers'))
            ->assertInertia(fn (Assert $page) => $page->where('shareUrl', $share->url()));

        // Another member can't remove it: the routes only act on one's own.
        $this->actingAs($this->leticia)->delete(route('tier-lists.share.destroy', TierListKind::Shows));
        $this->assertDatabaseCount('tier_list_shares', 1);

        $this->actingAs($this->bruno)->delete(route('tier-lists.share.destroy', TierListKind::Shows))->assertRedirect();
        auth()->logout();

        $this->get(route('tier-lists.public', $share->token))->assertNotFound();
        $this->assertNotSame($share->token, $this->share(TierListKind::Shows)->token);
    }

    public function test_unknown_links_and_kinds_are_not_found_and_sharing_needs_an_account(): void
    {
        $this->get(route('tier-lists.public', 'nao-existe'))->assertNotFound();
        $this->post('/tier-lists/shows/share')->assertRedirect(route('login'));
        $this->actingAs($this->bruno)->post('/tier-lists/movies/share')->assertNotFound();
        $this->assertDatabaseCount('tier_list_shares', 0);
    }
}
