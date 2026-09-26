<?php
/**
 * =====================================================================
 * SRS-SPPO PARISH NOTICE COMPONENT
 * =====================================================================
 * Purpose: Renders a styled notice card to provide disclaimers, 
 *          instructions, or warnings consistently across public pages.
 * =====================================================================
 */

/**
 * Renders a styled notice card.
 *
 * @param string $title The heading of the notice.
 * @param string $message The body text of the notice.
 * @param string $type   The style type: 'info' (default), 'warning', or 'important'.
 * @return string         The generated HTML.
 */
function render_parish_notice($title, $message, $type = 'info') {
    // Map types to CSS classes
    $typeClass = 'notice-info';
    if ($type === 'warning') {
        $typeClass = 'notice-warning';
    } elseif ($type === 'important') {
        $typeClass = 'notice-important';
    }

    return "
    <div class=\"parish-notice {$typeClass}\">
        <div class=\"notice-icon\">
            " . get_notice_icon($type) . "
        </div>
        <div class=\"notice-content\">
            <h4 class=\"notice-title\">" . e($title) . "</h4>
            <p class=\"notice-message\">" . e($message) . "</p>
        </div>
    </div>";
}

/**
 * Returns the SVG icon based on the notice type.
 */
function get_notice_icon($type) {
    switch ($type) {
        case 'warning':
            return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
        case 'important':
            return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
        case 'info':
        default:
            return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
    }
}
