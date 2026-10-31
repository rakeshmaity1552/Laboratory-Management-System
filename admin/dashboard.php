<?php
/**
 * Admin Dashboard
 * Laboratory Management System (LMS)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$db = getDB();

// 1. Fetch PC Status Counts for active PCs
$pcStatusCounts = [
    'Working'           => 0,
    'Reported Issue'    => 0,
    'Under Maintenance' => 0,
    'Out of Service'    => 0,
    'Inactive'          => 0,
];

try {
    $stmt = $db->query("
        SELECT operational_status, COUNT(*) AS count
        FROM pcs
        WHERE is_active = 1
        GROUP BY operational_status
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($pcStatusCounts[$row['operational_status']])) {
            $pcStatusCounts[$row['operational_status']] = (int)$row['count'];
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching PC stats: " . $e->getMessage());
}

// Total active PCs
$totalActivePcs = 0;
try {
    $totalActivePcs = (int)$db->query("SELECT COUNT(*) FROM pcs WHERE is_active = 1")->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching total active PCs: " . $e->getMessage());
}

// 2. Fetch Ticket Status Counts using GROUP BY
$ticketStatusCounts = [];
foreach (ALLOWED_TICKET_STATUSES as $status) {
    $ticketStatusCounts[$status] = 0;
}

try {
    $stmt = $db->query("
        SELECT status, COUNT(*) AS count
        FROM tickets
        GROUP BY status
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ticketStatusCounts[$row['status']] = (int)$row['count'];
    }
} catch (PDOException $e) {
    error_log("Error fetching ticket status stats: " . $e->getMessage());
}

$totalTickets = 0;
try {
    $totalTickets = (int)$db->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching total tickets: " . $e->getMessage());
}

// 3. Resolved tickets in the last 7 days
$resolvedThisWeek = 0;
try {
    $stmt = $db->query("
        SELECT COUNT(*) 
        FROM tickets 
        WHERE status IN ('Resolved', 'Closed') 
        AND (
            (resolved_at IS NOT NULL AND resolved_at >= datetime('now', '-7 days'))
            OR (resolved_at IS NULL AND updated_at >= datetime('now', '-7 days'))
        )
    ");
    $resolvedThisWeek = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching resolved this week: " . $e->getMessage());
}

// 4. Fetch all PCs for the visual status grid
$allPcs = [];
try {
    $stmt = $db->query("
        SELECT 
            p.pc_id,
            p.pc_code,
            p.pc_name,
            p.location,
            p.operational_status,
            p.is_active,
            (SELECT COUNT(*) FROM tickets t WHERE t.pc_id = p.pc_id AND t.status NOT IN ('Resolved', 'Closed', 'Rejected')) AS open_tickets_count
        FROM pcs p
        ORDER BY p.pc_code ASC
    ");
    $allPcs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching PCs grid: " . $e->getMessage());
}

// 5. Fetch recent tickets (last 10)
$recentTickets = [];
try {
    $stmt = $db->query("
        SELECT 
            t.ticket_id,
            t.ticket_number,
            t.title,
            t.category,
            t.priority,
            t.status,
            t.created_at,
            p.pc_code,
            u.full_name AS reporter_name,
            u.username AS reporter_username
        FROM tickets t
        LEFT JOIN pcs p ON t.pc_id = p.pc_id
        LEFT JOIN users u ON t.reporter_id = u.user_id
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $recentTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching recent tickets: " . $e->getMessage());
}

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Admin Dashboard CSS Fallback / Enhancement -->
<style>
.dashboard-wrap {
    display: flex;
    flex-direction: column;
    gap: 1.75rem;
}
.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
}
.page-heading h1 {
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--text-primary, #1e293b);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
}
.stats-section-title {
    font-size: 0.9rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-secondary, #64748b);
    margin-bottom: 0.85rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 1rem;
}
.stat-card {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 1.15rem 1.25rem;
    text-decoration: none !important;
    color: inherit;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    position: relative;
    overflow: hidden;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.3);
    border-color: var(--primary, #2563eb);
}
.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--primary, #2563eb);
}
.stat-card.card-working::before { background: #22c55e; }
.stat-card.card-issue::before { background: #f59e0b; }
.stat-card.card-maintenance::before { background: #a855f7; }
.stat-card.card-outofservice::before { background: #ef4444; }
.stat-card.card-open::before { background: #0284c7; }
.stat-card.card-progress::before { background: #d97706; }
.stat-card.card-resolved::before { background: #10b981; }

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}
.stat-title {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-secondary, #64748b);
}
.stat-icon {
    display: inline-flex;
    width: 1.25rem;
    height: 1.25rem;
    opacity: 0.8;
}
.stat-icon svg {
    width: 100%;
    height: 100%;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.stat-num {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.2;
    color: var(--text-primary, #1e293b);
}
.stat-desc {
    font-size: 0.75rem;
    color: #64748b;
    margin-top: 0.35rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* PC Grid */
.card-box {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
}
.card-box-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.card-box-header h2 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary, #1e293b);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.card-box-body {
    padding: 1.25rem;
}
.pc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 0.85rem;
}
.pc-node {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 6px;
    padding: 0.85rem 0.65rem;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.3rem;
    text-decoration: none !important;
    color: inherit;
    transition: all 0.15s ease;
    position: relative;
}
.pc-node:hover {
    transform: translateY(-2px);
    border-color: var(--primary, #2563eb);
    background: #ffffff;
}
.pc-node.status-working { border-top: 3px solid #22c55e; }
.pc-node.status-reported-issue { border-top: 3px solid #f59e0b; }
.pc-node.status-under-maintenance { border-top: 3px solid #a855f7; }
.pc-node.status-out-of-service { border-top: 3px solid #ef4444; }
.pc-node.status-inactive { border-top: 3px solid #64748b; opacity: 0.55; }

.pc-node-code {
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--text-primary, #1e293b);
}
.pc-node-name {
    font-size: 0.725rem;
    color: var(--text-secondary, #64748b);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
.pc-node-badge {
    margin-top: 0.2rem;
    font-size: 0.65rem;
    padding: 0.15rem 0.4rem;
}
.pc-open-alert {
    position: absolute;
    top: 4px;
    right: 4px;
    background: #ef4444;
    color: #fff;
    font-size: 0.65rem;
    font-weight: 700;
    border-radius: 9999px;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Recent Tickets Table */
.data-table-wrap {
    overflow-x: auto;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    text-align: left;
}
.data-table th {
    background: rgba(255,255,255,0.03);
    color: var(--text-secondary, #64748b);
    font-weight: 600;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
    font-size: 0.775rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    white-space: nowrap;
}
.data-table td {
    padding: 0.8rem 1rem;
    border-bottom: 1px solid rgba(51, 65, 85, 0.4);
    vertical-align: middle;
}
.data-table tbody tr:hover {
    background: rgba(255,255,255,0.02);
}
.btn-sm-action {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    font-size: 0.775rem;
    font-weight: 600;
    border-radius: 4px;
    background: rgba(56, 189, 248, 0.15);
    color: var(--primary, #2563eb);
    border: 1px solid rgba(56, 189, 248, 0.3);
    text-decoration: none !important;
    transition: all 0.15s ease;
}
.btn-sm-action:hover {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
}
.text-muted {
    color: #64748b;
}
.empty-notice {
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--text-secondary, #64748b);
}
</style>

<div class="dashboard-wrap">
    <!-- Header -->
    <div class="page-heading">
        <div>
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7"></rect>
                    <rect x="14" y="3" width="7" height="7"></rect>
                    <rect x="14" y="14" width="7" height="7"></rect>
                    <rect x="3" y="14" width="7" height="7"></rect>
                </svg>
                Admin Command Center
            </h1>
            <p style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.25rem;">
                Live laboratory workstation health metrics and issue ticketing status.
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="<?= APP_URL ?>/admin/pc-edit.php" class="btn-sm-action" style="padding: 0.45rem 0.9rem; font-size: 0.85rem;">
                + Add Workstation PC
            </a>
            <a href="<?= APP_URL ?>/admin/tickets.php" class="btn-sm-action" style="padding: 0.45rem 0.9rem; font-size: 0.85rem; background: rgba(255,255,255,0.05); color: var(--text-primary); border-color: var(--border);">
                View All Tickets
            </a>
            <a href="<?= APP_URL ?>/admin/audit-log.php" class="btn-sm-action" style="padding: 0.45rem 0.9rem; font-size: 0.85rem; background: rgba(255,255,255,0.05); color: var(--text-primary); border-color: var(--border);">
                Audit Log
            </a>
        </div>
    </div>

    <!-- Section 1: Workstation Health Stats -->
    <div>
        <div class="stats-section-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
            </svg>
            Workstation Infrastructure Status
        </div>
        <div class="stats-grid">
            <!-- Total Active PCs -->
            <a href="<?= APP_URL ?>/admin/pcs.php?active=1" class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Total Active PCs</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/></svg></span>
                </div>
                <div class="stat-num"><?= $totalActivePcs ?></div>
                <div class="stat-desc">
                    <span>Deployed in Lab</span>
                    <span>View all &rarr;</span>
                </div>
            </a>

            <!-- Working PCs -->
            <a href="<?= APP_URL ?>/admin/pcs.php?status=Working" class="stat-card card-working">
                <div class="stat-header">
                    <span class="stat-title">Working</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></span>
                </div>
                <div class="stat-num" style="color: #22c55e;"><?= $pcStatusCounts['Working'] ?></div>
                <div class="stat-desc">
                    <span>Operational & Ready</span>
                    <span>Filter &rarr;</span>
                </div>
            </a>

            <!-- Reported Issue PCs -->
            <a href="<?= APP_URL ?>/admin/pcs.php?status=Reported+Issue" class="stat-card card-issue">
                <div class="stat-header">
                    <span class="stat-title">Reported Issue</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5m0 3h.01"/></svg></span>
                </div>
                <div class="stat-num" style="color: #f59e0b;"><?= $pcStatusCounts['Reported Issue'] ?></div>
                <div class="stat-desc">
                    <span>Needs Attention</span>
                    <span>Filter &rarr;</span>
                </div>
            </a>

            <!-- Under Maintenance PCs -->
            <a href="<?= APP_URL ?>/admin/pcs.php?status=Under+Maintenance" class="stat-card card-maintenance">
                <div class="stat-header">
                    <span class="stat-title">Under Maintenance</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a5 5 0 0 0-6.6 6.6L3 18l3 3 5.1-5.1a5 5 0 0 0 6.6-6.6L14 13l-3-3 3.7-3.7Z"/></svg></span>
                </div>
                <div class="stat-num" style="color: #a855f7;"><?= $pcStatusCounts['Under Maintenance'] ?></div>
                <div class="stat-desc">
                    <span>Being Serviced</span>
                    <span>Filter &rarr;</span>
                </div>
            </a>

            <!-- Out of Service PCs -->
            <a href="<?= APP_URL ?>/admin/pcs.php?status=Out+of+Service" class="stat-card card-outofservice">
                <div class="stat-header">
                    <span class="stat-title">Out of Service</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10l4 4v10l-4 4H7l-4-4V7l4-4Z"/><path d="m9 9 6 6m0-6-6 6"/></svg></span>
                </div>
                <div class="stat-num" style="color: #ef4444;"><?= $pcStatusCounts['Out of Service'] ?></div>
                <div class="stat-desc">
                    <span>Offline / Defective</span>
                    <span>Filter &rarr;</span>
                </div>
            </a>
        </div>
    </div>

    <!-- Section 2: Ticket Metrics -->
    <div>
        <div class="stats-section-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            Support Tickets Overview
        </div>
        <div class="stats-grid">
            <!-- Total Tickets -->
            <a href="<?= APP_URL ?>/admin/tickets.php" class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Total Tickets</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h12v17H6zM9 4V2h6v2"/><path d="M9 9h6m-6 4h6m-6 4h4"/></svg></span>
                </div>
                <div class="stat-num"><?= $totalTickets ?></div>
                <div class="stat-desc">
                    <span>All-time reports</span>
                    <span>View all &rarr;</span>
                </div>
            </a>

            <!-- Open Tickets -->
            <a href="<?= APP_URL ?>/admin/tickets.php?status=Open" class="stat-card card-open">
                <div class="stat-header">
                    <span class="stat-title">Open Tickets</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8m-4-4h8"/></svg></span>
                </div>
                <div class="stat-num" style="color: #2563eb;"><?= $ticketStatusCounts['Open'] ?? 0 ?></div>
                <div class="stat-desc">
                    <span>Awaiting triage</span>
                    <span>Filter &rarr;</span>
                </div>
            </a>

            <!-- In Progress Tickets -->
            <a href="<?= APP_URL ?>/admin/tickets.php?status=In+Progress" class="stat-card card-progress">
                <div class="stat-header">
                    <span class="stat-title">In Progress</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                </div>
                <div class="stat-num" style="color: #f59e0b;"><?= $ticketStatusCounts['In Progress'] ?? 0 ?></div>
                <div class="stat-desc">
                    <span>Currently being fixed</span>
                    <span>Filter &rarr;</span>
                </div>
            </a>

            <!-- Resolved Tickets (This Week) -->
            <a href="<?= APP_URL ?>/admin/tickets.php?status=Resolved" class="stat-card card-resolved">
                <div class="stat-header">
                    <span class="stat-title">Resolved (This Week)</span>
<span class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 21h8m-7-4h6l1 4H8l1-4Zm-2-13h12v4a6 6 0 0 1-12 0V4Z"/><path d="M5 6H3v2a5 5 0 0 0 4 5m12-7h2v2a5 5 0 0 1-4 5"/></svg></span>
                </div>
                <div class="stat-num" style="color: #10b981;"><?= $resolvedThisWeek ?></div>
                <div class="stat-desc">
                    <span>Completed in last 7 days</span>
                    <span>Filter &rarr;</span>
                </div>
            </a>
        </div>
    </div>

    <!-- Section 3: PC Status Grid Visualization -->
    <div class="card-box">
        <div class="card-box-header">
            <h2>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
                Workstation Layout Grid (<?= count($allPcs) ?> Workstations)
            </h2>
            <div style="font-size: 0.8rem; color: var(--text-secondary); display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <span><span style="color:#22c55e;">â—</span> Working</span>
                <span><span style="color:#f59e0b;">â—</span> Reported Issue</span>
                <span><span style="color:#a855f7;">â—</span> Maintenance</span>
                <span><span style="color:#ef4444;">â—</span> Out of Service</span>
            </div>
        </div>
        <div class="card-box-body">
            <?php if (empty($allPcs)): ?>
                <div class="empty-notice">
                    <p>No workstations found in database.</p>
                    <a href="<?= APP_URL ?>/admin/pc-edit.php" class="btn-sm-action" style="margin-top: 0.5rem;">+ Add First Workstation</a>
                </div>
            <?php else: ?>
                <div class="pc-grid">
                    <?php foreach ($allPcs as $pc): 
                        $statusSlug = strtolower(str_replace(' ', '-', $pc['operational_status']));
                        $badgeClass = get_status_badge_class($pc['operational_status']);
                    ?>
                        <a href="<?= APP_URL ?>/admin/pc-edit.php?id=<?= $pc['pc_id'] ?>" 
                           class="pc-node status-<?= $statusSlug ?>" 
                           title="<?= htmlspecialchars($pc['pc_name'] ?? $pc['pc_code']) ?> (<?= htmlspecialchars($pc['operational_status']) ?>)">
                            
                            <?php if ($pc['open_tickets_count'] > 0): ?>
                                <span class="pc-open-alert" title="<?= $pc['open_tickets_count'] ?> active ticket(s)">
                                    <?= $pc['open_tickets_count'] ?>
                                </span>
                            <?php endif; ?>

                            <span class="pc-node-code"><?= htmlspecialchars($pc['pc_code']) ?></span>
                            
                            <span class="pc-node-name">
                                <?= !empty($pc['pc_name']) ? htmlspecialchars($pc['pc_name']) : htmlspecialchars($pc['location'] ?? 'Lab Desk') ?>
                            </span>

                            <span class="badge <?= $badgeClass ?> pc-node-badge">
                                <?= htmlspecialchars($pc['operational_status']) ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 4: Recent Tickets -->
    <div class="card-box">
        <div class="card-box-header">
            <h2>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                Recent Support Tickets (Last 10)
            </h2>
            <a href="<?= APP_URL ?>/admin/tickets.php" class="btn-sm-action">
                View All Tickets &rarr;
            </a>
        </div>
        <div class="card-box-body" style="padding: 0;">
            <?php if (empty($recentTickets)): ?>
                <div class="empty-notice">
                    <p>No tickets reported yet. The lab is running smoothly!</p>
                </div>
            <?php else: ?>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>PC</th>
                                <th>Reporter</th>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTickets as $ticket): ?>
                                <tr>
                                    <td>
                                        <a href="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $ticket['ticket_id'] ?>" style="font-weight: 700; font-family: monospace;">
                                            <?= htmlspecialchars($ticket['ticket_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: var(--text-primary);">
                                            <?= htmlspecialchars($ticket['pc_code'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($ticket['reporter_name'] ?? $ticket['reporter_username'] ?? 'User #' . $ticket['reporter_id']) ?>
                                    </td>
                                    <td>
                                        <span title="<?= htmlspecialchars($ticket['title']) ?>">
                                            <?= htmlspecialchars(mb_strimwidth($ticket['title'], 0, 38, '...')) ?>
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
                                        <span title="<?= format_datetime($ticket['created_at']) ?>">
                                            <?= time_ago($ticket['created_at']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $ticket['ticket_id'] ?>" class="btn-sm-action">
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
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';

