<?php
/**
 * System Audit Log
 * Laboratory Management System (LMS)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$db = getDB();

// 1. Read Filter Inputs
$filterAction     = isset($_GET['action']) ? trim($_GET['action']) : '';
$filterEntityType = isset($_GET['entity_type']) ? trim($_GET['entity_type']) : '';
$searchQuery      = isset($_GET['q']) ? trim($_GET['q']) : '';
$currentPage      = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$whereClauses = [];
$params = [];

if ($filterAction !== '') {
    $whereClauses[] = "a.action = :action";
    $params[':action'] = $filterAction;
}

if ($filterEntityType !== '') {
    $whereClauses[] = "a.entity_type = :entity_type";
    $params[':entity_type'] = $filterEntityType;
}

if ($searchQuery !== '') {
    $whereClauses[] = "(a.details LIKE :search OR u.username LIKE :search OR u.full_name LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// 2. Fetch distinct actions and entity types for filter dropdowns
$distinctActions = [];
$distinctEntityTypes = [];
try {
    $distinctActions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
    $distinctEntityTypes = $db->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching audit filter options: " . $e->getMessage());
}

// 3. Count total logs for pagination
$totalLogs = 0;
try {
    $countStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.user_id
        {$whereSql}
    ");
    $countStmt->execute($params);
    $totalLogs = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error counting audit logs: " . $e->getMessage());
}

$perPage = defined('ITEMS_PER_PAGE') ? ITEMS_PER_PAGE : 15;
$pagination = paginate($totalLogs, $perPage, $currentPage);

// 4. Fetch Paginated Logs
$logs = [];
try {
    $sql = "
        SELECT 
            a.audit_id,
            a.user_id,
            a.action,
            a.entity_type,
            a.entity_id,
            a.details,
            a.created_at,
            u.full_name,
            u.username,
            u.role
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.user_id
        {$whereSql}
        ORDER BY a.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching audit logs: " . $e->getMessage());
}

// Helper for pagination links
function build_audit_url(int $page): string {
    $p = $_GET;
    $p['page'] = $page;
    return APP_URL . '/admin/audit-log.php?' . http_build_query($p);
}

$page_title = 'System Audit Log';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.audit-wrap {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.audit-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
}
.audit-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary, #1e293b);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.audit-filter-panel {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 1.25rem;
}
.audit-filter-grid {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1.5fr auto auto;
    gap: 0.85rem;
    align-items: flex-end;
}
.a-form-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.a-form-group label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary, #64748b);
    letter-spacing: 0.05em;
}
.a-input, .a-select {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--text-primary, #1e293b);
    padding: 0.55rem 0.75rem;
    border-radius: 4px;
    font-size: 0.875rem;
    box-sizing: border-box;
    width: 100%;
}
.a-input:focus, .a-select:focus {
    outline: none;
    border-color: var(--primary, #2563eb);
}
.btn-a-apply {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
    font-weight: 600;
    padding: 0.55rem 1rem;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 0.875rem;
}
.btn-a-apply:hover {
    background: #1d4ed8;
}
.btn-a-reset {
    background: var(--bg-tertiary, #e2e8f0);
    color: var(--text-primary, #1e293b) !important;
    padding: 0.55rem 0.9rem;
    border-radius: 4px;
    text-decoration: none !important;
    font-size: 0.875rem;
    border: 1px solid #cbd5e1;
}
.btn-a-reset:hover {
    background: #cbd5e1;
}
.audit-table-panel {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
}
.audit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    text-align: left;
}
.audit-table th {
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
.audit-table td {
    padding: 0.8rem 1rem;
    border-bottom: 1px solid rgba(51, 65, 85, 0.4);
    vertical-align: middle;
}
.audit-table tbody tr:hover {
    background: rgba(255,255,255,0.02);
}
.action-tag {
    font-family: monospace;
    font-size: 0.775rem;
    font-weight: 700;
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
    background: rgba(56, 189, 248, 0.12);
    color: #7dd3fc;
    border: 1px solid rgba(56, 189, 248, 0.25);
}
.entity-tag {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary, #64748b);
}
.pagination-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--border, #e2e8f0);
    background: rgba(0, 0, 0, 0.1);
}
.p-nav {
    display: flex;
    gap: 0.35rem;
}
.p-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    padding: 0 0.5rem;
    border-radius: 4px;
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--text-secondary, #64748b);
    text-decoration: none !important;
    font-size: 0.85rem;
    font-weight: 500;
}
.p-btn:hover {
    background: var(--bg-tertiary, #e2e8f0);
    color: var(--text-primary, #1e293b);
}
.p-btn.active {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
    border-color: var(--primary, #2563eb);
    font-weight: 700;
}
.p-btn.disabled {
    opacity: 0.35;
    pointer-events: none;
}
@media (max-width: 850px) {
    .audit-filter-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="audit-wrap">
    <!-- Header -->
    <div class="audit-header">
        <div>
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
                System Audit & Security Logs
            </h1>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">
                Tracking administrative changes, status updates, and user modifications. (<?= $totalLogs ?> Total Events)
            </p>
        </div>
        <div>
            <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-a-reset">
                &larr; Return to Dashboard
            </a>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="audit-filter-panel">
        <form method="GET" action="<?= APP_URL ?>/admin/audit-log.php" class="audit-filter-grid">
            <div class="a-form-group">
                <label for="q">Search Audit Details or User</label>
                <input type="text" id="q" name="q" class="a-input" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Keywords or username...">
            </div>

            <div class="a-form-group">
                <label for="action">Action Type</label>
                <select id="action" name="action" class="a-select">
                    <option value="">All Action Types</option>
                    <?php foreach ($distinctActions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= ($filterAction === $act) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="a-form-group">
                <label for="entity_type">Entity Type</label>
                <select id="entity_type" name="entity_type" class="a-select">
                    <option value="">All Entities</option>
                    <?php foreach ($distinctEntityTypes as $et): ?>
                        <option value="<?= htmlspecialchars($et) ?>" <?= ($filterEntityType === $et) ? 'selected' : '' ?>>
                            <?= htmlspecialchars(strtoupper($et)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="btn-a-apply">
                    Filter Logs
                </button>
            </div>

            <div>
                <a href="<?= APP_URL ?>/admin/audit-log.php" class="btn-a-reset">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Audit Logs Table -->
    <div class="audit-table-panel">
        <div style="overflow-x: auto;">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Timestamp (UTC)</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Entity ID</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--text-secondary);">
                                No audit records found matching your filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <div style="font-weight: 500; color: var(--text-primary); font-size: 0.85rem;">
                                        <?= format_datetime($l['created_at']) ?>
                                    </div>
                                    <div style="font-size: 0.725rem; color: #64748b;">
                                        <?= time_ago($l['created_at']) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($l['user_id'])): ?>
                                        <strong style="color: var(--text-primary);">
                                            <?= htmlspecialchars($l['full_name'] ?? $l['username']) ?>
                                        </strong>
                                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                            @<?= htmlspecialchars($l['username'] ?? 'user' . $l['user_id']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-style: italic;">System / Automated</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="action-tag">
                                        <?= htmlspecialchars($l['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="entity-tag">
                                        <?= htmlspecialchars($l['entity_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($l['entity_id'])): ?>
                                        <?php if ($l['entity_type'] === 'ticket'): ?>
                                            <a href="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $l['entity_id'] ?>" style="font-weight: 600; font-family: monospace;">
                                                #<?= $l['entity_id'] ?>
                                            </a>
                                        <?php elseif ($l['entity_type'] === 'pc'): ?>
                                            <a href="<?= APP_URL ?>/admin/pc-edit.php?id=<?= $l['entity_id'] ?>" style="font-weight: 600; font-family: monospace;">
                                                #<?= $l['entity_id'] ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="font-family: monospace;">#<?= $l['entity_id'] ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #64748b;">â€”</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="color: var(--text-secondary); line-height: 1.4;">
                                        <?= htmlspecialchars($l['details'] ?? 'No extra details recorded.') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="pagination-row">
                <div style="font-size: 0.85rem; color: var(--text-secondary);">
                    Page <strong><?= $pagination['current_page'] ?></strong> of <strong><?= $pagination['total_pages'] ?></strong> 
                    (<?= $pagination['total'] ?> events)
                </div>
                <div class="p-nav">
                    <a href="<?= build_audit_url(1) ?>" class="p-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">&laquo;</a>
                    <a href="<?= build_audit_url($pagination['prev_page']) ?>" class="p-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">&lsaquo;</a>

                    <?php
                    $startP = max(1, $pagination['current_page'] - 2);
                    $endP   = min($pagination['total_pages'], $pagination['current_page'] + 2);
                    for ($p = $startP; $p <= $endP; $p++):
                    ?>
                        <a href="<?= build_audit_url($p) ?>" class="p-btn <?= $p === $pagination['current_page'] ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <a href="<?= build_audit_url($pagination['next_page']) ?>" class="p-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">&rsaquo;</a>
                    <a href="<?= build_audit_url($pagination['total_pages']) ?>" class="p-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">&raquo;</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';

