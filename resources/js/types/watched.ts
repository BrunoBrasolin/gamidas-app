export type WatchType = 'movie' | 'episode' | 'video';

/** Something the user watched to the end on Jellyfin. */
export type WatchedItem = {
    id: number;
    type: WatchType;
    /** The movie or video title; for episodes, the episode title. */
    title: string;
    series_name: string | null;
    season_number: number | null;
    episode_number: number | null;
    /** Release year. */
    year: number | null;
    runtime_seconds: number | null;
    /** ISO timestamp of when playback stopped. */
    watched_at: string;
    /** "YYYY-MM-DD", local day. */
    watched_on: string;
};

export type WatchedStats = {
    items: number;
    movies: number;
    episodes: number;
    series: number;
    seconds: number;
};

export type JellyfinConnection = {
    connected: boolean;
    last_received_at: string | null;
    webhook_url: string;
    /** Only right after issuing a token; it is never shown again. */
    new_token: string | null;
};
