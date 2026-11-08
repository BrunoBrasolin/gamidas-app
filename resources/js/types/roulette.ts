export type RouletteCategoryValue = 'lunch' | 'dessert' | 'dinner';

/** One option of the roulette. */
export type RoulettePlace = {
    id: number;
    name: string;
    /** How many times more likely than a place of weight 1 (1 to 10). */
    weight: number;
    /** First name of who added it. */
    created_by: string | null;
};

export type RouletteCategory = {
    value: RouletteCategoryValue;
    label: string;
    places: RoulettePlace[];
};
