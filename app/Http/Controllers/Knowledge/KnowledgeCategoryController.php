<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\KnowledgeCategoryMoveRequest;
use App\Http\Requests\Knowledge\KnowledgeCategoryRequest;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeSubject;
use App\Services\Knowledge\KnowledgeCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class KnowledgeCategoryController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly KnowledgeCategoryService $categories)
    {
        //
    }

    public function store(KnowledgeCategoryRequest $request, KnowledgeSubject $subject): RedirectResponse
    {
        $this->categories->create($subject, (string) $request->validated('title'), $request->category($subject, 'parent_id'));

        $this->toast('Categoria criada.');

        return back();
    }

    public function update(KnowledgeCategoryRequest $request, KnowledgeCategory $category): RedirectResponse
    {
        $this->categories->rename($category, (string) $request->validated('title'));

        $this->toast('Categoria renomeada.');

        return back();
    }

    public function move(KnowledgeCategoryMoveRequest $request, KnowledgeCategory $category): RedirectResponse
    {
        $this->categories->move($category, $request->category($category->subject, 'parent_id'));

        $this->toast('Categoria movida.');

        return back();
    }

    public function destroy(KnowledgeCategory $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $this->categories->delete($category);

        $this->toast($category->parent_id === null
            ? 'Categoria excluída. As páginas dela ficaram sem categoria.'
            : 'Categoria excluída. As páginas dela foram para a categoria de cima.');

        return back();
    }
}
