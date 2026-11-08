<?php

namespace Tests\Feature\ComplementaryHours;

use App\Enums\ComplementaryAttachmentKind;
use App\Models\ComplementaryActivity;
use App\Models\ComplementaryActivityAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\CreatesKnowledgeFiles;
use Tests\TestCase;

class ComplementaryHoursTest extends TestCase
{
    use CreatesHousehold, CreatesKnowledgeFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createHousehold();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => '  Semana de Tecnologia ',
            'completed_on' => '2026-09-14',
            'hours' => '1:30',
            'report_done' => '0',
            'attachments' => [
                ['kind' => 'certificate', 'file' => $this->pdf('Certificado.pdf')],
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createActivity(array $overrides = []): ComplementaryActivity
    {
        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return ComplementaryActivity::query()->latest('id')->firstOrFail();
    }

    public function test_the_goal_is_set_per_user(): void
    {
        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.goal.update'), ['hours' => '200'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('complementary-hours.index'));

        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.goal.update'), ['hours' => '120,5'])
            ->assertSessionHasNoErrors();

        $this->assertSame(7230, $this->bruno->complementaryHoursGoal?->required_minutes);
        $this->assertNull($this->leticia->complementaryHoursGoal);

        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.goal.update'), ['hours' => '0'])
            ->assertSessionHasErrors('hours');
    }

    public function test_activities_are_created_with_several_attachments(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), [
                ...$this->payload([
                    'report_done' => '1',
                    'attachments' => [
                        ['kind' => 'certificate', 'file' => $this->pdf('Certificado.pdf')],
                        ['kind' => 'report', 'file' => $this->office('Relatório.docx', 'word/document.xml')],
                        ['kind' => 'other', 'file' => $this->png('foto.png')],
                    ],
                ]),
                'user_id' => $this->leticia->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('complementary-hours.show', ComplementaryActivity::query()->sole()));

        $activity = ComplementaryActivity::query()->sole();
        $this->assertSame($this->bruno->id, $activity->user_id);
        $this->assertSame('Semana de Tecnologia', $activity->title);
        $this->assertSame('2026-09-14', $activity->completed_on?->toDateString());
        $this->assertSame(90, $activity->minutes);
        $this->assertTrue($activity->report_done);

        $attachments = $activity->attachments()->orderBy('id')->get();
        $this->assertSame(
            [
                ['certificate', 'Certificado.pdf', 'application/pdf'],
                ['report', 'Relatório.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                ['other', 'foto.png', 'image/png'],
            ],
            $attachments->map(fn (ComplementaryActivityAttachment $a) => [$a->kind->value, $a->filename, $a->mime])->all(),
        );

        foreach ($attachments as $attachment) {
            $this->assertMatchesRegularExpression('#^complementary-hours/'.$this->bruno->id.'/[0-9A-Z]{26}\.(pdf|docx|png)$#', $attachment->path);
            Storage::disk('local')->assertExists($attachment->path);
        }
    }

    public function test_a_certificate_is_required_and_must_be_a_pdf_or_an_image(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['attachments' => []]))
            ->assertSessionHasErrors(['attachments' => 'Anexe o certificado da atividade.']);

        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['attachments' => [
                ['kind' => 'report', 'file' => $this->pdf('relatorio.pdf')],
            ]]))
            ->assertSessionHasErrors(['attachments' => 'Anexe o certificado da atividade.']);

        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['attachments' => [
                ['kind' => 'certificate', 'file' => UploadedFile::fake()->createWithContent('cert.pdf', 'not a pdf')],
            ]]))
            ->assertSessionHasErrors('attachments.0.file');

        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['attachments' => [
                ['kind' => 'certificate', 'file' => $this->office('cert.docx', 'word/document.xml')],
            ]]))
            ->assertSessionHasErrors('attachments.0.file');

        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['attachments' => [
                ['kind' => 'certificate', 'file' => $this->pdf()],
                ['kind' => 'diploma', 'file' => $this->pdf()],
            ]]))
            ->assertSessionHasErrors('attachments.1.kind');

        $this->assertSame(0, ComplementaryActivity::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->createActivity(['attachments' => [['kind' => 'certificate', 'file' => $this->png('foto.png')]]]);
        $this->assertSame('image/png', ComplementaryActivityAttachment::query()->sole()->mime);
    }

    public function test_title_and_hours_are_validated_and_the_date_is_optional(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['title' => ' ', 'hours' => 'muitas']))
            ->assertSessionHasErrors(['title', 'hours']);

        $activity = $this->createActivity(['completed_on' => '']);
        $this->assertNull($activity->completed_on);
        $this->assertFalse($activity->report_done);
    }

    public function test_the_listing_shows_progress_and_which_activities_have_the_report_pending(): void
    {
        $this->actingAs($this->bruno)->put(route('complementary-hours.goal.update'), ['hours' => '10']);
        $this->createActivity(['title' => 'Palestra', 'hours' => '2', 'completed_on' => '2026-03-01', 'report_done' => '1']);
        $this->createActivity(['title' => 'Monitoria', 'hours' => '4:30', 'completed_on' => '2026-05-01']);

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('complementary-hours/index')
                ->where('summary', [
                    'required_minutes' => 600,
                    'done_minutes' => 390,
                    'remaining_minutes' => 210,
                    'activities' => 2,
                    'without_report' => 1,
                ])
                ->where('activities.0.title', 'Monitoria')
                ->where('activities.0.report_done', false)
                ->has('activities.0.attachments', 1)
                ->where('activities.1.title', 'Palestra')
                ->where('activities.1.report_done', true));

        $this->actingAs($this->leticia)
            ->get(route('complementary-hours.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities', 0)
                ->where('summary.required_minutes', null)
                ->where('summary.done_minutes', 0));
    }

    public function test_activities_are_updated_adding_and_removing_attachments(): void
    {
        $activity = $this->createActivity();
        $certificate = $activity->attachments()->sole();

        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.update', $activity), [
                'title' => 'Semana de TI',
                'completed_on' => '2026-09-15',
                'hours' => '3',
                'report_done' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('complementary-hours.show', $activity));

        $activity->refresh();
        $this->assertSame('Semana de TI', $activity->title);
        $this->assertSame(180, $activity->minutes);
        $this->assertTrue($activity->report_done);
        $this->assertSame([$certificate->id], $activity->attachments()->pluck('id')->all());

        // The only certificate cannot go without another one.
        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.update', $activity), [
                'title' => 'Semana de TI',
                'hours' => '3',
                'remove_attachments' => [$certificate->id],
            ])
            ->assertSessionHasErrors(['attachments' => 'Anexe o certificado da atividade.']);

        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.update', $activity), [
                'title' => 'Semana de TI',
                'hours' => '3',
                'attachments' => [
                    ['kind' => 'certificate', 'file' => $this->png('novo.png')],
                    ['kind' => 'report', 'file' => $this->pdf('relatorio.pdf')],
                ],
                'remove_attachments' => [$certificate->id],
            ])
            ->assertSessionHasNoErrors();

        $activity->refresh();
        $this->assertFalse($activity->report_done);
        $this->assertSame(['novo.png', 'relatorio.pdf'], $activity->attachments()->orderBy('id')->pluck('filename')->all());
        $this->assertModelMissing($certificate);
        Storage::disk('local')->assertMissing($certificate->path);
        Storage::disk('local')->assertExists($activity->attachments()->pluck('path')->all());
    }

    public function test_attachments_of_another_activity_are_not_removed(): void
    {
        $mine = $this->createActivity();
        $other = $this->createActivity(['title' => 'Outra']);
        $otherAttachment = $other->attachments()->sole();

        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.update', $mine), [
                'title' => 'Semana',
                'hours' => '1',
                'remove_attachments' => [$otherAttachment->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertModelExists($otherAttachment);
        Storage::disk('local')->assertExists($otherAttachment->path);
    }

    public function test_an_activity_keeps_at_most_the_configured_attachments(): void
    {
        config(['complementary_hours.max_attachments' => 2]);
        $activity = $this->createActivity();

        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.update', $activity), [
                'title' => 'Semana',
                'hours' => '1',
                'attachments' => [
                    ['kind' => 'other', 'file' => $this->pdf()],
                    ['kind' => 'other', 'file' => $this->pdf()],
                ],
            ])
            ->assertSessionHasErrors(['attachments' => 'Uma atividade pode ter no máximo 2 anexos.']);

        $this->assertSame(1, $activity->attachments()->count());
    }

    public function test_deleting_an_activity_removes_its_files(): void
    {
        $activity = $this->createActivity(['attachments' => [
            ['kind' => 'certificate', 'file' => $this->pdf()],
            ['kind' => 'report', 'file' => $this->pdf()],
        ]]);
        $paths = $activity->attachments()->pluck('path')->all();

        $this->actingAs($this->bruno)
            ->delete(route('complementary-hours.destroy', $activity))
            ->assertRedirect(route('complementary-hours.index'));

        $this->assertModelMissing($activity);
        $this->assertSame(0, ComplementaryActivityAttachment::query()->count());
        Storage::disk('local')->assertMissing($paths);
    }

    public function test_attachments_are_served_inline_or_downloaded(): void
    {
        $activity = $this->createActivity();
        $attachment = $activity->attachments()->sole();

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.attachments.show', [$activity, $attachment]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=Certificado.pdf');

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.attachments.show', [$activity, $attachment, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=Certificado.pdf');

        // An attachment is only reachable through its own activity.
        $other = $this->createActivity(['title' => 'Outra']);

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.attachments.show', [$other, $attachment]))
            ->assertNotFound();
    }

    public function test_activities_are_private_to_their_owner(): void
    {
        $activity = $this->createActivity();
        $attachment = $activity->attachments()->sole();

        foreach ([
            fn () => $this->get(route('complementary-hours.show', $activity)),
            fn () => $this->get(route('complementary-hours.edit', $activity)),
            fn () => $this->get(route('complementary-hours.attachments.show', [$activity, $attachment])),
            fn () => $this->put(route('complementary-hours.update', $activity), ['title' => 'X', 'hours' => '1', 'remove_attachments' => [$attachment->id]]),
            fn () => $this->delete(route('complementary-hours.destroy', $activity)),
        ] as $request) {
            $this->actingAs($this->leticia);
            $request()->assertNotFound();
        }

        $this->assertSame('Semana de Tecnologia', $activity->fresh()?->title);
        $this->assertModelExists($attachment);
    }

    public function test_the_pages_render(): void
    {
        $activity = $this->createActivity(['attachments' => [
            ['kind' => 'other', 'file' => $this->png()],
            ['kind' => 'certificate', 'file' => $this->pdf()],
        ]]);

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('complementary-hours/form')
                ->where('activity', null)
                ->where('maxAttachmentMb', 10)
                ->where('maxUploads', 10)
                ->where('maxAttachments', 20));

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.show', $activity))
            ->assertInertia(fn (Assert $page) => $page
                ->component('complementary-hours/show')
                ->where('activity.attachments.0.kind', ComplementaryAttachmentKind::Certificate->value)
                ->where('activity.attachments.0.is_pdf', true)
                ->where('activity.attachments.1.kind', 'other')
                ->where('activity.attachments.1.is_image', true));

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.edit', $activity))
            ->assertInertia(fn (Assert $page) => $page->component('complementary-hours/form')->where('activity.minutes', 90));
    }
}
