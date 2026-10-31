<?php
/**
 * Manage Workstation PCs
 * Laboratory Management System (LMS)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$db = getDB();

// 1. Process Filters
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$filterActive = isset($_GET['active']) && $_GET['active'] !== '' ? trim($_GET['active']) : '';
$searchQuery  = isset($_GET['q']) ? trim($_GET['q']) : '';

$whereClauses = [];
$params = [];

if ($filterStatus !== '' && in_array($filterStatus, ALLOWED_PC_STATUSES, true)) {
    $whereClauses[] = "p.operational_status = :status";
    $params[':status'] = $filterStatus;
}

if ($filterActive !== '') {
    $whereClauses[] = "p.is_active = :is_active";
    $params[':is_active'] = (int)$filterActive;
}

if ($searchQuery !== '') {
    $whereClauses[] = "(p.pc_code LIKE :search OR p.pc_name LIKE :search OR p.location LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// 2. Fetch PCs
$sql = "
    SELECT 
        p.pc_id,
        p.pc_code,
        p.pc_name,
        p.location,
        p.operational_status,
        p.notes,
        p.is_active,
        p.created_at,
        p.updated_at,
        (
            SELECT COUNT(*) 
            FROM tickets t 
            WHERE t.pc_id = p.pc_id 
              AND t.status NOT IN ('Resolved', 'Closed', 'Rejected')
        ) AS open_tickets_count
    FROM pcs p
    {$whereSql}
    ORDER BY p.pc_code ASC
";

$pcs = [];
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $pcs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching PCs: " . $e->getMessage());
}

// Stats overview for filter summary
$summaryStats = [
    'total'    => 0,
    'active'   => 0,
    'inactive' => 0,
    'issues'   => 0
];
try {
    $summaryStats['total']    = (int)$db->query("SELECT COUNT(*) FROM pcs")->fetchColumn();
    $summaryStats['active']   = (int)$db->query("SELECT COUNT(*) FROM pcs WHERE is_active = 1")->fetchColumn();
    $summaryStats['inactive'] = (int)$db->query("SELECT COUNT(*) FROM pcs WHERE is_active = 0")->fetchColumn();
    $summaryStats['issues']   = (int)$db->query("SELECT COUNT(*) FROM pcs WHERE operational_status != 'Working' AND is_active = 1")->fetchColumn();
} catch (PDOException $e) {
    // Keep zeroes on failure
}

$page_title = 'Manage PCs';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.pcs-container {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.page-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
}
.page-header-row h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary, #1e293b);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
}
.filter-box {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 1.25rem;
}
.filter-form {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1.2fr auto auto;
    gap: 0.75rem;
    align-items: flex-end;
}
.filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.filter-field label {
    font-size: 0.775rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary, #64748b);
}
.filter-field input, .filter-field select {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--text-primary, #1e293b);
    padding: 0.55rem 0.75rem;
    border-radius: 4px;
    font-size: 0.875rem;
}
.filter-field input:focus, .filter-field select:focus {
    outline: none;
    border-color: var(--primary, #2563eb);
}
.btn-action-primary {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
    font-weight: 600;
    padding: 0.55rem 1rem;
    border-radius: 4px;
    border: 1px solid var(--primary, #2563eb);
    cursor: pointer;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.875rem;
}
.btn-action-primary:hover {
    background: #1d4ed8;
}
.btn-action-secondary {
    background: var(--bg-tertiary, #e2e8f0);
    color: var(--text-primary, #1e293b) !important;
    font-weight: 600;
    padding: 0.55rem 1rem;
    border-radius: 4px;
    border: 1px solid #cbd5e1;
    cursor: pointer;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.875rem;
}
.btn-action-secondary:hover {
    background: #cbd5e1;
}
.table-panel {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
}
.table-responsive {
    overflow-x: auto;
}
.pcs-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    text-align: left;
}
.pcs-table th {
    background: rgba(255,255,255,0.03);
    color: var(--text-secondary, #64748b);
    font-weight: 600;
    padding: 0.8rem 1rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
    font-size: 0.775rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    white-space: nowrap;
}
.pcs-table td {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid rgba(51, 65, 85, 0.4);
    vertical-align: middle;
}
.pcs-table tbody tr:hover {
    background: rgba(255,255,255,0.02);
}
.pc-badge-active {
    background: rgba(34, 197, 94, 0.15);
    color: #86efac;
    border: 1px solid rgba(34, 197, 94, 0.3);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.pc-badge-inactive {
    background: rgba(239, 68, 68, 0.15);
    color: #fca5a5;
    border: 1px solid rgba(239, 68, 68, 0.3);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.open-ticket-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.2rem 0.55rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 700;
    background: rgba(239, 68, 68, 0.2);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.4);
    text-decoration: none !important;
}
.open-ticket-pill.none {
    background: rgba(255, 255, 255, 0.04);
    color: var(--text-secondary, #64748b);
    border: 1px solid var(--border, #e2e8f0);
    font-weight: 500;
}
.actions-cell {
    display: flex;
    gap: 0.4rem;
    align-items: center;
}
.btn-mini {
    padding: 0.25rem 0.55rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}
.btn-mini-edit {
    background: rgba(56, 189, 248, 0.15);
    color: var(--primary, #2563eb);
    border: 1px solid rgba(56, 189, 248, 0.3);
}
.btn-mini-edit:hover {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
}
.btn-mini-history {
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-primary, #1e293b);
    border: 1px solid var(--border, #e2e8f0);
}
.btn-mini-history:hover {
    background: var(--bg-tertiary, #e2e8f0);
}
.quick-stats-strip {
    display: flex;
    gap: 1.5rem;
    font-size: 0.85rem;
    color: var(--text-secondary, #64748b);
    flex-wrap: wrap;
}
.quick-stats-strip strong {
    color: var(--text-primary, #1e293b);
}
@media (max-width: 900px) {
    .filter-form {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="pcs-container">
    <!-- Header -->
    <div class="page-header-row">
        <div>
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
                Manage Computer Workstations
            </h1>
            <div class="quick-stats-strip" style="margin-top: 0.35rem;">
                <span>Total Workstations: <strong><?= $summaryStats['total'] ?></strong></span>
                <span>Active: <strong style="color: #22c55e;"><?= $summaryStats['active'] ?></strong></span>
                <span>Inactive: <strong style="color: #64748b;"><?= $summaryStats['inactive'] ?></strong></span>
                <span>Non-Working / Issues: <strong style="color: #f59e0b;"><?= $summaryStats['issues'] ?></strong></span>
            </div>
        </div>
        <div>
            <a href="<?= APP_URL ?>/admin/pc-edit.php" class="btn-action-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add New PC
            </a>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-box">
        <form method="GET" action="<?= APP_URL ?>/admin/pcs.php" class="filter-form">
            <div class="filter-field">
                <label for="search-input">Search Workstations</label>
                <input type="text" id="search-input" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search PC code, name, or location...">
            </div>

            <div class="filter-field">
                <label for="status-select">Operational Status</label>
                <select id="status-select" name="status">
                    <option value="">All Operational Statuses</option>
                    <?php foreach (ALLOWED_PC_STATUSES as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>>
                            <?= htmlspecialchars($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="active-select">Active Status</label>
                <select id="active-select" name="active">
                    <option value="">All (Active & Inactive)</option>
                    <option value="1" <?= $filterActive === '1' ? 'selected' : '' ?>>Active Only</option>
                    <option value="0" <?= $filterActive === '0' ? 'selected' : '' ?>>Inactive Only</option>
                </select>
            </div>

            <div>
                <button type="submit" class="btn-action-primary" style="width: 100%;">
                    Filter
                </button>
            </div>

            <div>
                <a href="<?= APP_URL ?>/admin/pcs.php" class="btn-action-secondary" title="Reset all filters">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- PC Table List -->
    <div class="table-panel">
        <div class="table-responsive">
            <table class="pcs-table">
                <thead>
                    <tr>
                        <th>PC Code</th>
                        <th>PC Name</th>
                        <th>Location</th>
                        <th>Operational Status</th>
                        <th>Active</th>
                        <th>Open Tickets</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pcs)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--text-secondary);">
                                <p style="font-size: 1rem; font-weight: 500;">No workstations found matching your criteria.</p>
                                <a href="<?= APP_URL ?>/admin/pc-edit.php" class="btn-action-primary" style="margin-top: 0.75rem;">
                                    + Add New PC
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pcs as $pc): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/admin/pc-edit.php?id=<?= $pc['pc_id'] ?>" style="font-weight: 700; font-size: 0.95rem; font-family: monospace;">
                                        <?= htmlspecialchars($pc['pc_code']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?= !empty($pc['pc_name']) ? htmlspecialchars($pc['pc_name']) : '<span style="color:#64748b;">â€”</span>' ?>
                                </td>
                                <td>
                                    <?= !empty($pc['location']) ? htmlspecialchars($pc['location']) : '<span style="color:#64748b;">Lab Room</span>' ?>
                                </td>
                                <td>
                                    <span class="badge <?= get_status_badge_class($pc['operational_status']) ?>">
                                        <?= htmlspecialchars($pc['operational_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($pc['is_active']): ?>
                                        <span class="pc-badge-active">Yes</span>
                                    <?php else: ?>
                                        <span class="pc-badge-inactive">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($pc['open_tickets_count'] > 0): ?>
                                        <a href="<?= APP_URL ?>/admin/tickets.php?pc_id=<?= $pc['pc_id'] ?>" class="open-ticket-pill" title="View open tickets for this PC">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5m0 3h.01"/></svg>
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5m0 3h.01"/></svg>
                                        </a>
                                    <?php else: ?>
                                        <span class="open-ticket-pill none">0 Open</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span title="<?= format_datetime($pc['updated_at']) ?>">
                                        <?= time_ago($pc['updated_at']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions-cell">
                                        <a href="<?= APP_URL ?>/admin/pc-edit.php?id=<?= $pc['pc_id'] ?>" class="btn-mini btn-mini-edit" title="Edit PC specifications and status">
                                            Edit
                                        </a>
                                        <a href="<?= APP_URL ?>/admin/pc-edit.php?id=<?= $pc['pc_id'] ?>#ticket-history" class="btn-mini btn-mini-history" title="View tickets history for this PC">
                                            History
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';

