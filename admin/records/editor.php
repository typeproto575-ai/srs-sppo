<?php
/**
 * Unified entry point for sacrament-specific register editors.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$editorTypes = [
    'baptism' => 'baptismal.php',
    'confirmation' => 'confirmation.php',
    'first_communion' => 'first-communion.php',
    'marriage' => 'marriage.php',
];
$typeInput = $_GET['type'] ?? '';
$type = is_string($typeInput) ? $typeInput : '';
$editorFile = $editorTypes[$type] ?? null;

if ($editorFile === null) {
    redirect_to('/admin/records/index.php');
    exit;
}

if ((filter_var($_GET['person_id'] ?? null, FILTER_VALIDATE_INT) ?: 0) > 0
    && !isset($_GET['edit'])
    && !isset($_GET['new'])
    && !isset($_GET['from_application'])) {
    $_GET['new'] = '1';
}

$hasEditorAction = (filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0) > 0
    || (string) ($_GET['new'] ?? '') === '1'
    || (filter_var($_GET['from_application'] ?? null, FILTER_VALIDATE_INT) ?: 0) > 0
    || (filter_var($_GET['person_id'] ?? null, FILTER_VALIDATE_INT) ?: 0) > 0;
if (!$hasEditorAction) {
    redirect_to('/admin/records/index.php?type=' . rawurlencode($type === 'baptism' ? 'baptismal' : $type));
    exit;
}

if (!isset($_GET['return'])) {
    $recordType = $type === 'baptism' ? 'baptismal' : $type;
    if ((filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0) > 0) {
        $_GET['return'] = '/admin/records/view.php?' . http_build_query([
            'type' => $recordType,
            'id' => (int) $_GET['edit'],
            'return' => '/admin/records/index.php?type=' . $recordType,
        ]);
    } elseif ((filter_var($_GET['from_application'] ?? null, FILTER_VALIDATE_INT) ?: 0) > 0) {
        $_GET['return'] = '/admin/requests/applications-queue.php';
    } else {
        $_GET['return'] = '/admin/records/index.php?type=' . $recordType;
    }
}

define('SRS_UNIFIED_RECORD_EDITOR', true);
require __DIR__ . '/' . $editorFile;
