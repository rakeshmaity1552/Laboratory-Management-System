<?php
/**
 * Ticket Details & Administration
 * Laboratory Management System (LMS)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$db = getDB();

$ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($ticketId <= 0) {
    set_flash('error', 'Invalid ticket identifier.');
    redirect(APP_URL . '/admin/tickets.php');
}

// 1. Fetch Ticket Information
$stmt = $db->prepare("
    SELECT 
        t.*,
        p.pc_code,
        p.pc_name,
        p.location AS pc_location,
        p.operational_status AS pc_operational_status,
        p.is_active AS pc_is_active,
        u.full_name AS reporter_name,
        u.username AS reporter_username,
        u.email AS reporter_email,
        r.full_name AS resolver_name,
        r.username AS resolver_username
    FROM tickets t
    LEFT JOIN pcs p ON t.pc_id = p.pc_id
    LEFT JOIN users u ON t.reporter_id = u.user_id
    LEFT JOIN users r ON t.resolved_by = r.user_id
    WHERE t.ticket_id = :id
");
$stmt->execute([':id' => $ticketId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    set_flash('error', 'The requested support ticket was not found.');
    redirect(APP_URL . '/admin/tickets.php');
}

$currentAdminId = get_current_user_id();

// 2. Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $formAction = trim($_POST['form_action'] ?? '');
    $now = gmdate('Y-m-d H:i:s');

    // Action A: Update Ticket Status
    if ($formAction === 'update_status') {
        $newStatus = trim($_POST['new_status'] ?? '');
        $statusNote = trim($_POST['status_note'] ?? '');

        if (!in_array($newStatus, ALLOWED_TICKET_STATUSES, true)) {
            set_flash('error', 'Invalid ticket status selected.');
            redirect(APP_URL . '/admin/ticket-details.php?id=' . $ticketId);
        }

        // Resolution notes required when resolving, closing, or rejecting
        $requireResolutionNote = in_array($newStatus, ['Resolved', 'Closed', 'Rejected'], true);
        if ($requireResolutionNote && $statusNote === '') {
            set_flash('error', "A note or resolution explanation is required when setting status to '{$newStatus}'.");
            redirect(APP_URL . '/admin/ticket-details.php?id=' . $ticketId);
        }

        $oldStatus = $ticket['status'];
        if (!is_valid_ticket_transition($oldStatus, $newStatus)) {
            set_flash('error', "Ticket cannot move from '{$oldStatus}' to '{$newStatus}'.");
            redirect(APP_URL . '/admin/ticket-details.php?id=' . $ticketId);
        }

        try {
            $db->beginTransaction();

            $isResolving = ($newStatus === 'Resolved');
            $resolverId = $ticket['resolved_by'];
            $resolvedAt = $ticket['resolved_at'];

            if ($isResolving) {
                $resolverId = $currentAdminId;
                $resolvedAt = $now;
            } elseif ($newStatus === 'Closed' && empty($resolvedAt)) {
                $resolverId = $currentAdminId;
                $resolvedAt = $now;
            }

            // Update ticket
            $updateTicketStmt = $db->prepare("
                UPDATE tickets
                SET status = :status,
                    resolution_notes = CASE 
                        WHEN :note != '' THEN :note 
                        ELSE resolution_notes 
                    END,
                    resolved_by = :resolved_by,
                    resolved_at = :resolved_at,
                    updated_at = :updated_at
                WHERE ticket_id = :ticket_id
            ");
            $updateTicketStmt->execute([
                ':status'      => $newStatus,
                ':note'        => $statusNote,
                ':resolved_by' => $resolverId,
                ':resolved_at' => $resolvedAt,
                ':updated_at'  => $now,
                ':ticket_id'   => $ticketId,
            ]);

            // Insert into ticket_status_history
            $historyStmt = $db->prepare("
                INSERT INTO ticket_status_history (ticket_id, changed_by, old_status, new_status, note, changed_at)
                VALUES (:ticket_id, :changed_by, :old_status, :new_status, :note, :changed_at)
            ");
            $historyStmt->execute([
                ':ticket_id'   => $ticketId,
                ':changed_by'  => $currentAdminId,
                ':old_status'  => $oldStatus,
                ':new_status'  => $newStatus,
                ':note'        => $statusNote !== '' ? $statusNote : null,
                ':changed_at'  => $now,
            ]);

            // Insert audit log
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at)
                VALUES (:user_id, :action, :entity_type, :entity_id, :details, :created_at)
            ");
            $auditStmt->execute([
                ':user_id'     => $currentAdminId,
                ':action'      => 'TICKET_STATUS_UPDATED',
                ':entity_type' => 'ticket',
                ':entity_id'   => $ticketId,
                ':details'     => "Ticket {$ticket['ticket_number']} status changed from '{$oldStatus}' to '{$newStatus}'" . ($statusNote !== '' ? ". Note: {$statusNote}" : ""),
                ':created_at'  => $now,
            ]);

            $db->commit();
            set_flash('success', "Ticket status successfully updated to '{$newStatus}'.");
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error updating ticket status: " . $e->getMessage());
            set_flash('error', 'Failed to update ticket status. Please try again.');
        }

        redirect(APP_URL . '/admin/ticket-details.php?id=' . $ticketId);
    }

    // Action B: Update PC Operational Status
    if ($formAction === 'update_pc_status') {
        $newPcStatus = trim($_POST['pc_operational_status'] ?? '');
        $pcNote      = trim($_POST['pc_status_note'] ?? '');

        if (!in_array($newPcStatus, ALLOWED_PC_STATUSES, true)) {
            set_flash('error', 'Invalid PC operational status.');
            redirect(APP_URL . '/admin/ticket-details.php?id=' . $ticketId);
        }

        try {
            $oldPcStatus = $ticket['pc_operational_status'];
            
            $db->beginTransaction();

            $updatePcStmt = $db->prepare("
                UPDATE pcs 
                SET operational_status = :status,
                    notes = CASE 
                        WHEN :note != '' THEN (coalesce(notes, '') || CHAR(10) || :note_with_date)
                        ELSE notes 
                    END,
                    updated_at = :updated_at
                WHERE pc_id = :pc_id
            ");
            $noteWithDate = "[{$now} UTC] Status set to {$newPcStatus}: {$pcNote}";
            $updatePcStmt->execute([
                ':status'          => $newPcStatus,
                ':note'            => $pcNote,
                ':note_with_date'  => $noteWithDate,
                ':updated_at'      => $now,
                ':pc_id'           => $ticket['pc_id'],
            ]);

            // Audit log
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at)
                VALUES (:user_id, :action, :entity_type, :entity_id, :details, :created_at)
            ");
            $auditStmt->execute([
                ':user_id'     => $currentAdminId,
                ':action'      => 'PC_STATUS_UPDATED',
                ':entity_type' => 'pc',
                ':entity_id'   => $ticket['pc_id'],
                ':details'     => "Workstation {$ticket['pc_code']} status changed from '{$oldPcStatus}' to '{$newPcStatus}' from ticket {$ticket['ticket_number']}" . ($pcNote !== '' ? ". Note: {$pcNote}" : ""),
                ':created_at'  => $now,
            ]);

            $db->commit();
            set_flash('success', "Workstation {$ticket['pc_code']} operational status updated to '{$newPcStatus}'.");
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error updating PC status: " . $e->getMessage());
            set_flash('error', 'Failed to update PC status. Please try again.');
        }

        redirect(APP_URL . '/admin/ticket-details.php?id=' . $ticketId);
    }

    // Action C: Add Admin Comment
    if ($formAction === 'add_comment') {
        $commentText = trim($_POST['comment_text'] ?? '');

        if ($commentText === '') {
            set_flash('error', 'Comment cannot be empty.');
            redirect(APP_URL . '/admin/ticket-details.php?id=' . $ticketId . '#comments');
        }

        try {
            $db->beginTransaction();

            $commentStmt = $db->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment_text, created_at)
                VALUES (:ticket_id, :user_id, :comment_text, :created_at)
            ");
            $commentStmt->execute([
                ':ticket_id'    => $ticketId,
                ':user_id'      => $currentAdminId,
                ':comment_text' => $commentText,
                ':created_at'   => $now,
            ]);

            // Touch ticket updated_at
            $touchStmt = $db->prepare("UPDATE tickets SET updated_at = :now WHERE ticket_id = :ticket_id");
            $touchStmt->execute([':now' => $now, ':ticket_id' => $ticketId]);

            // Audit log
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at)
                VALUES (:user_id, :action, :entity_type, :entity_id, :details, :created_at)
            ");
            $auditStmt->execute([
                ':user_id'     => $currentAdminId,
                ':action'      => 'TICKET_COMMENT_ADDED',
                ':entity_type' => 'ticket',
                ':entity_id'   => $ticketId,
                ':details'     => "Admin commented on ticket {$ticket['ticket_number']}",
                ':created_at'  => $now,
            ]);

            $db->commit();
            set_flash('success', 'Comment posted successfully.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error adding comment: " . $e->getMessage());
            set_flash('error', 'Failed to post comment.');
        }

        redirect(APP_URL . '/admin/ticket-details.php?id=' . $ticketId . '#comments');
    }
}

// 3. Fetch Status History
$statusHistory = [];
try {
    $stmt = $db->prepare("
        SELECT 
            h.*,
            u.full_name AS changer_name,
            u.username AS changer_username,
            u.role AS changer_role
        FROM ticket_status_history h
        LEFT JOIN users u ON h.changed_by = u.user_id
        WHERE h.ticket_id = :id
        ORDER BY h.changed_at DESC
    ");
    $stmt->execute([':id' => $ticketId]);
    $statusHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching status history: " . $e->getMessage());
}

// 4. Fetch Ticket Comments
$comments = [];
try {
    $stmt = $db->prepare("
        SELECT 
            c.*,
            u.full_name,
            u.username,
            u.role
        FROM ticket_comments c
        LEFT JOIN users u ON c.user_id = u.user_id
        WHERE c.ticket_id = :id
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([':id' => $ticketId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching comments: " . $e->getMessage());
}

$page_title = "Ticket {$ticket['ticket_number']} - Admin View";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.details-page-wrap {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.back-nav {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
}
.back-nav a {
    color: var(--primary, #2563eb);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.ticket-title-header {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
}
.ticket-meta-main h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary, #1e293b);
    margin: 0 0 0.5rem 0;
    line-height: 1.3;
}
.ticket-number-tag {
    font-family: monospace;
    font-size: 0.95rem;
    color: var(--primary, #2563eb);
    font-weight: 700;
    background: rgba(56, 189, 248, 0.1);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    border: 1px solid rgba(56, 189, 248, 0.25);
    display: inline-block;
    margin-right: 0.5rem;
}
.header-badges {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    flex-wrap: wrap;
}
.main-two-col {
    display: grid;
    grid-template-columns: 2fr 1.2fr;
    gap: 1.5rem;
    align-items: start;
}
.panel {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.panel-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
    background: rgba(255, 255, 255, 0.02);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.panel-header h2 {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--text-primary, #1e293b);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.panel-body {
    padding: 1.25rem;
}
.key-val-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.key-val-item {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}
.key-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary, #64748b);
    letter-spacing: 0.05em;
}
.key-value {
    font-size: 0.95rem;
    color: var(--text-primary, #1e293b);
    font-weight: 500;
}
.ticket-desc-box {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 6px;
    padding: 1.25rem;
    color: var(--text-primary, #1e293b);
    font-size: 0.925rem;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
}
.resolution-box {
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.3);
    border-radius: 6px;
    padding: 1rem 1.25rem;
    margin-top: 1.25rem;
}
.resolution-box h4 {
    color: #86efac;
    font-size: 0.9rem;
    margin: 0 0 0.5rem 0;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

/* Actions Panel */
.action-form-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin-bottom: 1rem;
}
.action-form-group label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
}
.action-input, .action-select, .action-textarea {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--text-primary, #1e293b);
    padding: 0.55rem 0.75rem;
    border-radius: 4px;
    font-size: 0.875rem;
    width: 100%;
    box-sizing: border-box;
    font-family: inherit;
}
.action-input:focus, .action-select:focus, .action-textarea:focus {
    outline: none;
    border-color: var(--primary, #2563eb);
}
.btn-submit-action {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
    font-weight: 600;
    padding: 0.55rem 1rem;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    width: 100%;
    font-size: 0.875rem;
    transition: all 0.15s ease;
}
.btn-submit-action:hover {
    background: #1d4ed8;
}

/* Timeline */
.timeline-list {
    position: relative;
    padding-left: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}
.timeline-list::before {
    content: '';
    position: absolute;
    left: 0.45rem;
    top: 0.3rem;
    bottom: 0.3rem;
    width: 2px;
    background: var(--border, #e2e8f0);
}
.timeline-entry {
    position: relative;
}
.timeline-point {
    position: absolute;
    left: -1.5rem;
    top: 0.2rem;
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 50%;
    background: var(--primary, #2563eb);
    border: 2px solid var(--bg-secondary, #ffffff);
}
.timeline-card {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 6px;
    padding: 0.75rem 1rem;
}
.timeline-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-bottom: 0.25rem;
}

/* Comments */
.comment-item {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
}
.comment-item:last-child {
    margin-bottom: 0;
}
.comment-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}
.comment-user {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary, #1e293b);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.comment-role-tag {
    font-size: 0.65rem;
    text-transform: uppercase;
    padding: 0.1rem 0.4rem;
    border-radius: 9999px;
    font-weight: 700;
}
.comment-role-tag.admin {
    background: rgba(245, 158, 11, 0.2);
    color: #fbbf24;
    border: 1px solid rgba(245, 158, 11, 0.4);
}
.comment-role-tag.user {
    background: rgba(56, 189, 248, 0.2);
    color: #7dd3fc;
    border: 1px solid rgba(56, 189, 248, 0.4);
}
.comment-time {
    font-size: 0.75rem;
    color: #64748b;
}
.comment-text {
    font-size: 0.9rem;
    color: var(--text-secondary, #64748b);
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}

@media (max-width: 900px) {
    .main-two-col {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="details-page-wrap">
    <!-- Back to tickets -->
    <div class="back-nav">
        <a href="<?= APP_URL ?>/admin/tickets.php">
            &larr; Back to Tickets List
        </a>
    </div>

    <!-- Header Block -->
    <div class="ticket-title-header">
        <div class="ticket-meta-main">
            <div>
                <span class="ticket-number-tag"><?= htmlspecialchars($ticket['ticket_number']) ?></span>
                <span style="color: var(--text-secondary); font-size: 0.85rem;">
                    Reported <?= time_ago($ticket['created_at']) ?> (<?= format_datetime($ticket['created_at']) ?>)
                </span>
            </div>
            <h1 style="margin-top: 0.5rem;"><?= htmlspecialchars($ticket['title']) ?></h1>
            <div style="color: var(--text-secondary); font-size: 0.875rem;">
                Workstation: 
                <a href="<?= APP_URL ?>/admin/pc-edit.php?id=<?= $ticket['pc_id'] ?>" style="font-weight: 700;">
                    <?= htmlspecialchars($ticket['pc_code']) ?>
                </a>
                <?= !empty($ticket['pc_name']) ? " (" . htmlspecialchars($ticket['pc_name']) . ")" : "" ?>
                &bull; Reporter: <strong><?= htmlspecialchars($ticket['reporter_name'] ?? $ticket['reporter_username']) ?></strong>
                (<?= htmlspecialchars($ticket['reporter_username']) ?>)
            </div>
        </div>

        <div class="header-badges">
            <span class="badge <?= get_priority_badge_class($ticket['priority']) ?>" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
                Priority: <?= htmlspecialchars($ticket['priority']) ?>
            </span>
            <span class="badge <?= get_status_badge_class($ticket['status']) ?>" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
                Status: <?= htmlspecialchars($ticket['status']) ?>
            </span>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="main-two-col">
        <!-- Left Column: Details, Resolution, Timeline, Comments -->
        <div>
            <!-- Ticket Info Panel -->
            <div class="panel">
                <div class="panel-header">
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        Issue Overview
                    </h2>
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">
                        Category: <strong><?= htmlspecialchars($ticket['category']) ?></strong>
                    </span>
                </div>
                <div class="panel-body">
                    <div class="key-val-grid">
                        <div class="key-val-item">
                            <span class="key-label">Category</span>
                            <span class="key-value"><?= htmlspecialchars($ticket['category']) ?></span>
                        </div>
                        <div class="key-val-item">
                            <span class="key-label">Priority Level</span>
                            <span class="key-value">
                                <span class="badge <?= get_priority_badge_class($ticket['priority']) ?>">
                                    <?= htmlspecialchars($ticket['priority']) ?>
                                </span>
                            </span>
                        </div>
                        <div class="key-val-item">
                            <span class="key-label">Workstation Code</span>
                            <span class="key-value">
                                <a href="<?= APP_URL ?>/admin/pc-edit.php?id=<?= $ticket['pc_id'] ?>">
                                    <?= htmlspecialchars($ticket['pc_code']) ?>
                                </a>
                                <span class="badge <?= get_status_badge_class($ticket['pc_operational_status']) ?>" style="font-size: 0.7rem; margin-left: 0.3rem;">
                                    <?= htmlspecialchars($ticket['pc_operational_status']) ?>
                                </span>
                            </span>
                        </div>
                        <div class="key-val-item">
                            <span class="key-label">PC Location</span>
                            <span class="key-value"><?= !empty($ticket['pc_location']) ? htmlspecialchars($ticket['pc_location']) : 'Lab Floor' ?></span>
                        </div>
                        <div class="key-val-item">
                            <span class="key-label">Reported At</span>
                            <span class="key-value" title="<?= format_datetime($ticket['created_at']) ?>">
                                <?= format_datetime($ticket['created_at']) ?>
                            </span>
                        </div>
                        <div class="key-val-item">
                            <span class="key-label">Last Updated</span>
                            <span class="key-value" title="<?= format_datetime($ticket['updated_at']) ?>">
                                <?= time_ago($ticket['updated_at']) ?>
                            </span>
                        </div>
                    </div>

                    <div style="margin-top: 1rem;">
                        <span class="key-label" style="display: block; margin-bottom: 0.4rem;">Description of the Problem</span>
                        <div class="ticket-desc-box"><?= htmlspecialchars($ticket['description']) ?></div>
                    </div>

                    <!-- Resolution Block if resolved -->
                    <?php if (!empty($ticket['resolution_notes']) || !empty($ticket['resolved_at'])): ?>
                        <div class="resolution-box">
                            <h4>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                                Resolution Notes
                            </h4>
                            <div style="font-size: 0.9rem; color: var(--text-primary); margin-bottom: 0.5rem; white-space: pre-wrap;">
                                <?= htmlspecialchars($ticket['resolution_notes'] ?? 'Issue resolved.') ?>
                            </div>
                            <div style="font-size: 0.775rem; color: #86efac;">
                                Resolved by <strong><?= htmlspecialchars($ticket['resolver_name'] ?? $ticket['resolver_username'] ?? 'Admin') ?></strong>
                                <?php if (!empty($ticket['resolved_at'])): ?>
                                    on <?= format_datetime($ticket['resolved_at']) ?> (<?= time_ago($ticket['resolved_at']) ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status History Timeline Panel -->
            <div class="panel">
                <div class="panel-header">
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        Status Progression Timeline
                    </h2>
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">
                        <?= count($statusHistory) ?> Update(s)
                    </span>
                </div>
                <div class="panel-body">
                    <?php if (empty($statusHistory)): ?>
                        <p style="color: var(--text-secondary); font-size: 0.875rem; margin: 0;">No status changes recorded yet.</p>
                    <?php else: ?>
                        <div class="timeline-list">
                            <?php foreach ($statusHistory as $h): ?>
                                <div class="timeline-entry">
                                    <div class="timeline-point"></div>
                                    <div class="timeline-card">
                                        <div class="timeline-top">
                                            <div>
                                                <strong style="color: var(--text-primary);">
                                                    <?= htmlspecialchars($h['changer_name'] ?? $h['changer_username'] ?? 'System') ?>
                                                </strong>
                                                <span style="color: var(--text-secondary); font-size: 0.8rem;">
                                                    changed status from 
                                                    <span class="badge <?= get_status_badge_class($h['old_status']) ?>"><?= htmlspecialchars($h['old_status'] ?? 'Initial') ?></span>
                                                    to 
                                                    <span class="badge <?= get_status_badge_class($h['new_status']) ?>"><?= htmlspecialchars($h['new_status']) ?></span>
                                                </span>
                                            </div>
                                            <span style="font-size: 0.75rem; color: #64748b;" title="<?= format_datetime($h['changed_at']) ?>">
                                                <?= time_ago($h['changed_at']) ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($h['note'])): ?>
                                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.4rem; padding-top: 0.4rem; border-top: 1px solid var(--border);">
                                                <?= htmlspecialchars($h['note']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Discussion & Comments Section -->
            <div class="panel" id="comments">
                <div class="panel-header">
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        Discussion & Notes (<?= count($comments) ?>)
                    </h2>
                </div>
                <div class="panel-body">
                    <!-- Comments list -->
                    <?php if (empty($comments)): ?>
                        <div style="text-align: center; padding: 1.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                            No comments posted on this ticket yet.
                        </div>
                    <?php else: ?>
                        <div style="margin-bottom: 1.5rem;">
                            <?php foreach ($comments as $c): ?>
                                <div class="comment-item">
                                    <div class="comment-top">
                                        <div class="comment-user">
                                            <span><?= htmlspecialchars($c['full_name'] ?? $c['username']) ?></span>
                                            <span class="comment-role-tag <?= ($c['role'] === 'admin') ? 'admin' : 'user' ?>">
                                                <?= htmlspecialchars($c['role'] ?? 'user') ?>
                                            </span>
                                        </div>
                                        <div class="comment-time" title="<?= format_datetime($c['created_at']) ?>">
                                            <?= time_ago($c['created_at']) ?>
                                        </div>
                                    </div>
                                    <div class="comment-text"><?= htmlspecialchars($c['comment_text']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Add Comment Form -->
                    <form method="POST" action="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $ticketId ?>">
                        <?= csrf_token_field() ?>
                        <input type="hidden" name="form_action" value="add_comment">

                        <div class="action-form-group">
                            <label for="comment_text">Post an Admin Comment or Note</label>
                            <textarea id="comment_text" name="comment_text" class="action-textarea" rows="3" 
                                      placeholder="Write internal findings, test updates, or feedback for the reporter..." required></textarea>
                        </div>

                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" class="btn-submit-action" style="width: auto; padding: 0.55rem 1.5rem;">
                                Post Comment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Column: Admin Actions Panels -->
        <div>
            <!-- Panel 1: Update Ticket Status -->
            <div class="panel">
                <div class="panel-header">
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                        </svg>
                        Admin Ticket Action
                    </h2>
                </div>
                <div class="panel-body">
                    <form method="POST" action="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $ticketId ?>" 
                          onsubmit="return confirm('Are you sure you want to update the ticket status?');">
                        <?= csrf_token_field() ?>
                        <input type="hidden" name="form_action" value="update_status">

                        <div class="action-form-group">
                            <label for="new_status">Change Ticket Status</label>
                            <select id="new_status" name="new_status" class="action-select" required onchange="handleStatusChange(this.value)">
                                <?php foreach (ALLOWED_TICKET_STATUSES as $status): ?>
                                    <option value="<?= htmlspecialchars($status) ?>" <?= ($ticket['status'] === $status) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="action-form-group">
                            <label for="status_note">
                                Resolution / Status Note
                                <span id="note-required-label" style="color: #ef4444; display: none;">* Required for Resolve/Close/Reject</span>
                            </label>
                            <textarea id="status_note" name="status_note" class="action-textarea" rows="3" 
                                      placeholder="State actions taken, hardware replaced, or reason for closure/rejection..."></textarea>
                            <span style="font-size: 0.75rem; color: #64748b;">
                                Mandatory when resolving, closing, or rejecting a ticket.
                            </span>
                        </div>

                        <button type="submit" class="btn-submit-action">
                            Update Ticket Status
                        </button>
                    </form>
                </div>
            </div>

            <!-- Panel 2: Workstation Operational Status Quick Action -->
            <div class="panel">
                <div class="panel-header">
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                            <line x1="8" y1="21" x2="16" y2="21"></line>
                            <line x1="12" y1="17" x2="12" y2="21"></line>
                        </svg>
                        Update PC Status
                    </h2>
                </div>
                <div class="panel-body">
                    <div style="margin-bottom: 1rem; padding: 0.75rem; background: var(--bg-primary); border-radius: 6px; border: 1px solid var(--border);">
                        <div style="font-size: 0.8rem; color: var(--text-secondary);">Workstation</div>
                        <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 0.2rem 0;">
                            <?= htmlspecialchars($ticket['pc_code']) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);">
                            Current State: 
                            <span class="badge <?= get_status_badge_class($ticket['pc_operational_status']) ?>">
                                <?= htmlspecialchars($ticket['pc_operational_status']) ?>
                            </span>
                        </div>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $ticketId ?>">
                        <?= csrf_token_field() ?>
                        <input type="hidden" name="form_action" value="update_pc_status">

                        <div class="action-form-group">
                            <label for="pc_operational_status">Change Workstation Status</label>
                            <select id="pc_operational_status" name="pc_operational_status" class="action-select" required>
                                <?php foreach (ALLOWED_PC_STATUSES as $pcStat): ?>
                                    <option value="<?= htmlspecialchars($pcStat) ?>" <?= ($ticket['pc_operational_status'] === $pcStat) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pcStat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="action-form-group">
                            <label for="pc_status_note">Maintenance Note (Optional)</label>
                            <input type="text" id="pc_status_note" name="pc_status_note" class="action-input" 
                                   placeholder="e.g. Swapped power supply, tested good.">
                        </div>

                        <button type="submit" class="btn-submit-action" style="background: var(--bg-tertiary); color: var(--text-primary) !important; border: 1px solid #cbd5e1;">
                            Save Workstation Status
                        </button>
                    </form>
                </div>
            </div>

            <!-- Panel 3: Quick Navigation / Reporter Info -->
            <div class="panel">
                <div class="panel-header">
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        Reporter Profile
                    </h2>
                </div>
                <div class="panel-body">
                    <div style="display: flex; flex-direction: column; gap: 0.6rem; font-size: 0.875rem;">
                        <div>
                            <span class="key-label">Full Name:</span>
                            <div style="font-weight: 600; color: var(--text-primary);">
                                <?= htmlspecialchars($ticket['reporter_name'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <span class="key-label">Username:</span>
                            <div><?= htmlspecialchars($ticket['reporter_username'] ?? 'N/A') ?></div>
                        </div>
                        <div>
                            <span class="key-label">Email:</span>
                            <div><?= !empty($ticket['reporter_email']) ? htmlspecialchars($ticket['reporter_email']) : '<span style="color:#64748b;">Not provided</span>' ?></div>
                        </div>
                        <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--border);">
                            <a href="<?= APP_URL ?>/admin/tickets.php?q=<?= urlencode($ticket['reporter_username']) ?>" style="font-size: 0.8rem;">
                                View other tickets by this user &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function handleStatusChange(status) {
    var reqStatuses = ['Resolved', 'Closed', 'Rejected'];
    var label = document.getElementById('note-required-label');
    var textarea = document.getElementById('status_note');
    if (reqStatuses.indexOf(status) !== -1) {
        if (label) label.style.display = 'inline';
        if (textarea) textarea.setAttribute('required', 'required');
    } else {
        if (label) label.style.display = 'none';
        if (textarea) textarea.removeAttribute('required');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('new_status');
    if (select) {
        handleStatusChange(select.value);
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';

