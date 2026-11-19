<?php

namespace Tests\Unit;

use App\Enums\BankAccountType;
use App\Enums\BankChannel;
use App\Enums\BankDirection;
use App\Services\Bank\TransactionClassifier;
use Tests\TestCase;

class TransactionClassifierTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    private function classify(array $transaction, BankAccountType $type = BankAccountType::Bank): array
    {
        return app(TransactionClassifier::class)->classify([
            'id' => 'tx-1',
            'date' => '2026-11-18T00:00:00.000Z',
            'description' => 'Compra',
            'amount' => 10,
            'type' => 'DEBIT',
            'status' => 'POSTED',
            ...$transaction,
        ], $type);
    }

    public function test_a_card_purchase_is_money_out_and_a_card_payment_is_a_bill_payment(): void
    {
        $purchase = $this->classify(['description' => 'Padaria Pão Quente', 'amount' => 23.9, 'type' => 'DEBIT'], BankAccountType::Credit);

        $this->assertSame(2390, $purchase['amount_cents']);
        $this->assertSame(BankDirection::Out, $purchase['direction']);
        $this->assertSame(BankChannel::Card, $purchase['channel']);

        $payment = $this->classify(['description' => 'Pagamento recebido', 'amount' => -830.1, 'type' => 'CREDIT'], BankAccountType::Credit);

        $this->assertSame(83010, $payment['amount_cents']);
        $this->assertSame(BankDirection::In, $payment['direction']);
        $this->assertSame(BankChannel::BillPayment, $payment['channel']);

        $refund = $this->classify(['description' => 'Estorno Loja X', 'amount' => -50, 'type' => 'CREDIT', 'operationType' => 'ESTORNO'], BankAccountType::Credit);

        $this->assertSame(BankDirection::In, $refund['direction']);
        $this->assertSame(BankChannel::Card, $refund['channel']);
    }

    public function test_open_finance_calls_a_card_purchase_a_payment(): void
    {
        $purchase = $this->classify(['description' => 'Mercado Bom Preço', 'amount' => 152.3, 'operationType' => 'PAGAMENTO'], BankAccountType::Credit);

        $this->assertSame(BankDirection::Out, $purchase['direction']);
        $this->assertSame(BankChannel::Card, $purchase['channel']);

        $fee = $this->classify(['description' => 'IOF de compra internacional', 'amount' => 3.21, 'operationType' => 'PAGAMENTO'], BankAccountType::Credit);

        $this->assertSame(BankChannel::Fee, $fee['channel']);

        $payment = $this->classify(['description' => 'Pagamento recebido', 'amount' => -950.4, 'type' => 'CREDIT', 'operationType' => 'PAGAMENTO_FATURA'], BankAccountType::Credit);

        $this->assertSame(BankDirection::In, $payment['direction']);
        $this->assertSame(BankChannel::BillPayment, $payment['channel']);
    }

    public function test_bank_account_direction_comes_from_the_type_whatever_the_sign(): void
    {
        $this->assertSame(BankDirection::Out, $this->classify(['amount' => -100, 'type' => 'DEBIT'])['direction']);
        $this->assertSame(BankDirection::Out, $this->classify(['amount' => 100, 'type' => 'DEBIT'])['direction']);
        $this->assertSame(BankDirection::In, $this->classify(['amount' => 100, 'type' => 'CREDIT'])['direction']);
        $this->assertSame(10000, $this->classify(['amount' => -100, 'type' => 'DEBIT'])['amount_cents']);
    }

    public function test_amounts_become_exact_cents(): void
    {
        $this->assertSame(1, TransactionClassifier::cents(0.01));
        $this->assertSame(30, TransactionClassifier::cents(0.1 + 0.2));
        $this->assertSame(-123456, TransactionClassifier::cents(-1234.56));
        $this->assertSame(1999, TransactionClassifier::cents('19.99'));
        $this->assertSame(0, TransactionClassifier::cents(null));
    }

    public function test_pix_counterparty_comes_from_payment_data_with_cpf_masked(): void
    {
        $sent = $this->classify([
            'description' => 'Transferência enviada',
            'operationType' => 'PIX',
            'amount' => -1800,
            'paymentData' => [
                'paymentMethod' => 'PIX',
                'payer' => ['name' => 'BRUNO TESTE'],
                'receiver' => ['name' => 'FULANO DE TAL', 'documentNumber' => ['type' => 'CPF', 'value' => '12345678901']],
            ],
        ]);

        $this->assertSame(BankChannel::Pix, $sent['channel']);
        $this->assertSame('Fulano De Tal', $sent['counterparty_name']);
        $this->assertSame('•••.456.789-••', $sent['counterparty_document']);
        $this->assertSame('fulano de tal', $sent['match_key']);

        $received = $this->classify([
            'description' => 'Transferência recebida',
            'type' => 'CREDIT',
            'paymentData' => [
                'paymentMethod' => 'PIX',
                'payer' => ['name' => 'Empresa Exemplo Ltda', 'documentNumber' => ['type' => 'CNPJ', 'value' => '12345678000199']],
                'receiver' => ['name' => 'Bruno Teste'],
            ],
        ]);

        $this->assertSame('Empresa Exemplo Ltda', $received['counterparty_name']);
        $this->assertSame('12.345.678/0001-99', $received['counterparty_document']);
    }

    public function test_pix_counterparty_falls_back_to_the_description(): void
    {
        $pix = $this->classify(['description' => 'Transferência enviada pelo Pix - FULANO DE TAL - •••.456.789-•• - BANCO EXEMPLO (0000) Agência: 1 Conta: 1-2']);

        $this->assertSame(BankChannel::Pix, $pix['channel']);
        $this->assertSame('Fulano De Tal', $pix['counterparty_name']);
        $this->assertSame('•••.456.789-••', $pix['counterparty_document']);
    }

    public function test_pix_counterparty_is_read_from_descriptions_split_by_pipes(): void
    {
        $pix = $this->classify(['description' => 'Transferência enviada pelo Pix|FULANO DE TAL|•••.456.789-••']);

        $this->assertSame(BankChannel::Pix, $pix['channel']);
        $this->assertSame('Fulano De Tal', $pix['counterparty_name']);
        $this->assertSame('fulano de tal', $pix['match_key']);
        $this->assertSame('•••.456.789-••', $pix['counterparty_document']);
    }

    public function test_channels_from_the_description_when_open_finance_fields_are_missing(): void
    {
        $this->assertSame(BankChannel::BillPayment, $this->classify(['description' => 'Pagamento de fatura'])['channel']);
        $this->assertSame(BankChannel::Investment, $this->classify(['description' => 'Aplicação RDB'])['channel']);
        $this->assertSame(BankChannel::Boleto, $this->classify(['description' => 'Pagamento de boleto efetuado - Escola'])['channel']);
        $this->assertSame(BankChannel::Transfer, $this->classify(['description' => 'Transferência enviada - Fulano'])['channel']);
        $this->assertSame(BankChannel::DebitCard, $this->classify(['description' => 'Compra no débito - Padaria'])['channel']);
        $this->assertSame(BankChannel::Fee, $this->classify(['description' => 'IOF de compra internacional'], BankAccountType::Credit)['channel']);
        $this->assertSame(BankChannel::Other, $this->classify(['description' => 'Ajuste'])['channel']);
    }

    public function test_card_installments_and_bill_are_read(): void
    {
        $installment = $this->classify([
            'description' => 'Loja de Móveis 2/10',
            'creditCardMetadata' => ['installmentNumber' => 2, 'totalInstallments' => 10, 'billId' => 'bill-1', 'purchaseDate' => '2026-09-15T00:00:00.000Z'],
        ], BankAccountType::Credit);

        $this->assertSame(2, $installment['installment_number']);
        $this->assertSame(10, $installment['installment_count']);
        $this->assertSame('bill-1', $installment['pluggy_bill_id']);
        $this->assertSame('2026-09-15', $installment['purchase_date']->toDateString());
        // Numbers are dropped from the key, so every installment matches the same rule.
        $this->assertSame('loja de moveis', $installment['match_key']);

        $single = $this->classify(['creditCardMetadata' => ['installmentNumber' => 1, 'totalInstallments' => 1]], BankAccountType::Credit);
        $this->assertNull($single['installment_number']);
    }

    public function test_dates_without_time_keep_their_day_and_real_times_use_the_local_day(): void
    {
        $this->assertSame('2026-11-18', $this->classify(['date' => '2026-11-18T00:00:00.000Z'])['date']->toDateString());
        // 01:30 UTC is 22:30 of the day before in São Paulo.
        $this->assertSame('2026-11-17', $this->classify(['date' => '2026-11-18T01:30:00.000Z'])['date']->toDateString());
        $this->assertSame('PENDING', $this->classify(['status' => 'PENDING'])['bank_status']);
    }

    public function test_keys_are_normalized(): void
    {
        $this->assertSame('padaria sao joao', TransactionClassifier::normalize('  Padaria SÃO   João! '));
        $this->assertSame('uber trip', TransactionClassifier::matchKey(null, 'UBER *TRIP 1234'));
        $this->assertSame('fulano', TransactionClassifier::matchKey('Fulano', 'PIX 123'));
    }
}
