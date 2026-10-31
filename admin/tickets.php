<?php
/**
 * Manage Tickets
 * Laboratory Management System (LMS)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$db = getDB();

// 1. Fetch Workstations for the filter dropdown
$workstations = [];
try {
    $stmt = $db->query("SELECT pc_id, pc_code, pc_name FROM pcs ORDER BY pc_code ASC");
    $workstations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching workstations for filter: " . $e->getMessage());
}

// 2. Read Filter Inputs
$filterStatus   = isset($_GET['status']) ? trim($_GET['status']) : '';
$filterPcId     = isset($_GET['pc_id']) && $_GET['pc_id'] !== '' ? (int)$_GET['pc_id'] : null;
$filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$filterPriority = isset($_GET['priority']) ? trim($_GET['priority']) : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo   = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$searchQuery    = isset($_GET['q']) ? trim($_GET['q']) : '';
$currentPage    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$whereClauses = [];
$params = [];

if ($filterStatus !== '' && in_array($filterStatus, ALLOWED_TICKET_STATUSES, true)) {
    $whereClauses[] = "t.status = :status";
    $params[':status'] = $filterStatus;
}

if ($filterPcId !== null && $filterPcId > 0) {
    $whereClauses[] = "t.pc_id = :pc_id";
    $params[':pc_id'] = $filterPcId;
}

if ($filterCategory !== '' && in_array($filterCategory, ALLOWED_CATEGORIES, true)) {
    $whereClauses[] = "t.category = :category";
    $params[':category'] = $filterCategory;
}

if ($filterPriority !== '' && in_array($filterPriority, ALLOWED_PRIORITIES, true)) {
    $whereClauses[] = "t.priority = :priority";
    $params[':priority'] = $filterPriority;
}

if ($filterDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
    $whereClauses[] = "date(t.created_at) >= :date_from";
    $params[':date_from'] = $filterDateFrom;
}

if ($filterDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
    $whereClauses[] = "date(t.created_at) <= :date_to";
    $params[':date_to'] = $filterDateTo;
}

if ($searchQuery !== '') {
    $whereClauses[] = "(t.ticket_number LIKE :search OR t.title LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// 3. Count Total Matching Tickets for Pagination
$totalCount = 0;
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM tickets t {$whereSql}");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error counting tickets: " . $e->getMessage());
}

$perPage = defined('ITEMS_PER_PAGE') ? ITEMS_PER_PAGE : 15;
$pagination = paginate($totalCount, $perPage, $currentPage);

// 4. Fetch Paginated Tickets
$tickets = [];
try {
    $sql = "
        SELECT 
            t.ticket_id,
            t.ticket_number,
            t.title,
            t.category,
            t.priority,
            t.status,
            t.created_at,
            t.updated_at,
            p.pc_code,
            p.pc_name,
            u.full_name AS reporter_name,
            u.username AS reporter_username
        FROM tickets t
        LEFT JOIN pcs p ON t.pc_id = p.pc_id
        LEFT JOIN users u ON t.reporter_id = u.user_id
        {$whereSql}
        ORDER BY t.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching tickets: " . $e->getMessage());
}

// Build query string helper for pagination
function build_filter_url(int $page): string {
    $params = $_GET;
    $params['page'] = $page;
    return APP_URL . '/admin/tickets.php?' . http_build_query($params);
}

$page_title = 'Manage Tickets';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tickets-container {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.page-title-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
}
.page-title-row h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary, #1e293b);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.filter-card-full {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 1.25rem;
}
.filter-grid-tickets {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.85rem;
    align-items: flex-end;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.filter-group label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary, #64748b);
    letter-spacing: 0.05em;
}
.filter-input, .filter-select {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--text-primary, #1e293b);
    padding: 0.55rem 0.75rem;
    border-radius: 4px;
    font-size: 0.85rem;
    width: 100%;
    box-sizing: border-box;
}
.filter-input:focus, .filter-select:focus {
    outline: none;
    border-color: var(--primary, #2563eb);
}
.filter-buttons {
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
}
.btn-filter-apply {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
    font-weight: 600;
    padding: 0.55rem 1rem;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.btn-filter-apply:hover {
    background: #1d4ed8;
}
.btn-filter-clear {
    background: var(--bg-tertiary, #e2e8f0);
    color: var(--text-primary, #1e293b) !important;
    padding: 0.55rem 0.9rem;
    border-radius: 4px;
    border: 1px solid #cbd5e1;
    text-decoration: none !important;
    font-size: 0.85rem;
}
.btn-filter-clear:hover {
    background: #cbd5e1;
}
.tickets-table-panel {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
}
.tickets-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    text-align: left;
}
.tickets-table th {
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
.tickets-table td {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid rgba(51, 65, 85, 0.4);
    vertical-align: middle;
}
.tickets-table tbody tr {
    cursor: pointer;
    transition: background 0.15s ease;
}
.tickets-table tbody tr:hover {
    background: rgba(255,255,255,0.03);
}
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--border, #e2e8f0);
    background: rgba(0, 0, 0, 0.1);
}
.pagination-nav {
    display: flex;
    gap: 0.35rem;
}
.page-btn {
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
.page-btn:hover {
    background: var(--bg-tertiary, #e2e8f0);
    color: var(--text-primary, #1e293b);
}
.page-btn.active {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
    border-color: var(--primary, #2563eb);
    font-weight: 700;
}
.page-btn.disabled {
    opacity: 0.35;
    pointer-events: none;
}
</style>

<div class="tickets-container">
    <!-- Header -->
    <div class="page-title-row">
        <div>
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                Manage Support Tickets
            </h1>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">
                Showing <strong><?= $totalCount ?></strong> ticket(s) matching current filters.
            </p>
        </div>
        <div>
            <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-filter-clear">
                &larr; Admin Dashboard
            </a>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="filter-card-full">
        <form method="GET" action="<?= APP_URL ?>/admin/tickets.php">
            <div class="filter-grid-tickets">
                <!-- Search Query -->
                <div class="filter-group" style="grid-column: span 2;">
                    <label for="q">Search Ticket # or Title</label>
                    <input type="text" id="q" name="q" class="filter-input" 
                           value="<?= htmlspecialchars($searchQuery) ?>" 
                           placeholder="Search ticket number, e.g. LAB-2026-00001, or keywords...">
                </div>

                <!-- Status Filter -->
                <div class="filter-group">
                    <label for="status">Ticket Status</label>
                    <select id="status" name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <?php foreach (ALLOWED_TICKET_STATUSES as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>" <?= ($filterStatus === $status) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- PC Filter -->
                <div class="filter-group">
                    <label for="pc_id">Workstation PC</label>
                    <select id="pc_id" name="pc_id" class="filter-select">
                        <option value="">All Workstations</option>
                        <?php foreach ($workstations as $ws): ?>
                            <option value="<?= $ws['pc_id'] ?>" <?= ($filterPcId === (int)$ws['pc_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ws['pc_code']) ?> <?= !empty($ws['pc_name']) ? '(' . htmlspecialchars($ws['pc_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Category Filter -->
                <div class="filter-group">
                    <label for="category">Category</label>
                    <select id="category" name="category" class="filter-select">
                        <option value="">All Categories</option>
                        <?php foreach (ALLOWED_CATEGORIES as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= ($filterCategory === $cat) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Priority Filter -->
                <div class="filter-group">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority" class="filter-select">
                        <option value="">All Priorities</option>
                        <?php foreach (ALLOWED_PRIORITIES as $prio): ?>
                            <option value="<?= htmlspecialchars($prio) ?>" <?= ($filterPriority === $prio) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($prio) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Range From -->
                <div class="filter-group">
                    <label for="date_from">Date From</label>
                    <input type="date" id="date_from" name="date_from" class="filter-input" value="<?= htmlspecialchars($filterDateFrom) ?>">
                </div>

                <!-- Date Range To -->
                <div class="filter-group">
                    <label for="date_to">Date To</label>
                    <input type="date" id="date_to" name="date_to" class="filter-input" value="<?= htmlspecialchars($filterDateTo) ?>">
                </div>

                <!-- Action Buttons -->
                <div class="filter-buttons">
                    <button type="submit" class="btn-filter-apply">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                        </svg>
                        Filter
                    </button>
                    <a href="<?= APP_URL ?>/admin/tickets.php" class="btn-filter-clear">
                        Clear
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Tickets Table -->
    <div class="tickets-table-panel">
        <div style="overflow-x: auto;">
            <table class="tickets-table">
                <thead>
                    <tr>
                        <th>Ticket #</th>
                        <th>Workstation</th>
                        <th>Reporter</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 3rem 1rem; color: var(--text-secondary);">
                                <p style="font-size: 1rem; font-weight: 500;">No tickets found matching the specified filter criteria.</p>
                                <a href="<?= APP_URL ?>/admin/tickets.php" class="btn-filter-clear" style="display: inline-block; margin-top: 0.75rem;">
                                    Clear Filters
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): ?>
                            <tr onclick="window.location='<?= APP_URL ?>/admin/ticket-details.php?id=<?= $t['ticket_id'] ?>';">
                                <td>
                                    <a href="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $t['ticket_id'] ?>" 
                                       onclick="event.stopPropagation();"
                                       style="font-weight: 700; font-family: monospace; font-size: 0.9rem;">
                                        <?= htmlspecialchars($t['ticket_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: var(--text-primary);">
                                        <?= htmlspecialchars($t['pc_code'] ?? 'Unknown PC') ?>
                                    </span>
                                </td>
                                <td>
                                    <?= htmlspecialchars($t['reporter_name'] ?? $t['reporter_username'] ?? 'User') ?>
                                </td>
                                <td>
                                    <span title="<?= htmlspecialchars($t['title']) ?>" style="font-weight: 500;">
                                        <?= htmlspecialchars(mb_strimwidth($t['title'], 0, 36, '...')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: var(--text-secondary); font-size: 0.8rem;">
                                        <?= htmlspecialchars($t['category']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= get_priority_badge_class($t['priority']) ?>">
                                        <?= htmlspecialchars($t['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= get_status_badge_class($t['status']) ?>">
                                        <?= htmlspecialchars($t['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span title="<?= format_datetime($t['created_at']) ?>" style="font-size: 0.8rem;">
                                        <?= time_ago($t['created_at']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span title="<?= format_datetime($t['updated_at']) ?>" style="font-size: 0.8rem; color: #64748b;">
                                        <?= time_ago($t['updated_at']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?= APP_URL ?>/admin/ticket-details.php?id=<?= $t['ticket_id'] ?>" 
                                       onclick="event.stopPropagation();"
                                       class="btn-filter-apply" 
                                       style="padding: 0.25rem 0.6rem; font-size: 0.775rem;">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="pagination-container">
                <div style="font-size: 0.85rem; color: var(--text-secondary);">
                    Page <strong><?= $pagination['current_page'] ?></strong> of <strong><?= $pagination['total_pages'] ?></strong> 
                    (<?= $pagination['total'] ?> total records)
                </div>
                <div class="pagination-nav">
                    <!-- First & Prev -->
                    <a href="<?= build_filter_url(1) ?>" 
                       class="page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>" title="First Page">&laquo;</a>
                    <a href="<?= build_filter_url($pagination['prev_page']) ?>" 
                       class="page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>" title="Previous Page">&lsaquo;</a>

                    <!-- Page Numbers Window -->
                    <?php
                    $startPage = max(1, $pagination['current_page'] - 2);
                    $endPage   = min($pagination['total_pages'], $pagination['current_page'] + 2);
                    for ($p = $startPage; $p <= $endPage; $p++):
                    ?>
                        <a href="<?= build_filter_url($p) ?>" 
                           class="page-btn <?= $p === $pagination['current_page'] ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Next & Last -->
                    <a href="<?= build_filter_url($pagination['next_page']) ?>" 
                       class="page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>" title="Next Page">&rsaquo;</a>
                    <a href="<?= build_filter_url($pagination['total_pages']) ?>" 
                       class="page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>" title="Last Page">&raquo;</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';

