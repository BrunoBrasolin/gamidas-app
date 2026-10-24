<?php

namespace Tests\Feature\Study;

use App\Models\StudyAttachment;
use App\Models\StudyPage;
use App\Models\StudySubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\CreatesStudyFiles;
use Tests\TestCase;

class StudyAttachmentTest extends TestCase
{
    use CreatesHousehold, CreatesStudyFiles, RefreshDatabase;

    private StudyPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createHousehold();
        $this->page = StudyPage::factory()->for(StudySubject::factory()->for($this->leticia), 'subject')->create();
    }

    public function test_images_are_uploaded_with_their_dimensions(): void
    {
        $response = $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $this->page), ['file' => $this->png('../../Célula "1".png', 640, 480)])
            ->assertCreated()
            ->assertJson(['kind' => 'image', 'mime' => 'image/png', 'width' => 640, 'height' => 480, 'name' => 'Célula 1.png']);

        $attachment = StudyAttachment::query()->sole();
        $this->assertSame($this->page->id, $attachment->page_id);
        $this->assertSame($this->page->subject_id, $attachment->subject_id);
        $this->assertSame(route('study.attachments.show', $attachment), $response->json('url'));
        $this->assertMatchesRegularExpression('#^study/'.$this->leticia->id.'/[0-9A-Z]{26}\.png$#', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_pdfs_are_uploaded_and_opened_inline_or_downloaded(): void
    {
        $id = $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $this->page), ['file' => $this->pdf('Apostila.pdf')])
            ->assertCreated()
            ->assertJson(['kind' => 'pdf', 'mime' => 'application/pdf', 'name' => 'Apostila.pdf'])
            ->json('id');

        $this->actingAs($this->leticia)
            ->get(route('study.attachments.show', $id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=Apostila.pdf');

        $this->actingAs($this->leticia)
            ->get(route('study.attachments.show', ['attachment' => $id, 'download' => 1]))
            ->assertOk()
            ->assertDownload('Apostila.pdf');
    }

    public function test_files_are_checked_by_content_not_by_name(): void
    {
        $fakes = [
            UploadedFile::fake()->createWithContent('foto.png', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->createWithContent('desenho.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->createWithContent('apostila.pdf', 'só texto'),
            UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;'),
        ];

        foreach ($fakes as $file) {
            $this->actingAs($this->leticia)
                ->postJson(route('study.attachments.store', $this->page), ['file' => $file])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file');
        }

        // A real PNG with a misleading name is stored as PNG.
        $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $this->page), ['file' => $this->png('notas.pdf')])
            ->assertCreated()
            ->assertJson(['kind' => 'image', 'name' => 'notas.png']);

        $this->assertSame(1, StudyAttachment::query()->count());
    }

    public function test_size_limits_are_enforced_per_kind(): void
    {
        config(['study.max_image_kb' => 1, 'study.max_pdf_kb' => 1000]);

        $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $this->page), ['file' => $this->png('grande.png', 200, 200)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'A imagem pode ter no máximo']);

        $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $this->page), ['file' => $this->pdf()])
            ->assertCreated();
    }

    public function test_files_are_only_served_to_their_owner(): void
    {
        $id = $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $this->page), ['file' => $this->png()])
            ->json('id');

        $this->actingAs($this->leticia)->get(route('study.attachments.show', $id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($this->bruno)->get(route('study.attachments.show', $id))->assertNotFound();

        auth()->logout();
        $this->get(route('study.attachments.show', $id))->assertRedirect(route('login'));
    }

    public function test_a_missing_file_answers_404(): void
    {
        $id = $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $this->page), ['file' => $this->png()])
            ->json('id');

        Storage::disk('local')->delete(StudyAttachment::query()->findOrFail($id)->path);

        $this->actingAs($this->leticia)->get(route('study.attachments.show', $id))->assertNotFound();
        $this->actingAs($this->leticia)->get(route('study.attachments.show', 999))->assertNotFound();
    }
}
