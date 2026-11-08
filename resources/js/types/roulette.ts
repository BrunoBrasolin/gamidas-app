export type RouletteCategoryValue = 'lunch' | 'dessert' | 'dinner';

/** One option of the roulette. */
export type RoulettePlace = {
    id: number;
    name: string;
    /** First name of who added it. */
    created_by: string | null;
};

export type RouletteCategory = {
    value: RouletteCategoryValue;
    label: string;
    places: RoulettePlace[];
};
