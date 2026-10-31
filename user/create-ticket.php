<?php
/**
 * Create Maintenance Ticket
 * Laboratory Management System (LMS)
 * Path: user/create-ticket.php
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

// Fetch active PCs for selection
try {
    $stmt = $db->query("
        SELECT pc_id, pc_code, pc_name, operational_status, location 
        FROM pcs 
        WHERE is_active = 1 
        ORDER BY pc_code ASC
    ");
    $activePcs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching active PCs: " . $e->getMessage());
    $activePcs = [];
}

// Initial form state
$selectedPcId = isset($_GET['pc_id']) ? (int)$_GET['pc_id'] : 0;
$category = '';
$priority = 'Medium';
$title = '';
$description = '';
$errors = [];
$warningNotice = null;

// Check if PC was preselected and currently has any open tickets to inform the user upfront
if ($selectedPcId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $stmtOpen = $db->prepare("
            SELECT ticket_number, category, status 
            FROM tickets 
            WHERE pc_id = :pc_id 
              AND status NOT IN ('Resolved', 'Closed', 'Rejected')
            ORDER BY ticket_id DESC
        ");
        $stmtOpen->execute([':pc_id' => $selectedPcId]);
        $existingTickets = $stmtOpen->fetchAll();
        if (!empty($existingTickets)) {
            $ticketRefs = array_map(function($t) {
                return $t['ticket_number'] . ' (' . $t['category'] . ' - ' . $t['status'] . ')';
            }, $existingTickets);
            $warningNotice = "Note: This PC currently has open/active ticket(s): " . implode(', ', $ticketRefs) . ". You may still submit this report if it describes a new or distinct problem.";
        }
    } catch (PDOException $e) {
        // Silently continue
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    check_csrf();

    $selectedPcId = isset($_POST['pc_id']) ? (int)$_POST['pc_id'] : 0;
    $category = trim($_POST['category'] ?? '');
    $priority = trim($_POST['priority'] ?? 'Medium');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Validation: PC Selection
    $pcExists = false;
    $pcData = null;
    if ($selectedPcId <= 0) {
        $errors['pc_id'] = 'Please select a laboratory workstation.';
    } else {
        foreach ($activePcs as $pc) {
            if ((int)$pc['pc_id'] === $selectedPcId) {
                $pcExists = true;
                $pcData = $pc;
                break;
            }
        }
        if (!$pcExists) {
            $errors['pc_id'] = 'Selected workstation is invalid or inactive.';
        }
    }

    // Validation: Category
    $allowedCategories = defined('ALLOWED_CATEGORIES') ? ALLOWED_CATEGORIES : [
        'Hardware', 'Software', 'Operating System', 'Network/Internet', 'Peripheral', 'Power', 'Other'
    ];
    if (empty($category)) {
        $errors['category'] = 'Please select an issue category.';
    } elseif (!in_array($category, $allowedCategories, true)) {
        $errors['category'] = 'Invalid category selected.';
    }

    // Validation: Priority
    $allowedPriorities = defined('ALLOWED_PRIORITIES') ? ALLOWED_PRIORITIES : ['Low', 'Medium', 'High', 'Critical'];
    if (empty($priority) || !in_array($priority, $allowedPriorities, true)) {
        $errors['priority'] = 'Invalid priority level selected.';
    }

    // Validation: Title
    if (empty($title)) {
        $errors['title'] = 'Issue title is required.';
    } elseif (mb_strlen($title) < 3) {
        $errors['title'] = 'Title must be at least 3 characters long.';
    } elseif (mb_strlen($title) > 200) {
        $errors['title'] = 'Title cannot exceed 200 characters.';
    }

    // Validation: Description
    $maxDescLength = defined('MAX_DESCRIPTION_LENGTH') ? MAX_DESCRIPTION_LENGTH : 2000;
    if (empty($description)) {
        $errors['description'] = 'Please provide detailed description of the issue.';
    } elseif (mb_strlen($description) < 5) {
        $errors['description'] = 'Description must be at least 5 characters.';
    } elseif (mb_strlen($description) > $maxDescLength) {
        $errors['description'] = "Description cannot exceed {$maxDescLength} characters.";
    }

    // Process submission if no validation errors
    if (empty($errors)) {
        try {
            // Check for existing open tickets for same PC and category
            $stmtCheck = $db->prepare("
                SELECT ticket_number, status, title 
                FROM tickets 
                WHERE pc_id = :pc_id 
                  AND category = :category 
                  AND status NOT IN ('Resolved', 'Closed', 'Rejected')
                ORDER BY ticket_id DESC 
                LIMIT 1
            ");
            $stmtCheck->execute([
                ':pc_id'    => $selectedPcId,
                ':category' => $category
            ]);
            $duplicateOpenTicket = $stmtCheck->fetch();

            // Serialize ticket number allocation to avoid duplicate references on concurrent submissions.
            $db->exec('BEGIN IMMEDIATE');

            // Generate Sequential Ticket Number
            $ticketNumber = generate_ticket_number($db);

            // Insert Ticket Record
            $stmtInsert = $db->prepare("
                INSERT INTO tickets (
                    ticket_number, pc_id, reporter_id, category, priority, 
                    title, description, status, created_at, updated_at
                ) VALUES (
                    :ticket_number, :pc_id, :reporter_id, :category, :priority, 
                    :title, :description, 'Open', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");
            $stmtInsert->execute([
                ':ticket_number' => $ticketNumber,
                ':pc_id'         => $selectedPcId,
                ':reporter_id'   => $currentUserId,
                ':category'      => $category,
                ':priority'      => $priority,
                ':title'         => $title,
                ':description'   => $description
            ]);

            $stmtPc = $db->prepare("UPDATE pcs SET operational_status = 'Reported Issue', updated_at = CURRENT_TIMESTAMP WHERE pc_id = :pc_id AND operational_status = 'Working'");
            $stmtPc->execute([':pc_id' => $selectedPcId]);

            $newTicketId = (int)$db->lastInsertId();

            // Insert Initial Status History
            $stmtHistory = $db->prepare("
                INSERT INTO ticket_status_history (
                    ticket_id, changed_by, old_status, new_status, note, changed_at
                ) VALUES (
                    :ticket_id, :changed_by, NULL, 'Open', :note, CURRENT_TIMESTAMP
                )
            ");
            $stmtHistory->execute([
                ':ticket_id'   => $newTicketId,
                ':changed_by'  => $currentUserId,
                ':note'        => 'Initial ticket submission by user'
            ]);

            // Commit Transaction
            $db->commit();

            // Set appropriate flash message
            if ($duplicateOpenTicket) {
                set_flash(
                    'warning', 
                    "Ticket {$ticketNumber} submitted successfully! (Note: An active ticket [{$duplicateOpenTicket['ticket_number']}] already exists for this PC under category '{$category}')."
                );
            } else {
                set_flash('success', "Ticket {$ticketNumber} has been created successfully!");
            }

            // Redirect to ticket details page
            redirect(APP_URL . "/user/ticket-details.php?id={$newTicketId}");

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error creating ticket: " . $e->getMessage());
            $errors['general'] = "An unexpected error occurred while saving the ticket. Please try again.";
        }
    }
}

$page_title = 'Report an Issue';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .form-container {
        max-width: 800px;
        margin: 0 auto;
    }

    .page-back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.9rem;
        margin-bottom: 1.25rem;
        transition: color 0.15s ease;
    }

    .page-back-link:hover {
        color: var(--primary);
    }

    .form-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 2rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .form-header {
        margin-bottom: 1.75rem;
        border-bottom: 1px solid var(--border);
        padding-bottom: 1.25rem;
    }

    .form-header h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.35rem;
    }

    .form-header p {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
    }

    @media (max-width: 640px) {
        .form-row {
            grid-template-columns: 1fr;
        }
    }

    .form-label {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .form-label .required {
        color: var(--danger);
        margin-left: 0.2rem;
    }

    .form-control, .form-select {
        width: 100%;
        padding: 0.75rem 0.9rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text-primary);
        font-size: 0.95rem;
        font-family: inherit;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .form-control:focus, .form-select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
    }

    .form-control.is-invalid, .form-select.is-invalid {
        border-color: var(--danger);
        background-color: rgba(239, 68, 68, 0.04);
    }

    .form-error {
        color: #fca5a5;
        font-size: 0.8rem;
        margin-top: 0.4rem;
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    .form-hint {
        font-size: 0.8rem;
        color: var(--text-secondary);
        margin-top: 0.4rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .char-counter {
        font-size: 0.775rem;
        color: var(--text-secondary);
        font-variant-numeric: tabular-nums;
    }

    .char-counter.limit-near {
        color: var(--warning);
    }

    .char-counter.limit-reached {
        color: var(--danger);
        font-weight: bold;
    }

    .form-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 1rem;
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border);
    }

    .alert-box {
        padding: 0.85rem 1.15rem;
        border-radius: 6px;
        font-size: 0.9rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
    }

    .alert-warning {
        background: rgba(245, 158, 11, 0.12);
        border: 1px solid rgba(245, 158, 11, 0.35);
        color: #fde68a;
    }

    .alert-danger {
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.35);
        color: #fca5a5;
    }
</style>

<div class="form-container">
    <a href="<?= APP_URL ?>/user/dashboard.php" class="page-back-link">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="19" y1="12" x2="5" y2="12"></line>
            <polyline points="12 19 5 12 12 5"></polyline>
        </svg>
        Back to Dashboard
    </a>

    <div class="form-card">
        <div class="form-header">
            <h1>Report Workstation Issue</h1>
            <p>Please provide specific details about the issue you are experiencing so lab administrators can address it promptly.</p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert-box alert-danger">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span><?= htmlspecialchars($errors['general']) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($warningNotice): ?>
            <div class="alert-box alert-warning">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                <span><?= htmlspecialchars($warningNotice) ?></span>
            </div>
        <?php endif; ?>

        <form action="<?= APP_URL ?>/user/create-ticket.php" method="POST" id="createTicketForm" novalidate>
            <?= csrf_token_field() ?>

            <!-- PC Selection -->
            <div class="form-group">
                <label for="pc_id" class="form-label">
                    Workstation / PC <span class="required">*</span>
                </label>
                <select name="pc_id" id="pc_id" class="form-select <?= isset($errors['pc_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">-- Select Computer Station --</option>
                    <?php foreach ($activePcs as $pc): ?>
                        <option value="<?= (int)$pc['pc_id'] ?>" <?= $selectedPcId === (int)$pc['pc_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pc['pc_code']) ?> 
                            <?= !empty($pc['pc_name']) ? ' - ' . htmlspecialchars($pc['pc_name']) : '' ?> 
                            [Status: <?= htmlspecialchars($pc['operational_status']) ?>]
                            <?= !empty($pc['location']) ? ' (' . htmlspecialchars($pc['location']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['pc_id'])): ?>
                    <div class="form-error">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <?= htmlspecialchars($errors['pc_id']) ?>
                    </div>
                <?php else: ?>
                    <div class="form-hint">Select the computer station that is having problems.</div>
                <?php endif; ?>
            </div>

            <!-- Category & Priority Row -->
            <div class="form-row">
                <div class="form-group">
                    <label for="category" class="form-label">
                        Issue Category <span class="required">*</span>
                    </label>
                    <select name="category" id="category" class="form-select <?= isset($errors['category']) ? 'is-invalid' : '' ?>" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach (ALLOWED_CATEGORIES as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['category'])): ?>
                        <div class="form-error">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <?= htmlspecialchars($errors['category']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="priority" class="form-label">
                        Priority Level <span class="required">*</span>
                    </label>
                    <select name="priority" id="priority" class="form-select <?= isset($errors['priority']) ? 'is-invalid' : '' ?>" required>
                        <?php foreach (ALLOWED_PRIORITIES as $prio): ?>
                            <option value="<?= htmlspecialchars($prio) ?>" <?= $priority === $prio ? 'selected' : '' ?>>
                                <?= htmlspecialchars($prio) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['priority'])): ?>
                        <div class="form-error">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <?= htmlspecialchars($errors['priority']) ?>
                        </div>
                    <?php else: ?>
                        <div class="form-hint">Default is Medium. Use High/Critical if lab work is completely blocked.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Issue Title -->
            <div class="form-group">
                <label for="title" class="form-label">
                    Brief Summary / Title <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    name="title" 
                    id="title" 
                    class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>" 
                    value="<?= htmlspecialchars($title) ?>" 
                    maxlength="200" 
                    placeholder="e.g. Monitor display is flickering or black" 
                    required
                >
                <?php if (isset($errors['title'])): ?>
                    <div class="form-error">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <?= htmlspecialchars($errors['title']) ?>
                    </div>
                <?php else: ?>
                    <div class="form-hint">Max 200 characters. Give a clear, concise summary.</div>
                <?php endif; ?>
            </div>

            <!-- Detailed Description with Character Counter -->
            <div class="form-group">
                <label for="description" class="form-label">
                    Detailed Description <span class="required">*</span>
                </label>
                <textarea 
                    name="description" 
                    id="description" 
                    rows="6" 
                    class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>" 
                    maxlength="<?= (int)MAX_DESCRIPTION_LENGTH ?>" 
                    placeholder="Describe what happened, any error messages displayed, and steps to reproduce..." 
                    required
                ><?= htmlspecialchars($description) ?></textarea>
                
                <div class="form-hint">
                    <?php if (isset($errors['description'])): ?>
                        <span class="form-error" style="margin-top:0;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <?= htmlspecialchars($errors['description']) ?>
                        </span>
                    <?php else: ?>
                        <span>Provide as much detail as possible to assist the technician.</span>
                    <?php endif; ?>
                    <span id="descCharCount" class="char-counter">0 / <?= (int)MAX_DESCRIPTION_LENGTH ?></span>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <a href="<?= APP_URL ?>/user/dashboard.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                    Submit Ticket
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const descArea = document.getElementById('description');
    const charCounter = document.getElementById('descCharCount');
    const maxLength = <?= (int)MAX_DESCRIPTION_LENGTH ?>;

    function updateCounter() {
        const currentLength = descArea.value.length;
        charCounter.textContent = currentLength + ' / ' + maxLength;

        if (currentLength >= maxLength) {
            charCounter.className = 'char-counter limit-reached';
        } else if (currentLength >= maxLength * 0.9) {
            charCounter.className = 'char-counter limit-near';
        } else {
            charCounter.className = 'char-counter';
        }
    }

    if (descArea && charCounter) {
        descArea.addEventListener('input', updateCounter);
        // Initial count check
        updateCounter();
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';

