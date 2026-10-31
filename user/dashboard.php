<?php
/**
 * User Dashboard
 * Laboratory Management System (LMS)
 * Path: user/dashboard.php
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

// Summary Statistics
$totalActivePcs = 0;
$workingPcs = 0;
$pcsWithIssues = 0;

try {
    // Total active PCs
    $stmt = $db->query("SELECT COUNT(*) FROM pcs WHERE is_active = 1");
    $totalActivePcs = (int)$stmt->fetchColumn();

    // Working PCs
    $stmt = $db->query("SELECT COUNT(*) FROM pcs WHERE is_active = 1 AND operational_status = 'Working'");
    $workingPcs = (int)$stmt->fetchColumn();

    // PCs with issues (active but operational_status != 'Working')
    $stmt = $db->query("SELECT COUNT(*) FROM pcs WHERE is_active = 1 AND operational_status != 'Working'");
    $pcsWithIssues = (int)$stmt->fetchColumn();

    // Fetch all active PCs for grid
    $stmt = $db->query("
        SELECT pc_id, pc_code, pc_name, location, operational_status, notes 
        FROM pcs 
        WHERE is_active = 1 
        ORDER BY pc_code ASC
    ");
    $pcs = $stmt->fetchAll();

    // Fetch user's recent tickets (last 5)
    $stmt = $db->prepare("
        SELECT t.ticket_id, t.ticket_number, t.title, t.category, t.priority, t.status, t.created_at,
               p.pc_code, p.pc_name
        FROM tickets t
        JOIN pcs p ON t.pc_id = p.pc_id
        WHERE t.reporter_id = :user_id
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([':user_id' => $currentUserId]);
    $recentTickets = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    $pcs = [];
    $recentTickets = [];
}

$page_title = 'User Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    /* Dashboard specific styling */
    .dashboard-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 2rem;
        padding-bottom: 1.25rem;
        border-bottom: 1px solid var(--border);
    }

    .welcome-text h1 {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .welcome-text p {
        color: var(--text-secondary);
        font-size: 0.95rem;
    }

    .dashboard-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 0.6rem 1.15rem;
        border-radius: 6px;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s ease-in-out;
        border: 1px solid transparent;
        line-height: 1.2;
    }

    .btn-primary {
        background-color: var(--primary);
        color: #ffffff;
        border-color: var(--primary);
    }

    .btn-primary:hover {
        background-color: var(--primary-hover);
        border-color: var(--primary-hover);
        box-shadow: 0 4px 12px rgba(56, 189, 248, 0.25);
    }

    .btn-secondary {
        background-color: var(--bg-secondary);
        color: var(--text-primary);
        border-color: var(--border);
    }

    .btn-secondary:hover {
        background-color: var(--bg-tertiary);
        border-color: #cbd5e1;
    }

    .btn-sm {
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
    }

    .btn-danger-outline {
        background: transparent;
        color: #f87171;
        border-color: rgba(239, 68, 68, 0.4);
    }

    .btn-danger-outline:hover {
        background-color: var(--danger);
        color: #fff;
        border-color: var(--danger);
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2.5rem;
    }

    .stat-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 1.25rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        transition: transform 0.15s ease, border-color 0.15s ease;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        border-color: #cbd5e1;
    }

    .stat-info h3 {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-secondary);
        margin-bottom: 0.4rem;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1;
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .stat-icon-blue {
        background: rgba(56, 189, 248, 0.15);
        color: var(--primary);
    }

    .stat-icon-green {
        background: rgba(34, 197, 94, 0.15);
        color: var(--success);
    }

    .stat-icon-amber {
        background: rgba(245, 158, 11, 0.15);
        color: var(--warning);
    }

    /* Section Styling */
    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.25rem;
    }

    .section-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .section-meta {
        font-size: 0.875rem;
        color: var(--text-secondary);
    }

    /* PC Grid */
    .pc-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 1.25rem;
        margin-bottom: 3rem;
    }

    .pc-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 1rem;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .pc-card:hover {
        border-color: #cbd5e1;
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
    }

    .pc-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .pc-identity {
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .pc-icon {
        color: var(--primary);
        background: rgba(56, 189, 248, 0.1);
        padding: 0.4rem;
        border-radius: 6px;
    }

    .pc-code {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .pc-name {
        font-size: 0.85rem;
        color: var(--text-secondary);
    }

    .pc-location {
        font-size: 0.8rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 0.35rem;
        margin-top: 0.25rem;
    }

    .pc-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-top: 0.75rem;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
    }

    .status-notice {
        font-size: 0.775rem;
        color: #64748b;
        font-style: italic;
    }

    /* Recent Tickets Card & Table */
    .card-box {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
    }

    .styled-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
        text-align: left;
    }

    .styled-table thead th {
        background: rgba(0, 0, 0, 0.2);
        color: var(--text-secondary);
        font-weight: 600;
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid var(--border);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .styled-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background-color 0.15s ease;
    }

    .styled-table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.02);
    }

    .styled-table tbody td {
        padding: 0.9rem 1.25rem;
        vertical-align: middle;
        color: var(--text-primary);
    }

    .ticket-code-link {
        font-family: monospace;
        font-weight: 600;
        color: var(--primary);
        text-decoration: none;
    }

    .ticket-code-link:hover {
        text-decoration: underline;
    }

    .empty-state {
        padding: 3rem 1.5rem;
        text-align: center;
        color: var(--text-secondary);
    }

    .empty-state svg {
        margin-bottom: 1rem;
        color: #cbd5e1;
    }

    .empty-state p {
        font-size: 0.95rem;
        margin-bottom: 1.25rem;
    }
</style>

<div class="dashboard-header">
    <div class="welcome-text">
        <h1>Welcome, <?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'User') ?>!</h1>
        <p>College Computer Lab Management System &mdash; Monitor computer statuses and report hardware or software issues.</p>
    </div>
    <div class="dashboard-actions">
        <a href="<?= APP_URL ?>/user/create-ticket.php" class="btn btn-primary">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="16"></line>
                <line x1="8" y1="12" x2="16" y2="12"></line>
            </svg>
            Report Issue
        </a>
        <a href="<?= APP_URL ?>/user/my-tickets.php" class="btn btn-secondary">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            My Tickets
        </a>
    </div>
</div>

<!-- Summary Statistics Section -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-info">
            <h3>Active Lab PCs</h3>
            <div class="stat-number"><?= $totalActivePcs ?></div>
        </div>
        <div class="stat-icon stat-icon-blue">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
            </svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Working PCs</h3>
            <div class="stat-number" style="color: #4ade80;"><?= $workingPcs ?></div>
        </div>
        <div class="stat-icon stat-icon-green">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>PCs With Issues</h3>
            <div class="stat-number" style="color: #fbbf24;"><?= $pcsWithIssues ?></div>
        </div>
        <div class="stat-icon stat-icon-amber">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
        </div>
    </div>
</div>

<!-- Laboratory PCs Grid Section -->
<div class="section-header">
    <div class="section-title">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
            <line x1="8" y1="21" x2="16" y2="21"></line>
            <line x1="12" y1="17" x2="12" y2="21"></line>
        </svg>
        <span>Computer Stations Status</span>
    </div>
    <div class="section-meta">
        Showing all <?= count($pcs) ?> workstations
    </div>
</div>

<?php if (empty($pcs)): ?>
    <div class="card-box empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
            <line x1="8" y1="21" x2="16" y2="21"></line>
            <line x1="12" y1="17" x2="12" y2="21"></line>
        </svg>
        <p>No active computers found in the lab database.</p>
    </div>
<?php else: ?>
    <div class="pc-grid">
        <?php foreach ($pcs as $pc): ?>
            <?php 
                $isWorking = (strcasecmp($pc['operational_status'], 'Working') === 0);
                $badgeClass = get_status_badge_class($pc['operational_status']);
            ?>
            <div class="pc-card">
                <div>
                    <div class="pc-card-header">
                        <div class="pc-identity">
                            <div class="pc-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                    <line x1="8" y1="21" x2="16" y2="21"></line>
                                    <line x1="12" y1="17" x2="12" y2="21"></line>
                                </svg>
                            </div>
                            <div>
                                <div class="pc-code"><?= htmlspecialchars($pc['pc_code']) ?></div>
                                <div class="pc-name"><?= htmlspecialchars($pc['pc_name'] ?? 'Workstation') ?></div>
                            </div>
                        </div>
                        <span class="badge <?= $badgeClass ?>">
                            <?= htmlspecialchars($pc['operational_status']) ?>
                        </span>
                    </div>

                    <?php if (!empty($pc['location'])): ?>
                        <div class="pc-location">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            <span><?= htmlspecialchars($pc['location']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pc-card-footer">
                    <?php if ($isWorking): ?>
                        <a href="<?= APP_URL ?>/user/create-ticket.php?pc_id=<?= (int)$pc['pc_id'] ?>" class="btn btn-sm btn-danger-outline">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                            </svg>
                            Report Issue
                        </a>
                    <?php else: ?>
                        <span class="status-notice">
                            Currently <?= htmlspecialchars(strtolower($pc['operational_status'])) ?>
                        </span>
                        <a href="<?= APP_URL ?>/user/create-ticket.php?pc_id=<?= (int)$pc['pc_id'] ?>" class="btn btn-sm btn-secondary" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;" title="Report another issue with this PC">
                            Add Report
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Recent Tickets Section -->
<div class="section-header">
    <div class="section-title">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        <span>My Recent Tickets</span>
    </div>
    <div>
        <a href="<?= APP_URL ?>/user/my-tickets.php" class="btn btn-secondary btn-sm">
            View All My Tickets &rarr;
        </a>
    </div>
</div>

<div class="card-box">
    <?php if (empty($recentTickets)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <p>You haven't submitted any maintenance tickets yet.</p>
            <a href="<?= APP_URL ?>/user/create-ticket.php" class="btn btn-primary btn-sm">Submit Your First Ticket</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="styled-table">
                <thead>
                    <tr>
                        <th>Ticket #</th>
                        <th>Workstation</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTickets as $ticket): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/user/ticket-details.php?id=<?= (int)$ticket['ticket_id'] ?>" class="ticket-code-link">
                                    <?= htmlspecialchars($ticket['ticket_number']) ?>
                                </a>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($ticket['pc_code']) ?></strong>
                                <?php if (!empty($ticket['pc_name'])): ?>
                                    <span style="color: var(--text-secondary); font-size: 0.8rem;">(<?= htmlspecialchars($ticket['pc_name']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span title="<?= htmlspecialchars($ticket['title']) ?>">
                                    <?= htmlspecialchars(mb_strimwidth($ticket['title'], 0, 45, '...')) ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 0.85rem; color: var(--text-secondary);">
                                    <?= htmlspecialchars($ticket['category']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= get_status_badge_class($ticket['status']) ?>">
                                    <?= htmlspecialchars($ticket['status']) ?>
                                </span>
                            </td>
                            <td style="color: var(--text-secondary); font-size: 0.85rem;">
                                <?= htmlspecialchars(format_datetime($ticket['created_at'], 'M j, Y g:i A')) ?>
                            </td>
                            <td style="text-align: right;">
                                <a href="<?= APP_URL ?>/user/ticket-details.php?id=<?= (int)$ticket['ticket_id'] ?>" class="btn btn-secondary btn-sm">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';

