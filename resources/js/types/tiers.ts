export type Tier = 'S' | 'A' | 'B' | 'C' | 'D' | 'F';

/** What identifies a show on a tier list. */
export type TierShow = {
    band: string;
    tour: string | null;
    venue: string;
    /** "YYYY-MM-DD" */
    date: string;
};

/** A show the user can put on their tier list. */
export type RankableShow = TierShow & { id: number };
