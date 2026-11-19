<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank connected by one member through Pluggy (a Pluggy "item"). It is
 * private to that member, like everything imported through it. The item id
 * is encrypted at rest; webhooks find the connection by its SHA-256.
 *
 * @property int $id
 * @property int $user_id
 * @property string $item_id
 * @property string $item_hash
 * @property int|null $connector_id
 * @property string $connector_name
 * @property string|null $connector_image_url
 * @property string|null $connector_color
 * @property string $status
 * @property string|null $execution_status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $consent_expires_at
 * @property CarbonImmutable|null $bank_updated_at
 * @property CarbonImmutable $import_from
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $sync_requested_at
 * @property string|null $sync_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'item_id', 'item_hash', 'connector_id', 'connector_name', 'connector_image_url',
    'connector_color', 'status', 'execution_status', 'error_code', 'error_message',
    'consent_expires_at', 'bank_updated_at', 'import_from', 'synced_at', 'sync_requested_at', 'sync_error',
])]
#[Hidden(['item_id', 'item_hash'])]
class BankConnection extends Model
{
    /**
     * Item statuses that need the member to connect again.
     */
    public const RECONNECT_STATUSES = ['LOGIN_ERROR', 'OUTDATED', 'WAITING_USER_INPUT'];

    /**
     * Error codes that need the member to connect again.
     */
    public const RECONNECT_ERRORS = ['USER_AUTHORIZATION_EXPIRED', 'USER_AUTHORIZATION_REVOKED', 'USER_AUTHORIZATION_NOT_GRANTED', 'INVALID_CREDENTIALS', 'ACCOUNT_CREDENTIALS_RESET', 'USER_INPUT_TIMEOUT'];

    /**
     * Days before the consent expires to start warning the member.
     */
    public const CONSENT_WARNING_DAYS = 15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_id' => 'encrypted',
            'connector_id' => 'integer',
            'consent_expires_at' => 'datetime',
            'bank_updated_at' => 'datetime',
            'import_from' => DateOnly::class,
            'synced_at' => 'datetime',
            'sync_requested_at' => 'datetime',
        ];
    }

    public static function hashItem(string $itemId): string
    {
        return hash('sha256', $itemId);
    }

    /**
     * Whether the bank stopped sharing data until the member connects again
     * (login error, expired or revoked consent).
     */
    public function needsReconnect(): bool
    {
        return in_array($this->status, self::RECONNECT_STATUSES, true)
            || in_array($this->error_code, self::RECONNECT_ERRORS, true)
            || ($this->consent_expires_at !== null && $this->consent_expires_at->isPast());
    }

    /**
     * Whether the consent expires within {@see CONSENT_WARNING_DAYS} days.
     */
    public function consentExpiringSoon(): bool
    {
        return $this->consent_expires_at !== null
            && $this->consent_expires_at->isFuture()
            && $this->consent_expires_at->lte(now()->addDays(self::CONSENT_WARNING_DAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * @return HasMany<BankInvestment, $this>
     */
    public function investments(): HasMany
    {
        return $this->hasMany(BankInvestment::class);
    }
}
