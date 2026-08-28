<?php
// DELETED: Priest removed as system user. Marriage approval is now handled
// by the Secretary. Redirect stub so old links don't 404; helpers loaded
// for a subfolder-safe redirect (base_path()).
require_once __DIR__ . '/../includes/functions.php';
redirect_to('/admin/requests/applications-queue.php');
