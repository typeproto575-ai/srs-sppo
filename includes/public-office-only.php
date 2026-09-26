<?php
/**
 * Public workflow guard: all requests/applications are encoded onsite by
 * the parish secretary. Direct legacy form routes now redirect visitors to
 * the requirements page instead of accepting online submissions.
 */
require_once __DIR__ . '/functions.php';
$notice = rawurlencode('Requests and applications are processed at the parish office. Please review the requirements and visit the secretary.');
redirect_to('/public/requirements.php?office=1&notice=' . $notice);
