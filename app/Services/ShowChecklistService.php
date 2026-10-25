<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Show;
use App\Models\ShowChecklistItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The household's show checklist. It is edited as a whole list of labels:
 * items whose label is kept (ignoring case and accents) keep what was already
 * checked on each show, new labels become new items and missing ones are
 * removed with their checks.
 */
class ShowChecklistService
{
    /**
     * @param  list<string>  $labels
     */
    public function sync(Household $household, array $labels): void
    {
        DB::transaction(function () use ($household, $labels) {
            $existing = $household->showChecklistItems()->get()
                ->keyBy(fn (ShowChecklistItem $item) => self::key($item->label));

            $kept = [];

            foreach (array_values($labels) as $position => $label) {
                $item = $existing->get(self::key($label));

                if ($item === null) {
                    $item = $household->showChecklistItems()->create(['position' => $position, 'label' => $label]);
                } else {
                    $item->update(['position' => $position, 'label' => $label]);
                }

                $kept[] = $item->id;
            }

            $household->showChecklistItems()->whereNotIn('id', $kept)->delete();
        });
    }

    public function check(Show $show, ShowChecklistItem $item, bool $checked): void
    {
        if ($checked) {
            $show->checkedItems()->syncWithoutDetaching([$item->id]);
        } else {
            $show->checkedItems()->detach($item->id);
        }
    }

    /**
     * Labels are compared ignoring case, accents and extra spaces.
     */
    public static function key(string $label): string
    {
        return Str::lower(Str::ascii(Str::squish($label)));
    }
}
