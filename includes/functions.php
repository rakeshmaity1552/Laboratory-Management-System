<?php
/**
 * Utility Functions
 * Laboratory Management System (LMS)
 */

/**
 * Sanitizes text input for safe display and usage.
 *
 * @param mixed $data
 * @return string
 */
function sanitize_input(mixed $data): string
{
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Generates sequential ticket numbers formatted as LAB-YYYY-NNNNN.
 *
 * @param PDO $db
 * @return string
 */
function generate_ticket_number(PDO $db): string
{
    $year = date('Y');
    $prefix = "LAB-{$year}-";

    $stmt = $db->prepare("
        SELECT ticket_number 
        FROM tickets 
        WHERE ticket_number LIKE :prefix 
        ORDER BY ticket_id DESC 
        LIMIT 1
    ");
    $stmt->execute([':prefix' => "{$prefix}%"]);
    $latest = $stmt->fetchColumn();

    $nextNum = 1;
    if ($latest && preg_match('/LAB-\d{4}-(\d+)/', $latest, $matches)) {
        $nextNum = (int)$matches[1] + 1;
    }

    return sprintf("LAB-%s-%05d", $year, $nextNum);
}

/** Validates ticket workflow transitions before recording a new state. */
function is_valid_ticket_transition(string $old, string $new): bool
{
    $transitions = [
        'Open' => ['Acknowledged', 'In Progress', 'Waiting for Parts', 'Resolved', 'Rejected'],
        'Acknowledged' => ['In Progress', 'Waiting for Parts', 'Resolved', 'Rejected'],
        'In Progress' => ['Waiting for Parts', 'Resolved', 'Rejected'],
        'Waiting for Parts' => ['In Progress', 'Resolved', 'Rejected'],
        'Resolved' => ['Closed', 'In Progress'],
        'Closed' => [],
        'Rejected' => [],
    ];
    return $old === $new || in_array($new, $transitions[$old] ?? [], true);
}

/**
 * Formats a UTC datetime string for human-readable display.
 *
 * @param string|null $datetime
 * @param string $format
 * @return string
 */
function format_datetime(?string $datetime, string $format = 'M j, Y g:i A'): string
{
    if (empty($datetime)) {
        return 'N/A';
    }
    try {
        $date = new DateTime($datetime, new DateTimeZone('UTC'));
        return $date->format($format);
    } catch (Exception) {
        return htmlspecialchars($datetime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Formats a UTC datetime into a relative human-friendly string (e.g., '2 hours ago').
 *
 * @param string|null $datetime
 * @return string
 */
function time_ago(?string $datetime): string
{
    if (empty($datetime)) {
        return 'N/A';
    }
    try {
        $time = new DateTime($datetime, new DateTimeZone('UTC'));
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $diff = $now->diff($time);

        if ($diff->y > 0) {
            return $diff->y === 1 ? '1 year ago' : "{$diff->y} years ago";
        }
        if ($diff->m > 0) {
            return $diff->m === 1 ? '1 month ago' : "{$diff->m} months ago";
        }
        if ($diff->d > 0) {
            return $diff->d === 1 ? '1 day ago' : "{$diff->d} days ago";
        }
        if ($diff->h > 0) {
            return $diff->h === 1 ? '1 hour ago' : "{$diff->h} hours ago";
        }
        if ($diff->i > 0) {
            return $diff->i === 1 ? '1 minute ago' : "{$diff->i} minutes ago";
        }
        return 'just now';
    } catch (Exception) {
        return 'N/A';
    }
}

/**
 * Redirects to a specified URL and immediately halts script execution.
 *
 * @param string $url
 * @return never
 */
function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

/**
 * Sets a session flash message.
 *
 * @param string $type ('success', 'error', 'warning', 'info')
 * @param string $message
 * @return void
 */
function set_flash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Normalize type aliases
    if ($type === 'danger') {
        $type = 'error';
    }
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message
    ];
}

/**
 * Retrieves and clears the current flash message from session.
 *
 * @return array|null Returns ['type' => string, 'message' => string] or null
 */
function get_flash(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Returns CSS badge class for ticket and PC operational statuses.
 *
 * @param string|null $status
 * @return string
 */
function get_status_badge_class(?string $status): string
{
    if (!$status) {
        return 'badge-status-default';
    }

    return match (strtolower(trim($status))) {
        'open'              => 'badge-status-open',
        'acknowledged'      => 'badge-status-acknowledged',
        'in progress'       => 'badge-status-in-progress',
        'waiting for parts' => 'badge-status-waiting',
        'resolved'          => 'badge-status-resolved',
        'closed'            => 'badge-status-closed',
        'rejected'          => 'badge-status-rejected',
        'working'           => 'badge-status-working',
        'reported issue'    => 'badge-status-reported-issue',
        'under maintenance' => 'badge-status-maintenance',
        'out of service'    => 'badge-status-out-of-service',
        'inactive'          => 'badge-status-inactive',
        default             => 'badge-status-default'
    };
}

/**
 * Returns CSS badge class for ticket priority levels.
 *
 * @param string|null $priority
 * @return string
 */
function get_priority_badge_class(?string $priority): string
{
    if (!$priority) {
        return 'badge-priority-default';
    }

    return match (strtolower(trim($priority))) {
        'critical' => 'badge-priority-critical',
        'high'     => 'badge-priority-high',
        'medium'   => 'badge-priority-medium',
        'low'      => 'badge-priority-low',
        default    => 'badge-priority-default'
    };
}

/**
 * Calculates pagination values and navigation parameters.
 *
 * @param int $total Total item count
 * @param int $per_page Items per page
 * @param int $current_page Current requested page
 * @return array
 */
function paginate(int $total, int $per_page, int $current_page = 1): array
{
    $per_page = max(1, $per_page);
    $total_pages = max(1, (int)ceil($total / $per_page));
    $current_page = max(1, min($current_page, $total_pages));
    $offset = ($current_page - 1) * $per_page;

    return [
        'total'        => $total,
        'per_page'     => $per_page,
        'current_page' => $current_page,
        'total_pages'  => $total_pages,
        'offset'       => $offset,
        'has_prev'     => $current_page > 1,
        'has_next'     => $current_page < $total_pages,
        'prev_page'    => $current_page - 1,
        'next_page'    => $current_page + 1,
    ];
}
