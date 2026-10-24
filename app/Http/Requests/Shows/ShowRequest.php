<?php

namespace App\Http\Requests\Shows;

use App\Http\Requests\Finance\FinanceRequest;
use App\Services\ShowService;
use App\Support\Day;

/**
 * Validates a show. Setlists may be sent as text with one song per line
 * (as typed in the form) or as a list; either way they are stored as a list
 * of trimmed, non-empty song titles.
 */
class ShowRequest extends FinanceRequest
{
    public const MAX_SONGS = 200;

    public const MAX_OPENING_ACTS = 10;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'band' => ['required', 'string', 'max:120'],
            'tour' => ['nullable', 'string', 'max:160'],
            'venue' => ['required', 'string', 'max:160'],
            'show_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Day::MIN, 'before_or_equal:'.Day::MAX],
            'setlist' => ['present', 'array', 'max:'.self::MAX_SONGS],
            'setlist.*' => ['required', 'string', 'max:200'],
            'photos_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'opening_acts' => ['present', 'array', 'max:'.self::MAX_OPENING_ACTS],
            'opening_acts.*.band' => ['required', 'string', 'max:120'],
            'opening_acts.*.setlist' => ['present', 'array', 'max:'.self::MAX_SONGS],
            'opening_acts.*.setlist.*' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'band' => 'banda',
            'tour' => 'nome da tour',
            'venue' => 'local',
            'show_date' => 'data',
            'setlist' => 'setlist',
            'setlist.*' => 'música',
            'photos_url' => 'link do álbum',
            'opening_acts' => 'shows de abertura',
            'opening_acts.*.band' => 'banda de abertura',
            'opening_acts.*.setlist' => 'setlist da abertura',
            'opening_acts.*.setlist.*' => 'música',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos_url.url' => 'Informe um link https válido, como o de compartilhamento do álbum do Google Fotos.',
            'setlist.max' => 'O setlist pode ter no máximo '.self::MAX_SONGS.' músicas.',
            'opening_acts.*.setlist.max' => 'O setlist pode ter no máximo '.self::MAX_SONGS.' músicas.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $acts = $this->input('opening_acts');

        $this->merge([
            'band' => $this->text('band'),
            'tour' => $this->text('tour') ?: null,
            'venue' => $this->text('venue'),
            'photos_url' => $this->text('photos_url') ?: null,
            'setlist' => self::songs($this->input('setlist')),
            'opening_acts' => is_array($acts) ? array_values(array_map(fn ($act) => is_array($act) ? [
                'band' => trim((string) ($act['band'] ?? '')),
                'setlist' => self::songs($act['setlist'] ?? null),
            ] : $act, $acts)) : [],
        ]);
    }

    /**
     * The validated show, ready for {@see ShowService}.
     *
     * @return array{band: string, tour: string|null, venue: string, show_date: string, setlist: list<string>, photos_url: string|null, opening_acts: list<array{band: string, setlist: list<string>}>}
     */
    public function show(): array
    {
        /** @var array{band: string, tour: string|null, venue: string, show_date: string, setlist: list<string>, photos_url: string|null, opening_acts: list<array{band: string, setlist: list<string>}>} */
        return $this->safe()->only(['band', 'tour', 'venue', 'show_date', 'setlist', 'photos_url', 'opening_acts']);
    }

    private function text(string $key): string
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Turn a setlist typed one song per line (or already a list) into a list
     * of song titles, dropping blank lines and list numbering like "1." or "2)".
     *
     * @return list<mixed>
     */
    public static function songs(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $songs = [];

        foreach ($value as $song) {
            if (! is_string($song)) {
                $songs[] = $song;

                continue;
            }

            $song = trim((string) preg_replace('/^\s*\d{1,3}\s*[.)\-–]\s+/u', '', $song));

            if ($song !== '') {
                $songs[] = $song;
            }
        }

        return $songs;
    }
}
