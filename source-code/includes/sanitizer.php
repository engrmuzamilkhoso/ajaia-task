<?php
/**
 * Allowlist HTML sanitizer for document content.
 *
 * Only the tags the editor toolbar can produce survive, and every attribute is
 * removed, so no scripts, event handlers, styles, links or embeds can be
 * stored. Runs on every save, on import, and again when rendering.
 */
declare(strict_types=1);

const SANITIZER_ALLOWED = ['p', 'br', 'strong', 'em', 'u', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote'];

// Equivalent tags normalised to the allowed set.
const SANITIZER_RENAME = [
    'b' => 'strong', 'i' => 'em', 'div' => 'p',
    'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3',
];

// Removed together with everything inside them.
const SANITIZER_DROP = [
    'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
    'template', 'noscript', 'svg', 'math', 'head', 'title', 'meta', 'link', 'base',
    'form', 'input', 'button', 'textarea', 'select', 'option', 'img', 'video', 'audio',
    'canvas', 'source', 'track', 'picture',
];

function sanitize_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = null;
    foreach ($doc->childNodes as $node) {
        if ($node instanceof DOMElement) {
            $root = $node;
            break;
        }
    }
    if ($root === null) {
        return '';
    }

    sanitize_children($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

function sanitize_children(DOMNode $parent): void
{
    foreach (iterator_to_array($parent->childNodes) as $node) {
        if ($node instanceof DOMText && !($node instanceof DOMCdataSection)) {
            continue;
        }
        if (!$node instanceof DOMElement) {
            // Comments, CDATA, processing instructions.
            $parent->removeChild($node);
            continue;
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, SANITIZER_DROP, true)) {
            $parent->removeChild($node);
            continue;
        }

        sanitize_children($node);

        $target = SANITIZER_RENAME[$tag] ?? $tag;
        if (!in_array($target, SANITIZER_ALLOWED, true)) {
            // Unknown wrapper (span, font, a, table…): keep its content only.
            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);
            continue;
        }

        // Re-create the element so no attribute of any kind survives.
        $clean = $node->ownerDocument->createElement($target);
        while ($node->firstChild) {
            $clean->appendChild($node->firstChild);
        }
        $parent->replaceChild($clean, $node);
    }
}

/** Plain-text preview of document HTML, for list snippets. */
function html_to_text(string $html): string
{
    $text = preg_replace('/<(br|\/p|\/h[1-3]|\/li|\/blockquote)>/i', ' ', $html);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}
