import type { Tier } from './tiers';

/** A user's Steam account, as the last sync saw it. */
export type SteamAccount = {
    steam_id: string;
    persona_name: string | null;
    profile_url: string;
    avatar_url: string | null;
    country_code: string | null;
    /** The community profile is public. */
    public_profile: boolean;
    /** The profile shows its game details (library and playtime). */
    public_games: boolean;
    /** 0 offline, 1 online, 2 busy, 3 away, 4 snooze, 5 looking to trade, 6 looking to play. */
    persona_state: number;
    /** What the account was playing at the last sync. */
    playing: { app_id: number | null; name: string } | null;
    account_created_at: string | null;
    last_logoff_at: string | null;
    level: number | null;
    xp: number | null;
    /** XP at which the current level started. */
    xp_level_start: number | null;
    /** XP still needed for the next level. */
    xp_to_next_level: number | null;
    badges_count: number | null;
    /** Null until the bans are read. */
    bans: SteamBans | null;
    /** ISO timestamp of the last sync attempt. */
    checked_at: string | null;
    /** ISO timestamp of the last time the library was read. */
    synced_at: string | null;
    sync_error: string | null;
};

export type SteamBans = {
    vac: number;
    game: number;
    days_since_last: number | null;
    community: boolean;
    trade: boolean;
};

/** A game of the library; playtime in minutes. Images come from its app id. */
export type SteamGame = {
    id: number;
    app_id: number;
    name: string;
    playtime_minutes: number;
    playtime_2weeks_minutes: number;
    last_played_at: string | null;
};

export type SteamPlatformMinutes = {
    windows: number;
    mac: number;
    linux: number;
    deck: number;
};

/** What a game's page shows at once. */
export type SteamGameDetails = SteamGame & {
    store_url: string;
    platforms: SteamPlatformMinutes;
    /** When the game left the library (refunded or removed). */
    removed_at: string | null;
};

/**
 * A game's achievements and stats, loaded after the page (fetched from
 * Steam when they are out of date).
 */
export type SteamGameProgress = {
    achievements_total: number | null;
    achievements_unlocked: number | null;
    /** Whether they were ever fetched. */
    fetched: boolean;
    achievements: SteamAchievement[];
    stats: SteamGameStat[];
    /** Why Steam could not be asked now (what is shown was stored before). */
    error: string | null;
};

export type SteamGameStat = {
    /** Api name, e.g. "total_kills". */
    name: string;
    /** The game's display name, when it gives one. */
    label: string | null;
    value: number;
};

/** A game on the wishlist, with the store's data at the last sync. */
export type SteamWishlistItem = {
    id: number;
    app_id: number;
    name: string;
    /** The user's rank on Steam; 0 when unranked. */
    priority: number;
    added_at: string | null;
    /** Integer cents in the store's currency. */
    price_cents: number | null;
    original_price_cents: number | null;
    discount_percent: number;
    price_formatted: string | null;
    original_price_formatted: string | null;
    is_free: boolean;
    release_at: string | null;
    coming_soon: boolean;
    review_label: string | null;
    review_percent: number | null;
    review_count: number | null;
    header_url: string;
    store_url: string;
    /** The game's page when it is already in the library. */
    owned_game_id: number | null;
};

export type SteamWishlistSummary = {
    items: number;
    on_sale: number;
    coming_soon: number;
    free: number;
    /** Sum of current prices in cents (BRL); null outside Brazil's store. */
    total_cents: number | null;
    /** What the current discounts save, in cents (BRL). */
    savings_cents: number | null;
};

/** A friend, as Steam shows them now. */
export type SteamFriend = {
    steam_id: string;
    persona_name: string;
    avatar_url: string | null;
    profile_url: string;
    persona_state: number;
    playing: { app_id: number | null; name: string } | null;
    friend_since: string | null;
    last_logoff_at: string | null;
    country_code: string | null;
    /** The game's page when the friend plays a game of the user's library. */
    my_game_id: number | null;
};

export type SteamAchievement = {
    id: number;
    name: string;
    description: string | null;
    /** Colored when unlocked, gray otherwise. */
    icon_url: string | null;
    hidden: boolean;
    /** Share of all Steam players who unlocked it, in hundredths of a percent. */
    global_percent_bp: number | null;
    achieved: boolean;
    unlocked_at: string | null;
};

export type SteamSummary = {
    games: number;
    played: number;
    never_played: number;
    playtime_minutes: number;
    playtime_2weeks_minutes: number;
    recent_games: number;
    achievements_unlocked: number;
    achievements_total: number;
    perfect_games: number;
    platforms: SteamPlatformMinutes;
};

export type SteamMonth = { month: string; minutes: number };

export type SteamDay = { date: string; minutes: number };

export type SteamScreenshot = { thumb_url: string; full_url: string };

/** The game's store page. */
export type SteamStoreDetails = {
    name: string | null;
    type: string | null;
    description: string | null;
    header_url: string | null;
    background_url: string | null;
    website: string | null;
    developers: string[];
    publishers: string[];
    /** As the store writes it, e.g. "18/abr./2011". */
    release_date: string | null;
    coming_soon: boolean;
    genres: string[];
    categories: string[];
    metacritic: { score: number; url: string | null } | null;
    /** Formatted in the store's currency, e.g. "R$ 33,99". */
    price: string | null;
    discount_percent: number;
    is_free: boolean;
    platforms: ('windows' | 'mac' | 'linux')[];
    controller_support: 'full' | 'partial' | null;
    recommendations: number | null;
    screenshots: SteamScreenshot[];
    store_url: string;
};

/** The friends list as Steam gave it. */
export type SteamFriendList = {
    friends: SteamFriend[];
    /** The friends list is private on Steam. */
    private: boolean;
    error: string | null;
};

/** A game on one of the user's lists; library data is null when it left the library. */
export type ListGame = {
    app_id: number;
    name: string;
    /** The game's page, while it is in the library. */
    game_id: number | null;
    playtime_minutes: number | null;
    last_played_at: string | null;
};

export type BacklogStatus = 'queued' | 'playing' | 'completed';

/** A game on the "para zerar" list. */
export type BacklogEntry = ListGame & {
    id: number;
    status: BacklogStatus;
    position: number;
    /** Steam playtime (minutes) when it entered the list. */
    playtime_at_add: number;
    /** Steam playtime (minutes) when it was finished. */
    playtime_at_completion: number | null;
    added_at: string;
    started_at: string | null;
    completed_at: string | null;
    tier: Tier | null;
};

/** Where a game is on the user's lists. */
export type GameLists = {
    tier: Tier | null;
    backlog: {
        id: number;
        status: BacklogStatus;
        added_at: string;
        completed_at: string | null;
    } | null;
};
