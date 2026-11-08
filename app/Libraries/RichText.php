<?php

namespace App\Libraries;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Sanitises HTML from the Quill editors down to the formatting its toolbar
 * can produce, so stored rich text is safe to render back on a page.
 */
final class RichText
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'u', 'ol', 'ul', 'li'];
    private const DROPPED_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'template'];

    /**
     * Returns the cleaned HTML, or '' when it has no visible text.
     *
     * @param string|null $html What the editor posted (any HTML; untrusted)
     *
     * @return string Safe HTML using only ALLOWED_TAGS, or '' for an empty editor (e.g. "<p><br></p>")
     */
    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        // Parse as a fragment inside a wrapper <div>, in UTF-8, without letting
        // malformed HTML raise warnings.
        $doc      = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }
        self::sanitise($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim(html_entity_decode(strip_tags($out))) === '' ? '' : trim($out);
    }

    /**
     * Walks $node's children in place: keeps text and ALLOWED_TAGS (without
     * their attributes, except Quill's data-list on <li>), deletes comments and
     * DROPPED_TAGS with their content, and unwraps any other element so its
     * text survives.
     *
     * @param DOMNode $node Changed in place, recursively
     */
    private static function sanitise(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType !== XML_TEXT_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROPPED_TAGS, true)) {
                $node->removeChild($child);

                continue;
            }

            self::sanitise($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unknown wrapper (e.g. Quill's <span class="ql-ui">): keep its text.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attr) {
                // Quill 2 marks bullet vs numbered items with data-list on <li>.
                $keep = $tag === 'li' && $attr->name === 'data-list' && in_array($attr->value, ['bullet', 'ordered'], true);
                if (! $keep) {
                    $child->removeAttribute($attr->name);
                }
            }
        }
    }
}
