# Laboratory Management System

PHP and SQLite application for tracking laboratory workstations and support tickets.

## Setup

1. Install PHP 8.1+ with `pdo_sqlite` enabled, or use XAMPP with the same extension.
2. Set `DB_PATH` in `config/database.php` to a writable SQLite file outside the public web root.
3. Set the first administrator credentials in your shell environment as `LMS_ADMIN_USERNAME` and `LMS_ADMIN_PASSWORD` (password at least 6 characters). Optionally set `LMS_ADMIN_NAME`.
4. Run `php database/setup.php`. It creates the schema, seeds PC-01 through PC-30 once, and creates the initial admin when credentials are provided.
5. Point Apache at the project folder and open its URL. Configure `APP_URL` in `config/app.php` if automatic path detection does not match your virtual host.
6. Create user accounts from **Manage Users**. Change the administrator password after initial setup using the user management page.

The setup script is CLI-only. The included `.htaccess` rules block direct access to source/configuration files on Apache; use equivalent server rules on other web servers.

## Operations

- Ticket reports retain reporter, selected workstation, category, priority, notes, timestamps, and full status history.
- PC operational status is tracked separately from ticket status. Reporting an issue changes a Working PC to Reported Issue; admins can update its status from PC management or the ticket page.
- Back up the SQLite database file while the application is stopped, or use SQLite's online backup API when adding automated backups.
- Use `admin/audit-log.php` for administrative change history.

## Known deployment requirements

- Keep the SQLite database outside the document root and ensure the PHP/Apache account can write to its directory.
- Configure Apache/PHP error logging and firewall/LAN access for the host environment.
- Provide a regular backup schedule and a verified restore procedure before production use.
