<?php

namespace App\Http\Requests\Knowledge;

use App\Http\Requests\Finance\FinanceRequest;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeSubject;
use App\Support\KnowledgeCategoryTree;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared helpers for the knowledge base requests. The routed record is
 * authorized before validation (see {@see FinanceRequest}); ids in the payload
 * are only accepted when they belong to the user's own knowledge base.
 */
abstract class KnowledgeRequest extends FinanceRequest
{
    protected function ownedSubject(): Exists
    {
        return Rule::exists('knowledge_subjects', 'id')->where('user_id', $this->user()?->id);
    }

    protected function categoryOf(?int $subjectId): Exists
    {
        return Rule::exists('knowledge_categories', 'id')->where('subject_id', $subjectId ?? 0);
    }

    /**
     * Rejects a parent the category (or a new one) cannot go into: itself,
     * one of its own subcategories, or a place deeper than the limit.
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected function placement(KnowledgeSubject $subject, ?KnowledgeCategory $moving = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($subject, $moving) {
            if (! is_numeric($value)) {
                return;
            }

            $tree = new KnowledgeCategoryTree($subject->categories()->get());

            if (! $tree->has((int) $value)) {
                return;
            }

            if (! $tree->canPlace($moving?->id, (int) $value)) {
                $fail($moving !== null && ($moving->id === (int) $value || in_array((int) $value, $tree->descendants($moving->id), true))
                    ? 'Uma categoria não pode ficar dentro dela mesma.'
                    : 'As categorias podem ter no máximo '.KnowledgeCategory::MAX_DEPTH.' níveis.');
            }
        };
    }

    protected function text(string $key): string
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : '';
    }

    public function category(KnowledgeSubject $subject, string $key = 'category_id'): ?KnowledgeCategory
    {
        $id = $this->validated($key);

        return $id === null ? null : $subject->categories()->findOrFail((int) $id);
    }
}
