<?php

function redirect_legacy_record_editor(string $type): void
{
    if (defined('SRS_UNIFIED_RECORD_EDITOR')) {
        return;
    }

    $hasEditorAction = false;
    $params = ['type' => $type];
    foreach (['edit', 'new', 'from_application', 'person_id', 'return'] as $key) {
        if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
            continue;
        }
        $value = trim((string) $_GET[$key]);
        if ($value === '') {
            continue;
        }
        $params[$key] = $value;
        if ($key !== 'return') {
            $hasEditorAction = true;
        }
    }

    if ($hasEditorAction) {
        redirect_to('/admin/records/editor.php?' . http_build_query($params));
        exit;
    }

    $recordType = $type === 'baptism' ? 'baptismal' : $type;
    $indexParams = ['type' => $recordType];
    foreach (['added', 'updated', 'nochange', 'shifted', 'completed_app'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
            $indexParams[$key] = (string) $_GET[$key];
        }
    }
    redirect_to('/admin/records/index.php?' . http_build_query($indexParams));
    exit;
}
