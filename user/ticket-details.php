<?php
/**
 * Ticket Details View
 * Laboratory Management System (LMS)
 * Path: user/ticket-details.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

// Authentication requirement
require_login();

$db = getDB();
$currentUser = get_authenticated_user();
$currentUserId = get_current_user_id();

// Retrieve Ticket ID from query parameter
$ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($ticketId <= 0) {
    http_response_code(404);
    $page_title = 'Ticket Not Found';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div style="max-width: 600px; margin: 4rem auto; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 8px; padding: 2.5rem;">';
    echo '<h1 style="color: #ef4444; margin-bottom: 0.5rem;">Ticket Not Found</h1>';
    echo '<p style="color: var(--text-secondary); margin-bottom: 1.5rem;">The ticket ID provided is invalid or missing.</p>';
    echo '<a href="' . APP_URL . '/user/my-tickets.php" style="display:inline-block; padding: 0.6rem 1.25rem; background: var(--primary); color: #ffffff; font-weight: 600; border-radius: 6px; text-decoration: none;">&larr; Return to My Tickets</a>';
    echo '</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch Ticket with JOINs
try {
    $stmt = $db->prepare("
        SELECT t.*,
               p.pc_code, p.pc_name, p.location as pc_location, p.operational_status as pc_status,
               u.full_name as reporter_name, u.username as reporter_username, u.email as reporter_email,
               r.full_name as resolved_by_name, r.username as resolved_by_username
        FROM tickets t
        JOIN pcs p ON t.pc_id = p.pc_id
        JOIN users u ON t.reporter_id = u.user_id
        LEFT JOIN users r ON t.resolved_by = r.user_id
        WHERE t.ticket_id = :ticket_id
        LIMIT 1
    ");
    $stmt->execute([':ticket_id' => $ticketId]);
    $ticket = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Error fetching ticket: " . $e->getMessage());
    $ticket = null;
}

if (!$ticket) {
    http_response_code(404);
    $page_title = 'Ticket Not Found';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div style="max-width: 600px; margin: 4rem auto; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 8px; padding: 2.5rem;">';
    echo '<h1 style="color: #ef4444; margin-bottom: 0.5rem;">Ticket Not Found</h1>';
    echo '<p style="color: var(--text-secondary); margin-bottom: 1.5rem;">The requested ticket could not be found or may have been removed.</p>';
    echo '<a href="' . APP_URL . '/user/my-tickets.php" style="display:inline-block; padding: 0.6rem 1.25rem; background: var(--primary); color: #ffffff; font-weight: 600; border-radius: 6px; text-decoration: none;">&larr; Return to My Tickets</a>';
    echo '</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// CRITICAL SECURITY OWNERSHIP CHECK:
// The reporter_id must match the currently logged-in user_id
if ((int)$ticket['reporter_id'] !== (int)$currentUserId) {
    http_response_code(403);
    $page_title = '403 Forbidden - Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div style="max-width: 600px; margin: 4rem auto; text-align: center; background: var(--bg-secondary); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 8px; padding: 2.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
        <h1 style="color: #ef4444; margin-bottom: 0.75rem; font-size: 1.75rem;">403 Forbidden</h1>
        <p style="color: var(--text-secondary); line-height: 1.6; margin-bottom: 1.5rem;">
            Access denied. You do not have permission to view this ticket because you are not the designated reporter.
        </p>
        <a href="<?= APP_URL ?>/user/my-tickets.php" style="display:inline-block; padding: 0.6rem 1.25rem; background: var(--primary); color: #ffffff; font-weight: 600; border-radius: 6px; text-decoration: none;">
            &larr; Return to My Tickets
        </a>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Handle Comment Submission (POST)
$commentError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    check_csrf();

    $commentText = trim($_POST['comment_text'] ?? '');

    if (empty($commentText)) {
        $commentError = 'Comment cannot be blank. Please enter your message.';
    } elseif (mb_strlen($commentText) < 2) {
        $commentError = 'Comment is too short.';
    } elseif (mb_strlen($commentText) > 2000) {
        $commentError = 'Comment exceeds maximum allowed length of 2,000 characters.';
    } else {
        try {
            $stmtComment = $db->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment_text, created_at)
                VALUES (:ticket_id, :user_id, :comment_text, CURRENT_TIMESTAMP)
            ");
            $stmtComment->execute([
                ':ticket_id'    => $ticketId,
                ':user_id'      => $currentUserId,
                ':comment_text' => $commentText
            ]);

            // Touch ticket updated_at
            $stmtUpdateTicket = $db->prepare("
                UPDATE tickets 
                SET updated_at = CURRENT_TIMESTAMP 
                WHERE ticket_id = :ticket_id
            ");
            $stmtUpdateTicket->execute([':ticket_id' => $ticketId]);

            set_flash('success', 'Your comment has been added successfully.');
            redirect(APP_URL . "/user/ticket-details.php?id={$ticketId}#comments");

        } catch (PDOException $e) {
            error_log("Error adding ticket comment: " . $e->getMessage());
            $commentError = 'An unexpected database error occurred while saving your comment.';
        }
    }
}

// Fetch Status History Timeline
try {
    $stmtHistory = $db->prepare("
        SELECT h.*, u.full_name as changed_by_name, u.role as changed_by_role
        FROM ticket_status_history h
        LEFT JOIN users u ON h.changed_by = u.user_id
        WHERE h.ticket_id = :ticket_id
        ORDER BY h.history_id ASC
    ");
    $stmtHistory->execute([':ticket_id' => $ticketId]);
    $historyTimeline = $stmtHistory->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching status history: " . $e->getMessage());
    $historyTimeline = [];
}

// Fetch Comments
try {
    $stmtComments = $db->prepare("
        SELECT c.*, u.full_name as commenter_name, u.username as commenter_username, u.role as commenter_role
        FROM ticket_comments c
        LEFT JOIN users u ON c.user_id = u.user_id
        WHERE c.ticket_id = :ticket_id
        ORDER BY c.created_at ASC
    ");
    $stmtComments->execute([':ticket_id' => $ticketId]);
    $comments = $stmtComments->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching comments: " . $e->getMessage());
    $comments = [];
}

$page_title = 'Ticket ' . $ticket['ticket_number'];
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .details-container {
        max-width: 1000px;
        margin: 0 auto;
    }

    .top-nav-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 500;
        transition: color 0.15s ease;
    }

    .back-link:hover {
        color: var(--primary);
    }

    /* Ticket Header Card */
    .ticket-header-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 1.75rem;
        margin-bottom: 1.75rem;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    }

    .ticket-title-row {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .ticket-title-group h1 {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.35rem;
        line-height: 1.3;
    }

    .ticket-number-badge {
        font-family: monospace;
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--primary);
        background: rgba(56, 189, 248, 0.1);
        border: 1px solid rgba(56, 189, 248, 0.25);
        padding: 0.2rem 0.6rem;
        border-radius: 4px;
        display: inline-block;
        margin-bottom: 0.5rem;
    }

    .ticket-badge-group {
        display: flex;
        gap: 0.6rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        padding-top: 1.25rem;
        border-top: 1px solid var(--border);
        font-size: 0.875rem;
    }

    .meta-item {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .meta-label {
        font-size: 0.775rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-secondary);
        font-weight: 600;
    }

    .meta-value {
        color: var(--text-primary);
        font-weight: 500;
    }

    /* Description Card */
    .detail-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 1.75rem;
        margin-bottom: 1.75rem;
    }

    .card-heading {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .description-body {
        color: #e2e8f0;
        font-size: 0.95rem;
        line-height: 1.7;
        white-space: pre-wrap;
        word-break: break-word;
        background: var(--bg-primary);
        padding: 1.25rem;
        border-radius: 6px;
        border: 1px solid rgba(255, 255, 255, 0.05);
    }

    /* Resolution Card */
    .resolution-card {
        background: rgba(34, 197, 94, 0.08);
        border: 1px solid rgba(34, 197, 94, 0.35);
        border-radius: 8px;
        padding: 1.75rem;
        margin-bottom: 1.75rem;
    }

    .resolution-heading {
        font-size: 1.15rem;
        font-weight: 600;
        color: #86efac;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .resolution-notes {
        color: #f0fdf4;
        font-size: 0.95rem;
        line-height: 1.6;
        white-space: pre-wrap;
        margin-bottom: 1rem;
    }

    .resolution-meta {
        font-size: 0.825rem;
        color: #86efac;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        border-top: 1px solid rgba(34, 197, 94, 0.2);
        padding-top: 0.75rem;
    }

    /* Timeline Section */
    .timeline {
        position: relative;
        padding-left: 2rem;
        margin-top: 1rem;
    }

    .timeline::before {
        content: '';
        position: absolute;
        top: 8px;
        bottom: 8px;
        left: 9px;
        width: 2px;
        background: var(--border);
    }

    .timeline-item {
        position: relative;
        margin-bottom: 1.5rem;
    }

    .timeline-item:last-child {
        margin-bottom: 0;
    }

    .timeline-dot {
        position: absolute;
        left: -2rem;
        top: 4px;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: var(--bg-secondary);
        border: 2px solid var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .timeline-dot-inner {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--primary);
    }

    .timeline-content {
        background: var(--bg-primary);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 0.85rem 1.15rem;
    }

    .timeline-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.35rem;
    }

    .timeline-title {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .timeline-time {
        font-size: 0.775rem;
        color: var(--text-secondary);
    }

    .timeline-note {
        font-size: 0.85rem;
        color: var(--text-secondary);
        margin-top: 0.25rem;
    }

    /* Comments Section */
    .comments-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        margin-bottom: 1.75rem;
    }

    .comment-card {
        background: var(--bg-primary);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 1.15rem;
    }

    .comment-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
    }

    .comment-user-info {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .comment-user-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .comment-time {
        font-size: 0.8rem;
        color: var(--text-secondary);
    }

    .comment-content {
        font-size: 0.925rem;
        color: #e2e8f0;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
    }

    /* Comment Form */
    .comment-form-container {
        border-top: 1px solid var(--border);
        padding-top: 1.5rem;
        margin-top: 1.5rem;
    }

    .comment-textarea {
        width: 100%;
        padding: 0.75rem 1rem;
        background: var(--bg-primary);
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text-primary);
        font-size: 0.95rem;
        font-family: inherit;
        resize: vertical;
        min-height: 100px;
    }

    .comment-textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
    }

    .comment-footer-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 0.75rem;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 0.55rem 1.15rem;
        border-radius: 6px;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1px solid transparent;
    }

    .btn-primary {
        background-color: var(--primary);
        color: #ffffff;
        border-color: var(--primary);
    }

    .btn-primary:hover {
        background-color: var(--primary-hover);
        border-color: var(--primary-hover);
    }

    .form-error-alert {
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.35);
        color: #fca5a5;
        padding: 0.75rem 1rem;
        border-radius: 6px;
        font-size: 0.875rem;
        margin-bottom: 1rem;
    }
</style>

<div class="details-container">
    <div class="top-nav-bar">
        <a href="<?= APP_URL ?>/user/my-tickets.php" class="back-link">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Back to My Tickets
        </a>
        <div style="font-size: 0.85rem; color: var(--text-secondary);">
            Last updated: <?= htmlspecialchars(time_ago($ticket['updated_at'])) ?>
        </div>
    </div>

    <!-- Ticket Summary Header Card -->
    <div class="ticket-header-card">
        <div class="ticket-number-badge"><?= htmlspecialchars($ticket['ticket_number']) ?></div>
        
        <div class="ticket-title-row">
            <div class="ticket-title-group">
                <h1><?= htmlspecialchars($ticket['title']) ?></h1>
            </div>
            <div class="ticket-badge-group">
                <span class="badge <?= get_priority_badge_class($ticket['priority']) ?>">
                    <?= htmlspecialchars($ticket['priority']) ?> Priority
                </span>
                <span class="badge <?= get_status_badge_class($ticket['status']) ?>">
                    <?= htmlspecialchars($ticket['status']) ?>
                </span>
            </div>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">Workstation</span>
                <span class="meta-value">
                    <?= htmlspecialchars($ticket['pc_code']) ?>
                    <?php if (!empty($ticket['pc_name'])): ?>
                        <span style="color: var(--text-secondary); font-size: 0.8rem;">(<?= htmlspecialchars($ticket['pc_name']) ?>)</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="meta-item">
                <span class="meta-label">Location</span>
                <span class="meta-value"><?= htmlspecialchars($ticket['pc_location'] ?? 'Not Specified') ?></span>
            </div>

            <div class="meta-item">
                <span class="meta-label">Category</span>
                <span class="meta-value"><?= htmlspecialchars($ticket['category']) ?></span>
            </div>

            <div class="meta-item">
                <span class="meta-label">Submitted On</span>
                <span class="meta-value"><?= htmlspecialchars(format_datetime($ticket['created_at'])) ?></span>
            </div>
        </div>
    </div>

    <!-- Ticket Description -->
    <div class="detail-card">
        <div class="card-heading">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            Issue Description
        </div>
        <div class="description-body"><?= htmlspecialchars($ticket['description']) ?></div>
    </div>

    <!-- Resolution Section (if resolved/closed with notes) -->
    <?php if (!empty($ticket['resolution_notes']) || !empty($ticket['resolved_at'])): ?>
        <div class="resolution-card">
            <div class="resolution-heading">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                Resolution Details
            </div>
            <?php if (!empty($ticket['resolution_notes'])): ?>
                <div class="resolution-notes"><?= htmlspecialchars($ticket['resolution_notes']) ?></div>
            <?php else: ?>
                <div class="resolution-notes" style="font-style: italic; color: #86efac;">
                    This ticket has been marked as resolved by technical staff.
                </div>
            <?php endif; ?>
            <div class="resolution-meta">
                <?php if (!empty($ticket['resolved_by_name'])): ?>
                    <span><strong>Resolved by:</strong> <?= htmlspecialchars($ticket['resolved_by_name']) ?></span>
                <?php endif; ?>
                <?php if (!empty($ticket['resolved_at'])): ?>
                    <span><strong>Resolved on:</strong> <?= htmlspecialchars(format_datetime($ticket['resolved_at'])) ?> (<?= htmlspecialchars(time_ago($ticket['resolved_at'])) ?>)</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Status History Timeline -->
    <div class="detail-card">
        <div class="card-heading">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            Status History Timeline
        </div>

        <?php if (empty($historyTimeline)): ?>
            <p style="color: var(--text-secondary); font-size: 0.9rem;">No status history recorded yet.</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($historyTimeline as $item): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot">
                            <div class="timeline-dot-inner"></div>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <span class="timeline-title">
                                    <?php if (!empty($item['old_status'])): ?>
                                        <span class="badge <?= get_status_badge_class($item['old_status']) ?>" style="font-size: 0.7rem; padding: 0.15rem 0.4rem;">
                                            <?= htmlspecialchars($item['old_status']) ?>
                                        </span>
                                        &rarr;
                                    <?php endif; ?>
                                    <span class="badge <?= get_status_badge_class($item['new_status']) ?>" style="font-size: 0.7rem; padding: 0.15rem 0.4rem;">
                                        <?= htmlspecialchars($item['new_status']) ?>
                                    </span>
                                    <span style="margin-left: 0.5rem; font-weight: normal; color: var(--text-secondary); font-size: 0.8rem;">
                                        by <?= htmlspecialchars($item['changed_by_name'] ?? 'System') ?>
                                        <?php if (!empty($item['changed_by_role'])): ?>
                                            (<?= htmlspecialchars(ucfirst($item['changed_by_role'])) ?>)
                                        <?php endif; ?>
                                    </span>
                                </span>
                                <span class="timeline-time">
                                    <?= htmlspecialchars(format_datetime($item['changed_at'])) ?>
                                    (<?= htmlspecialchars(time_ago($item['changed_at'])) ?>)
                                </span>
                            </div>
                            <?php if (!empty($item['note'])): ?>
                                <div class="timeline-note">
                                    <?= htmlspecialchars($item['note']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Comments & Discussion Section -->
    <div class="detail-card" id="comments">
        <div class="card-heading">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
            Discussion & Technician Notes (<?= count($comments) ?>)
        </div>

        <?php if (!empty($commentError)): ?>
            <div class="form-error-alert">
                <?= htmlspecialchars($commentError) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($comments)): ?>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem;">
                No comments or responses yet. You can provide additional information below if needed.
            </p>
        <?php else: ?>
            <div class="comments-list">
                <?php foreach ($comments as $comment): ?>
                    <div class="comment-card">
                        <div class="comment-header">
                            <div class="comment-user-info">
                                <span class="comment-user-name">
                                    <?= htmlspecialchars($comment['commenter_name'] ?? $comment['commenter_username'] ?? 'User') ?>
                                </span>
                                <span class="role-badge <?= ($comment['commenter_role'] ?? 'user') === 'admin' ? 'role-badge-admin' : 'role-badge-user' ?>">
                                    <?= htmlspecialchars(strtoupper($comment['commenter_role'] ?? 'user')) ?>
                                </span>
                            </div>
                            <span class="comment-time">
                                <?= htmlspecialchars(format_datetime($comment['created_at'])) ?> 
                                (<?= htmlspecialchars(time_ago($comment['created_at'])) ?>)
                            </span>
                        </div>
                        <div class="comment-content"><?= htmlspecialchars($comment['comment_text']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Add Comment Form -->
        <div class="comment-form-container">
            <h3 style="font-size: 1rem; color: var(--text-primary); margin-bottom: 0.75rem;">Add a Note or Reply</h3>
            <form action="<?= APP_URL ?>/user/ticket-details.php?id=<?= $ticketId ?>" method="POST">
                <?= csrf_token_field() ?>
                <input type="hidden" name="action" value="add_comment">

                <textarea 
                    name="comment_text" 
                    id="commentText" 
                    rows="4" 
                    class="comment-textarea" 
                    placeholder="Type your question or additional details here..." 
                    maxlength="2000"
                    required
                ></textarea>

                <div class="comment-footer-bar">
                    <span id="commentCharCount" style="font-size: 0.75rem; color: var(--text-secondary);">
                        0 / 2000 characters
                    </span>
                    <button type="submit" class="btn btn-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                        Post Comment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const commentArea = document.getElementById('commentText');
    const commentCounter = document.getElementById('commentCharCount');

    if (commentArea && commentCounter) {
        commentArea.addEventListener('input', function () {
            const len = this.value.length;
            commentCounter.textContent = len + ' / 2000 characters';
            if (len >= 2000) {
                commentCounter.style.color = 'var(--danger)';
                commentCounter.style.fontWeight = 'bold';
            } else {
                commentCounter.style.color = 'var(--text-secondary)';
                commentCounter.style.fontWeight = 'normal';
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';

