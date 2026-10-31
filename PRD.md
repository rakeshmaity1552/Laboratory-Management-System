# Product Requirements Document (PRD)

## Laboratory Management System

**Version:** 1.0\
**Project type:** College software-laboratory support and asset-status
web application\
**Initial deployment:** Local Windows computer using XAMPP\
**Initial capacity:** 30 laboratory computers

## 1. Product overview

The Laboratory Management System (LMS) is a browser-based application
for reporting, tracking, and managing faults in a college computer
laboratory. Students or other authorized lab users can select a computer
by its asset number and submit a support ticket. Administrators can
monitor the status of all computers, review reported issues, manage
ticket progress, and record maintenance outcomes.

The initial installation is intended for a single lab with 30 PCs. The
design should allow the administrator to add or deactivate computers
later without changing application code.

## 2. Problem statement

Currently, faults may be reported verbally or informally. This can lead
to missed reports, duplicate complaints, uncertainty about which PC is
faulty, and no reliable history of repairs. The system provides one
central place to report issues and see the current condition of each PC.

## 3. Goals and success criteria

### Goals

-   Make issue reporting simple and quick for lab users.
-   Identify each computer unambiguously (PC-01 through PC-30
    initially).
-   Give administrators a live-at-page-load overview of lab
    availability.
-   Track every issue from submission through resolution.
-   Retain a history of tickets and maintenance actions.

### Success criteria

-   All 30 initial PCs appear in the administrator's inventory.
-   A valid user can submit a ticket against a selected PC.
-   Each submitted ticket receives a unique, human-readable ticket
    number.
-   Administrators can filter tickets by status, PC, category, and date.
-   PC status counts on the dashboard match the underlying records.
-   A resolved ticket retains its history and is not silently deleted.

## 4. Users and roles

### Student / Lab user

-   Sign in and sign out.
-   View the list of PCs and their availability.
-   Select a PC and submit an issue report.
-   View their own tickets and ticket status.
-   Add a follow-up comment to their own ticket, if enabled by the
    administrator.

### Administrator

-   Sign in to a protected admin area.
-   View dashboard statistics and all PC statuses.
-   Create, edit, deactivate, and inspect PC records.
-   View, search, filter, and update all tickets.
-   Assign a ticket to a technician or responsible staff member
    (optional in MVP).
-   Add internal maintenance notes and resolution details.
-   Change a PC's operational status after inspection or repair.
-   Manage user accounts and view audit history.

## 5. Scope

### MVP / Must have

1.  Authentication with role-based access (user and admin).
2.  PC inventory containing 30 initial records.
3.  PC status display.
4.  Ticket creation with PC, category, description, and priority.
5.  Ticket reference number.
6.  User ticket-history page.
7.  Admin dashboard with status counts and PC grid/list.
8.  Admin ticket queue with filters.
9.  Ticket status updates and resolution notes.
10. PC status updates.
11. Server-side validation, password hashing, session protection, and
    database backups.

### Should have

-   Ticket comments / conversation history.
-   Search by ticket number.
-   CSV export of tickets.
-   Maintenance history per PC.
-   Optional attachment upload for screenshots (restricted file type and
    size).
-   Admin audit log.

### Could have later

-   QR code label on each PC to open its report page.
-   Email or in-app notifications.
-   Multiple laboratories / buildings.
-   Technician role and assignment workflow.
-   Reports by month, category, PC, and resolution time.

### Out of scope for the first version

-   Remote diagnosis or remote control of PCs.
-   Automatic hardware monitoring or network scanning.
-   Public internet deployment.
-   Student attendance, examination, or asset-purchasing modules.

## 6. Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement             Priority
  ----------------------- ----------------------- -----------------------
  FR-01                   Users can authenticate  Must
                          using username/email    
                          and password.           

  FR-02                   The system enforces     Must
                          user and admin          
                          permissions.            

  FR-03                   The system displays     Must
                          active PCs and their    
                          status.                 

  FR-04                   A user can select a PC  Must
                          and submit an issue     
                          ticket.                 

  FR-05                   Ticket submission       Must
                          validates required      
                          fields and creates a    
                          unique reference.       

  FR-06                   A user can view only    Must
                          their own tickets.      

  FR-07                   An admin can view all   Must
                          tickets and filter      
                          them.                   

  FR-08                   An admin can change     Must
                          ticket status and add   
                          resolution notes.       

  FR-09                   An admin can change a   Must
                          PC's operational        
                          status.                 

  FR-10                   The dashboard shows     Must
                          accurate counts by PC   
                          status.                 

  FR-11                   The system records      Must
                          ticket creation and     
                          update timestamps.      

  FR-12                   An admin can manage PC  Should
                          inventory.              

  FR-13                   The system stores       Should
                          comments and            
                          maintenance history.    

  FR-14                   An admin can export     Could
                          filtered ticket data.   
  -----------------------------------------------------------------------

## 7. PC status model

Use a controlled set of values: - **Working:** PC is available for
normal use. - **Reported Issue:** A fault has been reported and is
awaiting assessment. - **Under Maintenance:** Staff are actively
inspecting or repairing the PC. - **Out of Service:** PC must not be
used until cleared. - **Inactive:** PC is removed from active lab
inventory.

A ticket status and a PC status are different concepts. For example, a
ticket can be `Resolved` while the PC is `Working`. Do not derive every
PC status solely from the latest ticket. An administrator should confirm
the operational status.

## 8. Ticket lifecycle

Suggested ticket statuses: 1. `Open` --- newly submitted. 2.
`Acknowledged` --- admin has reviewed it. 3. `In Progress` ---
troubleshooting or repair is underway. 4. `Waiting for Parts` ---
blocked pending a part or external action. 5. `Resolved` ---
repair/action completed. 6. `Closed` --- administratively closed. 7.
`Rejected` --- invalid, duplicate, or not actionable, with a reason.

Priority values: `Low`, `Medium`, `High`, `Critical`.

Issue categories: `Hardware`, `Software`, `Operating System`,
`Network/Internet`, `Peripheral`, `Power`, `Other`.

## 9. Dashboard requirements

Display: - Total active PCs. - Working PCs. - Reported-issue PCs. - PCs
under maintenance. - PCs out of service. - Open / in-progress tickets. -
Recently submitted tickets.

The dashboard must label counts clearly and provide a way to open the
filtered list behind each count.

## 10. Non-functional requirements

-   Usable on current desktop browsers; responsive enough for tablets
    and phones.
-   Normal page actions should complete quickly on a local network.
-   Passwords must be stored using PHP `password_hash()` and checked
    using `password_verify()`.
-   Use prepared SQL statements for all user-supplied values.
-   Enforce authorization on the server for every protected
    route/action.
-   Validate and normalize all input on the server; client-side
    validation is supplementary only.
-   Keep database files outside the public web root where practical.
-   Log important admin actions without logging passwords or session
    identifiers.
-   Provide a documented backup and restore procedure.

## 11. Assumptions and constraints

-   The first deployment is one lab with 30 PCs.
-   An administrator creates or approves user accounts; public
    self-registration is not required for MVP unless the college
    requests it.
-   Users access the application through a browser on the college
    network.
-   XAMPP is used for local development. For real multi-PC use, the
    XAMPP host computer must be reachable over the LAN and its firewall
    must permit the required HTTP port.
-   SQLite is suitable for a small, low-write-volume application. If
    many concurrent users or multiple labs are introduced, consider
    migrating to MySQL/MariaDB.

## 12. Acceptance criteria

-   A user cannot submit a ticket without selecting an active PC and
    entering a valid description.
-   A user cannot view another user's tickets by changing a URL or
    request parameter.
-   A non-admin cannot access admin pages or admin actions.
-   Ticket reference numbers are unique.
-   Admin changes persist after logout and restart.
-   Dashboard counts reflect the current PC records.
-   Closing a ticket does not delete its record.
-   Invalid input and database errors are handled without exposing stack
    traces or SQL details.

## 13. Risks

  -----------------------------------------------------------------------
  Risk                                Mitigation
  ----------------------------------- -----------------------------------
  Users select the wrong PC           Show PC number prominently;
                                      consider QR labels.

  Duplicate tickets                   Warn about an existing open ticket
                                      for the same PC and category; allow
                                      admin review.

  Unauthorized admin access           Server-side role checks, secure
                                      sessions, password hashing.

  Database loss                       Scheduled/manual backups and
                                      restore testing.

  SQLite write contention             Keep transactions short; migrate to
                                      MySQL/MariaDB if concurrency grows.

  Incorrect PC status                 Require admin confirmation when
                                      setting a PC back to Working.
  -----------------------------------------------------------------------

## 14. Future enhancements

Multi-lab support, QR code reporting, technician accounts,
notifications, analytics, attachment uploads, and integration with
college identity systems.
