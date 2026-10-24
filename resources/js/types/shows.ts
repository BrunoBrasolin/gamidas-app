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
    /** First name of who registered it; null for imported shows. */
    created_by: string | null;
    opening_acts: OpeningAct[];
};

export type ShowStats = {
    shows: number;
    bands: number;
    venues: number;
    songs: number;
};
