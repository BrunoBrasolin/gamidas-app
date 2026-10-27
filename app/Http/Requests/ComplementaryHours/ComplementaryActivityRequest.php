<?php

namespace App\Http\Requests\ComplementaryHours;

use App\Enums\KnowledgeAttachmentKind;
use App\Http\Requests\Finance\FinanceRequest;
use App\Models\ComplementaryActivity;
use App\Support\Day;
use App\Support\Hours;
use App\Support\KnowledgeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * A complementary activity. The certificate is required when the activity is
 * created and optional (replaces the current one) when it is edited; its type
 * is detected from the file contents and only PDFs and images are accepted.
 */
class ComplementaryActivityRequest extends FinanceRequest
{
    /**
     * @var array{mime: string, extension: string}|null
     */
    private ?array $certificateInfo = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = ! $this->route('activity') instanceof ComplementaryActivity;

        return [
            'title' => ['required', 'string', 'max:160'],
            'completed_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.Day::MIN, 'before_or_equal:'.Day::MAX],
            'hours' => ['required', 'string', 'max:12'],
            'certificate' => [$creating ? 'required' : 'nullable', 'file', 'max:'.(int) config('complementary_hours.max_certificate_kb')],
            'report' => ['nullable', 'string', 'max:'.(int) config('complementary_hours.max_report_chars')],
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
            'certificate' => 'certificado',
            'report' => 'relatório',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'certificate.required' => 'Anexe o certificado da atividade.',
            'certificate.uploaded' => 'Não foi possível enviar o certificado. Ele pode ser maior que o permitido pelo servidor.',
            'certificate.max' => 'O certificado pode ter no máximo '.intdiv((int) config('complementary_hours.max_certificate_kb'), 1024).' MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'completed_on' => $this->input('completed_on') ?: null,
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

            $file = $this->file('certificate');

            if (! $file instanceof UploadedFile || $validator->errors()->has('certificate')) {
                return;
            }

            $info = KnowledgeUpload::inspect($file);

            if ($info === null || ! in_array($info['kind'], [KnowledgeAttachmentKind::Pdf, KnowledgeAttachmentKind::Image], true)) {
                $validator->errors()->add('certificate', 'Envie o certificado em PDF ou como imagem (JPEG, PNG, WebP ou GIF).');

                return;
            }

            $this->certificateInfo = ['mime' => $info['mime'], 'extension' => $info['extension']];
        }];
    }

    /**
     * @return array{title: string, completed_on: string|null, minutes: int, report: string|null}
     */
    public function activity(): array
    {
        return [
            'title' => (string) $this->validated('title'),
            'completed_on' => $this->validated('completed_on'),
            'minutes' => (int) Hours::parse((string) $this->validated('hours')),
            'report' => $this->nullableText('report'),
        ];
    }

    public function certificate(): ?UploadedFile
    {
        $file = $this->file('certificate');

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * @return array{mime: string, extension: string}|null
     */
    public function certificateInfo(): ?array
    {
        return $this->certificateInfo;
    }
}
