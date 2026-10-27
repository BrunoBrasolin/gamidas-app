/** A kind of attention; each one has its own challenge in the game. */
export type AttentionType =
    | 'selective'
    | 'sustained'
    | 'divided'
    | 'alternating'
    | 'voluntary';

export type AttentionTypeOption = { value: AttentionType; label: string };

/** A room of the attention game, as seen by the household. */
export type AttentionGame = {
    id: number;
    title: string;
    code: string;
    /** Public link players open on their phones. */
    url: string;
    open: boolean;
    created_at: string | null;
    closed_at: string | null;
    created_by: string | null;
    types: AttentionTypeOption[];
};

export type AttentionGameListItem = AttentionGame & {
    players: number;
    finished: number;
};

export type AttentionSummary = {
    players: number;
    finished: number;
    playing: number;
    average_score: number | null;
    best_score: number | null;
    max_score: number;
    /** Percent of rounds done right. */
    success_rate: number | null;
    /** Percent of rounds whose kind of attention was recognized. */
    concept_rate: number | null;
    average_accuracy: number | null;
    average_elapsed_seconds: number | null;
    average_reaction_ms: number | null;
};

export type AttentionTypeStats = {
    type: AttentionType;
    label: string;
    challenge: string;
    rounds: number;
    success_rate: number | null;
    concept_rate: number | null;
    average_accuracy: number | null;
    average_duration_ms: number | null;
    average_reaction_ms: number | null;
    average_errors: number | null;
    average_misses: number | null;
    average_points: number | null;
    /** What players answered for this challenge; "none" = no answer. */
    guesses: Record<AttentionType | 'none', number>;
};

export type AttentionRoundDetails = Partial<
    Record<
        | 'expected'
        | 'answer'
        | 'expected_secondary'
        | 'answer_secondary'
        | 'perseverations',
        number
    >
>;

export type AttentionRoundResult = {
    type: AttentionType;
    correct: boolean;
    accuracy: number;
    hits: number;
    errors: number;
    misses: number;
    duration_ms: number;
    reaction_ms: number | null;
    guess: AttentionType | null;
    guess_correct: boolean;
    points: number;
    details: AttentionRoundDetails | null;
};

export type AttentionPlayerReport = {
    id: number;
    position: number;
    nickname: string;
    score: number;
    correct_rounds: number;
    concept_hits: number;
    answered: number;
    errors: number;
    misses: number;
    duration_ms: number;
    elapsed_seconds: number | null;
    finished: boolean;
    joined_at: string | null;
    rounds: AttentionRoundResult[];
};

export type AttentionRankingRow = {
    id: number;
    position: number;
    nickname: string;
    score: number;
    correct_rounds: number;
    answered: number;
    finished: boolean;
};

/** The player as the phone knows it. */
export type AttentionPlayerState = {
    id: number;
    nickname: string;
    score: number;
    correct_rounds: number;
    finished: boolean;
    answered: AttentionType[];
};

/** What a challenge measured, before the player names the attention type. */
export type ChallengeOutcome = {
    correct: boolean;
    accuracy: number;
    hits: number;
    errors: number;
    misses: number;
    duration_ms: number;
    reaction_ms: number | null;
    details?: AttentionRoundDetails;
    /** One line shown on the feedback screen, e.g. "Você achou 4 de 4 Q". */
    summary: string;
};
