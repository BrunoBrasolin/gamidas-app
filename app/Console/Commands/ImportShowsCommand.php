<?php

namespace App\Console\Commands;

use App\Http\Requests\Shows\ShowRequest;
use App\Models\Household;
use App\Services\ShowPageParser;
use App\Services\ShowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\select;

/**
 * Imports the shows listed on the old GitHub Pages site into a household.
 * Shows the household already has (same band and date) are skipped, so the
 * import can be run again safely.
 */
class ImportShowsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shows:import
        {household? : The household id (asked when there is more than one)}
        {--from=https://brunobrasolin.github.io/shows/ : URL or local path of the page}
        {--dry-run : List what would be imported without saving}';

    /**
     * @var string
     */
    protected $description = 'Import shows from the old GitHub Pages site';

    public function handle(ShowPageParser $parser, ShowService $shows): int
    {
        $household = $this->household();

        if ($household === null) {
            $this->components->error('Residência não encontrada.');

            return self::FAILURE;
        }

        $html = $this->read(is_string($this->option('from')) ? trim($this->option('from')) : '');

        if ($html === null) {
            return self::FAILURE;
        }

        $parsed = $parser->parse($html);

        if ($parsed === []) {
            $this->components->warn('Nenhum show encontrado na página.');

            return self::SUCCESS;
        }

        $imported = $skipped = $invalid = 0;

        foreach ($parsed as $show) {
            $label = "{$show['band']} — {$show['show_date']}";
            $validator = Validator::make($show, (new ShowRequest)->rules());

            if ($validator->fails()) {
                $this->components->error("{$label}: {$validator->errors()->first()}");
                $invalid++;

                continue;
            }

            $exists = $household->shows()
                ->where('band', $show['band'])
                ->whereDate('show_date', $show['show_date'])
                ->exists();

            if ($exists) {
                $this->components->twoColumnDetail($label, '<fg=yellow>já existe</>');
                $skipped++;

                continue;
            }

            if (! $this->option('dry-run')) {
                $shows->create($household, null, $show);
            }

            $acts = count($show['opening_acts']);
            $this->components->twoColumnDetail($label, count($show['setlist']).' músicas'.($acts > 0 ? ", {$acts} abertura(s)" : ''));
            $imported++;
        }

        $verb = $this->option('dry-run') ? 'seriam importados' : 'importados';
        $this->components->info("{$imported} {$verb} em \"{$household->name}\", {$skipped} já existiam, {$invalid} inválidos.");

        return $invalid > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The household given as argument, the only one there is, or the one
     * chosen from a list.
     */
    private function household(): ?Household
    {
        $id = $this->argument('household');

        if ($id !== null) {
            return is_numeric($id) ? Household::query()->find((int) $id) : null;
        }

        $households = Household::query()->orderBy('id')->pluck('name', 'id');

        if ($households->count() <= 1) {
            return Household::query()->first();
        }

        return Household::query()->find((int) select(label: 'Residência', options: $households->all()));
    }

    private function read(string $source): ?string
    {
        if (preg_match('#^https?://#i', $source) === 1) {
            $response = Http::timeout(20)->get($source);

            if ($response->failed()) {
                $this->components->error("Não foi possível baixar {$source} (HTTP {$response->status()}).");

                return null;
            }

            return $response->body();
        }

        if (! is_file($source) || ! is_readable($source)) {
            $this->components->error("Arquivo não encontrado: {$source}");

            return null;
        }

        return (string) file_get_contents($source);
    }
}
