<?php
/** Shared secretary-side requirements checklist and provenance controls. */
$workflowEntityType = (string) ($workflowEntityType ?? '');
$workflowEntityId = (int) ($workflowEntityId ?? 0);
$workflowSacramentType = (string) ($workflowSacramentType ?? '');
$workflowRequirements = [];
$workflowMeta = ['requirements_source' => 'not_yet_received', 'missing_requirements_note' => ''];
if ($workflowEntityType !== '' && $workflowEntityId > 0) {
    ensure_workflow_requirements($pdo, $workflowEntityType, $workflowEntityId, $workflowSacramentType);
    $workflowRequirements = workflow_requirements_for($pdo, $workflowEntityType, $workflowEntityId);
    $workflowTable = $workflowEntityType === 'certificate_request' ? 'certificate_requests' : 'sacrament_applications';
    $metaStmt = $pdo->prepare("SELECT requirements_source, missing_requirements_note FROM {$workflowTable} WHERE id = ?");
    $metaStmt->execute([$workflowEntityId]);
    $workflowMeta = array_merge($workflowMeta, $metaStmt->fetch() ?: []);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_requirements' && csrf_verify()) {
        $statuses = ['not_received', 'received', 'under_review', 'incomplete', 'verified', 'not_applicable'];
        $posted = is_array($_POST['requirement_status'] ?? null) ? $_POST['requirement_status'] : [];
        $notes = is_array($_POST['requirement_notes'] ?? null) ? $_POST['requirement_notes'] : [];
        $update = $pdo->prepare('UPDATE workflow_requirements SET status = ?, notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND entity_type = ? AND entity_id = ?');
        foreach ($workflowRequirements as $item) {
            $id = (int) $item['id'];
            $status = (string) ($posted[$id] ?? $item['status']);
            if (!in_array($status, $statuses, true)) continue;
            $note = trim((string) ($notes[$id] ?? ''));
            $update->execute([$status, $note !== '' ? $note : null, current_user()['id'], $id, $workflowEntityType, $workflowEntityId]);
        }
        $sourceOptions = ['not_yet_received', 'physical', 'official_gmail', 'physical_and_gmail'];
        $source = (string) ($_POST['requirements_source'] ?? 'not_yet_received');
        if (!in_array($source, $sourceOptions, true)) $source = 'not_yet_received';
        $missing = trim((string) ($_POST['missing_requirements_note'] ?? ''));
        $metaUpdate = $pdo->prepare("UPDATE {$workflowTable} SET requirements_source = ?, missing_requirements_note = ?, requirements_reviewed_by = ?, requirements_reviewed_at = NOW() WHERE id = ?");
        $metaUpdate->execute([$source, $missing !== '' ? $missing : null, current_user()['id'], $workflowEntityId]);
        log_activity($pdo, current_user()['id'], 'workflow_requirements_updated', $workflowEntityType, $workflowEntityId, 'Requirements checklist and provenance updated.');
        $workflowRequirements = workflow_requirements_for($pdo, $workflowEntityType, $workflowEntityId);
        $workflowMeta = ['requirements_source' => $source, 'missing_requirements_note' => $missing];
        $notice = 'Requirements checklist saved.';
    }
}
?>
<?php if (!empty($workflowRequirements)): ?>
<section class="detail-panel workflow-checklist-panel">
    <div class="detail-panel-head"><h2>Requirements Checklist</h2><span class="detail-chip neutral">Document review</span></div>
    <p class="field-hint">Record receipt and verification status. Do not upload or store document contents here.</p>
    <form autocomplete="off" method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_requirements">
        <div class="inline-fields workflow-meta-fields">
            <label>Requirements source <select name="requirements_source"><?php foreach (['not_yet_received' => 'Not yet received', 'physical' => 'Submitted physically', 'official_gmail' => 'Official parish Gmail', 'physical_and_gmail' => 'Physical + official Gmail'] as $value => $label): ?><option value="<?= e($value) ?>" <?= ($workflowMeta['requirements_source'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label>Missing requirements / review note <input type="text" name="missing_requirements_note" value="<?= e($workflowMeta['missing_requirements_note'] ?? '') ?>" maxlength="500" placeholder="Optional note"></label>
        </div>
        <table class="admin-table checklist-table"><thead><tr><th>Requirement</th><th>Status</th><th>Notes</th></tr></thead><tbody>
        <?php foreach ($workflowRequirements as $item): ?>
            <tr><td><?= e($item['requirement_label']) ?></td><td><select name="requirement_status[<?= (int) $item['id'] ?>]"><?php foreach (['not_received','received','under_review','incomplete','verified','not_applicable'] as $status): ?><option value="<?= e($status) ?>" <?= $item['status'] === $status ? 'selected' : '' ?>><?= e(workflow_requirement_status_label($status)) ?></option><?php endforeach; ?></select></td><td><input type="text" name="requirement_notes[<?= (int) $item['id'] ?>]" value="<?= e($item['notes'] ?? '') ?>" placeholder="Optional note"></td></tr>
        <?php endforeach; ?>
        </tbody></table><button type="submit" class="btn btn-small">Save Checklist</button>
    </form>
</section>
<?php endif; ?>
