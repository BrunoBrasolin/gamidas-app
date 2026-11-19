<?php

namespace App\Services\Bank;

use App\Enums\BankAccountType;
use App\Enums\BankChannel;
use App\Enums\BankDirection;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns a Pluggy transaction into the attributes of a BankTransaction:
 * absolute amount in cents and direction, channel (PIX, card, transfer...),
 * counterparty and the key rules match on.
 *
 * Signs: on a credit card a purchase is positive and a payment or refund is
 * negative; on a bank account the direction comes from `type` (DEBIT is money
 * out, CREDIT money in), falling back to the sign. Fields only Open Finance
 * fills (operationType, paymentData, billId) are optional: the description
 * is enough when they are missing.
 */
final class TransactionClassifier
{
    private const BILL_PAYMENT = '/pagamento\s+(de\s+|da\s+)?fatura|pagto\.?\s+fatura|fatura\s+(do\s+)?cart[aã]o/iu';

    private const INVESTMENT = '/\b(aplica[cç][aã]o|resgate|rdb|cdb|lci|lca|tesouro|caixinha|investimento|dinheiro guardado|rendimento)\b/iu';

    private const FEE = '/\b(iof|juros|tarifa|multa|encargos?|anuidade)\b/iu';

    /**
     * @param  array<string, mixed>  $transaction  A transaction as returned by GET /v2/transactions.
     * @return array<string, mixed>
     */
    public function classify(array $transaction, BankAccountType $accountType): array
    {
        $isCard = $accountType === BankAccountType::Credit;
        $amountCents = self::cents($transaction['amount'] ?? 0);
        $type = strtoupper((string) ($transaction['type'] ?? ''));

        $direction = match (true) {
            $isCard => $amountCents < 0 ? BankDirection::In : BankDirection::Out,
            $type === 'DEBIT' => BankDirection::Out,
            $type === 'CREDIT' => BankDirection::In,
            default => $amountCents < 0 ? BankDirection::Out : BankDirection::In,
        };

        $description = $this->text($transaction['description'] ?? null, 255)
            ?? $this->text($transaction['descriptionRaw'] ?? null, 255)
            ?? 'Sem descrição';

        $operationType = $this->text($transaction['operationType'] ?? null, 48);
        $payment = is_array($transaction['paymentData'] ?? null) ? $transaction['paymentData'] : [];
        $card = is_array($transaction['creditCardMetadata'] ?? null) ? $transaction['creditCardMetadata'] : [];

        [$counterparty, $document] = $this->counterparty($payment, $direction, $description);

        $installmentCount = $this->positiveInt($card['totalInstallments'] ?? null);
        $installmentNumber = $this->positiveInt($card['installmentNumber'] ?? null);

        if ($installmentCount === null || $installmentCount < 2 || $installmentNumber === null || $installmentNumber > $installmentCount) {
            $installmentCount = $installmentNumber = null;
        }

        return [
            'date' => self::date($transaction['date'] ?? null) ?? CarbonImmutable::today(),
            'description' => $description,
            'amount_cents' => abs($amountCents),
            'direction' => $direction,
            'channel' => $this->channel($isCard, $direction, $operationType, (string) ($payment['paymentMethod'] ?? ''), $description),
            'bank_status' => strtoupper((string) ($transaction['status'] ?? '')) === 'PENDING' ? 'PENDING' : 'POSTED',
            'operation_type' => $operationType,
            'counterparty_name' => $counterparty,
            'counterparty_document' => $document,
            'match_key' => self::matchKey($counterparty, $description),
            'installment_number' => $installmentNumber,
            'installment_count' => $installmentCount,
            'pluggy_bill_id' => $this->text($card['billId'] ?? null, 64),
            'purchase_date' => self::date($card['purchaseDate'] ?? null),
        ];
    }

    /**
     * The key rules compare with: the counterparty name when the bank sent
     * one, otherwise the description without numbers (installments, dates,
     * card or order numbers change from one purchase to the next).
     */
    public static function matchKey(?string $counterparty, string $description): string
    {
        if ($counterparty !== null && self::normalize($counterparty) !== '') {
            return Str::limit(self::normalize($counterparty), 160, '');
        }

        $key = self::normalize((string) preg_replace('/\d+/', ' ', $description));

        return Str::limit($key !== '' ? $key : self::normalize($description), 160, '');
    }

    /**
     * Lowercase ASCII words separated by single spaces ("Padaria São João!"
     * becomes "padaria sao joao").
     */
    public static function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $value)));
    }

    /**
     * Cents of a Pluggy amount (a JSON number in reais), keeping the sign.
     * Goes through a two-decimal string, never float arithmetic on money.
     */
    public static function cents(mixed $amount): int
    {
        if (! is_int($amount) && ! is_float($amount) && ! (is_string($amount) && is_numeric($amount))) {
            return 0;
        }

        $amount = (float) $amount;
        $cents = Money::toCents(number_format(abs($amount), 2, '.', ''));

        return $amount < 0 ? -$cents : $cents;
    }

    /**
     * The local date of a Pluggy timestamp. Dates without time come as
     * midnight UTC and are kept as they are; real times are converted to the
     * application timezone.
     */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        $timezone = (string) config('app.timezone');

        if (strlen($value) === 10 || preg_match('/T00:00:00(\.0+)?(Z|\+00:00)?$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), $timezone) ?: null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone($timezone)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), $timezone) ?: null;
        }
    }

    private function channel(bool $isCard, BankDirection $direction, ?string $operationType, string $paymentMethod, string $description): BankChannel
    {
        $operationType = strtoupper((string) $operationType);
        $paymentMethod = strtoupper($paymentMethod);

        if ($isCard) {
            return match (true) {
                in_array($operationType, ['PAGAMENTO', 'PAGAMENTO_FATURA'], true),
                $direction === BankDirection::In && preg_match(self::BILL_PAYMENT, $description) === 1,
                $direction === BankDirection::In && preg_match('/^pagamento\b/iu', $description) === 1 => BankChannel::BillPayment,
                in_array($operationType, ['TARIFA', 'OPERACOES_CREDITO_CONTRATADAS_CARTAO'], true),
                preg_match(self::FEE, $description) === 1 => BankChannel::Fee,
                default => BankChannel::Card,
            };
        }

        return match (true) {
            preg_match(self::BILL_PAYMENT, $description) === 1 => BankChannel::BillPayment,
            $operationType === 'PIX' || $paymentMethod === 'PIX' || preg_match('/\bpix\b/iu', $description) === 1 => BankChannel::Pix,
            in_array($operationType, ['RENDIMENTO_APLIC_FINANCEIRA', 'RESGATE_APLIC_FINANCEIRA'], true),
            preg_match(self::INVESTMENT, $description) === 1 => BankChannel::Investment,
            in_array($operationType, ['BOLETO', 'CONVENIO_ARRECADACAO'], true) || $paymentMethod === 'BOLETO',
            preg_match('/\bboleto\b/iu', $description) === 1 => BankChannel::Boleto,
            in_array($operationType, ['TED', 'DOC', 'TRANSFERENCIA_MESMA_INSTITUICAO', 'PORTABILIDADE_SALARIO'], true),
            in_array($paymentMethod, ['TED', 'DOC', 'TEV'], true),
            preg_match('/transfer[eê]ncia|\bted\b|\bdoc\b/iu', $description) === 1 => BankChannel::Transfer,
            $operationType === 'CARTAO' || preg_match('/compra no d[eé]bito|d[eé]bito\s+(visa|master|elo)/iu', $description) === 1 => BankChannel::DebitCard,
            in_array($operationType, ['PACOTE_TARIFA_SERVICOS', 'TARIFA_SERVICOS_AVULSOS', 'ENCARGOS_JUROS_CHEQUE_ESPECIAL'], true),
            preg_match(self::FEE, $description) === 1 => BankChannel::Fee,
            default => BankChannel::Other,
        };
    }

    /**
     * Who received the money (when it went out) or sent it (when it came in),
     * from the payment data or, for PIX and transfers, the description
     * ("Transferência enviada pelo Pix - FULANO DE TAL - •••.123.456-••" or
     * "Transferência enviada pelo Pix|FULANO DE TAL").
     * CPFs are masked; CNPJs identify companies and stay whole.
     *
     * @param  array<array-key, mixed>  $payment
     * @return array{0: ?string, 1: ?string}
     */
    private function counterparty(array $payment, BankDirection $direction, string $description): array
    {
        $party = $payment[$direction === BankDirection::Out ? 'receiver' : 'payer'] ?? null;
        $name = is_array($party) ? $this->text($party['name'] ?? null, 160) : null;
        $document = null;

        if (is_array($party) && is_array($party['documentNumber'] ?? null)) {
            $document = $this->maskDocument((string) ($party['documentNumber']['value'] ?? ''));
        }

        if ($name === null && preg_match('/(pix|transfer[eê]ncia|ted|doc)/iu', $description) === 1) {
            // "Transferência enviada pelo Pix - FULANO - ..." or "...Pix|FULANO|...".
            $parts = array_map('trim', preg_split('/\s+-\s+|\s*\|\s*/u', $description) ?: [$description]);

            if (count($parts) >= 2 && preg_match('/\p{L}/u', $parts[1]) === 1 && preg_match('/^[•*.\d\-\/\s]+$/u', $parts[1]) !== 1) {
                $name = $this->text($parts[1], 160);
            }

            if ($document === null && preg_match('/[•*]{3}\.\d{3}\.\d{3}-[•*]{2}/u', $description, $match) === 1) {
                $document = $match[0];
            }
        }

        return [$name !== null ? Str::title(Str::lower($name)) : null, $document];
    }

    private function maskDocument(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        return match (strlen($digits)) {
            11 => '•••.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-••',
            14 => substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2),
            default => null,
        };
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
