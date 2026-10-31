# Software Requirements Specification (SRS)

## Laboratory Management System

**Version:** 1.0\
**Stack:** HTML, CSS, JavaScript, PHP, SQLite3, XAMPP on Windows

## 1. Introduction

### 1.1 Purpose

This SRS defines the functional and non-functional requirements for the
Laboratory Management System (LMS), a web application used to report
faults in a college computer laboratory and manage the resulting support
tickets.

### 1.2 Intended audience

Project developer, project guide, laboratory administrator, lab staff,
testers, and future maintainers.

### 1.3 Product scope

The application manages an inventory of laboratory PCs, user accounts,
support tickets, ticket updates, and operational PC status. The initial
database is seeded with 30 PCs.

### 1.4 Definitions

-   **PC:** A uniquely identified laboratory computer.
-   **Ticket:** A support request describing an issue with a PC.
-   **User:** Student or authorized lab user who reports an issue.
-   **Admin:** Authorized staff member who manages PCs and tickets.
-   **Operational status:** Current condition/availability of a PC.
-   **Ticket status:** Progress state of a support request.

## 2. Overall description

### 2.1 Product perspective

A PHP application served by Apache through XAMPP. The browser renders
HTML/CSS and uses JavaScript for client-side interactions. PHP handles
authentication, authorization, validation, business logic, and database
operations. SQLite3 stores application data.

### 2.2 User classes

**User:** Can view available PCs, create tickets, and view their own
tickets.\
**Admin:** Can manage PCs, view all tickets, update ticket and PC
statuses, and inspect reports.

### 2.3 Operating environment

-   Windows 10/11 development machine.
-   XAMPP with Apache and a compatible PHP version.
-   PHP SQLite3 extension enabled (`SQLite3`) or PDO SQLite driver
    enabled.
-   Modern browser such as Chrome, Edge, or Firefox.
-   For shared lab use, clients connect to the host PC over the college
    LAN.

### 2.4 Constraints

-   SQLite3 is the required database.
-   PHP `mysqli` does not support SQLite3. Use PHP `SQLite3` or
    `PDO_SQLite`; do not attempt to use `mysqli` with the `.db` file.
-   The database should be stored outside the publicly accessible
    `htdocs` directory where practical.
-   The first release supports one laboratory and 30 seeded PCs.

### 2.5 Assumptions

-   The administrator can create user accounts or approve registrations.
-   The PC inventory is maintained by authorized staff.
-   Users provide accurate PC identifiers and issue descriptions.
-   The application host remains powered on and accessible during lab
    hours.

## 3. Functional requirements

### 3.1 Authentication and authorization

-   **SRS-AUTH-01:** The system shall allow an active user to sign in
    with valid credentials.
-   **SRS-AUTH-02:** The system shall store password hashes, never
    plaintext passwords.
-   **SRS-AUTH-03:** The system shall support `user` and `admin` roles.
-   **SRS-AUTH-04:** The system shall deny protected actions when the
    session is missing or the role is insufficient.
-   **SRS-AUTH-05:** The system shall allow users to log out and
    invalidate their session.
-   **SRS-AUTH-06:** The system shall regenerate the session ID after
    successful login.

### 3.2 PC inventory

-   **SRS-PC-01:** The system shall store a unique PC code, such as
    `PC-01`.
-   **SRS-PC-02:** The system shall initially create 30 PC records
    (`PC-01` to `PC-30`).
-   **SRS-PC-03:** The system shall display PC code and operational
    status.
-   **SRS-PC-04:** Admins shall be able to add, edit, and deactivate
    PCs.
-   **SRS-PC-05:** Deactivated PCs shall remain in historical ticket
    records.
-   **SRS-PC-06:** Only admins shall change operational status.

### 3.3 Ticket creation

-   **SRS-TKT-01:** An authenticated user shall be able to select an
    active PC.
-   **SRS-TKT-02:** The user shall select an issue category and
    priority.
-   **SRS-TKT-03:** The user shall provide a description with a
    configured maximum length.
-   **SRS-TKT-04:** The system shall validate the PC, category,
    priority, and description on the server.
-   **SRS-TKT-05:** The system shall generate a unique ticket reference.
-   **SRS-TKT-06:** The system shall record the reporter and creation
    timestamp.
-   **SRS-TKT-07:** The system may warn about a similar open ticket for
    the same PC but shall not silently discard a new report.

### 3.4 User ticket access

-   **SRS-USER-01:** Users shall see their own tickets.
-   **SRS-USER-02:** Users shall see ticket reference, PC, category,
    priority, status, and timestamps.
-   **SRS-USER-03:** Users shall not access another user's ticket by
    changing an ID in the URL or request.
-   **SRS-USER-04:** Users may add a follow-up comment if comments are
    enabled.

### 3.5 Admin ticket management

-   **SRS-ADMIN-01:** Admins shall view all tickets.
-   **SRS-ADMIN-02:** Admins shall filter tickets by status, PC,
    category, priority, and date.
-   **SRS-ADMIN-03:** Admins shall update ticket status.
-   **SRS-ADMIN-04:** Admins shall add resolution notes when resolving a
    ticket.
-   **SRS-ADMIN-05:** Admins shall view ticket history and comments.
-   **SRS-ADMIN-06:** Admins may assign tickets to a technician/staff
    member in a later release.

### 3.6 Dashboard and reports

-   **SRS-DASH-01:** The dashboard shall show the count of active PCs.
-   **SRS-DASH-02:** The dashboard shall show counts by operational PC
    status.
-   **SRS-DASH-03:** The dashboard shall show open and in-progress
    ticket counts.
-   **SRS-DASH-04:** Admins shall be able to open a PC detail view and
    see its ticket history.
-   **SRS-DASH-05:** Counts shall be calculated from database records,
    not hard-coded values.

### 3.7 Audit and maintenance history

-   **SRS-AUD-01:** The system should record admin changes to ticket and
    PC status.
-   **SRS-AUD-02:** The system should record actor, action, affected
    record, and timestamp.
-   **SRS-AUD-03:** Historical tickets shall not be physically deleted
    through normal UI actions.

## 4. External interface requirements

### 4.1 User interface

Required screens: 1. Login page. 2. User dashboard / PC selection. 3.
Create ticket page or modal. 4. My tickets page. 5. Ticket detail page.
6. Admin dashboard. 7. PC inventory management page. 8. Admin ticket
queue. 9. Admin ticket detail/update page. 10. User management page
(admin).

### 4.2 Software interfaces

-   Browser to Apache: HTTP during local development; HTTPS recommended
    for deployment.
-   Apache/PHP to SQLite: PHP `SQLite3` API or PDO SQLite.
-   No external API is required for MVP.

### 4.3 Database interface

Use prepared statements and bound parameters. Enable foreign-key
enforcement on each SQLite connection with `PRAGMA foreign_keys = ON`.

## 5. Data requirements

Core entities: - `users` - `pcs` - `tickets` - `ticket_comments`
(recommended) - `ticket_status_history` (recommended) - `audit_logs`
(recommended)

Detailed fields and relationships are defined in `ERD.md`.

## 6. Business rules

1.  PC code must be unique.
2.  A ticket must reference an existing PC and reporter.
3.  A ticket must have a non-empty description.
4.  Only admins may change PC operational status.
5.  Users can access only tickets they created.
6.  Admins can access all tickets.
7.  A resolved ticket must include a resolution note or resolution
    summary.
8.  A PC can have multiple historical tickets.
9.  A ticket's status must not be confused with the PC's operational
    status.
10. Deactivation must preserve historical records.

## 7. Non-functional requirements

### 7.1 Security

-   Use `password_hash()` and `password_verify()`.
-   Use prepared statements.
-   Escape output with `htmlspecialchars()` when rendering
    user-controlled text.
-   Use CSRF tokens for state-changing forms.
-   Set session cookies `HttpOnly`, `SameSite=Lax`, and `Secure` when
    HTTPS is enabled.
-   Regenerate session IDs after login.
-   Apply server-side role and ownership checks.
-   Restrict uploads by MIME type, extension, size, and generated
    filename if attachments are implemented.
-   Do not expose PHP errors, SQL statements, or filesystem paths to end
    users in production.

### 7.2 Reliability and backup

-   Use database transactions for multi-step updates.
-   Back up the SQLite database regularly.
-   Test restoration from a backup.
-   Avoid copying the database file while a write is in progress; use
    SQLite's backup facilities or a controlled maintenance window.

### 7.3 Performance

-   Use indexes for frequently filtered columns such as ticket status,
    PC ID, reporter ID, and creation date.
-   Paginate long ticket lists.
-   Keep database transactions short.

### 7.4 Usability

-   Use clear status labels and distinguish PC status from ticket
    status.
-   Show validation messages next to relevant fields.
-   Confirm consequential admin actions.
-   Ensure readable contrast and keyboard-accessible controls.

## 8. Error handling

-   Invalid credentials: show a generic login error.
-   Missing required fields: show validation errors.
-   Unauthorized request: return HTTP 403 or redirect to login as
    appropriate.
-   Missing record: return HTTP 404 or a safe not-found message.
-   Database failure: log technical details privately and show a generic
    error.
-   Duplicate PC code or ticket reference: reject safely and
    retry/reference generation as appropriate.

## 9. Verification and acceptance tests

  -----------------------------------------------------------------------
  Test ID                 Scenario                Expected result
  ----------------------- ----------------------- -----------------------
  TC-01                   Login with valid user   User dashboard opens
                          credentials             

  TC-02                   Login with invalid      Access denied with
                          credentials             generic message

  TC-03                   User attempts admin URL Request denied

  TC-04                   User submits valid      Ticket saved with
                          ticket                  unique reference

  TC-05                   User submits blank      Validation error; no
                          description             ticket saved

  TC-06                   User requests another   Access denied
                          user's ticket           

  TC-07                   Admin changes ticket to Change persists and
                          In Progress             history is recorded

  TC-08                   Admin marks repaired PC PC status updates
                          Working                 

  TC-09                   Dashboard loads         Counts match database

  TC-10                   PC is deactivated       PC hidden from new
                                                  reports; old tickets
                                                  remain

  TC-11                   SQL-like input is       Treated as data; no SQL
                          submitted               injection

  TC-12                   CSRF token is           State-changing request
                          missing/invalid         rejected
  -----------------------------------------------------------------------

## 10. Deployment requirements

1.  Install XAMPP and start Apache.
2.  Place application source under `C:\xampp\htdocs\lab-management\`.
3.  Enable the PHP SQLite3 extension in the active `php.ini` (and
    restart Apache).
4.  Keep the SQLite database in a non-public directory, for example
    `C:\xampp\lab-management-data\`.
5.  Configure the application database path.
6.  Run the setup/seed script once to create tables and 30 PCs.
7.  Create the first administrator securely; do not ship a public
    default password.
8.  Test from the host machine, then from another lab PC using the
    host's LAN IP and permitted firewall settings.
9.  For production use, configure HTTPS, backups, access controls, and a
    supported deployment environment.
