<?php
/**
 * Inline SVG icons (stroke style, 24x24 viewBox). No icon font or CDN needed.
 */
declare(strict_types=1);

const ICON_PATHS = [
    'plus'      => '<path d="M12 5v14M5 12h14"/>',
    'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
    'search'    => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
    'doc'       => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
    'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
    'lock'      => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'share'     => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
    'back'      => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
    'trash'     => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
    'bold'      => '<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/>',
    'italic'    => '<path d="M19 4h-9M14 20H5M15 4L9 20"/>',
    'underline' => '<path d="M6 4v6a6 6 0 0 0 12 0V4M4 20h16"/>',
    'ul'        => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
    'ol'        => '<path d="M10 6h11M10 12h11M10 18h11M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
    'quote'     => '<path d="M6 17h3l2-4V7H5v6h3zM14 17h3l2-4V7h-6v6h3z"/>',
    'undo'      => '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
    'redo'      => '<path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h3"/>',
    'clear'     => '<path d="M4 7V4h16v3M9 20h6M12 4v16"/><path d="M3 3l18 18"/>',
    'check'     => '<path d="M20 6L9 17l-5-5"/>',
    'alert'     => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
    'x'         => '<path d="M18 6L6 18M6 6l12 12"/>',
    'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'swap'      => '<path d="M16 3l4 4-4 4M20 7H4M8 21l-4-4 4-4M4 17h16"/>',
    'chevron'   => '<path d="M6 9l6 6 6-6"/>',
    'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    'cloud'     => '<path d="M17.5 19H9a7 7 0 1 1 6.7-9h1.8a4.5 4.5 0 0 1 0 9z"/>',
];

function icon(string $name, string $class = 'icon'): string
{
    $paths = ICON_PATHS[$name] ?? '';
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
}
