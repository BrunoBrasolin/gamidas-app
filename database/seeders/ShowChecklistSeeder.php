<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\ShowChecklistItem;
use App\Services\ShowChecklistService;
use Illuminate\Database\Seeder;

/**
 * Fills the household's show checklist with what we take to every show.
 *
 * Items the checklist already has (ignoring case and accents) are kept as
 * they are and the missing ones are added at the end, so it can run again.
 *
 * Run with: php artisan db:seed --class=ShowChecklistSeeder --force
 */
class ShowChecklistSeeder extends Seeder
{
    public const ITEMS = [
        'Capa de chuva',
        'Protetor de ouvido',
        'Cartão físico',
        'Meia extra',
        'Power bank (carregado)',
        'Carregador Power Bank',
        'CNH',
        'Água congelada (para a volta)',
        'Comida de volta',
        'Óculos de sol',
        'Cartão do estádio',
        'Guardanapo',
        'Espaço celular esposa',
        'Leque',
        'Protetor solar',
        'Remédio dor de cabeça',
    ];

    public function __construct(private readonly ShowChecklistService $checklist)
    {
        //
    }

    public function run(): void
    {
        $household = Household::query()->sole();

        $current = $household->showChecklistItems()->get()
            ->map(fn (ShowChecklistItem $item) => $item->label)->all();

        $known = array_map(ShowChecklistService::key(...), $current);
        $missing = array_values(array_filter(
            static::ITEMS,
            fn (string $item) => ! in_array(ShowChecklistService::key($item), $known, true),
        ));

        $this->checklist->sync($household, [...$current, ...$missing]);

        $this->command?->info(count($missing).' itens adicionados ao checklist de shows');
    }
}
