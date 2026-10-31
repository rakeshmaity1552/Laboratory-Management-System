<?php
/**
 * Add / Edit Workstation PC
 * Laboratory Management System (LMS)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$db = getDB();

$pcId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$isEditing = $pcId !== null && $pcId > 0;

$pc = [
    'pc_id'              => null,
    'pc_code'            => '',
    'pc_name'            => '',
    'location'           => '',
    'operational_status' => 'Working',
    'notes'              => '',
    'is_active'          => 1,
    'created_at'         => null,
    'updated_at'         => null,
];

// If editing, load the PC
if ($isEditing) {
    try {
        $stmt = $db->prepare("SELECT * FROM pcs WHERE pc_id = :id");
        $stmt->execute([':id' => $pcId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            set_flash('error', 'Workstation PC not found.');
            redirect(APP_URL . '/admin/pcs.php');
        }
        $pc = $existing;
    } catch (PDOException $e) {
        error_log("Error loading PC: " . $e->getMessage());
        set_flash('error', 'Database error while loading PC.');
        redirect(APP_URL . '/admin/pcs.php');
    }
}

$errors = [];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $pcCode            = strtoupper(trim($_POST['pc_code'] ?? ''));
    $pcName            = trim($_POST['pc_name'] ?? '');
    $location          = trim($_POST['location'] ?? '');
    $operationalStatus = trim($_POST['operational_status'] ?? 'Working');
    $notes             = trim($_POST['notes'] ?? '');
    $isActive          = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if ($pcCode === '') {
        $errors['pc_code'] = 'PC Code is required (e.g. PC-01).';
    } elseif (!preg_match('/^[A-Z0-9\-_]{2,20}$/i', $pcCode)) {
        $errors['pc_code'] = 'PC Code must be 2-20 alphanumeric characters, hyphens, or underscores.';
    } else {
        // Check uniqueness
        try {
            $uniqueSql = "SELECT COUNT(*) FROM pcs WHERE UPPER(pc_code) = UPPER(:pc_code)";
            $params = [':pc_code' => $pcCode];
            if ($isEditing) {
                $uniqueSql .= " AND pc_id != :pc_id";
                $params[':pc_id'] = $pcId;
            }
            $stmt = $db->prepare($uniqueSql);
            $stmt->execute($params);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors['pc_code'] = "A workstation with code '{$pcCode}' already exists.";
            }
        } catch (PDOException $e) {
            $errors['general'] = 'Failed to verify PC Code uniqueness.';
        }
    }

    if (!in_array($operationalStatus, ALLOWED_PC_STATUSES, true)) {
        $errors['operational_status'] = 'Invalid operational status selected.';
    }

    if (empty($errors)) {
        $currentUserId = get_current_user_id();
        $now = gmdate('Y-m-d H:i:s');

        try {
            if ($isEditing) {
                $oldStatus = $pc['operational_status'];
                $statusChanged = ($oldStatus !== $operationalStatus);

                if ($statusChanged && in_array($operationalStatus, ['Working', 'Out of Service'], true)) {
                    $confirmation = trim($_POST['status_confirmation'] ?? '');
                    $expected = $operationalStatus === 'Working' ? 'working' : 'out of service';
                    if (mb_strtolower($confirmation) !== $expected) {
                        $errors['status_confirmation'] = "Type '{$expected}' to confirm this status change.";
                    }
                }

                if (!empty($errors)) {
                    $pc['pc_code'] = $pcCode;
                    $pc['pc_name'] = $pcName;
                    $pc['location'] = $location;
                    $pc['operational_status'] = $operationalStatus;
                    $pc['notes'] = $notes;
                    $pc['is_active'] = $isActive;
                } else {

                $stmt = $db->prepare("
                    UPDATE pcs 
                    SET pc_code = :pc_code,
                        pc_name = :pc_name,
                        location = :location,
                        operational_status = :operational_status,
                        notes = :notes,
                        is_active = :is_active,
                        updated_at = :updated_at
                    WHERE pc_id = :pc_id
                ");
                $stmt->execute([
                    ':pc_code'            => $pcCode,
                    ':pc_name'            => $pcName !== '' ? $pcName : null,
                    ':location'           => $location !== '' ? $location : null,
                    ':operational_status' => $operationalStatus,
                    ':notes'              => $notes !== '' ? $notes : null,
                    ':is_active'          => $isActive,
                    ':updated_at'         => $now,
                    ':pc_id'              => $pcId,
                ]);

                // Audit log: general PC update
                $auditStmt = $db->prepare("
                    INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at)
                    VALUES (:user_id, :action, :entity_type, :entity_id, :details, :created_at)
                ");
                $auditStmt->execute([
                    ':user_id'     => $currentUserId,
                    ':action'      => 'PC_UPDATED',
                    ':entity_type' => 'pc',
                    ':entity_id'   => $pcId,
                    ':details'     => "Updated PC {$pcCode}",
                    ':created_at'  => $now,
                ]);

                // Specific audit log if status changed
                if ($statusChanged) {
                    $auditStmt->execute([
                        ':user_id'     => $currentUserId,
                        ':action'      => 'PC_STATUS_UPDATED',
                        ':entity_type' => 'pc',
                        ':entity_id'   => $pcId,
                        ':details'     => "Status changed from '{$oldStatus}' to '{$operationalStatus}'",
                        ':created_at'  => $now,
                    ]);
                }

                set_flash('success', "Workstation {$pcCode} updated successfully.");
                redirect(APP_URL . '/admin/pcs.php');
                }
            } else {
                // Insert New PC
                $stmt = $db->prepare("
                    INSERT INTO pcs (pc_code, pc_name, location, operational_status, notes, is_active, created_at, updated_at)
                    VALUES (:pc_code, :pc_name, :location, :operational_status, :notes, :is_active, :created_at, :updated_at)
                ");
                $stmt->execute([
                    ':pc_code'            => $pcCode,
                    ':pc_name'            => $pcName !== '' ? $pcName : null,
                    ':location'           => $location !== '' ? $location : null,
                    ':operational_status' => $operationalStatus,
                    ':notes'              => $notes !== '' ? $notes : null,
                    ':is_active'          => $isActive,
                    ':created_at'         => $now,
                    ':updated_at'         => $now,
                ]);
                $newPcId = (int)$db->lastInsertId();

                // Audit log
                $auditStmt = $db->prepare("
                    INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at)
                    VALUES (:user_id, :action, :entity_type, :entity_id, :details, :created_at)
                ");
                $auditStmt->execute([
                    ':user_id'     => $currentUserId,
                    ':action'      => 'PC_CREATED',
                    ':entity_type' => 'pc',
                    ':entity_id'   => $newPcId,
                    ':details'     => "Created new workstation PC {$pcCode} ({$operationalStatus})",
                    ':created_at'  => $now,
                ]);

                set_flash('success', "Workstation {$pcCode} created successfully.");
                redirect(APP_URL . '/admin/pcs.php');
            }
        } catch (PDOException $e) {
            error_log("Database error saving PC: " . $e->getMessage());
            $errors['general'] = 'Failed to save workstation. Check the PC code and try again.';
        }
    } else {
        // Repopulate form with submitted values
        $pc['pc_code']            = $pcCode;
        $pc['pc_name']            = $pcName;
        $pc['location']           = $location;
        $pc['operational_status'] = $operationalStatus;
        $pc['notes']              = $notes;
        $pc['is_active']          = $isActive;
    }
}

// Fetch PC's ticket history if editing
$pcTickets = [];
if ($isEditing) {
    try {
        $stmt = $db->prepare("
            SELECT 
                t.ticket_id,
                t.ticket_number,
                t.title,
                t.category,
                t.priority,
                t.status,
                t.created_at,
                u.full_name AS reporter_name,
                u.username AS reporter_username
            FROM tickets t
            LEFT JOIN users u ON t.reporter_id = u.user_id
            WHERE t.pc_id = :pc_id
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([':pc_id' => $pcId]);
        $pcTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error loading PC tickets history: " . $e->getMessage());
    }
}

$page_title = $isEditing ? "Edit Workstation {$pc['pc_code']}" : 'Add New Workstation';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.pc-edit-wrap {
    max-width: 900px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.back-bar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--text-secondary, #64748b);
}
.back-bar a {
    color: var(--primary, #2563eb);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.back-bar a:hover {
    text-decoration: underline;
}
.form-panel {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 1.5rem;
}
.form-panel-header {
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.form-panel-header h1 {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text-primary, #1e293b);
    margin: 0;
}
.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
}
.field-group {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    margin-bottom: 1.25rem;
}
.field-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
}
.field-group label.required::after {
    content: " *";
    color: #ef4444;
}
.field-input, .field-select, .field-textarea {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--text-primary, #1e293b);
    padding: 0.6rem 0.85rem;
    border-radius: 4px;
    font-size: 0.9rem;
    font-family: inherit;
    box-sizing: border-box;
    width: 100%;
}
.field-input:focus, .field-select:focus, .field-textarea:focus {
    outline: none;
    border-color: var(--primary, #2563eb);
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
}
.field-textarea {
    min-height: 90px;
    resize: vertical;
}
.field-error {
    color: #f87171;
    font-size: 0.775rem;
    margin-top: 0.25rem;
}
.field-checkbox-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0.75rem 0 1.25rem;
}
.field-checkbox-row input[type="checkbox"] {
    width: 1.2rem;
    height: 1.2rem;
    accent-color: var(--primary, #2563eb);
    cursor: pointer;
}
.field-checkbox-row label {
    font-size: 0.9rem;
    color: var(--text-primary, #1e293b);
    cursor: pointer;
    margin: 0;
}
.form-actions-row {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--border, #e2e8f0);
}
.btn-submit {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
    font-weight: 600;
    padding: 0.65rem 1.4rem;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.15s ease;
}
.btn-submit:hover {
    background: #1d4ed8;
}
.btn-cancel {
    background: var(--bg-tertiary, #e2e8f0);
    color: var(--text-primary, #1e293b) !important;
    padding: 0.65rem 1.2rem;
    border-radius: 4px;
    text-decoration: none !important;
    font-size: 0.9rem;
}
.btn-cancel:hover {
    background: #cbd5e1;
}

/* History Box */
.history-panel {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
}
.history-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.history-header h2 {
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
    color: var(--text-primary, #1e293b);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    text-align: left;
}
.history-table th {
    background: rgba(255,255,255,0.03);
    color: var(--text-secondary, #64748b);
    font-weight: 600;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.history-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(51, 65, 85, 0.4);
    vertical-align: middle;
}
.history-table tbody tr:hover {
    background: rgba(255,255,255,0.02);
}

@media (max-width: 700px) {
    .form-grid-2 {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="pc-edit-wrap">
    <!-- Breadcrumb back link -->
    <div class="back-bar">
        <a href="<?= APP_URL ?>/admin/pcs.php">
            &larr; Back to Workstations List
        </a>
    </div>

    <!-- Edit/Add Form -->
    <div class="form-panel">
        <div class="form-panel-header">
            <div>
                <h1><?= $isEditing ? "Edit Workstation " . htmlspecialchars($pc['pc_code']) : "Add New Workstation" ?></h1>
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">
                    <?= $isEditing ? "Modify lab hardware inventory details and operational status." : "Register a new workstation into the college computer laboratory." ?>
                </p>
            </div>
            <?php if ($isEditing): ?>
                <div>
                    <span class="badge <?= get_status_badge_class($pc['operational_status']) ?>" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
                        <?= htmlspecialchars($pc['operational_status']) ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem;">
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <?= csrf_token_field() ?>
            <?php if (!empty($errors['status_confirmation'])): ?>
                <div class="field-error" style="margin-bottom:1rem"><?= htmlspecialchars($errors['status_confirmation']) ?></div>
            <?php endif; ?>

            <div class="form-grid-2">
                <div class="field-group">
                    <label for="pc_code" class="required">PC Identifier Code</label>
                    <input type="text" id="pc_code" name="pc_code" class="field-input" 
                           value="<?= htmlspecialchars($pc['pc_code']) ?>" 
                           placeholder="e.g. PC-01, LAB-A-15" required maxlength="20">
                    <span style="font-size: 0.75rem; color: #64748b;">Unique physical identifier labeled on the workstation.</span>
                    <?php if (!empty($errors['pc_code'])): ?>
                        <span class="field-error"><?= htmlspecialchars($errors['pc_code']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field-group">
                    <label for="pc_name">PC Name / Model (Optional)</label>
                    <input type="text" id="pc_name" name="pc_name" class="field-input" 
                           value="<?= htmlspecialchars($pc['pc_name'] ?? '') ?>" 
                           placeholder="e.g. Dell OptiPlex 7080, HP EliteDesk" maxlength="100">
                    <span style="font-size: 0.75rem; color: #64748b;">Descriptive hardware name or manufacturer model.</span>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="field-group">
                    <label for="location">Location / Row (Optional)</label>
                    <input type="text" id="location" name="location" class="field-input" 
                           value="<?= htmlspecialchars($pc['location'] ?? '') ?>" 
                           placeholder="e.g. Row 3 - Seat 4, Lab 204" maxlength="100">
                    <span style="font-size: 0.75rem; color: #64748b;">Specific desk, aisle, or room in the computer lab.</span>
                </div>

                <div class="field-group">
                    <label for="operational_status" class="required">Operational Status</label>
                    <select id="operational_status" name="operational_status" class="field-select" required>
                        <?php foreach (ALLOWED_PC_STATUSES as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>" <?= ($pc['operational_status'] === $status) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span style="font-size: 0.75rem; color: #64748b;">Current physical health condition of the machine.</span>
                    <?php if (!empty($errors['operational_status'])): ?>
                        <span class="field-error"><?= htmlspecialchars($errors['operational_status']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="field-group" id="status-confirmation-group" style="display:none">
                <label for="status_confirmation">Confirm status change</label>
                <input type="text" id="status_confirmation" name="status_confirmation" class="field-input" autocomplete="off">
                <span style="font-size:0.75rem;color:#64748b">Type â€œworkingâ€ or â€œout of serviceâ€ when selecting that status.</span>
            </div>

            <div class="field-group">
                <label for="notes">Technical Notes & Specifications</label>
                <textarea id="notes" name="notes" class="field-textarea" 
                          placeholder="Hardware specs, OS version, peripheral notes, or maintenance history details..."><?= htmlspecialchars($pc['notes'] ?? '') ?></textarea>
            </div>

            <div class="field-checkbox-row">
                <input type="checkbox" id="is_active" name="is_active" value="1" <?= (!empty($pc['is_active'])) ? 'checked' : '' ?>>
                <label for="is_active">
                    <strong>Active Workstation</strong> â€” check if this PC is deployed and available for student/staff reporting
                </label>
            </div>

            <div class="form-actions-row">
                <a href="<?= APP_URL ?>/admin/pcs.php" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-submit">
                    <?= $isEditing ? 'Save Workstation Changes' : 'Create Workstation' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Ticket History Section (when editing) -->
    <?php if ($isEditing): ?>
        <div class="history-panel" id="ticket-history">
            <div class="history-header">
                <h2>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    Ticket History for <?= htmlspecialchars($pc['pc_code']) ?> (<?= count($pcTickets) ?> Total)
                </h2>
                <a href="<?= APP_URL ?>/admin/tickets.php?pc_id=<?= $pc['pc_id'] ?>" style="font-size: 0.85rem;">
                    Filter in Tickets &rarr;
                </a>
            </div>

            <div style="overflow-x: auto;">
                <?php if (empty($pcTickets)): ?>
                    <div style="text-align: center; padding: 2.5rem; color: var(--text-secondary);">
                        <p>No support tickets have been filed for workstation <strong><?= htmlspecialchars($pc['pc_code']) ?></strong>.</p>
                    </div>
                <?php else: ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Issue Title</th>
                                <th>Category</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Reporter</th>
                                <th>Reported</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pcTickets as $ticket): ?>
                                <tr>
                                    <td>
                                        <a href="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $ticket['ticket_id'] ?>" style="font-weight: 700; font-family: monospace;">
                                            <?= htmlspecialchars($ticket['ticket_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span title="<?= htmlspecialchars($ticket['title']) ?>">
                                            <?= htmlspecialchars(mb_strimwidth($ticket['title'], 0, 35, '...')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: var(--text-secondary); font-size: 0.8rem;">
                                            <?= htmlspecialchars($ticket['category']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= get_priority_badge_class($ticket['priority']) ?>">
                                            <?= htmlspecialchars($ticket['priority']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= get_status_badge_class($ticket['status']) ?>">
                                            <?= htmlspecialchars($ticket['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($ticket['reporter_name'] ?? $ticket['reporter_username'] ?? 'User') ?>
                                    </td>
                                    <td>
                                        <span title="<?= format_datetime($ticket['created_at']) ?>">
                                            <?= time_ago($ticket['created_at']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $ticket['ticket_id'] ?>" 
                                           style="font-size: 0.775rem; font-weight: 600; padding: 0.2rem 0.5rem; background: rgba(56, 189, 248, 0.15); border-radius: 4px; border: 1px solid rgba(56, 189, 248, 0.3);">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(() => {
    const status = document.getElementById('operational_status');
    const group = document.getElementById('status-confirmation-group');
    const input = document.getElementById('status_confirmation');
    const original = <?= json_encode($pc['operational_status']) ?>;
    const update = () => {
        const required = <?= $isEditing ? 'true' : 'false' ?> && status.value !== original && ['Working', 'Out of Service'].includes(status.value);
        group.style.display = required ? '' : 'none';
        input.required = required;
        input.placeholder = status.value === 'Working' ? 'working' : 'out of service';
    };
    status.addEventListener('change', update);
    update();
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';

