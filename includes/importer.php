<?php
/**
 * File import: validates an uploaded file and converts it to editor HTML.
 * Supports .txt, .md/.markdown and .docx using only built-in PHP extensions.
 * Every conversion result is passed through sanitize_html().
 */
declare(strict_types=1);

const IMPORT_MAX_BYTES = 2 * 1024 * 1024;
const IMPORT_EXTENSIONS = ['txt', 'md', 'markdown', 'docx'];
const DOCX_MAX_XML_BYTES = 20 * 1024 * 1024;
const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

/**
 * Validate an uploaded file and convert it.
 *
 * @param array $file An entry from $_FILES.
 * @return array{title: string, html: string}
 */
function import_uploaded_file(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new HttpError(413, 'That file is larger than the 2 MB limit.');
    }
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new HttpError(400, 'Choose a file to import.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new HttpError(400, 'The upload did not complete. Please try again.');
    }
    return import_file((string) $file['tmp_name'], (string) ($file['name'] ?? ''));
}

/**
 * Convert a file on disk. Separate from the upload wrapper so it can be tested.
 *
 * @return array{title: string, html: string}
 */
function import_file(string $path, string $originalName): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, IMPORT_EXTENSIONS, true)) {
        throw new HttpError(415, 'Unsupported file type. Import a .txt, .md or .docx file.');
    }

    $size = filesize($path);
    if ($size === false || $size === 0) {
        throw new HttpError(422, 'That file is empty.');
    }
    if ($size > IMPORT_MAX_BYTES) {
        throw new HttpError(413, 'That file is larger than the 2 MB limit.');
    }

    $mime = detect_mime($path);
    if ($extension === 'docx') {
        $allowed = ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'];
        if ($mime !== null && !in_array($mime, $allowed, true)) {
            throw new HttpError(415, 'That file does not look like a Word document.');
        }
        $html = docx_to_html($path);
    } else {
        if ($mime !== null && !str_starts_with($mime, 'text/') && $mime !== 'application/x-empty') {
            throw new HttpError(415, 'That file does not contain plain text.');
        }
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text); // UTF-8 BOM
        if (str_contains($text, "\0") || !preg_match('//u', $text)) {
            throw new HttpError(422, 'That file is not valid UTF-8 text.');
        }
        $html = $extension === 'txt' ? text_to_html($text) : markdown_to_html($text);
    }

    $html = sanitize_html($html);
    if (html_to_text($html) === '') {
        throw new HttpError(422, 'That file has no readable text to import.');
    }

    return ['title' => title_from_filename($originalName), 'html' => $html];
}

function detect_mime(string $path): ?string
{
    if (!class_exists('finfo')) {
        return null;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    return $mime ?: null;
}

function title_from_filename(string $name): string
{
    $title = pathinfo($name, PATHINFO_FILENAME);
    $title = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title) ?? '';
    $title = trim((string) preg_replace('/[\s_]+/u', ' ', $title));
    if ($title === '') {
        return 'Imported document';
    }
    return utf8_length($title) > DOCUMENT_TITLE_MAX ? mb_substr_safe($title, DOCUMENT_TITLE_MAX) : $title;
}

function mb_substr_safe(string $value, int $length): string
{
    preg_match('/^.{0,' . $length . '}/su', $value, $m);
    return $m[0] ?? '';
}

function text_to_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $html = '';
    foreach (preg_split('/\n\s*\n/', $text) as $paragraph) {
        if (trim($paragraph) === '') {
            continue;
        }
        $html .= '<p>' . str_replace("\n", '<br>', e(trim($paragraph, "\n"))) . '</p>';
    }
    return $html;
}

/**
 * Minimal, safe Markdown converter: headings, paragraphs, bullet and numbered
 * lists, blockquotes, fenced code (as plain text), bold, italic and inline
 * code. Text is escaped before any markup is added. Nested lists are
 * flattened; links keep their text and URL as plain text.
 */
function markdown_to_html(string $markdown): string
{
    $lines = preg_split('/\R/', $markdown);
    $html = '';
    $paragraph = [];
    $quote = [];
    $listType = null;
    $items = [];
    $inFence = false;

    $flushParagraph = function () use (&$paragraph, &$html) {
        if ($paragraph) {
            $html .= '<p>' . markdown_inline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        }
    };
    $flushQuote = function () use (&$quote, &$html) {
        if ($quote) {
            $html .= '<blockquote><p>' . markdown_inline(implode(' ', $quote)) . '</p></blockquote>';
            $quote = [];
        }
    };
    $flushList = function () use (&$listType, &$items, &$html) {
        if ($listType) {
            $html .= "<$listType>";
            foreach ($items as $item) {
                $html .= '<li>' . markdown_inline($item) . '</li>';
            }
            $html .= "</$listType>";
        }
        $listType = null;
        $items = [];
    };
    $flushAll = function () use ($flushParagraph, $flushQuote, $flushList) {
        $flushParagraph();
        $flushQuote();
        $flushList();
    };

    foreach ($lines as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line)) {
            $flushAll();
            $inFence = !$inFence;
            continue;
        }
        if ($inFence) {
            if (trim($line) !== '') {
                $html .= '<p>' . e($line) . '</p>';
            }
            continue;
        }
        if (trim($line) === '') {
            $flushAll();
            continue;
        }
        if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
            $flushAll();
            $level = min(strlen($m[1]), 3);
            $html .= "<h$level>" . markdown_inline($m[2]) . "</h$level>";
            continue;
        }
        if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line)) {
            $flushAll(); // horizontal rule: treated as a section break
            continue;
        }
        if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
            $flushParagraph();
            $flushQuote();
            if ($listType !== 'ul') {
                $flushList();
                $listType = 'ul';
            }
            $items[] = $m[1];
            continue;
        }
        if (preg_match('/^\s*\d{1,9}[.)]\s+(.*)$/', $line, $m)) {
            $flushParagraph();
            $flushQuote();
            if ($listType !== 'ol') {
                $flushList();
                $listType = 'ol';
            }
            $items[] = $m[1];
            continue;
        }
        if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m)) {
            $flushParagraph();
            $flushList();
            $quote[] = $m[1];
            continue;
        }
        if ($listType && preg_match('/^\s+\S/', $line)) {
            $items[count($items) - 1] .= ' ' . trim($line); // list item continuation
            continue;
        }
        $flushList();
        $flushQuote();
        $paragraph[] = trim($line);
    }
    $flushAll();

    return $html;
}

function markdown_inline(string $text): string
{
    $text = e($text);
    // Images and links: keep readable text, never create live links.
    $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/', '$1 ($2)', $text);
    $text = preg_replace('/`([^`]+)`/', '$1', $text);
    $text = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/', '<strong>$2</strong>', $text);
    $text = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $text);
    $text = preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/', '<em>$1</em>', $text);
    return (string) $text;
}

/**
 * Extract paragraphs, headings (Heading 1-6 / Title styles), bullet and
 * numbered lists, and bold/italic/underline runs from a .docx file.
 * Tables are flattened to paragraphs; images, fonts, colours and layout are
 * not imported.
 */
function docx_to_html(string $path): string
{
    if (!class_exists('ZipArchive')) {
        throw new HttpError(415, '.docx import is not available on this server (PHP zip extension missing).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new HttpError(422, 'That .docx file is damaged or not a Word document.');
    }

    $readXml = function (string $name) use ($zip): ?DOMDocument {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > DOCX_MAX_XML_BYTES) {
            throw new HttpError(413, 'That .docx file is too large to import.');
        }
        $xml = $zip->getFromName($name);
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $xml !== false && $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $doc : null;
    };

    $document = $readXml('word/document.xml');
    if ($document === null) {
        $zip->close();
        throw new HttpError(422, 'That .docx file is damaged or not a Word document.');
    }
    $numberingFormats = docx_numbering_formats($readXml('word/numbering.xml'));
    $zip->close();

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', WORD_NS);

    $html = '';
    $listType = null;
    foreach ($xpath->query('//w:body//w:p') as $p) {
        $style = (string) $xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
        $numId = (string) $xpath->evaluate('string(w:pPr/w:numPr/w:numId/@w:val)', $p);
        $level = (string) $xpath->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $p) ?: '0';

        $inline = '';
        foreach ($xpath->query('.//w:r', $p) as $run) {
            $inline .= docx_run_html($xpath, $run);
        }
        if (trim(strip_tags(str_replace('<br>', '', $inline))) === '') {
            continue;
        }

        $isList = $numId !== '' && $numId !== '0';
        if ($isList) {
            $type = ($numberingFormats[$numId][$level] ?? 'bullet') === 'bullet' ? 'ul' : 'ol';
            if ($listType !== $type) {
                $html .= $listType ? "</$listType>" : '';
                $html .= "<$type>";
                $listType = $type;
            }
            $html .= "<li>$inline</li>";
            continue;
        }
        if ($listType) {
            $html .= "</$listType>";
            $listType = null;
        }

        if (preg_match('/^(?:Heading|berschrift|Titre)\s*([1-6])$/i', $style, $m)) {
            $tag = 'h' . min((int) $m[1], 3);
        } elseif (strcasecmp($style, 'Title') === 0) {
            $tag = 'h1';
        } elseif (strcasecmp($style, 'Subtitle') === 0) {
            $tag = 'h2';
        } elseif (stripos($style, 'Quote') !== false) {
            $html .= "<blockquote><p>$inline</p></blockquote>";
            continue;
        } else {
            $tag = 'p';
        }
        $html .= "<$tag>$inline</$tag>";
    }
    if ($listType) {
        $html .= "</$listType>";
    }
    return $html;
}

function docx_run_html(DOMXPath $xpath, DOMElement $run): string
{
    $text = '';
    foreach ($run->childNodes as $child) {
        if (!$child instanceof DOMElement || $child->namespaceURI !== WORD_NS) {
            continue;
        }
        if ($child->localName === 't') {
            $text .= e($child->textContent);
        } elseif ($child->localName === 'tab') {
            $text .= ' ';
        } elseif ($child->localName === 'br' || $child->localName === 'cr') {
            $text .= '<br>';
        }
    }
    if ($text === '') {
        return '';
    }

    $isOn = function (string $property) use ($xpath, $run): bool {
        $nodes = $xpath->query("w:rPr/w:$property", $run);
        if ($nodes->length === 0) {
            return false;
        }
        $val = strtolower($nodes->item(0)->getAttributeNS(WORD_NS, 'val'));
        return !in_array($val, ['0', 'false', 'none'], true);
    };

    if ($isOn('u')) {
        $text = "<u>$text</u>";
    }
    if ($isOn('i')) {
        $text = "<em>$text</em>";
    }
    if ($isOn('b')) {
        $text = "<strong>$text</strong>";
    }
    return $text;
}

/** Map numId => [ilvl => numFmt] so lists import as bulleted or numbered. */
function docx_numbering_formats(?DOMDocument $numbering): array
{
    if ($numbering === null) {
        return [];
    }
    $xpath = new DOMXPath($numbering);
    $xpath->registerNamespace('w', WORD_NS);

    $abstract = [];
    foreach ($xpath->query('//w:abstractNum') as $node) {
        $id = $node->getAttributeNS(WORD_NS, 'abstractNumId');
        foreach ($xpath->query('w:lvl', $node) as $lvl) {
            $abstract[$id][$lvl->getAttributeNS(WORD_NS, 'ilvl')] =
                (string) $xpath->evaluate('string(w:numFmt/@w:val)', $lvl);
        }
    }
    $formats = [];
    foreach ($xpath->query('//w:num') as $num) {
        $abstractId = (string) $xpath->evaluate('string(w:abstractNumId/@w:val)', $num);
        $formats[$num->getAttributeNS(WORD_NS, 'numId')] = $abstract[$abstractId] ?? [];
    }
    return $formats;
}
