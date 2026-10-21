<?php

namespace App\Http\Controllers\Household;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ExpenseCategoryRequest;
use App\Models\CreditCardPurchase;
use App\Models\ExpenseCategory;
use App\Models\ExpenseOccurrence;
use App\Models\RecurringExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request): Response
    {
        $household = $this->household($request);

        return Inertia::render('household/categories', [
            'categories' => $household->categories()
                ->withCount(['occurrences' => fn ($query) => $query->withTrashed()])
                ->get()
                ->map(fn (ExpenseCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'occurrences_count' => (int) $category->getAttribute('occurrences_count'),
                ]),
        ]);
    }

    public function store(ExpenseCategoryRequest $request): RedirectResponse
    {
        $request->household()->categories()->create(['name' => trim((string) $request->validated('name'))]);

        $this->toast('Categoria criada.');

        return back();
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $category->update(['name' => trim((string) $request->validated('name'))]);

        $this->toast('Categoria atualizada.');

        return back();
    }

    /**
     * Categories in use are kept so that historical records keep their category.
     */
    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $inUse = ExpenseOccurrence::withTrashed()->where('expense_category_id', $category->id)->exists()
            || RecurringExpense::where('expense_category_id', $category->id)->exists()
            || CreditCardPurchase::where('expense_category_id', $category->id)->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'category' => 'Esta categoria está em uso e não pode ser excluída. Renomeie-a se necessário.',
            ]);
        }

        $category->delete();

        $this->toast('Categoria excluída.');

        return back();
    }
}
