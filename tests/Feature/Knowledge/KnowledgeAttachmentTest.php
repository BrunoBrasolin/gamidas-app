<?php

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeAttachment;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\CreatesKnowledgeFiles;
use Tests\TestCase;

class KnowledgeAttachmentTest extends TestCase
{
    use CreatesHousehold, CreatesKnowledgeFiles, RefreshDatabase;

    private KnowledgePage $page;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createHousehold();
        $this->page = KnowledgePage::factory()->for(KnowledgeSubject::factory()->for($this->leticia), 'subject')->create();
    }

    public function test_images_are_uploaded_with_their_dimensions(): void
    {
        $response = $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->png('../../Célula "1".png', 640, 480)])
            ->assertCreated()
            ->assertJson(['kind' => 'image', 'mime' => 'image/png', 'width' => 640, 'height' => 480, 'name' => 'Célula 1.png']);

        $attachment = KnowledgeAttachment::query()->sole();
        $this->assertSame($this->page->id, $attachment->page_id);
        $this->assertSame($this->page->subject_id, $attachment->subject_id);
        $this->assertSame(route('knowledge.attachments.show', $attachment), $response->json('url'));
        $this->assertMatchesRegularExpression('#^knowledge/'.$this->leticia->id.'/[0-9A-Z]{26}\.png$#', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_pdfs_are_uploaded_and_opened_inline_or_downloaded(): void
    {
        $id = $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->pdf('Apostila.pdf')])
            ->assertCreated()
            ->assertJson(['kind' => 'pdf', 'mime' => 'application/pdf', 'name' => 'Apostila.pdf'])
            ->json('id');

        $this->actingAs($this->leticia)
            ->get(route('knowledge.attachments.show', $id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=Apostila.pdf');

        $this->actingAs($this->leticia)
            ->get(route('knowledge.attachments.show', ['attachment' => $id, 'download' => 1]))
            ->assertOk()
            ->assertDownload('Apostila.pdf');
    }

    public function test_office_files_are_detected_by_the_parts_inside_them(): void
    {
        $cases = [
            ['Aula 1.pptx', 'ppt/presentation.xml', 'slides', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'Aula 1.pptx'],
            ['Resumo.docx', 'word/document.xml', 'document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Resumo.docx'],
            ['Notas.xlsx', 'xl/workbook.xml', 'sheet', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Notas.xlsx'],
            // The name does not matter: a presentation called .zip is a presentation.
            ['slides.zip', 'ppt/presentation.xml', 'slides', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'slides.pptx'],
        ];

        foreach ($cases as [$name, $part, $kind, $mime, $stored]) {
            $id = $this->actingAs($this->leticia)
                ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->office($name, $part)])
                ->assertCreated()
                ->assertJson(['kind' => $kind, 'mime' => $mime, 'name' => $stored, 'reading_page' => null, 'page_count' => null])
                ->json('id');

            $this->actingAs($this->leticia)
                ->get(route('knowledge.attachments.show', $id))
                ->assertOk()
                ->assertHeader('Content-Type', $mime)
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        // Any other ZIP, or a package without content types, is refused.
        foreach ([$this->office('aula.pptx', null), $this->office('aula.pptx', 'ppt/presentation.xml', contentTypes: false)] as $file) {
            $this->actingAs($this->leticia)
                ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $file])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['file' => 'PowerPoint, Word ou Excel']);
        }
    }

    public function test_the_reader_remembers_the_page_of_a_file(): void
    {
        $pdf = $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->pdf()])
            ->json('id');

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.attachments.reading', $pdf), ['page' => 12, 'pages' => 40])
            ->assertOk();

        $attachment = KnowledgeAttachment::query()->findOrFail($pdf);
        $this->assertSame([12, 40], [$attachment->reading_page, $attachment->page_count]);

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.attachments.reading', $pdf), ['page' => 41, 'pages' => 40])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.attachments.reading', $pdf), ['page' => 0, 'pages' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page', 'pages']);

        // Images and spreadsheets are not read page by page.
        $image = $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->png()])
            ->json('id');

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.attachments.reading', $image), ['page' => 1, 'pages' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        // Someone else's file is not found.
        $this->actingAs($this->bruno)
            ->putJson(route('knowledge.attachments.reading', $pdf), ['page' => 1, 'pages' => 40])
            ->assertNotFound();

        $this->assertSame(12, $attachment->fresh()?->reading_page);
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
                ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $file])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file');
        }

        // A real PNG with a misleading name is stored as PNG.
        $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->png('notas.pdf')])
            ->assertCreated()
            ->assertJson(['kind' => 'image', 'name' => 'notas.png']);

        $this->assertSame(1, KnowledgeAttachment::query()->count());
    }

    public function test_size_limits_are_enforced_per_kind(): void
    {
        config(['knowledge.max_image_kb' => 1, 'knowledge.max_file_kb' => 1000]);

        $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->png('grande.png', 200, 200)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'A imagem pode ter no máximo']);

        $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->pdf()])
            ->assertCreated();
    }

    public function test_files_are_only_served_to_their_owner(): void
    {
        $id = $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->png()])
            ->json('id');

        $this->actingAs($this->leticia)->get(route('knowledge.attachments.show', $id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($this->bruno)->get(route('knowledge.attachments.show', $id))->assertNotFound();

        auth()->logout();
        $this->get(route('knowledge.attachments.show', $id))->assertRedirect(route('login'));
    }

    public function test_a_missing_file_answers_404(): void
    {
        $id = $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $this->page), ['file' => $this->png()])
            ->json('id');

        Storage::disk('local')->delete(KnowledgeAttachment::query()->findOrFail($id)->path);

        $this->actingAs($this->leticia)->get(route('knowledge.attachments.show', $id))->assertNotFound();
        $this->actingAs($this->leticia)->get(route('knowledge.attachments.show', 999))->assertNotFound();
    }
}
