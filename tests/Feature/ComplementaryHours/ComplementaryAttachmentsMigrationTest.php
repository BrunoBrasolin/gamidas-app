<?php

namespace Tests\Feature\ComplementaryHours;

use App\Models\ComplementaryActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class ComplementaryAttachmentsMigrationTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_08_000001_add_attachments_to_complementary_activities.php';

    public function test_certificates_and_written_reports_become_attachments(): void
    {
        Storage::fake('local');
        $this->createHousehold();

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('complementary_activities', 'report'));

        $row = fn (string $title, ?string $report) => [
            'user_id' => $this->bruno->id,
            'title' => $title,
            'minutes' => 60,
            'certificate_disk' => 'local',
            'certificate_path' => 'complementary-hours/'.$this->bruno->id.'/'.$title.'.pdf',
            'certificate_filename' => 'Certificado '.$title.'.pdf',
            'certificate_mime' => 'application/pdf',
            'certificate_size' => 1234,
            'report' => $report,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('complementary_activities')->insert([
            $row('Palestra', "## O que aprendi\n\n- Testes"),
            $row('Monitoria', '  '),
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('complementary_activities', 'report'));
        $this->assertFalse(Schema::hasColumn('complementary_activities', 'certificate_path'));

        $lecture = ComplementaryActivity::query()->where('title', 'Palestra')->sole();
        $this->assertTrue($lecture->report_done);
        $attachments = $lecture->attachments()->orderBy('id')->get();
        $this->assertSame(['certificate', 'report'], $attachments->map(fn ($a) => $a->kind->value)->all());
        $this->assertSame('Certificado Palestra.pdf', $attachments[0]->filename);
        $this->assertSame('complementary-hours/'.$this->bruno->id.'/Palestra.pdf', $attachments[0]->path);
        $this->assertSame('relatorio-palestra.md', $attachments[1]->filename);
        $this->assertSame("## O que aprendi\n\n- Testes", Storage::disk('local')->get($attachments[1]->path));

        $tutoring = ComplementaryActivity::query()->where('title', 'Monitoria')->sole();
        $this->assertFalse($tutoring->report_done);
        $this->assertSame(['certificate'], $tutoring->attachments()->get()->map(fn ($a) => $a->kind->value)->all());
    }
}
