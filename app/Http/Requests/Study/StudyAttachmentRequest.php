<?php

namespace App\Http\Requests\Study;

use App\Enums\StudyAttachmentKind;
use App\Support\StudyUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * An image or PDF uploaded into a page. The type is detected from the file
 * contents; the name and MIME type sent by the browser are not trusted.
 */
class StudyAttachmentRequest extends StudyRequest
{
    /**
     * @var array{kind: StudyAttachmentKind, mime: string, extension: string, width: int|null, height: int|null}|null
     */
    private ?array $info = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = max(StudyUpload::maxKilobytes(StudyAttachmentKind::Image), StudyUpload::maxKilobytes(StudyAttachmentKind::Pdf));

        return ['file' => ['required', 'file', 'max:'.$max]];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'arquivo'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Escolha um arquivo para enviar.',
            'file.uploaded' => 'Não foi possível enviar o arquivo. Ele pode ser maior que o permitido pelo servidor.',
            'file.max' => 'O arquivo é grande demais.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $file = $this->file('file');

            if (! $file instanceof UploadedFile || $validator->errors()->has('file')) {
                return;
            }

            $info = StudyUpload::inspect($file);

            if ($info === null) {
                $validator->errors()->add('file', 'Envie uma imagem (JPEG, PNG, WebP ou GIF) ou um PDF.');

                return;
            }

            $max = StudyUpload::maxKilobytes($info['kind']);

            if ((int) $file->getSize() > $max * 1024) {
                $label = $info['kind'] === StudyAttachmentKind::Image ? 'A imagem' : 'O PDF';
                $validator->errors()->add('file', $label.' pode ter no máximo '.intdiv($max, 1024).' MB.');

                return;
            }

            $this->info = $info;
        }];
    }

    public function upload(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('file');
    }

    /**
     * @return array{kind: StudyAttachmentKind, mime: string, extension: string, width: int|null, height: int|null}
     */
    public function info(): array
    {
        /** @var array{kind: StudyAttachmentKind, mime: string, extension: string, width: int|null, height: int|null} */
        return $this->info;
    }
}
