<?php

namespace App\Services;

use App\Http\Requests\Shows\ShowRequest;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reads shows from the HTML of the old GitHub Pages site (rendered from
 * Markdown). Each show is an <h1> "Banda | Tour" followed by a list with
 * "Data: dd/mm/aaaa", "Local: …" and "Setlist:" (an <ol>); each opening act
 * is a lower heading "Banda | Show Abertura" followed by its <ol>.
 *
 * @phpstan-import-type ShowData from ShowService
 */
class ShowPageParser
{
    /**
     * @return list<ShowData>
     */
    public function parse(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $root = $this->elements($xpath, '//article')[0] ?? $document->documentElement;

        $shows = [];
        $current = null;

        foreach ($root === null ? [] : $root->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if ($tag === 'h1') {
                if ($current !== null) {
                    $shows[] = $current;
                }

                [$band, $tour] = $this->splitTitle($this->text($node));
                $current = [
                    'band' => $band,
                    'tour' => $tour,
                    'venue' => '',
                    'show_date' => '',
                    'setlist' => [],
                    'photos_url' => null,
                    'opening_acts' => [],
                ];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^h[2-6]$/', $tag) === 1) {
                $current['opening_acts'][] = ['band' => $this->splitTitle($this->text($node))[0], 'setlist' => []];
            } elseif ($tag === 'ul') {
                $this->readDetails($xpath, $node, $current);
            } elseif ($tag === 'ol' && $current['opening_acts'] !== []) {
                $last = count($current['opening_acts']) - 1;
                $current['opening_acts'][$last] = ['band' => $current['opening_acts'][$last]['band'], 'setlist' => $this->songs($xpath, $node)];
            } elseif ($tag === 'ol') {
                $current['setlist'] = $this->songs($xpath, $node);
            }
        }

        if ($current !== null) {
            $shows[] = $current;
        }

        return $shows;
    }

    /**
     * @param  ShowData  $show
     */
    private function readDetails(DOMXPath $xpath, DOMElement $list, array &$show): void
    {
        foreach ($this->elements($xpath, './li', $list) as $item) {
            $label = $this->text($item);

            if (preg_match('/^Data:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/u', $label, $date) === 1) {
                $show['show_date'] = sprintf('%04d-%02d-%02d', $date[3], $date[2], $date[1]);
            } elseif (preg_match('/^Local:\s*(.+)$/u', $label, $venue) === 1) {
                $show['venue'] = trim($venue[1]);
            } elseif (preg_match('/^(Álbum|Album|Fotos):/iu', $label) === 1) {
                $link = $this->elements($xpath, './/a[@href]', $item)[0] ?? null;
                $show['photos_url'] = $link?->getAttribute('href');
            }

            $setlist = $this->elements($xpath, './ol', $item)[0] ?? null;

            if ($setlist !== null) {
                $show['setlist'] = $this->songs($xpath, $setlist);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function songs(DOMXPath $xpath, DOMElement $list): array
    {
        $songs = [];

        foreach ($this->elements($xpath, './li', $list) as $item) {
            $songs[] = $this->text($item);
        }

        /** @var list<string> */
        return ShowRequest::songs($songs);
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $elements = [];

        foreach ($xpath->query($query, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * "Banda | Tour" → [banda, tour]; "Show Abertura" is not a tour name.
     *
     * @return array{string, string|null}
     */
    private function splitTitle(string $title): array
    {
        $parts = array_map('trim', explode('|', $title, 2));
        $tour = $parts[1] ?? null;

        if ($tour === '' || ($tour !== null && preg_match('/^show\s+(de\s+)?abertura$/iu', $tour) === 1)) {
            $tour = null;
        }

        return [$parts[0], $tour];
    }

    private function text(DOMNode $node): string
    {
        // Only the node's own text, not the nested setlist of "Setlist:".
        $text = '';

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), ['ol', 'ul'], true)) {
                $text .= $child->textContent;
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
