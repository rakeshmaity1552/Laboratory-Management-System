# Development To-Do List

## Laboratory Management System

Use this checklist to build and test the project in manageable phases.

## Phase 1 --- Environment setup

-   [ ] Install XAMPP on Windows.
-   [ ] Start Apache from the XAMPP Control Panel.
-   [ ] Confirm `http://localhost/` opens.
-   [ ] Check the PHP version used by Apache.
-   [ ] Enable `sqlite3` and/or `pdo_sqlite` in the active `php.ini`.
-   [ ] Restart Apache after changing `php.ini`.
-   [ ] Verify SQLite support using a temporary PHP diagnostic page;
    remove that page afterwards.
-   [ ] Create `C:\xampp\htdocs\lab-management\`.
-   [ ] Create a separate data directory such as
    `C:\xampp\lab-management-data\` for the SQLite database.

> Database note: `mysqli` is for MySQL/MariaDB, not SQLite. This project
> must use PHP's `SQLite3` class or `PDO_SQLite`.

## Phase 2 --- Project structure

-   [ ] Create `assets/css/`, `assets/js/`, `assets/images/`.
-   [ ] Create `config/`, `includes/`, `auth/`, `user/`, `admin/`,
    `api/`, `database/`, `uploads/` (only if attachments are
    implemented).
-   [ ] Add a central configuration file for database path and
    application settings.
-   [ ] Add a database connection helper using SQLite3 or PDO_SQLite.
-   [ ] Keep secrets and the database outside the public web root where
    possible.
-   [ ] Add a shared header, footer, navigation, and flash-message
    component.
-   [ ] Add a `.htaccess` or server configuration to deny access to
    private files where applicable.

## Phase 3 --- Database

-   [ ] Create the `users` table.
-   [ ] Create the `pcs` table.
-   [ ] Create the `tickets` table.
-   [ ] Create the `ticket_comments` table.
-   [ ] Create the `ticket_status_history` table.
-   [ ] Create the `audit_logs` table.
-   [ ] Enable `PRAGMA foreign_keys = ON` for every database connection.
-   [ ] Add indexes for ticket status, PC, reporter, and creation date.
-   [ ] Write an idempotent setup/migration script.
-   [ ] Seed PC-01 through PC-30 exactly once.
-   [ ] Create the first admin through a secure setup command/page and
    remove or lock that setup path afterwards.

## Phase 4 --- Authentication and security

-   [ ] Build login page.
-   [ ] Implement login using `password_verify()`.
-   [ ] Implement account creation/admin user management.
-   [ ] Store passwords using `password_hash()`.
-   [ ] Regenerate session ID after login.
-   [ ] Implement logout and session invalidation.
-   [ ] Implement `require_login()` middleware/helper.
-   [ ] Implement `require_admin()` middleware/helper.
-   [ ] Add ownership checks for user ticket details.
-   [ ] Add CSRF token validation to every state-changing form/action.
-   [ ] Use prepared statements for all SQL containing user input.
-   [ ] Escape output using `htmlspecialchars()`.
-   [ ] Set secure session cookie options.
-   [ ] Add basic login throttling or rate limiting.

## Phase 5 --- User interface

-   [ ] Build responsive login page.
-   [ ] Build user dashboard.
-   [ ] Display active PC cards with PC code and operational status.
-   [ ] Build report-issue form.
-   [ ] Add issue categories: Hardware, Software, Operating System,
    Network/Internet, Peripheral, Power, Other.
-   [ ] Add priority choices: Low, Medium, High, Critical.
-   [ ] Add title and detailed description fields.
-   [ ] Show field-level validation errors.
-   [ ] Show ticket submission confirmation and ticket reference.
-   [ ] Build My Tickets page.
-   [ ] Build ticket detail page.
-   [ ] Display ticket status and timestamps clearly.

## Phase 6 --- Ticket backend

-   [ ] Implement server-side ticket validation.
-   [ ] Confirm selected PC exists and is active.
-   [ ] Generate unique ticket references (for example
    `LAB-YYYY-00001`).
-   [ ] Save ticket with authenticated reporter ID.
-   [ ] Save initial status-history record in the same transaction.
-   [ ] Prevent users from viewing or changing another user's ticket.
-   [ ] Implement ticket listing with pagination.
-   [ ] Implement optional comments/follow-up messages.

## Phase 7 --- Admin dashboard

-   [ ] Build admin-only dashboard.
-   [ ] Show total active PCs.
-   [ ] Show Working PC count.
-   [ ] Show Reported Issue PC count.
-   [ ] Show Under Maintenance PC count.
-   [ ] Show Out of Service PC count.
-   [ ] Show Open and In Progress ticket counts.
-   [ ] Build PC grid/table with clear status indicators.
-   [ ] Build PC detail page with ticket history.
-   [ ] Build PC add/edit/deactivate functionality.
-   [ ] Require confirmation when changing a PC to Working or Out of
    Service.

## Phase 8 --- Admin ticket management

-   [ ] Build all-tickets table.
-   [ ] Add filters for status, PC, category, priority, and date.
-   [ ] Add ticket search by reference number.
-   [ ] Build ticket detail view.
-   [ ] Allow admin to acknowledge a ticket.
-   [ ] Allow admin to mark ticket In Progress.
-   [ ] Allow admin to mark ticket Waiting for Parts.
-   [ ] Allow admin to resolve/close/reject a ticket with an appropriate
    note.
-   [ ] Record every status change in ticket history.
-   [ ] Record important admin actions in audit logs.
-   [ ] Update PC operational status separately from ticket status.

## Phase 9 --- Testing

-   [ ] Test valid and invalid login.
-   [ ] Test user/admin access boundaries.
-   [ ] Test direct URL access to admin pages as a user.
-   [ ] Test ticket creation with valid input.
-   [ ] Test empty and oversized descriptions.
-   [ ] Test invalid/inactive PC selection.
-   [ ] Test ticket reference uniqueness.
-   [ ] Test user cannot view another user's ticket.
-   [ ] Test admin ticket filters.
-   [ ] Test ticket status transitions and history.
-   [ ] Test PC status updates.
-   [ ] Verify dashboard counts against database queries.
-   [ ] Test SQL injection strings as ordinary input.
-   [ ] Test CSRF rejection.
-   [ ] Test database backup and restore.
-   [ ] Test on another device connected to the same LAN.

## Phase 10 --- Deployment and handover

-   [ ] Remove diagnostic and setup scripts.
-   [ ] Disable verbose PHP errors in user-facing production pages.
-   [ ] Ensure database is not downloadable through the browser.
-   [ ] Configure Windows Firewall for the required LAN access.
-   [ ] Document the host PC's LAN address and application URL for lab
    users.
-   [ ] Create a regular database backup schedule.
-   [ ] Prepare an admin user guide.
-   [ ] Demonstrate ticket reporting and admin resolution to lab staff.
-   [ ] Record known limitations and future enhancements.

## Suggested folder structure

``` text
lab-management/
│
├── index.php
├── login.php
├── logout.php
├── config/
│   ├── app.php
│   └── database.php
│
├── includes/
│   ├── auth.php
│   ├── csrf.php
│   ├── functions.php
│   ├── header.php
│   └── footer.php
│
├── user/
│   ├── dashboard.php
│   ├── create-ticket.php
│   ├── my-tickets.php
│   └── ticket-details.php
│
├── admin/
│   ├── dashboard.php
│   ├── pcs.php
│   ├── pc-edit.php
│   ├── tickets.php
│   ├── ticket-details.php
│   └── users.php
│
├── api/
│   ├── create-ticket.php
│   ├── update-ticket.php
│   └── update-pc-status.php
│
├── assets/
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── app.js
│   └── images/
│
└── database/
    ├── schema.sql
    └── seed-pcs.sql
```

Keep the actual SQLite database file outside `htdocs`; the `database/`
folder above is for schema and seed scripts, not the live database.

## Recommended implementation order

1.  Database connection and schema.
2.  Secure admin creation and authentication.
3.  PC inventory and 30-PC seed.
4.  User dashboard and ticket submission.
5.  My Tickets and ticket ownership checks.
6.  Admin dashboard and PC status management.
7.  Admin ticket queue and ticket lifecycle.
8.  History, audit, testing, backups, and deployment.
