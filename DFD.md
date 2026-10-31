# Data Flow Diagrams (DFD)

## Laboratory Management System

## 1. System context (Level 0)

The LMS receives authentication details and issue reports from lab
users. It sends ticket references and status information back to users.
Administrators provide management actions and receive dashboard, PC, and
ticket information.

``` mermaid
flowchart LR
    U[Student / Lab User] -->|Login details, PC selection, issue report| LMS((Laboratory Management System))
    LMS -->|Login result, ticket reference, ticket status| U
    A[Administrator] -->|Login, PC updates, ticket actions| LMS
    LMS -->|Dashboard, PC status, ticket queue, reports| A
    LMS <-->|Read/write application data| DB[(SQLite3 Database)]
```

## 2. Level 1 DFD

``` mermaid
flowchart TB
    U[Student / Lab User]
    A[Administrator]

    P1((1.0 Authentication))
    P2((2.0 View PC Inventory))
    P3((3.0 Submit Support Ticket))
    P4((4.0 Track User Tickets))
    P5((5.0 Manage Tickets))
    P6((6.0 Manage PC Status and Inventory))
    P7((7.0 Generate Dashboard))

    D1[(D1 Users)]
    D2[(D2 PCs)]
    D3[(D3 Tickets)]
    D4[(D4 Comments and History)]

    U -->|Credentials| P1
    A -->|Credentials| P1
    P1 <-->|User account lookup| D1
    P1 -->|Authenticated session / result| U
    P1 -->|Authenticated admin session / result| A

    U -->|Request PC list| P2
    P2 <-->|PC records| D2
    P2 -->|PC list and status| U

    U -->|PC, category, priority, description| P3
    P3 -->|Validate reporter| D1
    P3 -->|Validate selected PC| D2
    P3 -->|Create ticket| D3
    P3 -->|Initial status history| D4
    P3 -->|Ticket reference| U

    U -->|My tickets request| P4
    P4 <-->|Tickets filtered by reporter| D3
    P4 -->|Ticket list and details| U

    A -->|Search/filter/update ticket| P5
    P5 <-->|Read/update tickets| D3
    P5 -->|Status change and notes| D4
    P5 -->|Updated ticket details| A

    A -->|PC edit/status change| P6
    P6 <-->|Read/update PC records| D2
    P6 -->|Audit event| D4
    P6 -->|Updated PC information| A

    A -->|Dashboard request| P7
    P7 -->|Read PC status counts| D2
    P7 -->|Read ticket counts| D3
    P7 -->|Dashboard statistics| A
```

## 3. Level 2 DFD --- Submit support ticket

``` mermaid
flowchart TD
    U[Authenticated User]
    P31((3.1 Display active PC list))
    P32((3.2 Receive issue details))
    P33((3.3 Validate input and permissions))
    P34((3.4 Generate ticket number))
    P35((3.5 Save ticket and history))
    P36((3.6 Return confirmation))
    D1[(Users)]
    D2[(PCs)]
    D3[(Tickets)]
    D4[(Ticket Status History)]

    U -->|Open report form| P31
    P31 <-->|Active PC records| D2
    P31 -->|PC options| U
    U -->|Selected PC, category, priority, title, description| P32
    P32 --> P33
    P33 <-->|Verify user| D1
    P33 <-->|Verify PC is active| D2
    P33 -->|Valid data| P34
    P33 -->|Validation errors| U
    P34 -->|Unique reference| P35
    P35 -->|Insert ticket| D3
    P35 -->|Insert initial Open history| D4
    P35 --> P36
    P36 -->|Ticket number and confirmation| U
```

## 4. Level 2 DFD --- Admin updates a ticket

``` mermaid
flowchart TD
    A[Administrator]
    P51((5.1 Select ticket))
    P52((5.2 Load ticket details))
    P53((5.3 Validate admin and transition))
    P54((5.4 Update ticket))
    P55((5.5 Record status history / audit))
    P56((5.6 Display updated ticket))
    D1[(Users)]
    D3[(Tickets)]
    D4[(History and Audit)]

    A -->|Ticket ID| P51
    P51 --> P52
    P52 <-->|Ticket details| D3
    P52 -->|Current ticket| A
    A -->|New status and resolution note| P53
    P53 <-->|Verify admin role| D1
    P53 -->|Valid update| P54
    P53 -->|Reject invalid request| A
    P54 -->|Save new status and updated time| D3
    P54 --> P55
    P55 -->|Record actor, old/new status, note, time| D4
    P55 --> P56
    P56 -->|Updated ticket details| A
```

## 5. Data stores

  -----------------------------------------------------------------------
  Store                               Contents
  ----------------------------------- -----------------------------------
  D1 -- Users                         User profile, username/email,
                                      password hash, role, account status

  D2 -- PCs                           PC code, location, operational
                                      status, active flag

  D3 -- Tickets                       Reporter, PC, issue details,
                                      priority, ticket status, resolution

  D4 -- History                       Comments, status transitions, audit
                                      records
  -----------------------------------------------------------------------

## 6. Data-flow notes

-   Authentication must be checked before protected data is returned.
-   The user-facing ticket query must filter by the authenticated user's
    ID on the server.
-   Admin dashboard totals must be calculated from the database.
-   A ticket status update and its history record should be saved in one
    database transaction.
-   PC operational status should be changed by an authorized admin, not
    automatically assumed from a ticket's status.
