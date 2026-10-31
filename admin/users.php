<?php
/**
 * User Management
 * Laboratory Management System (LMS)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$db = getDB();
$currentAdminId = get_current_user_id();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = trim($_POST['action'] ?? '');
    $now = gmdate('Y-m-d H:i:s');

    // 1. Add User
    if ($action === 'add_user') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $role     = trim($_POST['role'] ?? 'user');

        $errors = [];

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }

        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]{1,30}$/', $username)) {
            $errors[] = 'Username must be 1-30 characters with letters, numbers, underscores, dashes, or dots.';
        } else {
            // Check username uniqueness
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(:u)");
            $stmt->execute([':u' => $username]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = "Username '{$username}' is already taken.";
            }
        }

        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email address format.';
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(:e)");
                $stmt->execute([':e' => $email]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $errors[] = "Email '{$email}' is already registered to another user.";
                }
            }
        }

        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }

        if (!in_array($role, ['user', 'admin'], true)) {
            $errors[] = 'Invalid user role selected.';
        }

        if (!empty($errors)) {
            set_flash('error', implode(' ', $errors));
            redirect(APP_URL . '/admin/users.php?open=add');
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("
                INSERT INTO users (full_name, username, email, password_hash, role, account_status, created_at, updated_at)
                VALUES (:full_name, :username, :email, :password_hash, :role, 'active', :created_at, :updated_at)
            ");
            $stmt->execute([
                ':full_name'     => $fullName,
                ':username'      => $username,
                ':email'         => $email !== '' ? $email : null,
                ':password_hash' => $passwordHash,
                ':role'          => $role,
                ':created_at'    => $now,
                ':updated_at'    => $now,
            ]);
            $newUserId = (int)$db->lastInsertId();

            // Audit log
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at)
                VALUES (:user_id, :action, :entity_type, :entity_id, :details, :created_at)
            ");
            $auditStmt->execute([
                ':user_id'     => $currentAdminId,
                ':action'      => 'USER_CREATED',
                ':entity_type' => 'user',
                ':entity_id'   => $newUserId,
                ':details'     => "Admin created user '{$username}' ({$fullName}) with role '{$role}'",
                ':created_at'  => $now,
            ]);

            set_flash('success', "User '{$username}' has been successfully created.");
            redirect(APP_URL . '/admin/users.php');
        } catch (PDOException $e) {
            error_log("Error creating user: " . $e->getMessage());
            error_log('Error creating user: ' . $e->getMessage());
            set_flash('error', 'Database error creating user. Check that username and email are unique.');
            redirect(APP_URL . '/admin/users.php');
        }
    }

    // 2. Edit User
    if ($action === 'edit_user') {
        $editUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $fullName   = trim($_POST['full_name'] ?? '');
        $username   = trim($_POST['username'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $role       = trim($_POST['role'] ?? 'user');

        $errors = [];

        if ($editUserId <= 0) {
            $errors[] = 'Invalid user ID.';
        }

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }

        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]{1,30}$/', $username)) {
            $errors[] = 'Username must be 1-30 characters with letters, numbers, underscores, dashes, or dots.';
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(:u) AND user_id != :id");
            $stmt->execute([':u' => $username, ':id' => $editUserId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = "Username '{$username}' is already taken by another account.";
            }
        }

        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email address format.';
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(:e) AND user_id != :id");
                $stmt->execute([':e' => $email, ':id' => $editUserId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $errors[] = "Email '{$email}' is already taken by another account.";
                }
            }
        }

        if (!in_array($role, ['user', 'admin'], true)) {
            $errors[] = 'Invalid user role selected.';
        }

        // If current admin is editing themselves, prevent demoting themselves if only admin
        if ($editUserId === $currentAdminId && $role !== 'admin') {
            $errors[] = 'You cannot revoke your own administrator privileges.';
        }

        $updatePassword = false;
        if (!empty($password)) {
            if (strlen($password) < 6) {
                $errors[] = 'New password must be at least 6 characters.';
            } elseif ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            } else {
                $updatePassword = true;
            }
        }

        if (!empty($errors)) {
            set_flash('error', implode('<br>', $errors));
            redirect(APP_URL . '/admin/users.php');
        }

        try {
            if ($updatePassword) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    UPDATE users
                    SET full_name = :full_name,
                        username = :username,
                        email = :email,
                        password_hash = :password_hash,
                        role = :role,
                        updated_at = :updated_at
                    WHERE user_id = :id
                ");
                $stmt->execute([
                    ':full_name'     => $fullName,
                    ':username'      => $username,
                    ':email'         => $email !== '' ? $email : null,
                    ':password_hash' => $passwordHash,
                    ':role'          => $role,
                    ':updated_at'    => $now,
                    ':id'            => $editUserId,
                ]);
            } else {
                $stmt = $db->prepare("
                    UPDATE users
                    SET full_name = :full_name,
                        username = :username,
                        email = :email,
                        role = :role,
                        updated_at = :updated_at
                    WHERE user_id = :id
                ");
                $stmt->execute([
                    ':full_name'  => $fullName,
                    ':username'   => $username,
                    ':email'      => $email !== '' ? $email : null,
                    ':role'       => $role,
                    ':updated_at' => $now,
                    ':id'         => $editUserId,
                ]);
            }

            // Audit log
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at)
                VALUES (:user_id, :action, :entity_type, :entity_id, :details, :created_at)
            ");
            $auditStmt->execute([
                ':user_id'     => $currentAdminId,
                ':action'      => 'USER_UPDATED',
                ':entity_type' => 'user',
                ':entity_id'   => $editUserId,
                ':details'     => "Updated profile for user '{$username}'" . ($updatePassword ? " (password reset)" : ""),
                ':created_at'  => $now,
            ]);

            set_flash('success', "User '{$username}' updated successfully.");
            redirect(APP_URL . '/admin/users.php');
        } catch (PDOException $e) {
            error_log("Error updating user: " . $e->getMessage());
            set_flash('error', 'Database error updating user. Check the supplied details and try again.');
            redirect(APP_URL . '/admin/users.php');
        }
    }

    // 3. Toggle Status (Activate / Disable)
    if ($action === 'toggle_status') {
        $targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

        if ($targetUserId <= 0) {
            set_flash('error', 'Invalid user selected.');
            redirect(APP_URL . '/admin/users.php');
        }

        // Security check: Cannot disable own account
        if ($targetUserId === $currentAdminId) {
            set_flash('error', 'Security Violation: You cannot disable your own administrator account.');
            redirect(APP_URL . '/admin/users.php');
        }

        try {
            $stmt = $db->prepare("SELECT username, account_status FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                set_flash('error', 'User not found.');
                redirect(APP_URL . '/admin/users.php');
            }

            $newStatus = ($targetUser['account_status'] === 'active') ? 'disabled' : 'active';

            $updateStmt = $db->prepare("
                UPDATE users 
                SET account_status = :status, updated_at = :updated_at 
                WHERE user_id = :id
            ");
            $updateStmt->execute([
                ':status'     => $newStatus,
                ':updated_at' => $now,
                ':id'         => $targetUserId,
            ]);

            // Audit log
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at)
                VALUES (:user_id, :action, :entity_type, :entity_id, :details, :created_at)
            ");
            $auditStmt->execute([
                ':user_id'     => $currentAdminId,
                ':action'      => 'USER_STATUS_TOGGLED',
                ':entity_type' => 'user',
                ':entity_id'   => $targetUserId,
                ':details'     => "User '{$targetUser['username']}' account status toggled to '{$newStatus}'",
                ':created_at'  => $now,
            ]);

            set_flash('success', "User '{$targetUser['username']}' is now {$newStatus}.");
            redirect(APP_URL . '/admin/users.php');
        } catch (PDOException $e) {
            error_log("Error toggling user status: " . $e->getMessage());
            set_flash('error', 'Database error toggling status.');
            redirect(APP_URL . '/admin/users.php');
        }
    }
}

// Fetch all users
$users = [];
try {
    $stmt = $db->query("
        SELECT 
            user_id,
            full_name,
            username,
            email,
            role,
            account_status,
            created_at,
            updated_at
        FROM users
        ORDER BY role ASC, full_name ASC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
}

$page_title = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.users-page-wrap {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.page-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
}
.page-top-bar h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary, #1e293b);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.btn-new-user {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
    font-weight: 600;
    padding: 0.55rem 1rem;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}
.btn-new-user:hover {
    background: #1d4ed8;
}
.users-table-box {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
}
.users-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    text-align: left;
}
.users-table th {
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
.users-table td {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid rgba(51, 65, 85, 0.4);
    vertical-align: middle;
}
.users-table tbody tr:hover {
    background: rgba(255,255,255,0.02);
}
.badge-role {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    letter-spacing: 0.05em;
}
.badge-role.admin {
    background: rgba(245, 158, 11, 0.2);
    color: #fbbf24;
    border: 1px solid rgba(245, 158, 11, 0.4);
}
.badge-role.user {
    background: rgba(56, 189, 248, 0.2);
    color: #7dd3fc;
    border: 1px solid rgba(56, 189, 248, 0.4);
}
.badge-account-active {
    background: rgba(34, 197, 94, 0.15);
    color: #86efac;
    border: 1px solid rgba(34, 197, 94, 0.3);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.badge-account-disabled {
    background: rgba(239, 68, 68, 0.15);
    color: #fca5a5;
    border: 1px solid rgba(239, 68, 68, 0.3);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.user-actions {
    display: flex;
    gap: 0.4rem;
    align-items: center;
}
.btn-user-action {
    padding: 0.25rem 0.55rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 4px;
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none !important;
}
.btn-edit-user {
    background: rgba(56, 189, 248, 0.15);
    color: var(--primary, #2563eb);
    border-color: rgba(56, 189, 248, 0.3);
}
.btn-edit-user:hover {
    background: var(--primary, #2563eb);
    color: #ffffff !important;
}
.btn-toggle-disable {
    background: rgba(239, 68, 68, 0.15);
    color: #f87171;
    border-color: rgba(239, 68, 68, 0.3);
}
.btn-toggle-disable:hover {
    background: #ef4444;
    color: #fff;
}
.btn-toggle-enable {
    background: rgba(34, 197, 94, 0.15);
    color: #4ade80;
    border-color: rgba(34, 197, 94, 0.3);
}
.btn-toggle-enable:hover {
    background: #22c55e;
    color: #ffffff;
}

/* Modal Styling */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(4px);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.modal-overlay.active {
    display: flex;
}
.modal-card {
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    max-width: 500px;
    width: 100%;
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    overflow: hidden;
}
.modal-top {
    padding: 1.15rem 1.25rem;
    border-bottom: 1px solid var(--border, #e2e8f0);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-top h3 {
    margin: 0;
    font-size: 1.1rem;
    color: var(--text-primary, #1e293b);
}
.modal-close-x {
    background: transparent;
    border: none;
    color: var(--text-secondary, #64748b);
    font-size: 1.4rem;
    cursor: pointer;
    line-height: 1;
}
.modal-close-x:hover {
    color: var(--text-primary, #1e293b);
}
.modal-body {
    padding: 1.25rem;
}
.m-form-group {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    margin-bottom: 1rem;
}
.m-form-group label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
}
.m-form-group label.required::after {
    content: " *";
    color: #ef4444;
}
.m-input, .m-select {
    background: var(--bg-primary, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--text-primary, #1e293b);
    padding: 0.55rem 0.75rem;
    border-radius: 4px;
    font-size: 0.875rem;
    box-sizing: border-box;
    width: 100%;
}
.m-input:focus, .m-select:focus {
    outline: none;
    border-color: var(--primary, #2563eb);
}
.modal-bottom {
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--border, #e2e8f0);
    background: rgba(0, 0, 0, 0.15);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}
</style>

<div class="users-page-wrap">
    <!-- Header -->
    <div class="page-top-bar">
        <div>
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                Manage System Users
            </h1>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">
                Total registered user accounts: <strong><?= count($users) ?></strong>
            </p>
        </div>
        <div>
            <button type="button" class="btn-new-user" onclick="openAddModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add New User
            </button>
        </div>
    </div>

    <!-- Users Table -->
    <div class="users-table-box">
        <div style="overflow-x: auto;">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2.5rem; color: var(--text-secondary);">
                                No user accounts found in the database.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): 
                            $isSelf = ((int)$u['user_id'] === (int)$currentAdminId);
                        ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--text-primary);">
                                        <?= htmlspecialchars($u['full_name']) ?>
                                    </strong>
                                    <?php if ($isSelf): ?>
                                        <span style="font-size: 0.7rem; color: var(--primary); margin-left: 0.3rem;">(You)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-size: 0.9rem;">
                                        <?= htmlspecialchars($u['username']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= !empty($u['email']) ? htmlspecialchars($u['email']) : '<span style="color:#64748b;">â€”</span>' ?>
                                </td>
                                <td>
                                    <span class="badge-role <?= htmlspecialchars($u['role']) ?>">
                                        <?= htmlspecialchars($u['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['account_status'] === 'active'): ?>
                                        <span class="badge-account-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge-account-disabled">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span title="<?= format_datetime($u['created_at']) ?>">
                                        <?= time_ago($u['created_at']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="user-actions">
                                        <!-- Edit button -->
                                        <button type="button" 
                                                class="btn-user-action btn-edit-user"
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                                    'user_id'   => $u['user_id'],
                                                    'full_name' => $u['full_name'],
                                                    'username'  => $u['username'],
                                                    'email'     => $u['email'] ?? '',
                                                    'role'      => $u['role']
                                                ])) ?>)">
                                            Edit
                                        </button>

                                        <!-- Toggle Status button -->
                                        <?php if (!$isSelf): ?>
                                            <form method="POST" action="<?= APP_URL ?>/admin/users.php" style="margin: 0;"
                                                  onsubmit="return confirm('Are you sure you want to <?= ($u['account_status'] === 'active') ? 'disable' : 'activate' ?> user \'<?= htmlspecialchars($u['username']) ?>\'?');">
                                                <?= csrf_token_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                                
                                                <?php if ($u['account_status'] === 'active'): ?>
                                                    <button type="submit" class="btn-user-action btn-toggle-disable" title="Disable user access">
                                                        Disable
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn-user-action btn-toggle-enable" title="Activate user access">
                                                        Activate
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: #64748b; font-style: italic;">Active Self</span>
                                        <?php endif; ?>
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

<!-- Modal: Add New User -->
<div id="addUserModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-top">
            <h3>Add New System User</h3>
            <button type="button" class="modal-close-x" onclick="closeAddModal()">&times;</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/admin/users.php">
            <?= csrf_token_field() ?>
            <input type="hidden" name="action" value="add_user">

            <div class="modal-body">
                <div class="m-form-group">
                    <label for="add_full_name" class="required">Full Name</label>
                    <input type="text" id="add_full_name" name="full_name" class="m-input" placeholder="e.g. John Doe" required>
                </div>

                <div class="m-form-group">
                    <label for="add_username" class="required">Username</label>
                    <input type="text" id="add_username" name="username" class="m-input" placeholder="e.g. jdoe24" required>
                </div>

                <div class="m-form-group">
                    <label for="add_email">Email Address (Optional)</label>
                    <input type="email" id="add_email" name="email" class="m-input" placeholder="e.g. jdoe@college.edu">
                </div>

                <div class="m-form-group">
                    <label for="add_password" class="required">Password</label>
                    <input type="password" id="add_password" name="password" class="m-input" placeholder="At least 6 characters" required minlength="6">
                </div>

                <div class="m-form-group">
                    <label for="add_confirm_password" class="required">Confirm Password</label>
                    <input type="password" id="add_confirm_password" name="confirm_password" class="m-input" placeholder="Re-enter password" required minlength="6">
                </div>

                <div class="m-form-group">
                    <label for="add_role" class="required">Role</label>
                    <select id="add_role" name="role" class="m-select" required>
                        <option value="user">User (Standard Student / Faculty)</option>
                        <option value="admin">Admin (Laboratory Administrator)</option>
                    </select>
                </div>
            </div>

            <div class="modal-bottom">
                <button type="button" class="btn-new-user" style="background: var(--bg-tertiary); color: var(--text-primary) !important;" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn-new-user">Create User Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit User -->
<div id="editUserModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-top">
            <h3>Edit User Account</h3>
            <button type="button" class="modal-close-x" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/admin/users.php">
            <?= csrf_token_field() ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" id="edit_user_id" name="user_id" value="">

            <div class="modal-body">
                <div class="m-form-group">
                    <label for="edit_full_name" class="required">Full Name</label>
                    <input type="text" id="edit_full_name" name="full_name" class="m-input" required>
                </div>

                <div class="m-form-group">
                    <label for="edit_username" class="required">Username</label>
                    <input type="text" id="edit_username" name="username" class="m-input" required>
                </div>

                <div class="m-form-group">
                    <label for="edit_email">Email Address (Optional)</label>
                    <input type="email" id="edit_email" name="email" class="m-input">
                </div>

                <div class="m-form-group">
                    <label for="edit_password">New Password (Leave blank to keep unchanged)</label>
                    <input type="password" id="edit_password" name="password" class="m-input" placeholder="Enter new password if updating">
                </div>

                <div class="m-form-group">
                    <label for="edit_confirm_password">Confirm New Password</label>
                    <input type="password" id="edit_confirm_password" name="confirm_password" class="m-input" placeholder="Repeat new password">
                </div>

                <div class="m-form-group">
                    <label for="edit_role" class="required">Role</label>
                    <select id="edit_role" name="role" class="m-select" required>
                        <option value="user">User (Standard Student / Faculty)</option>
                        <option value="admin">Admin (Laboratory Administrator)</option>
                    </select>
                </div>
            </div>

            <div class="modal-bottom">
                <button type="button" class="btn-new-user" style="background: var(--bg-tertiary); color: var(--text-primary) !important;" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-new-user">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addUserModal').classList.add('active');
}
function closeAddModal() {
    document.getElementById('addUserModal').classList.remove('active');
}
function openEditModal(userData) {
    document.getElementById('edit_user_id').value = userData.user_id;
    document.getElementById('edit_full_name').value = userData.full_name;
    document.getElementById('edit_username').value = userData.username;
    document.getElementById('edit_email').value = userData.email || '';
    document.getElementById('edit_role').value = userData.role;
    document.getElementById('edit_password').value = '';
    document.getElementById('edit_confirm_password').value = '';
    document.getElementById('editUserModal').classList.add('active');
}
function closeEditModal() {
    document.getElementById('editUserModal').classList.remove('active');
}
// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddModal();
        closeEditModal();
    }
});
<?php if (isset($_GET['open']) && $_GET['open'] === 'add'): ?>
document.addEventListener('DOMContentLoaded', openAddModal);
<?php endif; ?>
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';

