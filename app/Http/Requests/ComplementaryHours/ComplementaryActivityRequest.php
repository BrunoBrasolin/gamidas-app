<?php

namespace App\Http\Requests\ComplementaryHours;

use App\Enums\ComplementaryAttachmentKind;
use App\Http\Requests\Finance\FinanceRequest;
use App\Models\ComplementaryActivity;
use App\Support\Day;
use App\Support\Hours;
use App\Support\KnowledgeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A complementary activity with the files sent along (each marked as
 * certificate, report or other) and, when editing, the attachments to remove.
 * File types are detected from the contents; the activity must keep at least
 * one certificate.
 *
 * @phpstan-type Upload array{kind: ComplementaryAttachmentKind, file: UploadedFile, mime: string, extension: string}
 */
class ComplementaryActivityRequest extends FinanceRequest
{
    /**
     * @var list<Upload>
     */
    private array $uploads = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'completed_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.Day::MIN, 'before_or_equal:'.Day::MAX],
            'hours' => ['required', 'string', 'max:12'],
            'report_done' => ['boolean'],
            'attachments' => ['array', 'max:'.(int) config('complementary_hours.max_uploads')],
            'attachments.*.kind' => ['required', Rule::enum(ComplementaryAttachmentKind::class)],
            'attachments.*.file' => ['required', 'file', 'max:'.(int) config('complementary_hours.max_attachment_kb')],
            'remove_attachments' => ['array'],
            'remove_attachments.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'completed_on' => 'data',
            'hours' => 'horas',
            'report_done' => 'relatório preenchido',
            'attachments' => 'anexos',
            'attachments.*.kind' => 'tipo do anexo',
            'attachments.*.file' => 'arquivo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachments.max' => 'Envie no máximo :max arquivos de uma vez.',
            'attachments.*.file.required' => 'Escolha o arquivo.',
            'attachments.*.file.uploaded' => 'Não foi possível enviar o arquivo. Ele pode ser maior que o permitido pelo servidor.',
            'attachments.*.file.max' => 'Cada arquivo pode ter no máximo '.intdiv((int) config('complementary_hours.max_attachment_kb'), 1024).' MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'completed_on' => $this->input('completed_on') ?: null,
            'report_done' => $this->boolean('report_done'),
        ]);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('hours') && Hours::parse((string) $this->input('hours')) === null) {
                $validator->errors()->add('hours', 'Informe as horas como 4, 1,5 ou 1:30.');
            }

            if ($validator->errors()->hasAny(['attachments', 'attachments.*'])) {
                return;
            }

            $this->inspectUploads($validator);

            if ($validator->errors()->has('attachments.*')) {
                return;
            }

            $this->checkTotals($validator);
        }];
    }

    private function inspectUploads(Validator $validator): void
    {
        /** @var array<int|string, mixed> $entries */
        $entries = (array) $this->input('attachments', []);

        foreach (array_keys($entries) as $index) {
            $file = $this->file("attachments.{$index}.file");
            $kind = ComplementaryAttachmentKind::from((string) $this->input("attachments.{$index}.kind"));

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $info = KnowledgeUpload::inspect($file);

            if ($info === null || ! $kind->accepts($info['kind'])) {
                $validator->errors()->add("attachments.{$index}.file", $kind->invalidTypeMessage());

                continue;
            }

            $this->uploads[] = ['kind' => $kind, 'file' => $file, 'mime' => $info['mime'], 'extension' => $info['extension']];
        }
    }

    /**
     * The activity keeps at least one certificate and at most the configured
     * number of attachments.
     */
    private function checkTotals(Validator $validator): void
    {
        $activity = $this->route('activity');
        $existing = $activity instanceof ComplementaryActivity
            ? $activity->attachments()->whereNotIn('id', $this->removedAttachmentIds())->get(['id', 'kind'])
            : collect();

        $certificates = $existing->where('kind', ComplementaryAttachmentKind::Certificate)->count()
            + count(array_filter($this->uploads, fn (array $upload) => $upload['kind'] === ComplementaryAttachmentKind::Certificate));

        if ($certificates === 0) {
            $validator->errors()->add('attachments', 'Anexe o certificado da atividade.');
        }

        $max = (int) config('complementary_hours.max_attachments');

        if ($existing->count() + count($this->uploads) > $max) {
            $validator->errors()->add('attachments', "Uma atividade pode ter no máximo {$max} anexos.");
        }
    }

    /**
     * @return array{title: string, completed_on: string|null, minutes: int, report_done: bool}
     */
    public function activity(): array
    {
        return [
            'title' => (string) $this->validated('title'),
            'completed_on' => $this->validated('completed_on'),
            'minutes' => (int) Hours::parse((string) $this->validated('hours')),
            'report_done' => (bool) $this->validated('report_done'),
        ];
    }

    /**
     * The files sent, in order, with the type detected from their contents.
     *
     * @return list<Upload>
     */
    public function uploads(): array
    {
        return $this->uploads;
    }

    /**
     * @return list<int>
     */
    public function removedAttachmentIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('remove_attachments', [])));
    }
}
