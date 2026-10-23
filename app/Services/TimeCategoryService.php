<?php

namespace App\Services;

use App\Models\TimeCategory;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

class TimeCategoryService
{
    public function create(User $user, string $name): TimeCategory
    {
        return $user->timeCategories()->create(['name' => $name]);
    }

    public function rename(TimeCategory $category, string $name): TimeCategory
    {
        $category->update(['name' => $name]);

        return $category;
    }

    public function archive(TimeCategory $category): void
    {
        $category->update(['archived_at' => Date::now()]);
    }

    public function restore(TimeCategory $category): void
    {
        $category->update(['archived_at' => null]);
    }

    /**
     * Only categories without recorded time can be deleted; otherwise the
     * history must be kept and the category archived.
     */
    public function delete(TimeCategory $category): void
    {
        if ($category->logs()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Esta categoria tem horas ou notas registradas. Arquive-a para preservar o histórico.',
            ]);
        }

        $category->delete();
    }
}
