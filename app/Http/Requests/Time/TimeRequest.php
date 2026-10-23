<?php

namespace App\Http\Requests\Time;

use App\Http\Requests\Finance\FinanceRequest;
use App\Models\TimeCategory;
use App\Support\Day;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared helpers for the time-tracking requests. Like finance requests, the
 * routed record is authorized before validation; category ids in the payload
 * are only accepted when the category belongs to the user.
 */
abstract class TimeRequest extends FinanceRequest
{
    protected function ownedCategory(): Exists
    {
        return Rule::exists('time_categories', 'id')->where('user_id', $this->user()?->id);
    }

    /**
     * @return array<int, mixed>
     */
    protected function dateRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'after_or_equal:'.Day::MIN, 'before_or_equal:'.Day::MAX];
    }

    public function category(): TimeCategory
    {
        return TimeCategory::query()->where('user_id', $this->user()?->id)->findOrFail((int) $this->validated('category_id'));
    }
}
