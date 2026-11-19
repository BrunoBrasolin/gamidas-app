<?php

namespace App\Enums;

/**
 * How a bank transaction happened, derived from Pluggy's operation type,
 * payment method and description. Used for filters, labels and to spot the
 * movements that must not become expenses (credit card bill payments).
 */
enum BankChannel: string
{
    case Pix = 'pix';
    case Card = 'card';
    case Transfer = 'transfer';
    case Boleto = 'boleto';
    case DebitCard = 'debit_card';
    case BillPayment = 'bill_payment';
    case Investment = 'investment';
    case Fee = 'fee';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Card => 'Cartão de crédito',
            self::Transfer => 'Transferência',
            self::Boleto => 'Boleto',
            self::DebitCard => 'Cartão de débito',
            self::BillPayment => 'Pagamento de fatura',
            self::Investment => 'Investimento',
            self::Fee => 'Tarifa / encargo',
            self::Other => 'Outro',
        };
    }
}
