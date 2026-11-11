<?php

namespace App\Services\Attention;

use App\Enums\AttentionType;
use App\Models\AttentionGame;
use App\Support\AttentionFormat;
use App\Support\PdfIcons;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The report page of a room as a PDF: the same cards (numbers, performance by
 * kind of attention, confusion table and the players with each of their
 * challenges), drawn by resources/views/attention/report-pdf.blade.php.
 */
class AttentionPdf
{
    /**
     * Players listed with each of their challenges. Drawing a PDF costs memory
     * per player (about 1 MB), so a crowded room lists the rest by their totals.
     */
    public const DETAIL_LIMIT = 100;

    private const MEMORY = '512M';

    public function render(AttentionGame $game): string
    {
        $this->allowHeavyWork();

        $report = new AttentionReport($game);

        $html = view('attention.report-pdf', [
            'game' => $game->loadMissing('creator'),
            'summary' => $report->summary(),
            'types' => $report->types(),
            'players' => $report->players(),
            'detailLimit' => self::DETAIL_LIMIT,
            'typeList' => AttentionType::cases(),
            'format' => AttentionFormat::class,
            'icons' => PdfIcons::class,
        ])->render();

        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper('a4');
        $dompdf->loadHtml($html);
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 120,
            $canvas->get_height() - 28,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $font,
            7,
            [0.45, 0.45, 0.45],
        );

        return (string) $dompdf->output();
    }

    /**
     * Dompdf keeps the whole document in memory. Shared hosting often starts
     * PHP with less than a big room needs, so ask for more (a host that caps
     * it just ignores the request).
     */
    private function allowHeavyWork(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit !== '-1' && ini_parse_quantity($limit) < ini_parse_quantity(self::MEMORY)) {
            @ini_set('memory_limit', self::MEMORY);
        }

        @set_time_limit(120);
    }

    private function options(): Options
    {
        // Compiled font data goes to storage: the vendor directory is replaced on every deploy.
        $cache = storage_path('framework/cache/dompdf');

        if (! is_dir($cache)) {
            mkdir($cache, 0775, true);
        }

        return new Options([
            'defaultFont' => 'DejaVu Sans',
            'tempDir' => $cache,
            'fontCache' => $cache,
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ]);
    }
}
