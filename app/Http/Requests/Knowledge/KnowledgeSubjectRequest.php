<?php

namespace App\Http\Requests\Knowledge;

use App\Enums\KnowledgeAttachmentKind;
use App\Enums\KnowledgeColor;
use App\Support\KnowledgeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a subject (book) with its optional cover image. The cover is
 * checked by content: only JPEG, PNG, WebP or GIF images within the limit.
 */
class KnowledgeSubjectRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'accent_color' => ['required', Rule::enum(KnowledgeColor::class)],
            'cover' => ['nullable', 'file', 'max:'.KnowledgeUpload::maxKilobytes(KnowledgeAttachmentKind::Image)],
            'remove_cover' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'description' => 'descrição',
            'accent_color' => 'cor',
            'cover' => 'capa',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cover.max' => 'A capa pode ter no máximo '.intdiv(KnowledgeUpload::maxKilobytes(KnowledgeAttachmentKind::Image), 1024).' MB.',
            'cover.uploaded' => 'Não foi possível enviar a capa. Verifique o tamanho do arquivo e tente de novo.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $cover = $this->file('cover');

            if ($cover instanceof UploadedFile && ! $validator->errors()->has('cover')) {
                $info = KnowledgeUpload::inspect($cover);

                if ($info === null || $info['kind'] !== KnowledgeAttachmentKind::Image) {
                    $validator->errors()->add('cover', 'A capa precisa ser uma imagem JPEG, PNG, WebP ou GIF.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->text('title'),
            'description' => $this->text('description') ?: null,
            'remove_cover' => $this->boolean('remove_cover'),
        ]);
    }

    /**
     * @return array{title: string, description: string|null, accent_color: KnowledgeColor}
     */
    public function subject(): array
    {
        return [
            'title' => (string) $this->validated('title'),
            'description' => $this->validated('description'),
            'accent_color' => KnowledgeColor::from((string) $this->validated('accent_color')),
        ];
    }

    public function cover(): ?UploadedFile
    {
        $cover = $this->file('cover');

        return $cover instanceof UploadedFile ? $cover : null;
    }
}
