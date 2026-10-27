export type WatchType = 'movie' | 'episode' | 'video';

/** What a movie, episode or video is, as Jellyfin sent it. */
export type WatchMedia = {
    type: WatchType;
    /** The movie or video title; for episodes, the episode title. */
    title: string;
    series_name: string | null;
    season_number: number | null;
    episode_number: number | null;
    /** Release year; for episodes, the series' year. */
    year: number | null;
    runtime_seconds: number | null;
};

/** Something the household watched to the end on Jellyfin. */
export type WatchedItem = WatchMedia & {
    id: number;
    /** Jellyfin user who watched it. */
    viewer: string | null;
    /** ISO timestamp of when playback stopped. */
    watched_at: string;
    /** "YYYY-MM-DD", local day. */
    watched_on: string;
};

/** Something someone started and has not finished. */
export type WatchProgress = WatchMedia & {
    id: number;
    viewer: string | null;
    /** Where they stopped, in seconds. */
    position_seconds: number;
    /** Jellyfin reports it playing right now. */
    playing: boolean;
    /** ISO timestamp. */
    last_played_at: string;
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

export type TmdbPerson = {
    name: string;
    character: string | null;
    photo_url: string | null;
};

export type TmdbEpisode = {
    name: string | null;
    overview: string | null;
    /** "YYYY-MM-DD" */
    date: string | null;
    runtime_minutes: number | null;
    still_url: string | null;
    season_number: number | null;
    episode_number: number | null;
    rating: number | null;
};

/** Poster, synopsis and cast from TMDB. */
export type TmdbDetails = {
    kind: 'movie' | 'tv';
    /** The item's page on themoviedb.org. */
    url: string;
    title: string | null;
    original_title: string | null;
    tagline: string | null;
    overview: string | null;
    poster_url: string | null;
    backdrop_url: string | null;
    /** Release or first air date, "YYYY-MM-DD". */
    date: string | null;
    runtime_minutes: number | null;
    genres: string[];
    /** Average vote out of 10. */
    rating: number | null;
    /** Directors of a movie, creators of a series. */
    creators: string[];
    seasons: number | null;
    cast: TmdbPerson[];
    episode: TmdbEpisode | null;
};
