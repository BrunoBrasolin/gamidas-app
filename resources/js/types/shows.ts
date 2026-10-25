/** A band that opened a show, with its own setlist. */
export type OpeningAct = {
    band: string;
    setlist: string[];
};

export type Show = {
    id: number;
    band: string;
    tour: string | null;
    venue: string;
    /** "YYYY-MM-DD" */
    date: string;
    setlist: string[];
    photos_url: string | null;
    playlist_url: string | null;
    /** First name of who registered it; null for imported shows. */
    created_by: string | null;
    opening_acts: OpeningAct[];
};

/** An item of the household's checklist, as packed for one show. */
export type ShowChecklistItem = {
    id: number;
    label: string;
    checked: boolean;
};

export type ShowStats = {
    shows: number;
    bands: number;
    venues: number;
    songs: number;
};
