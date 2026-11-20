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
    /** ISO timestamp of the last sync attempt. */
    checked_at: string | null;
    /** ISO timestamp of the last time the library was read. */
    synced_at: string | null;
    sync_error: string | null;
    /** Games played whose achievements were not fetched yet. */
    pending_achievements: number;
};

/** A game of the library; playtime in minutes. */
export type SteamGame = {
    id: number;
    app_id: number;
    name: string;
    icon_url: string | null;
    /** 460×215 store header. */
    header_url: string;
    /** 600×900 library cover; not every game has one. */
    cover_url: string;
    playtime_minutes: number;
    playtime_2weeks_minutes: number;
    last_played_at: string | null;
    /** Null until the achievements are fetched. */
    achievements_total: number | null;
    achievements_unlocked: number | null;
};

export type SteamPlatformMinutes = {
    windows: number;
    mac: number;
    linux: number;
    deck: number;
};

export type SteamGameDetails = SteamGame & {
    store_url: string;
    platforms: SteamPlatformMinutes;
    added_at: string;
    /** When the game left the library (refunded or removed). */
    removed_at: string | null;
    achievements_synced_at: string | null;
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
    /** Only in lists that mix games. */
    game: { id: number; name: string } | null;
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
    /** Average completion of games with an achievement unlocked, in basis points. */
    average_completion_bp: number | null;
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
