<?php

namespace App\Console\Commands;

use App\Services\Study\StudyAttachmentService;
use Illuminate\Console\Command;

class PruneStudyAttachmentsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'study:prune-attachments';

    /**
     * @var string
     */
    protected $description = 'Delete study files removed from their page and files without attachment';

    public function handle(StudyAttachmentService $attachments): int
    {
        $count = $attachments->prune();

        $this->components->info("{$count} arquivo(s) de estudo removido(s).");

        return self::SUCCESS;
    }
}
