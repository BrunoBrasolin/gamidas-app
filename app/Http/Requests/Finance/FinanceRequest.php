<?php

namespace App\Http\Requests\Finance;

use App\Enums\ExpenseScope;
use App\Models\Household;
use App\Support\Money;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared helpers for finance form requests. The record targeted by the route
 * is authorized through its policy, and identifiers in the payload are only
 * accepted when they belong to the user's own household.
 */
abstract class FinanceRequest extends FormRequest
{
    /**
     * The policy ability checked on the route's record before validating.
     */
    protected string $routeAbility = 'update';

    /**
     * Authorize the record targeted by the route before validation runs, so
     * records of other households are never even validated against.
     */
    public function authorize(): Response|bool
    {
        foreach ($this->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return Gate::inspect($this->routeAbility, $parameter);
            }
        }

        return true;
    }

    public function household(): Household
    {
        /** @var Household */
        return $this->user()->household();
    }

    protected function householdId(): ?int
    {
        return $this->user()?->householdId();
    }

    /**
     * An exists rule restricted to the user's household.
     */
    protected function existsInHousehold(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('household_id', $this->householdId());
    }

    /**
     * @return array<int, mixed>
     */
    protected function monthRules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:'.Month::PATTERN];
    }

    public function cents(string $key): int
    {
        return Money::toCents((string) $this->validated($key));
    }

    public function month(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? Month::parse($value) : null;
    }

    public function day(string $key): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated($key), config('app.timezone'));
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function nullableText(string $key): ?string
    {
        $value = trim((string) $this->validated($key));

        return $value === '' ? null : $value;
    }

    public function scope(): ExpenseScope
    {
        return ExpenseScope::from((string) $this->validated('scope'));
    }
}
