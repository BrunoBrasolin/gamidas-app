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
    | 'offset'
    | 'settlement'
    | 'split';

/** The expense a transaction (or one part of it) became. */
export type BankDestinationExpense =
    | { missing: true }
    | {
          missing: false;
          id: number;
          title: string;
          billing_month: string;
          status: 'pending' | 'paid';
      };

/** The personal entry a transaction (or one part of it) became. */
export type BankDestinationEntry =
    | { missing: true }
    | {
          missing: false;
          id: number;
          type: 'income' | 'expense' | 'investment';
          slug: 'income' | 'expenses' | 'investments';
          title: string;
          month: string;
      };

/** One part of a transaction whose value was split. */
export type BankSplitPart = {
    type: 'household' | 'linked' | 'personal' | 'investment';
    label: string;
    amount_cents: number;
    expense: BankDestinationExpense | null;
    entry: BankDestinationEntry | null;
};

/** The other side of a refund and its charge, which cancel each other out. */
export type BankOffsetSide = {
    id: number;
    display_name: string;
    description: string;
    date: string;
    direction: 'out' | 'in';
    removed: boolean;
};

/**
 * A household expense a transfer between the members may settle: the share
 * of the member who did not register it.
 */
export type BankSettlementCandidate = {
    id: number;
    title: string;
    amount_cents: number;
    due_date: string;
    billing_month: string;
    installment_number: number | null;
    installment_count: number | null;
    payer_user_id: number;
    share_cents: number;
    /** Already paid by the same transfer seen in the other member's bank. */
    settled_by_partner: boolean;
};

export type BankCandidate = {
    id: number;
    title: string;
    amount_cents: number;
    due_date: string;
    billing_month: string;
    installment_number: number | null;
    installment_count: number | null;
    awaits_amount: boolean;
    is_recurring: boolean;
    /** Already paid (by hand): linking only attaches the bank proof. */
    paid: boolean;
    paid_at: string | null;
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
        | 'settlement'
        | 'split'
        | null;
    catalog_label: string | null;
    ignore_reason: 'bill_payment' | 'user' | 'offset' | null;
    removed: boolean;
    expense: BankDestinationExpense | null;
    entry: BankDestinationEntry | null;
    /** The parts of a transaction whose value was split. */
    split: BankSplitPart[] | null;
    /** What a house settlement paid. */
    settlement: { count: number; months: string[] } | null;
    /** The household expenses whose shares add up to the transfer. */
    settlement_ids: number[];
    offset: BankOffsetSide | null;
    offset_candidates: BankOffsetSide[];
    suggestion: {
        action: BankCatalogAction;
        offset_transaction_id: number | null;
        label: string;
        title: string | null;
        occurrence_id: number | null;
        occurrence_ids: number[];
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
    | 'ignore'
    | 'settlement';

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
