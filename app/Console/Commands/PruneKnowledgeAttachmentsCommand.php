<?php

namespace App\Console\Commands;

use App\Services\Knowledge\KnowledgeAttachmentService;
use Illuminate\Console\Command;

class PruneKnowledgeAttachmentsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'knowledge:prune-attachments';

    /**
     * @var string
     */
    protected $description = 'Delete knowledge files removed from their page and files without attachment';

    public function handle(KnowledgeAttachmentService $attachments): int
    {
        $count = $attachments->prune();

        $this->components->info("{$count} arquivo(s) da Base de Conhecimento removido(s).");

        return self::SUCCESS;
    }
}
