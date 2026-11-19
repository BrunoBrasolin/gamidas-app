export type BankAccountType = 'BANK' | 'CREDIT';

export type BankBill = {
    id: number;
    due_date: string;
    total_cents: number;
    minimum_payment_cents: number | null;
    finance_charges_cents: number;
};

export type BankAccount = {
    id: number;
    name: string;
    type: BankAccountType;
    type_label: string;
    number: string | null;
    balance_cents: number;
    credit_limit_cents: number | null;
    available_credit_cents: number | null;
    balance_due_date: string | null;
    bills: BankBill[];
};

export type BankInvestment = {
    id: number;
    name: string;
    type: string | null;
    subtype: string | null;
    balance_cents: number;
    invested_cents: number | null;
    rate: string | null;
    due_date: string | null;
};

export type BankConnection = {
    id: number;
    connector_name: string;
    connector_image_url: string | null;
    connector_color: string | null;
    status: string;
    status_label: string;
    needs_reconnect: boolean;
    error_message: string | null;
    consent_expires_at: string | null;
    consent_expiring: boolean;
    is_meu_pluggy: boolean;
    import_from: string;
    bank_updated_at: string | null;
    synced_at: string | null;
    sync_error: string | null;
    accounts: BankAccount[];
    investments: BankInvestment[];
    investments_total_cents: number;
};

export type BankCatalogAction =
    | 'household'
    | 'personal'
    | 'income'
    | 'investment'
    | 'link'
    | 'ignore'
    | 'offset';

/** The other side of a refund and its charge, which cancel each other out. */
export type BankOffsetSide = {
    id: number;
    display_name: string;
    description: string;
    date: string;
    direction: 'out' | 'in';
    removed: boolean;
};

export type BankCandidate = {
    id: number;
    title: string;
    scope: 'household' | 'personal';
    amount_cents: number;
    due_date: string;
    billing_month: string;
    installment_number: number | null;
    installment_count: number | null;
    awaits_amount: boolean;
    is_recurring: boolean;
    /** Only on suggestions: same amount and clearly the same expense. */
    strong?: boolean;
};

export type BankTransaction = {
    id: number;
    date: string;
    reference_date: string;
    description: string;
    display_name: string;
    counterparty_name: string | null;
    counterparty_document: string | null;
    amount_cents: number;
    direction: 'out' | 'in';
    channel: string;
    channel_label: string;
    pending_at_bank: boolean;
    installment_number: number | null;
    installment_count: number | null;
    account: { id: number; name: string; type: BankAccountType };
    review_status: 'pending' | 'catalogued' | 'ignored';
    catalog_type:
        | 'household'
        | 'personal'
        | 'linked'
        | 'income'
        | 'investment'
        | null;
    catalog_label: string | null;
    ignore_reason: 'bill_payment' | 'user' | 'offset' | null;
    removed: boolean;
    expense:
        | { missing: true }
        | {
              missing: false;
              id: number;
              title: string;
              scope: 'household' | 'personal';
              billing_month: string;
              status: 'pending' | 'paid';
          }
        | null;
    entry:
        | { missing: true }
        | {
              missing: false;
              id: number;
              type: 'income' | 'expense' | 'investment';
              slug: 'income' | 'expenses' | 'investments';
              title: string;
              month: string;
          }
        | null;
    offset: BankOffsetSide | null;
    offset_candidates: BankOffsetSide[];
    suggestion: {
        action: BankCatalogAction;
        offset_transaction_id: number | null;
        label: string;
        title: string | null;
        occurrence_id: number | null;
        rule: {
            id: number;
            label: string;
            amount_cents: number | null;
        } | null;
    } | null;
    candidates: BankCandidate[];
};

export type BankRuleAction =
    | 'household'
    | 'personal'
    | 'income'
    | 'investment'
    | 'recurring'
    | 'ignore';

export type BankRule = {
    id: number;
    label: string;
    pattern: string;
    match_mode: 'equals' | 'contains';
    match_label: string;
    amount_cents: number | null;
    action: BankRuleAction;
    action_label: string;
    title: string | null;
    recurring_expense: { id: number; title: string } | null;
    auto_apply: boolean;
    hits: number;
    last_used_at: string | null;
};
