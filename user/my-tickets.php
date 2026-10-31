<?php
/**
 * My Tickets List
 * Laboratory Management System (LMS)
 * Path: user/my-tickets.php
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

// Status filter handling
$selectedStatus = trim($_GET['status'] ?? '');
$allowedStatuses = defined('ALLOWED_TICKET_STATUSES') ? ALLOWED_TICKET_STATUSES : [
    'Open', 'Acknowledged', 'In Progress', 'Waiting for Parts', 'Resolved', 'Closed', 'Rejected'
];

if (!empty($selectedStatus) && !in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = '';
}

// Current page handling
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}

$perPage = defined('ITEMS_PER_PAGE') ? ITEMS_PER_PAGE : 15;

// Count total tickets for user
$countSql = "SELECT COUNT(*) FROM tickets WHERE reporter_id = :reporter_id";
$countParams = [':reporter_id' => $currentUserId];

if (!empty($selectedStatus)) {
    $countSql .= " AND status = :status";
    $countParams[':status'] = $selectedStatus;
}

try {
    $stmtCount = $db->prepare($countSql);
    $stmtCount->execute($countParams);
    $totalTickets = (int)$stmtCount->fetchColumn();
} catch (PDOException $e) {
    error_log("Count tickets error: " . $e->getMessage());
    $totalTickets = 0;
}

// Pagination parameters
$pagination = paginate($totalTickets, $perPage, $currentPage);

// Fetch paginated tickets
$tickets = [];
if ($totalTickets > 0) {
    $fetchSql = "
        SELECT t.ticket_id, t.ticket_number, t.title, t.category, t.priority, t.status, t.created_at,
               p.pc_code, p.pc_name, p.location
        FROM tickets t
        JOIN pcs p ON t.pc_id = p.pc_id
        WHERE t.reporter_id = :reporter_id
    ";
    $fetchParams = [
        ':reporter_id' => $currentUserId,
        ':limit'       => $pagination['per_page'],
        ':offset'      => $pagination['offset']
    ];

    if (!empty($selectedStatus)) {
        $fetchSql .= " AND t.status = :status";
        $fetchParams[':status'] = $selectedStatus;
    }

    $fetchSql .= " ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";

    try {
        $stmtFetch = $db->prepare($fetchSql);
        foreach ($fetchParams as $key => $val) {
            if ($key === ':limit' || $key === ':offset') {
                $stmtFetch->bindValue($key, $val, PDO::PARAM_INT);
            } else {
                $stmtFetch->bindValue($key, $val, PDO::PARAM_STR);
            }
        }
        $stmtFetch->execute();
        $tickets = $stmtFetch->fetchAll();
    } catch (PDOException $e) {
        error_log("Fetch tickets error: " . $e->getMessage());
        $tickets = [];
    }
}

$page_title = 'My Tickets';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .page-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .page-title-box h1 {
        font-size: 1.65rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .page-title-box p {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .filter-bar {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .filter-form {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .filter-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-secondary);
    }

    .filter-select {
        background-color: var(--bg-primary);
        color: var(--text-primary);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 0.45rem 0.85rem;
        font-size: 0.875rem;
        cursor: pointer;
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--primary);
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 0.5rem 1rem;
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
    }

    .btn-secondary {
        background-color: var(--bg-primary);
        color: var(--text-primary);
        border-color: var(--border);
    }

    .btn-secondary:hover {
        background-color: var(--bg-tertiary);
    }

    .btn-sm {
        padding: 0.35rem 0.65rem;
        font-size: 0.8rem;
    }

    .table-container {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
    }

    .tickets-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
        text-align: left;
    }

    .tickets-table thead th {
        background: rgba(0, 0, 0, 0.2);
        color: var(--text-secondary);
        font-weight: 600;
        padding: 0.9rem 1.2rem;
        border-bottom: 1px solid var(--border);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        white-space: nowrap;
    }

    .tickets-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background-color 0.15s ease;
    }

    .tickets-table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.02);
    }

    .tickets-table tbody td {
        padding: 0.95rem 1.2rem;
        vertical-align: middle;
        color: var(--text-primary);
    }

    .ticket-number-cell {
        font-family: monospace;
        font-weight: 600;
        color: var(--primary);
        text-decoration: none;
        display: inline-block;
    }

    .ticket-number-cell:hover {
        text-decoration: underline;
    }

    .pc-badge-sub {
        font-size: 0.775rem;
        color: var(--text-secondary);
        display: block;
    }

    .ticket-title-text {
        font-weight: 500;
        color: var(--text-primary);
        display: block;
        max-width: 320px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .empty-state-box {
        text-align: center;
        padding: 3.5rem 1.5rem;
        color: var(--text-secondary);
    }

    .empty-state-box svg {
        color: #cbd5e1;
        margin-bottom: 1rem;
    }

    .empty-state-box h3 {
        font-size: 1.2rem;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .empty-state-box p {
        font-size: 0.95rem;
        margin-bottom: 1.5rem;
        max-width: 420px;
        margin-left: auto;
        margin-right: auto;
    }

    /* Pagination Bar */
    .pagination-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.25rem;
        border-top: 1px solid var(--border);
        background: rgba(0, 0, 0, 0.1);
        font-size: 0.875rem;
        color: var(--text-secondary);
    }

    .pagination-nav {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .page-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
        padding: 0 0.5rem;
        border-radius: 4px;
        border: 1px solid var(--border);
        background: var(--bg-primary);
        color: var(--text-primary);
        text-decoration: none;
        font-size: 0.85rem;
        transition: all 0.15s ease;
    }

    .page-btn:hover {
        background: var(--bg-tertiary);
        border-color: #64748b;
    }

    .page-btn.active {
        background: var(--primary);
        color: #ffffff;
        font-weight: 700;
        border-color: var(--primary);
    }

    .page-btn.disabled {
        opacity: 0.4;
        cursor: not-allowed;
        pointer-events: none;
    }
</style>

<div class="page-header">
    <div class="page-title-box">
        <h1>My Tickets</h1>
        <p>Track the status and technician updates for all issues you have reported.</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/user/create-ticket.php" class="btn btn-primary">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="16"></line>
                <line x1="8" y1="12" x2="16" y2="12"></line>
            </svg>
            Report New Issue
        </a>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form action="<?= APP_URL ?>/user/my-tickets.php" method="GET" class="filter-form" id="filterForm">
        <label for="statusFilter" class="filter-label">Filter by Status:</label>
        <select name="status" id="statusFilter" class="filter-select" onchange="document.getElementById('filterForm').submit();">
            <option value="">All Statuses</option>
            <?php foreach ($allowedStatuses as $statusOpt): ?>
                <option value="<?= htmlspecialchars($statusOpt) ?>" <?= $selectedStatus === $statusOpt ? 'selected' : '' ?>>
                    <?= htmlspecialchars($statusOpt) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript>
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        </noscript>
        <?php if (!empty($selectedStatus)): ?>
            <a href="<?= APP_URL ?>/user/my-tickets.php" class="btn btn-secondary btn-sm">Clear Filter</a>
        <?php endif; ?>
    </form>

    <div style="font-size: 0.85rem; color: var(--text-secondary);">
        Total: <strong><?= $totalTickets ?></strong> ticket<?= $totalTickets === 1 ? '' : 's' ?>
    </div>
</div>

<!-- Tickets Table Container -->
<div class="table-container">
    <?php if (empty($tickets)): ?>
        <div class="empty-state-box">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            <?php if (!empty($selectedStatus)): ?>
                <h3>No tickets with status "<?= htmlspecialchars($selectedStatus) ?>"</h3>
                <p>None of your reported issues currently match this status filter.</p>
                <a href="<?= APP_URL ?>/user/my-tickets.php" class="btn btn-secondary btn-sm">Show All Tickets</a>
            <?php else: ?>
                <h3>You haven't submitted any tickets yet.</h3>
                <p>When you encounter a broken peripheral, system freeze, or software issue in the lab, submit a ticket here.</p>
                <a href="<?= APP_URL ?>/user/create-ticket.php" class="btn btn-primary">Report an Issue</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="tickets-table">
                <thead>
                    <tr>
                        <th>Ticket #</th>
                        <th>Workstation</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/user/ticket-details.php?id=<?= (int)$t['ticket_id'] ?>" class="ticket-number-cell">
                                    <?= htmlspecialchars($t['ticket_number']) ?>
                                </a>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($t['pc_code']) ?></strong>
                                <?php if (!empty($t['pc_name'])): ?>
                                    <span class="pc-badge-sub"><?= htmlspecialchars($t['pc_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ticket-title-text" title="<?= htmlspecialchars($t['title']) ?>">
                                    <?= htmlspecialchars($t['title']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 0.85rem; color: var(--text-secondary);">
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
                            <td style="color: var(--text-secondary); font-size: 0.85rem; white-space: nowrap;">
                                <?= htmlspecialchars(format_datetime($t['created_at'], 'M j, Y g:i A')) ?>
                                <span class="pc-badge-sub"><?= htmlspecialchars(time_ago($t['created_at'])) ?></span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="<?= APP_URL ?>/user/ticket-details.php?id=<?= (int)$t['ticket_id'] ?>" class="btn btn-secondary btn-sm">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <?php 
                $startItem = $pagination['offset'] + 1;
                $endItem = min($pagination['offset'] + $pagination['per_page'], $pagination['total']);
            ?>
            <div class="pagination-bar">
                <div>
                    Showing <strong><?= $startItem ?></strong> &ndash; <strong><?= $endItem ?></strong> of <strong><?= $pagination['total'] ?></strong> tickets
                </div>
                <ul class="pagination-nav">
                    <!-- Previous Page -->
                    <?php if ($pagination['has_prev']): ?>
                        <li>
                            <a href="<?= APP_URL ?>/user/my-tickets.php?<?= http_build_query(array_merge($_GET, ['page' => $pagination['prev_page']])) ?>" class="page-btn" aria-label="Previous page">
                                &laquo;
                            </a>
                        </li>
                    <?php else: ?>
                        <li><span class="page-btn disabled">&laquo;</span></li>
                    <?php endif; ?>

                    <!-- Page Numbers -->
                    <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
                        <?php if ($p === $pagination['current_page']): ?>
                            <li><span class="page-btn active"><?= $p ?></span></li>
                        <?php elseif ($p === 1 || $p === $pagination['total_pages'] || abs($p - $pagination['current_page']) <= 2): ?>
                            <li>
                                <a href="<?= APP_URL ?>/user/my-tickets.php?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="page-btn">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php elseif (abs($p - $pagination['current_page']) === 3): ?>
                            <li><span class="page-btn disabled">&hellip;</span></li>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <!-- Next Page -->
                    <?php if ($pagination['has_next']): ?>
                        <li>
                            <a href="<?= APP_URL ?>/user/my-tickets.php?<?= http_build_query(array_merge($_GET, ['page' => $pagination['next_page']])) ?>" class="page-btn" aria-label="Next page">
                                &raquo;
                            </a>
                        </li>
                    <?php else: ?>
                        <li><span class="page-btn disabled">&raquo;</span></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';

