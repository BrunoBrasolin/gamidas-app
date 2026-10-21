export type Scope = 'household' | 'personal';

export type OccurrenceStatus = 'pending' | 'paid';

export type OccurrenceSource = 'one_off' | 'recurring' | 'credit_card';

export type Member = {
    user_id: number;
    name: string;
    first_name: string;
    salary_cents: number;
    payment_label: string;
};

export type Category = {
    id: number;
    name: string;
};

export type UserRef = {
    id: number;
    name: string;
};

export type Share = {
    user_id: number;
    amount_cents: number;
    salary_cents: number;
    combined_salary_cents: number;
};

export type PaymentEvent = {
    id: number;
    type: 'paid' | 'reopened';
    user: string;
    reason: string | null;
    amount_cents: number;
    created_at: string;
};

export type Occurrence = {
    id: number;
    title: string;
    notes: string | null;
    scope: Scope;
    source: OccurrenceSource;
    source_label: string;
    recurring_expense_id: number | null;
    credit_card_purchase_id: number | null;
    credit_card_name: string | null;
    installment_number: number | null;
    installment_count: number | null;
    category: Category | null;
    billing_month: string;
    due_date: string;
    amount_cents: number;
    status: OccurrenceStatus;
    is_overdue: boolean;
    is_customized: boolean;
    shares: Share[];
    created_by: UserRef;
    created_at: string | null;
    updated_by: UserRef | null;
    updated_at: string | null;
    paid_at: string | null;
    paid_by: UserRef | null;
    payment: {
        confirm_label: string;
        paid_label: string | null;
        can_confirm: boolean;
        can_reopen: boolean;
    };
    can: {
        update: boolean;
        delete: boolean;
    };
    history?: PaymentEvent[];
};

export type MemberTotals = {
    user_id: number;
    total_cents: number;
    paid_cents: number;
    pending_cents: number;
};

export type HouseholdMonthSummary = {
    total_cents: number;
    paid_cents: number;
    pending_cents: number;
    count: number;
    paid_count: number;
    pending_count: number;
    overdue_count: number;
    by_source: {
        one_off: number;
        fixed: number;
        temporary: number;
        credit_card: number;
    };
    by_member: MemberTotals[];
    by_category: { category: string; total_cents: number }[];
};

export type PersonalFlows = {
    income_cents: number;
    ledger_expense_cents: number;
    personal_bills_cents: number;
    household_share_cents: number;
    expense_cents: number;
    investment_cents: number;
    net_cents: number;
};

export type PersonalMonthSummary = PersonalFlows & {
    balance_cents: number;
};

export type PersonalYearSummary = {
    months: (PersonalMonthSummary & { month: string })[];
    totals: PersonalFlows;
    opening_balance_cents: number;
    closing_balance_cents: number;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};

export type TransactionType = 'income' | 'expense' | 'investment';
