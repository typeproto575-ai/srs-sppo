# SRS-SPPO System Graph

This graph summarizes the current local PHP application and the 13-table schema in `database/v2/schema.sql`.

## Application architecture and workflows

```mermaid
flowchart LR
    Parishioner[Parishioner / visitor]
    Secretary[Parish secretary]
    Browser[Web browser]

    Parishioner --> Browser
    Secretary --> Browser

    subgraph PHP["PHP application on XAMPP / Apache"]
        Public["Public pages<br/>Home · requirements · calendar<br/>Sacrament pre-applications<br/>Certificate requests"]
        Admin["Staff pages<br/>Applications and certificate queues<br/>Records · schedule · reports<br/>People · system settings"]
        Shared["Shared includes<br/>Authentication · validation · record helpers<br/>Notifications · audit · email"]
        Config["Configuration<br/>PDO database connection<br/>Environment and mail settings"]
        Public --> Shared
        Admin --> Shared
        Shared --> Config
    end

    Browser --> Public
    Browser --> Admin

    subgraph Database["MySQL / MariaDB"]
        People[(persons<br/>Master identity)]
        Apps[(sacrament_applications<br/>Intake · status · requirements)]
        Requests[(certificate_requests<br/>Request · print · release)]
        Records[(sacrament_records<br/>Baptism · Communion<br/>Confirmation · Marriage)]
        Participants[(sacrament_participants<br/>Godparents · sponsors · witnesses)]
        Batches[(sacrament_batches<br/>Formation groups)]
        Calendar[(schedule_events)]
        Funerals[(funeral_records)]
        AdminData[(users · settings · announcements<br/>notifications · audit_logs)]
    end

    Config --> Database

    Public -->|"Submit pre-application"| Apps
    Public -->|"Request certificate"| Requests
    Public -->|"View published content"| Calendar
    Public -->|"View announcements / settings"| AdminData

    Admin -->|"Review · match · update status"| Apps
    Admin -->|"Link person and verify record"| People
    Admin -->|"Encode / correct register entry"| Records
    Records --> Participants
    Admin -->|"Manage groups and calendar"| Batches
    Batches --> Apps
    Apps --> Calendar
    Admin -->|"Match · print · sign · release"| Requests
    Admin -->|"Encode funeral register"| Funerals
    Admin -->|"Users · reports · audit · notices"| AdminData

    Shared -->|"Status and delivery notifications"| Email[Configured SMTP relay]
    Shared -->|"Encrypted backup files"| Storage[Local protected storage]
```

## Relational data model

```mermaid
erDiagram
    USERS {
        int id PK
        string username
        string role
    }
    PERSONS {
        int id PK
        string first_name
        string last_name
        date birth_date
        int father_person_id FK
        int mother_person_id FK
    }
    SACRAMENT_RECORDS {
        int id PK
        string sacrament_type
        int person_id FK
        int spouse_person_id FK
        date event_date
        json details
    }
    SACRAMENT_PARTICIPANTS {
        int id PK
        int record_id FK
        string role
        string full_name
    }
    SACRAMENT_APPLICATIONS {
        int id PK
        string request_no
        string sacrament_type
        int person_id FK
        int spouse_person_id FK
        int batch_id FK
        int record_id FK
        string status
        json details
        json requirements
    }
    SACRAMENT_BATCHES {
        int id PK
        string sacrament_type
        string batch_name
    }
    CERTIFICATE_REQUESTS {
        int id PK
        string request_no
        string sacrament_type
        int person_id FK
        int record_id FK
        string status
        json subject_details
    }
    SCHEDULE_EVENTS {
        int id PK
        date event_date
        int application_id FK
        int batch_id FK
    }
    FUNERAL_RECORDS {
        int id PK
        int person_id FK
        date date_of_death
        date date_of_burial
    }
    ANNOUNCEMENTS {
        int id PK
        string title
        date event_date
        int posted_by FK
    }
    SETTINGS {
        string setting_key PK
        int updated_by FK
    }
    NOTIFICATIONS {
        int id PK
        string channel
        string type
        int entity_id
    }
    AUDIT_LOGS {
        int id PK
        int user_id FK
        string action
        string target_table
        int target_id
    }

    PERSONS o|--o{ PERSONS : "father / mother links"
    PERSONS ||--o{ SACRAMENT_RECORDS : recipient
    PERSONS o|--o{ SACRAMENT_RECORDS : spouse
    SACRAMENT_RECORDS ||--o{ SACRAMENT_PARTICIPANTS : has
    PERSONS o|--o{ SACRAMENT_APPLICATIONS : applicant
    PERSONS o|--o{ SACRAMENT_APPLICATIONS : spouse
    SACRAMENT_BATCHES o|--o{ SACRAMENT_APPLICATIONS : groups
    SACRAMENT_RECORDS o|--o{ SACRAMENT_APPLICATIONS : completes
    PERSONS o|--o{ CERTIFICATE_REQUESTS : subject
    SACRAMENT_RECORDS o|--o{ CERTIFICATE_REQUESTS : matched_record
    SACRAMENT_APPLICATIONS o|--o{ SCHEDULE_EVENTS : schedules
    SACRAMENT_BATCHES o|--o| SCHEDULE_EVENTS : group_schedule
    PERSONS ||--o| FUNERAL_RECORDS : funeral_entry

    USERS o|--o{ SACRAMENT_RECORDS : encodes_or_verifies
    USERS o|--o{ SACRAMENT_APPLICATIONS : assigned_or_processes
    USERS o|--o{ SACRAMENT_BATCHES : creates
    USERS o|--o{ CERTIFICATE_REQUESTS : processes
    USERS o|--o{ SCHEDULE_EVENTS : creates
    USERS o|--o{ FUNERAL_RECORDS : encodes
    USERS o|--o{ ANNOUNCEMENTS : posts
    USERS o|--o{ SETTINGS : updates
    USERS o|--o{ AUDIT_LOGS : acts
```

`NOTIFICATIONS.entity_type` and `entity_id` form a polymorphic reference, so they are intentionally not shown as a foreign-key relationship. Application and certificate details are JSON snapshots; the master person and official register remain separate records.
