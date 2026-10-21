<?php

namespace App\Console\Commands;

use App\Services\OccurrenceGenerator;
use App\Support\Month;
use Illuminate\Console\Command;

class GenerateRecurringExpensesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'expenses:generate {--through= : Generate up to this month (YYYY-MM)}';

    /**
     * @var string
     */
    protected $description = 'Generate the monthly occurrences of recurring expenses (idempotent)';

    public function handle(OccurrenceGenerator $generator): int
    {
        $through = $this->option('through');

        if ($through !== null && (! is_string($through) || preg_match(Month::PATTERN, $through) !== 1)) {
            $this->components->error('Use o formato AAAA-MM em --through.');

            return self::FAILURE;
        }

        $created = $generator->generateAll(is_string($through) ? Month::parse($through) : null);

        $this->components->info("{$created} lançamento(s) mensal(is) gerado(s).");

        return self::SUCCESS;
    }
}
