<?php

namespace App\Support;

/**
 * The document of a study page, as produced by the Tiptap editor
 * (ProseMirror JSON). Everything sent by the browser goes through
 * {@see self::sanitize()}, which rebuilds the tree keeping only the nodes,
 * marks and attributes the editor and the reader know about. Links keep only
 * http(s)/mailto targets and attachment blocks only ids the page owns, so the
 * stored document is safe to render as is.
 */
final class StudyContent
{
    /**
     * Allowed node types and the attributes each one keeps.
     */
    private const NODES = [
        'doc' => [],
        'paragraph' => [],
        'text' => [],
        'heading' => ['level'],
        'bulletList' => [],
        'orderedList' => ['start'],
        'listItem' => [],
        'blockquote' => [],
        'codeBlock' => ['language'],
        'horizontalRule' => [],
        'hardBreak' => [],
        'table' => [],
        'tableRow' => [],
        'tableHeader' => ['colspan', 'rowspan', 'colwidth'],
        'tableCell' => ['colspan', 'rowspan', 'colwidth'],
        'studyImage' => ['attachmentId', 'alt'],
        'studyFile' => ['attachmentId'],
    ];

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'link'];

    /** Nodes that reference an attachment through attrs.attachmentId. */
    public const ATTACHMENT_NODES = ['studyImage', 'studyFile'];

    /** Nodes whose text ends with a line break in the plain-text version. */
    private const BLOCKS = ['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock', 'tableRow', 'studyImage', 'studyFile'];

    private const MAX_DEPTH = 40;

    /**
     * An empty document.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    /**
     * Whether the value looks like an editor document at all.
     */
    public static function isDocument(mixed $value): bool
    {
        return is_array($value) && ($value['type'] ?? null) === 'doc';
    }

    /**
     * Rebuild the document keeping only known nodes, marks and attributes.
     * Attachment blocks whose id is not in $attachmentIds are dropped.
     *
     * @param  array<mixed>  $document
     * @param  array<int, int>  $attachmentIds
     * @return array<string, mixed>
     */
    public static function sanitize(array $document, array $attachmentIds): array
    {
        $allowed = array_flip($attachmentIds);
        $content = self::children($document, $allowed, 1);

        return ['type' => 'doc', 'content' => $content === [] ? [['type' => 'paragraph']] : $content];
    }

    /**
     * Ids of the attachments placed in the document, in reading order.
     *
     * @param  array<mixed>|null  $document
     * @return list<int>
     */
    public static function attachmentIds(?array $document): array
    {
        $ids = [];

        self::walk($document ?? [], function (array $node) use (&$ids) {
            if (in_array($node['type'] ?? null, self::ATTACHMENT_NODES, true) && is_int($node['attrs']['attachmentId'] ?? null)) {
                $ids[] = $node['attrs']['attachmentId'];
            }
        });

        return array_values(array_unique($ids));
    }

    /**
     * The document with attachment ids swapped according to the map (used
     * when a page is duplicated together with its files).
     *
     * @param  array<mixed>  $document
     * @param  array<int, int>  $map
     * @return array<mixed>
     */
    public static function remapAttachments(array $document, array $map): array
    {
        if (in_array($document['type'] ?? null, self::ATTACHMENT_NODES, true)) {
            $id = $document['attrs']['attachmentId'] ?? null;

            if (is_int($id) && isset($map[$id])) {
                $document['attrs']['attachmentId'] = $map[$id];
            }
        }

        if (isset($document['content']) && is_array($document['content'])) {
            $document['content'] = array_map(
                fn ($child) => is_array($child) ? self::remapAttachments($child, $map) : $child,
                $document['content'],
            );
        }

        return $document;
    }

    /**
     * Plain text of the document, one block per line, for search.
     *
     * @param  array<mixed>|null  $document
     */
    public static function text(?array $document): string
    {
        $text = self::textOf($document ?? []);
        $text = (string) preg_replace("/[ \t]+/u", ' ', $text);
        $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);

        return trim($text);
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<int, int>  $allowed
     * @return list<array<string, mixed>>
     */
    private static function children(array $node, array $allowed, int $depth): array
    {
        if (! isset($node['content']) || ! is_array($node['content']) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $children = [];

        foreach ($node['content'] as $child) {
            $clean = is_array($child) ? self::node($child, $allowed, $depth) : null;

            if ($clean !== null) {
                $children[] = $clean;
            }
        }

        return $children;
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<int, int>  $allowed
     * @return array<string, mixed>|null
     */
    private static function node(array $node, array $allowed, int $depth): ?array
    {
        $type = $node['type'] ?? null;

        if (! is_string($type) || ! array_key_exists($type, self::NODES) || $type === 'doc') {
            return null;
        }

        if ($type === 'text') {
            $text = $node['text'] ?? null;

            if (! is_string($text) || $text === '') {
                return null;
            }

            $clean = ['type' => 'text', 'text' => $text];
            $marks = self::marks($node['marks'] ?? null);

            return $marks === [] ? $clean : [...$clean, 'marks' => $marks];
        }

        $attrs = self::attrs($type, is_array($node['attrs'] ?? null) ? $node['attrs'] : []);

        if (in_array($type, self::ATTACHMENT_NODES, true) && ! isset($allowed[$attrs['attachmentId'] ?? -1])) {
            return null;
        }

        $clean = ['type' => $type];

        if ($attrs !== []) {
            $clean['attrs'] = $attrs;
        }

        $children = self::children($node, $allowed, $depth + 1);

        if ($children !== []) {
            $clean['content'] = $children;
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $attrs
     * @return array<string, mixed>
     */
    private static function attrs(string $type, array $attrs): array
    {
        $clean = [];

        foreach (self::NODES[$type] as $name) {
            $value = $attrs[$name] ?? null;

            $value = match ($name) {
                'level' => is_int($value) ? max(1, min(3, $value)) : 2,
                'start' => is_int($value) && $value > 0 ? min($value, 100_000) : null,
                'language' => is_string($value) && preg_match('/^[\w+#.-]{1,30}$/', $value) === 1 ? $value : null,
                'colspan', 'rowspan' => is_int($value) && $value > 1 ? min($value, 50) : null,
                'colwidth' => is_array($value) ? array_values(array_map(fn ($width) => is_int($width) ? max(0, min($width, 5000)) : 0, array_slice($value, 0, 50))) : null,
                'attachmentId' => is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null),
                'alt' => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 300) : null,
            };

            if ($value !== null) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function marks(mixed $marks): array
    {
        if (! is_array($marks)) {
            return [];
        }

        $clean = [];

        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if (! is_string($type) || ! in_array($type, self::MARKS, true) || isset($clean[$type])) {
                continue;
            }

            if ($type === 'link') {
                $href = self::safeHref($mark['attrs']['href'] ?? null);

                if ($href !== null) {
                    $clean[$type] = ['type' => 'link', 'attrs' => ['href' => $href]];
                }

                continue;
            }

            $clean[$type] = ['type' => $type];
        }

        return array_values($clean);
    }

    /**
     * Only web and e-mail links survive; javascript:, data: and friends do not.
     */
    public static function safeHref(mixed $href): ?string
    {
        if (! is_string($href)) {
            return null;
        }

        $href = trim($href);

        if ($href === '' || strlen($href) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return null;
        }

        return preg_match('#^(https?://|mailto:)#i', $href) === 1 ? $href : null;
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function textOf(array $node): string
    {
        $type = $node['type'] ?? null;

        if ($type === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }

        if ($type === 'hardBreak') {
            return "\n";
        }

        $text = '';

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $text .= self::textOf($child).(in_array($child['type'] ?? null, ['tableCell', 'tableHeader'], true) ? ' ' : '');
            }
        }

        if ($type === 'studyImage' && is_string($node['attrs']['alt'] ?? null)) {
            $text .= $node['attrs']['alt'];
        }

        return in_array($type, self::BLOCKS, true) ? $text."\n" : $text;
    }

    /**
     * @param  array<mixed>  $node
     * @param  callable(array<mixed>): void  $visit
     */
    private static function walk(array $node, callable $visit, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH + 1) {
            return;
        }

        $visit($node);

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                self::walk($child, $visit, $depth + 1);
            }
        }
    }
}
