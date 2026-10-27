<?php

namespace Tests\Feature\ComplementaryHours;

use App\Models\ComplementaryActivity;
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
            'certificate' => $this->pdf('Certificado.pdf'),
            'report' => "## O que aprendi\n\n- Testes",
            ...$overrides,
        ];
    }

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

    public function test_activities_are_created_with_their_certificate(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), [...$this->payload(), 'user_id' => $this->leticia->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('complementary-hours.show', ComplementaryActivity::query()->sole()));

        $activity = ComplementaryActivity::query()->sole();
        $this->assertSame($this->bruno->id, $activity->user_id);
        $this->assertSame('Semana de Tecnologia', $activity->title);
        $this->assertSame('2026-09-14', $activity->completed_on?->toDateString());
        $this->assertSame(90, $activity->minutes);
        $this->assertSame("## O que aprendi\n\n- Testes", $activity->report);
        $this->assertSame('Certificado.pdf', $activity->certificate_filename);
        $this->assertSame('application/pdf', $activity->certificate_mime);
        $this->assertMatchesRegularExpression('#^complementary-hours/'.$this->bruno->id.'/[0-9A-Z]{26}\.pdf$#', $activity->certificate_path);
        Storage::disk('local')->assertExists($activity->certificate_path);
    }

    public function test_the_certificate_is_required_and_must_be_a_pdf_or_an_image(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['certificate' => null]))
            ->assertSessionHasErrors(['certificate' => 'Anexe o certificado da atividade.']);

        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['certificate' => UploadedFile::fake()->createWithContent('cert.pdf', 'not a pdf')]))
            ->assertSessionHasErrors('certificate');

        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['certificate' => $this->office('cert.docx', 'word/document.xml')]))
            ->assertSessionHasErrors('certificate');

        $this->assertSame(0, ComplementaryActivity::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->createActivity(['certificate' => $this->png('foto.png')]);
        $this->assertSame('image/png', ComplementaryActivity::query()->sole()->certificate_mime);
    }

    public function test_title_and_hours_are_validated_and_the_report_and_date_are_optional(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('complementary-hours.store'), $this->payload(['title' => ' ', 'hours' => 'muitas']))
            ->assertSessionHasErrors(['title', 'hours']);

        $activity = $this->createActivity(['report' => "  \n ", 'completed_on' => '']);
        $this->assertNull($activity->report);
        $this->assertNull($activity->completed_on);
        $this->assertFalse($activity->hasReport());
    }

    public function test_the_listing_shows_progress_and_which_activities_have_no_report(): void
    {
        $this->actingAs($this->bruno)->put(route('complementary-hours.goal.update'), ['hours' => '10']);
        $this->createActivity(['title' => 'Palestra', 'hours' => '2', 'completed_on' => '2026-03-01']);
        $this->createActivity(['title' => 'Monitoria', 'hours' => '4:30', 'report' => '', 'completed_on' => '2026-05-01']);

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
                ->where('activities.0.has_report', false)
                ->where('activities.0.report', null)
                ->where('activities.1.title', 'Palestra')
                ->where('activities.1.has_report', true));

        $this->actingAs($this->leticia)
            ->get(route('complementary-hours.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities', 0)
                ->where('summary.required_minutes', null)
                ->where('summary.done_minutes', 0));
    }

    public function test_activities_are_updated_and_the_certificate_replaced(): void
    {
        $activity = $this->createActivity();
        $oldPath = $activity->certificate_path;

        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.update', $activity), [
                'title' => 'Semana de TI',
                'completed_on' => '2026-09-15',
                'hours' => '3',
                'report' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('complementary-hours.show', $activity));

        $activity->refresh();
        $this->assertSame('Semana de TI', $activity->title);
        $this->assertSame(180, $activity->minutes);
        $this->assertNull($activity->report);
        $this->assertSame($oldPath, $activity->certificate_path);

        $this->actingAs($this->bruno)
            ->put(route('complementary-hours.update', $activity), [
                'title' => 'Semana de TI',
                'hours' => '3',
                'certificate' => $this->png('novo.png'),
            ])
            ->assertSessionHasNoErrors();

        $activity->refresh();
        $this->assertSame('novo.png', $activity->certificate_filename);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($activity->certificate_path);
    }

    public function test_deleting_an_activity_removes_its_certificate(): void
    {
        $activity = $this->createActivity();

        $this->actingAs($this->bruno)
            ->delete(route('complementary-hours.destroy', $activity))
            ->assertRedirect(route('complementary-hours.index'));

        $this->assertModelMissing($activity);
        Storage::disk('local')->assertMissing($activity->certificate_path);
    }

    public function test_the_certificate_is_served_inline_or_downloaded(): void
    {
        $activity = $this->createActivity();

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.certificate', $activity))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=Certificado.pdf');

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.certificate', [$activity, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=Certificado.pdf');
    }

    public function test_the_report_is_exported_as_pdf(): void
    {
        $activity = $this->createActivity(['report' => "# Relatório\n\nAprendi **muito** <script>alert(1)</script>"]);

        $response = $this->actingAs($this->bruno)
            ->get(route('complementary-hours.report', $activity))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="relatorio-semana-de-tecnologia.pdf"');

        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

        $withoutReport = $this->createActivity(['report' => '']);

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.report', $withoutReport))
            ->assertNotFound();
    }

    public function test_activities_are_private_to_their_owner(): void
    {
        $activity = $this->createActivity();

        foreach ([
            fn () => $this->get(route('complementary-hours.show', $activity)),
            fn () => $this->get(route('complementary-hours.edit', $activity)),
            fn () => $this->get(route('complementary-hours.certificate', $activity)),
            fn () => $this->get(route('complementary-hours.report', $activity)),
            fn () => $this->put(route('complementary-hours.update', $activity), ['title' => 'X', 'hours' => '1']),
            fn () => $this->delete(route('complementary-hours.destroy', $activity)),
        ] as $request) {
            $this->actingAs($this->leticia);
            $request()->assertNotFound();
        }

        $this->assertSame('Semana de Tecnologia', $activity->fresh()?->title);
    }

    public function test_the_pages_render(): void
    {
        $activity = $this->createActivity();

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.create'))
            ->assertInertia(fn (Assert $page) => $page->component('complementary-hours/form')->where('activity', null)->where('maxCertificateMb', 10));

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.show', $activity))
            ->assertInertia(fn (Assert $page) => $page
                ->component('complementary-hours/show')
                ->where('activity.report', "## O que aprendi\n\n- Testes")
                ->where('activity.certificate.is_image', false));

        $this->actingAs($this->bruno)
            ->get(route('complementary-hours.edit', $activity))
            ->assertInertia(fn (Assert $page) => $page->component('complementary-hours/form')->where('activity.minutes', 90));
    }
}
