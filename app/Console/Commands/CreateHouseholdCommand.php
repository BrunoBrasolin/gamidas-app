<?php

namespace App\Console\Commands;

use App\Services\HouseholdService;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

class CreateHouseholdCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'household:create {--name= : The household name}';

    /**
     * @var string
     */
    protected $description = 'Create a household';

    public function handle(HouseholdService $households): int
    {
        $name = $this->option('name');

        $name = is_string($name) && $name !== '' ? $name : text(
            label: 'Nome da residência',
            placeholder: 'Casa Bruno & Letícia',
            required: true,
            validate: fn (string $value) => mb_strlen($value) > 100 ? 'Use no máximo 100 caracteres.' : null,
        );

        $household = $households->create(trim($name));

        $this->components->info("Residência \"{$household->name}\" criada (id {$household->id}).");
        $this->line("Adicione os membros com: php artisan household:add-member {$household->id}");

        return self::SUCCESS;
    }
}
