<?php

namespace App\Services;

use App\Models\ComplementaryActivity;
use App\Support\Hours;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Renders the report of a complementary activity as a PDF. The Markdown is
 * converted with raw HTML stripped and unsafe links dropped, and Dompdf never
 * fetches remote resources, so a report cannot make the server load URLs.
 */
class ComplementaryReportPdf
{
    public function render(ComplementaryActivity $activity): string
    {
        $fontCache = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($fontCache);

        $options = new Options([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'fontCache' => $fontCache,
            'tempDir' => $fontCache,
        ]);

        $html = view('complementary-hours.report-pdf', [
            'activity' => $activity,
            'student' => $activity->user->name,
            'hours' => Hours::format($activity->minutes),
            'body' => Str::markdown((string) $activity->report, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        ])->render();

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * "relatorio-semana-de-tecnologia.pdf"
     */
    public function filename(ComplementaryActivity $activity): string
    {
        $slug = Str::slug($activity->title);

        return 'relatorio-'.($slug === '' ? $activity->id : Str::limit($slug, 80, '')).'.pdf';
    }
}
