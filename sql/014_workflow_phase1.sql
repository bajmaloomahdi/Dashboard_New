/* ==========================================================================
   پچ 014 | Workflow Engine — فاز ۱ (Linear Core) + ماژول مستقل Business Calendar
   --------------------------------------------------------------------------
   شامل فقط: ایجاد ۱۷ جدول جدید + Index/Constraint + Seedهای افزایشی.
   Stored Procedureها و لایه‌ی Service در Step 4 (پچ بعدی) اضافه می‌شوند.

   قوانین رعایت‌شده:
   - هیچ DROP / TRUNCATE / DELETE مخرب / RENAME / تغییر نوع روی ساختار موجود.
   - همه‌ی Seedها با IF NOT EXISTS و افزایشی.
   - فقط ستون‌هایی که در فاز ۱ واقعاً استفاده می‌شوند (بدون ستون‌های آینده).
   - FK فقط به dbo.Users و dbo.msgPriorities و بین جداول خودِ Workflow.
   - ستون‌های audit طبق کانونشن دوره‌ی Calendar: Date_InsertFirst / UserID_InsertFirst
     / Date_LastUpdate / UserID_LastUpdate (بدون FK روی ستون‌های audit، مطابق
     Reminders / CalendarEvents موجود).
   ========================================================================== */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

/* =======================================================================
   گروه D — ماژول عمومیِ مستقل Business Calendar (۳ جدول، پیشوند BusinessCalendar*)
   ======================================================================= */

-- D1 --------------------------------------------------------------------
IF OBJECT_ID('dbo.BusinessCalendars', 'U') IS NULL
CREATE TABLE dbo.BusinessCalendars (
    CalendarID          INT              IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_BusinessCalendars_RowGuid DEFAULT (NEWSEQUENTIALID()),
    Code                NVARCHAR(32)      NOT NULL,
    Name                NVARCHAR(200)     NOT NULL,
    TimeZone            NVARCHAR(64)      NOT NULL CONSTRAINT DF_BusinessCalendars_TimeZone DEFAULT (N'Asia/Tehran'),
    IsDefault           BIT              NOT NULL CONSTRAINT DF_BusinessCalendars_IsDefault DEFAULT (0),
    IsActive            BIT              NOT NULL CONSTRAINT DF_BusinessCalendars_IsActive DEFAULT (1),
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_BusinessCalendars_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_BusinessCalendars PRIMARY KEY CLUSTERED (CalendarID),
    CONSTRAINT UQ_BusinessCalendars_Code UNIQUE (Code)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_BusinessCalendars_OneDefault')
CREATE UNIQUE INDEX UX_BusinessCalendars_OneDefault
    ON dbo.BusinessCalendars (IsDefault) WHERE IsDefault = 1;
GO

-- D2 --------------------------------------------------------------------
IF OBJECT_ID('dbo.BusinessCalendarWorkingHours', 'U') IS NULL
CREATE TABLE dbo.BusinessCalendarWorkingHours (
    WorkingHourID       INT              IDENTITY(1,1) NOT NULL,
    CalendarID          INT              NOT NULL,
    DayOfWeek           TINYINT          NOT NULL,          -- 0=شنبه ... 6=جمعه
    IsWorkingDay        BIT              NOT NULL CONSTRAINT DF_BCWH_IsWorkingDay DEFAULT (1),
    StartTime           TIME(0)          NULL,
    EndTime             TIME(0)          NULL,
    SortOrder           TINYINT          NOT NULL CONSTRAINT DF_BCWH_SortOrder DEFAULT (0),
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_BCWH_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_BusinessCalendarWorkingHours PRIMARY KEY CLUSTERED (WorkingHourID),
    CONSTRAINT CK_BCWH_DayOfWeek CHECK (DayOfWeek BETWEEN 0 AND 6),
    CONSTRAINT FK_BCWH_Calendar FOREIGN KEY (CalendarID) REFERENCES dbo.BusinessCalendars (CalendarID),
    CONSTRAINT UQ_BCWH_Cal_Day_Sort UNIQUE (CalendarID, DayOfWeek, SortOrder)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_BCWH_Calendar')
CREATE INDEX IX_BCWH_Calendar ON dbo.BusinessCalendarWorkingHours (CalendarID);
GO

-- D3 --------------------------------------------------------------------
IF OBJECT_ID('dbo.BusinessCalendarExceptions', 'U') IS NULL
CREATE TABLE dbo.BusinessCalendarExceptions (
    ExceptionID         INT              IDENTITY(1,1) NOT NULL,
    CalendarID          INT              NOT NULL,
    ExceptionDate       DATE             NOT NULL,
    ExceptionType       NVARCHAR(20)     NOT NULL,          -- HOLIDAY / NON_WORKING / WORKING_OVERRIDE
    Title               NVARCHAR(200)    NULL,
    OverrideStartTime   TIME(0)          NULL,
    OverrideEndTime     TIME(0)          NULL,
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_BCE_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_BusinessCalendarExceptions PRIMARY KEY CLUSTERED (ExceptionID),
    CONSTRAINT CK_BCE_Type CHECK (ExceptionType IN (N'HOLIDAY', N'NON_WORKING', N'WORKING_OVERRIDE')),
    CONSTRAINT FK_BCE_Calendar FOREIGN KEY (CalendarID) REFERENCES dbo.BusinessCalendars (CalendarID),
    CONSTRAINT UQ_BCE_Cal_Date UNIQUE (CalendarID, ExceptionDate)
);
GO


/* =======================================================================
   گروه A — Workflow Definition (فاز ۱)
   ======================================================================= */

-- A1  WorkflowDefinitions ----------------------------------------------
IF OBJECT_ID('dbo.WorkflowDefinitions', 'U') IS NULL
CREATE TABLE dbo.WorkflowDefinitions (
    DefinitionID        INT              IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfDefinitions_RowGuid DEFAULT (NEWSEQUENTIALID()),
    Code                NVARCHAR(64)      NOT NULL,
    Name                NVARCHAR(200)     NOT NULL,
    Description         NVARCHAR(1000)    NULL,
    EntityType          NVARCHAR(64)      NOT NULL,
    IsActive            BIT              NOT NULL CONSTRAINT DF_WfDefinitions_IsActive DEFAULT (1),
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfDefinitions_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowDefinitions PRIMARY KEY CLUSTERED (DefinitionID),
    CONSTRAINT UQ_WorkflowDefinitions_Code UNIQUE (Code)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowDefinitions_EntityType')
CREATE INDEX IX_WorkflowDefinitions_EntityType ON dbo.WorkflowDefinitions (EntityType);
GO

-- A2  WorkflowVersions ------------------------------------------------
IF OBJECT_ID('dbo.WorkflowVersions', 'U') IS NULL
CREATE TABLE dbo.WorkflowVersions (
    VersionID           INT              IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfVersions_RowGuid DEFAULT (NEWSEQUENTIALID()),
    DefinitionID        INT              NOT NULL,
    VersionNo           INT              NOT NULL,
    Status              NVARCHAR(20)     NOT NULL CONSTRAINT DF_WfVersions_Status DEFAULT (N'DRAFT'),
    ValidationResultJson NVARCHAR(MAX)   NULL,
    PublishedAt         DATETIME2(3)     NULL,
    PublishedByUserID   INT              NULL,
    ArchivedAt          DATETIME2(3)     NULL,
    ClonedFromVersionID INT              NULL,
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfVersions_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowVersions PRIMARY KEY CLUSTERED (VersionID),
    CONSTRAINT CK_WfVersions_Status CHECK (Status IN (N'DRAFT', N'ACTIVE', N'ARCHIVED')),
    CONSTRAINT CK_WfVersions_ValidationJson CHECK (ValidationResultJson IS NULL OR ISJSON(ValidationResultJson) = 1),
    CONSTRAINT FK_WfVersions_Definition   FOREIGN KEY (DefinitionID)        REFERENCES dbo.WorkflowDefinitions (DefinitionID),
    CONSTRAINT FK_WfVersions_PublishedBy  FOREIGN KEY (PublishedByUserID)   REFERENCES dbo.Users (UserID),
    CONSTRAINT FK_WfVersions_ClonedFrom   FOREIGN KEY (ClonedFromVersionID) REFERENCES dbo.WorkflowVersions (VersionID),
    CONSTRAINT UQ_WorkflowVersions_Def_No UNIQUE (DefinitionID, VersionNo)
);
GO
-- Filtered Unique: حداکثر یک نسخه‌ی ACTIVE برای هر Definition
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WorkflowVersions_OneActive')
CREATE UNIQUE INDEX UX_WorkflowVersions_OneActive
    ON dbo.WorkflowVersions (DefinitionID) WHERE Status = N'ACTIVE';
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowVersions_Status')
CREATE INDEX IX_WorkflowVersions_Status ON dbo.WorkflowVersions (Status);
GO

-- A3  WorkflowSteps --------------------------------------------------
IF OBJECT_ID('dbo.WorkflowSteps', 'U') IS NULL
CREATE TABLE dbo.WorkflowSteps (
    StepID              INT              IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfSteps_RowGuid DEFAULT (NEWSEQUENTIALID()),
    VersionID           INT              NOT NULL,
    Code                NVARCHAR(64)      NOT NULL,
    Name                NVARCHAR(200)     NOT NULL,
    StepType            NVARCHAR(30)     NOT NULL,
    AssignPolicy        NVARCHAR(10)     NOT NULL CONSTRAINT DF_WfSteps_AssignPolicy DEFAULT (N'ANY'),
    RequiredApprovals   INT              NULL,
    AllowForward        BIT              NOT NULL CONSTRAINT DF_WfSteps_AllowForward DEFAULT (0),
    ForwardMax          INT              NULL,
    AllowDelegation     BIT              NOT NULL CONSTRAINT DF_WfSteps_AllowDelegation DEFAULT (1),
    SortOrder           INT              NOT NULL CONSTRAINT DF_WfSteps_SortOrder DEFAULT (0),
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfSteps_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowSteps PRIMARY KEY CLUSTERED (StepID),
    CONSTRAINT CK_WfSteps_StepType CHECK (StepType IN
        (N'START', N'USER_TASK', N'APPROVAL', N'CONDITION', N'AUTOMATIC', N'NOTIFICATION',
         N'PARALLEL_SPLIT', N'PARALLEL_JOIN', N'SUBPROCESS', N'END')),
    CONSTRAINT CK_WfSteps_AssignPolicy CHECK (AssignPolicy IN (N'ANY', N'ALL', N'N_OF_M')),
    CONSTRAINT FK_WfSteps_Version FOREIGN KEY (VersionID) REFERENCES dbo.WorkflowVersions (VersionID),
    CONSTRAINT UQ_WorkflowSteps_Version_Code UNIQUE (VersionID, Code)
);
GO
-- Filtered Unique: دقیقاً یک START در هر Version
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WorkflowSteps_OneStart')
CREATE UNIQUE INDEX UX_WorkflowSteps_OneStart
    ON dbo.WorkflowSteps (VersionID) WHERE StepType = N'START';
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowSteps_VersionID')
CREATE INDEX IX_WorkflowSteps_VersionID ON dbo.WorkflowSteps (VersionID);
GO

-- A5  WorkflowStepActions ------------------------------------------
IF OBJECT_ID('dbo.WorkflowStepActions', 'U') IS NULL
CREATE TABLE dbo.WorkflowStepActions (
    ActionID            INT              IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfStepActions_RowGuid DEFAULT (NEWSEQUENTIALID()),
    VersionID           INT              NOT NULL,
    StepID              INT              NOT NULL,
    Code                NVARCHAR(64)      NOT NULL,
    Kind                NVARCHAR(20)     NOT NULL CONSTRAINT DF_WfStepActions_Kind DEFAULT (N'CUSTOM'),
    Label               NVARCHAR(100)     NOT NULL,
    Icon                NVARCHAR(50)      NULL,
    Style               NVARCHAR(20)      NULL,
    RequiresComment     BIT              NOT NULL CONSTRAINT DF_WfStepActions_RequiresComment DEFAULT (0),
    RequiresConfirm     BIT              NOT NULL CONSTRAINT DF_WfStepActions_RequiresConfirm DEFAULT (0),
    ConfirmMessage      NVARCHAR(300)     NULL,
    PermissionCode      NVARCHAR(100)     NULL,
    SortOrder           INT              NOT NULL CONSTRAINT DF_WfStepActions_SortOrder DEFAULT (0),
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfStepActions_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowStepActions PRIMARY KEY CLUSTERED (ActionID),
    CONSTRAINT CK_WfStepActions_Kind CHECK (Kind IN
        (N'APPROVE', N'REJECT', N'RETURN', N'FORWARD', N'COMPLETE', N'CANCEL', N'CUSTOM')),
    CONSTRAINT FK_WfStepActions_Version FOREIGN KEY (VersionID) REFERENCES dbo.WorkflowVersions (VersionID),
    CONSTRAINT FK_WfStepActions_Step    FOREIGN KEY (StepID)    REFERENCES dbo.WorkflowSteps (StepID),
    CONSTRAINT UQ_WorkflowStepActions_Step_Code UNIQUE (StepID, Code)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowStepActions_StepID')
CREATE INDEX IX_WorkflowStepActions_StepID ON dbo.WorkflowStepActions (StepID);
GO

-- A6  WorkflowStepAssignments --------------------------------------
IF OBJECT_ID('dbo.WorkflowStepAssignments', 'U') IS NULL
CREATE TABLE dbo.WorkflowStepAssignments (
    StepAssignmentID    INT              IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfStepAssign_RowGuid DEFAULT (NEWSEQUENTIALID()),
    VersionID           INT              NOT NULL,
    StepID              INT              NOT NULL,
    AssigneeType        NVARCHAR(30)     NOT NULL,
    RefID               INT              NULL,
    RefExpression       NVARCHAR(400)     NULL,
    SortOrder           INT              NOT NULL CONSTRAINT DF_WfStepAssign_SortOrder DEFAULT (0),
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfStepAssign_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowStepAssignments PRIMARY KEY CLUSTERED (StepAssignmentID),
    CONSTRAINT CK_WfStepAssign_AssigneeType CHECK (AssigneeType IN
        (N'USER', N'ROLE', N'POSITION', N'UNIT', N'UNIT_MANAGER', N'DIRECT_MANAGER',
         N'ENTITY_OWNER', N'INITIATOR', N'VARIABLE', N'EXPRESSION')),
    CONSTRAINT FK_WfStepAssign_Version FOREIGN KEY (VersionID) REFERENCES dbo.WorkflowVersions (VersionID),
    CONSTRAINT FK_WfStepAssign_Step    FOREIGN KEY (StepID)    REFERENCES dbo.WorkflowSteps (StepID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowStepAssignments_StepID')
CREATE INDEX IX_WorkflowStepAssignments_StepID ON dbo.WorkflowStepAssignments (StepID);
GO

-- A4  WorkflowTransitions -----------------------------------------
IF OBJECT_ID('dbo.WorkflowTransitions', 'U') IS NULL
CREATE TABLE dbo.WorkflowTransitions (
    TransitionID        INT              IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfTransitions_RowGuid DEFAULT (NEWSEQUENTIALID()),
    VersionID           INT              NOT NULL,
    Code                NVARCHAR(64)      NOT NULL,
    FromStepID          INT              NOT NULL,
    ToStepID            INT              NOT NULL,
    TriggerActionID     INT              NULL,
    Priority            INT              NOT NULL CONSTRAINT DF_WfTransitions_Priority DEFAULT (100),
    IsDefault           BIT              NOT NULL CONSTRAINT DF_WfTransitions_IsDefault DEFAULT (0),
    Label               NVARCHAR(100)     NULL,
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfTransitions_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowTransitions PRIMARY KEY CLUSTERED (TransitionID),
    CONSTRAINT FK_WfTransitions_Version   FOREIGN KEY (VersionID)       REFERENCES dbo.WorkflowVersions (VersionID),
    CONSTRAINT FK_WfTransitions_FromStep  FOREIGN KEY (FromStepID)      REFERENCES dbo.WorkflowSteps (StepID),
    CONSTRAINT FK_WfTransitions_ToStep    FOREIGN KEY (ToStepID)        REFERENCES dbo.WorkflowSteps (StepID),
    CONSTRAINT FK_WfTransitions_Action    FOREIGN KEY (TriggerActionID) REFERENCES dbo.WorkflowStepActions (ActionID),
    CONSTRAINT UQ_WorkflowTransitions_Version_Code UNIQUE (VersionID, Code)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowTransitions_From')
CREATE INDEX IX_WorkflowTransitions_From ON dbo.WorkflowTransitions (FromStepID);
GO


/* =======================================================================
   گروه B — Workflow Runtime (فاز ۱)
   ======================================================================= */

-- B1  WorkflowInstances -----------------------------------------
IF OBJECT_ID('dbo.WorkflowInstances', 'U') IS NULL
CREATE TABLE dbo.WorkflowInstances (
    InstanceID          BIGINT           IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfInstances_RowGuid DEFAULT (NEWSEQUENTIALID()),
    InstanceNumber      NVARCHAR(50)      NOT NULL,
    DefinitionID        INT              NOT NULL,
    VersionID           INT              NOT NULL,
    EntityType          NVARCHAR(64)      NOT NULL,
    EntityID            BIGINT           NOT NULL,
    Status              NVARCHAR(20)     NOT NULL CONSTRAINT DF_WfInstances_Status DEFAULT (N'RUNNING'),
    StartedByUserID     INT              NULL,
    StartedAt           DATETIME2(3)     NOT NULL CONSTRAINT DF_WfInstances_StartedAt DEFAULT (SYSDATETIME()),
    CompletedAt         DATETIME2(3)     NULL,
    TransitionCount     INT              NOT NULL CONSTRAINT DF_WfInstances_TransitionCount DEFAULT (0),
    RowVersion          ROWVERSION       NOT NULL,
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfInstances_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowInstances PRIMARY KEY CLUSTERED (InstanceID),
    CONSTRAINT CK_WfInstances_Status CHECK (Status IN (N'RUNNING', N'COMPLETED', N'CANCELLED', N'SUSPENDED', N'FAILED')),
    CONSTRAINT FK_WfInstances_Definition FOREIGN KEY (DefinitionID)    REFERENCES dbo.WorkflowDefinitions (DefinitionID),
    CONSTRAINT FK_WfInstances_Version    FOREIGN KEY (VersionID)       REFERENCES dbo.WorkflowVersions (VersionID),
    CONSTRAINT FK_WfInstances_StartedBy  FOREIGN KEY (StartedByUserID) REFERENCES dbo.Users (UserID),
    CONSTRAINT UQ_WorkflowInstances_Number UNIQUE (InstanceNumber)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowInstances_Entity')
CREATE INDEX IX_WorkflowInstances_Entity ON dbo.WorkflowInstances (EntityType, EntityID);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowInstances_Status')
CREATE INDEX IX_WorkflowInstances_Status ON dbo.WorkflowInstances (Status);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowInstances_Definition')
CREATE INDEX IX_WorkflowInstances_Definition ON dbo.WorkflowInstances (DefinitionID);
GO

-- B3  WorkflowStepInstances -----------------------------------
IF OBJECT_ID('dbo.WorkflowStepInstances', 'U') IS NULL
CREATE TABLE dbo.WorkflowStepInstances (
    StepInstanceID      BIGINT           IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfStepInst_RowGuid DEFAULT (NEWSEQUENTIALID()),
    InstanceID          BIGINT           NOT NULL,
    StepID              INT              NOT NULL,
    StepCode            NVARCHAR(64)      NOT NULL,
    StepType            NVARCHAR(30)     NOT NULL,
    Status              NVARCHAR(20)     NOT NULL CONSTRAINT DF_WfStepInst_Status DEFAULT (N'ACTIVE'),
    EnteredViaTransitionID INT           NULL,
    EnteredAt           DATETIME2(3)     NOT NULL CONSTRAINT DF_WfStepInst_EnteredAt DEFAULT (SYSDATETIME()),
    CompletedAt         DATETIME2(3)     NULL,
    OutcomeActionCode   NVARCHAR(64)      NULL,
    OutcomeTransitionID INT              NULL,
    IterationNo         INT              NOT NULL CONSTRAINT DF_WfStepInst_IterationNo DEFAULT (1),
    RowVersion          ROWVERSION       NOT NULL,
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfStepInst_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowStepInstances PRIMARY KEY CLUSTERED (StepInstanceID),
    CONSTRAINT CK_WfStepInst_Status CHECK (Status IN (N'ACTIVE', N'COMPLETED', N'SKIPPED', N'FAILED', N'WAITING_JOIN')),
    CONSTRAINT FK_WfStepInst_Instance    FOREIGN KEY (InstanceID)             REFERENCES dbo.WorkflowInstances (InstanceID),
    CONSTRAINT FK_WfStepInst_Step        FOREIGN KEY (StepID)                 REFERENCES dbo.WorkflowSteps (StepID),
    CONSTRAINT FK_WfStepInst_EnteredVia  FOREIGN KEY (EnteredViaTransitionID) REFERENCES dbo.WorkflowTransitions (TransitionID),
    CONSTRAINT FK_WfStepInst_Outcome     FOREIGN KEY (OutcomeTransitionID)    REFERENCES dbo.WorkflowTransitions (TransitionID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowStepInstances_Instance')
CREATE INDEX IX_WorkflowStepInstances_Instance ON dbo.WorkflowStepInstances (InstanceID);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowStepInstances_Active')
CREATE INDEX IX_WorkflowStepInstances_Active
    ON dbo.WorkflowStepInstances (InstanceID) WHERE Status = N'ACTIVE';
GO

-- B4  WorkflowTasks -------------------------------------------
IF OBJECT_ID('dbo.WorkflowTasks', 'U') IS NULL
CREATE TABLE dbo.WorkflowTasks (
    TaskID              BIGINT           IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfTasks_RowGuid DEFAULT (NEWSEQUENTIALID()),
    TaskNumber          NVARCHAR(50)      NOT NULL,
    InstanceID          BIGINT           NOT NULL,
    StepInstanceID      BIGINT           NOT NULL,
    StepID              INT              NOT NULL,
    DefinitionID        INT              NOT NULL,
    EntityType          NVARCHAR(64)      NOT NULL,
    EntityID            BIGINT           NOT NULL,
    Title               NVARCHAR(300)     NOT NULL,
    Description         NVARCHAR(MAX)     NULL,
    Status              NVARCHAR(20)     NOT NULL CONSTRAINT DF_WfTasks_Status DEFAULT (N'PENDING'),
    PriorityID          INT              NULL,
    AssignPolicy        NVARCHAR(10)     NOT NULL CONSTRAINT DF_WfTasks_AssignPolicy DEFAULT (N'ANY'),
    RequiredApprovals   INT              NULL,
    ReceivedApprovals   INT              NOT NULL CONSTRAINT DF_WfTasks_ReceivedApprovals DEFAULT (0),
    ReceivedRejections  INT              NOT NULL CONSTRAINT DF_WfTasks_ReceivedRejections DEFAULT (0),
    CreatedAt           DATETIME2(3)     NOT NULL CONSTRAINT DF_WfTasks_CreatedAt DEFAULT (SYSDATETIME()),
    FirstOpenedAt       DATETIME2(3)     NULL,
    CompletedAt         DATETIME2(3)     NULL,
    CompletedByUserID   INT              NULL,
    CompletionActionCode NVARCHAR(64)     NULL,
    ParentTaskID        BIGINT           NULL,
    ForwardedFromUserID INT              NULL,
    ForwardedToUserID   INT              NULL,
    IsDelegated         BIT              NOT NULL CONSTRAINT DF_WfTasks_IsDelegated DEFAULT (0),
    DelegatedFromUserID INT              NULL,
    DelegatedToUserID   INT              NULL,
    DelegatedAt         DATETIME2(3)     NULL,
    RowVersion          ROWVERSION       NOT NULL,
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfTasks_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowTasks PRIMARY KEY CLUSTERED (TaskID),
    CONSTRAINT CK_WfTasks_Status CHECK (Status IN
        (N'PENDING', N'IN_PROGRESS', N'COMPLETED', N'REJECTED', N'RETURNED', N'FORWARDED', N'CANCELLED', N'EXPIRED')),
    CONSTRAINT CK_WfTasks_AssignPolicy CHECK (AssignPolicy IN (N'ANY', N'ALL', N'N_OF_M')),
    CONSTRAINT FK_WfTasks_Instance      FOREIGN KEY (InstanceID)          REFERENCES dbo.WorkflowInstances (InstanceID),
    CONSTRAINT FK_WfTasks_StepInstance  FOREIGN KEY (StepInstanceID)      REFERENCES dbo.WorkflowStepInstances (StepInstanceID),
    CONSTRAINT FK_WfTasks_Step          FOREIGN KEY (StepID)              REFERENCES dbo.WorkflowSteps (StepID),
    CONSTRAINT FK_WfTasks_Definition    FOREIGN KEY (DefinitionID)        REFERENCES dbo.WorkflowDefinitions (DefinitionID),
    CONSTRAINT FK_WfTasks_Priority      FOREIGN KEY (PriorityID)          REFERENCES dbo.msgPriorities (msgPriorityID),
    CONSTRAINT FK_WfTasks_CompletedBy   FOREIGN KEY (CompletedByUserID)   REFERENCES dbo.Users (UserID),
    CONSTRAINT FK_WfTasks_ForwardedFrom FOREIGN KEY (ForwardedFromUserID) REFERENCES dbo.Users (UserID),
    CONSTRAINT FK_WfTasks_ForwardedTo   FOREIGN KEY (ForwardedToUserID)   REFERENCES dbo.Users (UserID),
    CONSTRAINT FK_WfTasks_DelegatedFrom FOREIGN KEY (DelegatedFromUserID) REFERENCES dbo.Users (UserID),
    CONSTRAINT FK_WfTasks_DelegatedTo   FOREIGN KEY (DelegatedToUserID)   REFERENCES dbo.Users (UserID),
    CONSTRAINT FK_WfTasks_ParentTask    FOREIGN KEY (ParentTaskID)        REFERENCES dbo.WorkflowTasks (TaskID),
    CONSTRAINT UQ_WorkflowTasks_Number  UNIQUE (TaskNumber)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowTasks_StepInstance')
CREATE INDEX IX_WorkflowTasks_StepInstance ON dbo.WorkflowTasks (StepInstanceID);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowTasks_Instance')
CREATE INDEX IX_WorkflowTasks_Instance ON dbo.WorkflowTasks (InstanceID);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowTasks_Entity_Status')
CREATE INDEX IX_WorkflowTasks_Entity_Status ON dbo.WorkflowTasks (EntityType, EntityID, Status);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowTasks_Definition_Status')
CREATE INDEX IX_WorkflowTasks_Definition_Status ON dbo.WorkflowTasks (DefinitionID, Status);
GO

-- B5  WorkflowTaskAssignees ----------------------------------
IF OBJECT_ID('dbo.WorkflowTaskAssignees', 'U') IS NULL
CREATE TABLE dbo.WorkflowTaskAssignees (
    TaskAssigneeID      BIGINT           IDENTITY(1,1) NOT NULL,
    RowGuid             UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_WfTaskAssignees_RowGuid DEFAULT (NEWSEQUENTIALID()),
    TaskID              BIGINT           NOT NULL,
    UserID              INT              NOT NULL,
    SourceType          NVARCHAR(30)     NOT NULL,
    SourceRefID         INT              NULL,
    IsActive            BIT              NOT NULL CONSTRAINT DF_WfTaskAssignees_IsActive DEFAULT (1),
    Decision            NVARCHAR(20)     NULL,
    ActionCode          NVARCHAR(64)      NULL,
    ActedAt             DATETIME2(3)     NULL,
    Comment             NVARCHAR(MAX)     NULL,
    Date_InsertFirst    DATETIME2(3)     NOT NULL CONSTRAINT DF_WfTaskAssignees_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT              NULL,
    Date_LastUpdate     DATETIME2(3)     NULL,
    UserID_LastUpdate   INT              NULL,
    CONSTRAINT PK_WorkflowTaskAssignees PRIMARY KEY CLUSTERED (TaskAssigneeID),
    CONSTRAINT CK_WfTaskAssignees_SourceType CHECK (SourceType IN
        (N'USER', N'ROLE', N'POSITION', N'UNIT', N'UNIT_MANAGER', N'DIRECT_MANAGER', N'ENTITY_OWNER',
         N'INITIATOR', N'VARIABLE', N'EXPRESSION', N'DELEGATION', N'ESCALATION', N'FORWARD')),
    CONSTRAINT CK_WfTaskAssignees_Decision CHECK (Decision IS NULL OR Decision IN
        (N'APPROVED', N'REJECTED', N'RETURNED', N'ABSTAINED')),
    CONSTRAINT FK_WfTaskAssignees_Task FOREIGN KEY (TaskID) REFERENCES dbo.WorkflowTasks (TaskID),
    CONSTRAINT FK_WfTaskAssignees_User FOREIGN KEY (UserID) REFERENCES dbo.Users (UserID)
);
GO
-- یک assignee فعالِ منحصربه‌فرد به‌ازای هر (Task, User)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WorkflowTaskAssignees_Task_User_Active')
CREATE UNIQUE INDEX UX_WorkflowTaskAssignees_Task_User_Active
    ON dbo.WorkflowTaskAssignees (TaskID, UserID) WHERE IsActive = 1;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowTaskAssignees_User_Active')
CREATE INDEX IX_WorkflowTaskAssignees_User_Active
    ON dbo.WorkflowTaskAssignees (UserID) INCLUDE (TaskID) WHERE IsActive = 1;
GO


/* =======================================================================
   گروه C — Cross-cutting (فاز ۱: فقط History)
   ======================================================================= */

-- C1  WorkflowHistory ---------------------------------------
IF OBJECT_ID('dbo.WorkflowHistory', 'U') IS NULL
CREATE TABLE dbo.WorkflowHistory (
    HistoryID           BIGINT           IDENTITY(1,1) NOT NULL,
    InstanceID          BIGINT           NULL,
    StepInstanceID      BIGINT           NULL,
    TaskID              BIGINT           NULL,
    EntityType          NVARCHAR(64)      NOT NULL,
    EntityID            BIGINT           NOT NULL,
    EventCode           NVARCHAR(50)     NOT NULL,
    ActorUserID         INT              NULL,
    ActorType           NVARCHAR(10)     NOT NULL CONSTRAINT DF_WfHistory_ActorType DEFAULT (N'USER'),
    OccurredAt          DATETIME2(3)     NOT NULL CONSTRAINT DF_WfHistory_OccurredAt DEFAULT (SYSDATETIME()),
    Summary             NVARCHAR(500)     NULL,
    OldValueJson        NVARCHAR(MAX)     NULL,
    NewValueJson        NVARCHAR(MAX)     NULL,
    DetailJson          NVARCHAR(MAX)     NULL,
    IpAddress           NVARCHAR(45)      NULL,
    CONSTRAINT PK_WorkflowHistory PRIMARY KEY CLUSTERED (HistoryID),
    CONSTRAINT CK_WfHistory_ActorType CHECK (ActorType IN (N'USER', N'SYSTEM', N'SCHEDULER', N'API')),
    CONSTRAINT FK_WfHistory_Instance     FOREIGN KEY (InstanceID)     REFERENCES dbo.WorkflowInstances (InstanceID),
    CONSTRAINT FK_WfHistory_StepInstance FOREIGN KEY (StepInstanceID) REFERENCES dbo.WorkflowStepInstances (StepInstanceID),
    CONSTRAINT FK_WfHistory_Task         FOREIGN KEY (TaskID)         REFERENCES dbo.WorkflowTasks (TaskID),
    CONSTRAINT FK_WfHistory_Actor        FOREIGN KEY (ActorUserID)    REFERENCES dbo.Users (UserID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowHistory_Instance')
CREATE INDEX IX_WorkflowHistory_Instance ON dbo.WorkflowHistory (InstanceID, OccurredAt);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowHistory_Task')
CREATE INDEX IX_WorkflowHistory_Task ON dbo.WorkflowHistory (TaskID, OccurredAt);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowHistory_Entity')
CREATE INDEX IX_WorkflowHistory_Entity ON dbo.WorkflowHistory (EntityType, EntityID, OccurredAt);
GO


/* ==========================================================================
   [MANUAL DATA SEED] — افزایشی، همه با IF NOT EXISTS
   (تریگر DDL این INSERTها را ردیابی نمی‌کند؛ برای هماهنگی محیط‌ها اینجا آمده‌اند)
   ========================================================================== */

-- 1) شمارنده‌های شماره‌گذاری Workflow در dbo.CodeCounters --------------
IF NOT EXISTS (SELECT 1 FROM dbo.CodeCounters WHERE EntityName = N'WF_DEFINITION')
    INSERT INTO dbo.CodeCounters (EntityName, LastNumber) VALUES (N'WF_DEFINITION', 0);
IF NOT EXISTS (SELECT 1 FROM dbo.CodeCounters WHERE EntityName = N'WF_INSTANCE')
    INSERT INTO dbo.CodeCounters (EntityName, LastNumber) VALUES (N'WF_INSTANCE', 0);
IF NOT EXISTS (SELECT 1 FROM dbo.CodeCounters WHERE EntityName = N'WF_TASK')
    INSERT INTO dbo.CodeCounters (EntityName, LastNumber) VALUES (N'WF_TASK', 0);
GO

-- 2) تقویم کاریِ پیش‌فرض (۲۴×۷ ⇒ رفتار = ساعتِ مطلق) --------------------
IF NOT EXISTS (SELECT 1 FROM dbo.BusinessCalendars WHERE Code = N'DEFAULT')
BEGIN
    INSERT INTO dbo.BusinessCalendars (Code, Name, TimeZone, IsDefault, IsActive)
    VALUES (N'DEFAULT', N'تقویم پیش‌فرض (۲۴ ساعته)', N'Asia/Tehran', 1, 1);

    DECLARE @calId INT = SCOPE_IDENTITY();
    DECLARE @d TINYINT = 0;
    WHILE @d <= 6
    BEGIN
        INSERT INTO dbo.BusinessCalendarWorkingHours (CalendarID, DayOfWeek, IsWorkingDay, StartTime, EndTime, SortOrder)
        VALUES (@calId, @d, 1, '00:00:00', '23:59:59', 0);
        SET @d += 1;
    END
END
GO

-- 3) دسترسی‌های عملیاتیِ Workflow (گروه Workflow) در dbo.Permissions ---
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_VIEW')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_VIEW', N'مشاهده فرایندها و تسک‌ها', N'Workflow', N'دیدن لیست فرایندها، نسخه‌ها و تسک‌های خود', 1, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_DESIGN')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_DESIGN', N'طراحی و ویرایش فرایند', N'Workflow', N'ساخت/ویرایش Definition و نسخه‌های DRAFT', 2, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_PUBLISH')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_PUBLISH', N'انتشار نسخه‌ی فرایند', N'Workflow', N'فعال‌سازی یک نسخه‌ی DRAFT', 3, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_START')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_START', N'شروع دستی فرایند', N'Workflow', N'اجرای یک فرایند روی یک موجودیت', 4, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_VIEW_ALL_TASKS')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_VIEW_ALL_TASKS', N'مشاهده تسک‌های همه‌ی کاربران', N'Workflow', N'دیدن تسک‌ها و Instanceهایی که کاربر در آن‌ها نقش ندارد', 5, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_CANCEL')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_CANCEL', N'لغو فرایند', N'Workflow', N'لغو یک Instance در حال اجرا', 6, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_SUSPEND')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_SUSPEND', N'تعلیق/ازسرگیری فرایند', N'Workflow', N'Suspend و Resume یک Instance', 7, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_FORWARD')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_FORWARD', N'ارجاع تسک', N'Workflow', N'ارجاع (Forward) تسک به کاربر دیگر — تسکِ فرزند می‌سازد', 8, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_DELEGATE')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_DELEGATE', N'تفویض تسک', N'Workflow', N'تفویض (Delegation) تسک به کاربر دیگر — همان تسک ادامه می‌یابد', 9, 1);
GO

-- 4) اعطای همه‌ی دسترسی‌های Workflow به نقشِ «مدیر سیستم» (RoleID = 1) ---
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionGroup = N'Workflow'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO

-- 5) منوی «اتوماسیون فرایند» + زیرمنوها ------------------------------
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuCode = N'WORKFLOW')
BEGIN
    DECLARE @rootId INT;

    INSERT INTO dbo.Menu (ParentID, MenuCode, MenuTitle, MenuKind, Url, Icon, Level, SortOrder, OpenInNewTab, IsVisible, IsActive, IsHomeTab, CreateDate)
    VALUES (NULL, N'WORKFLOW', N'اتوماسیون فرایند', N'FOLDER', NULL, N'bi bi-diagram-3', 1, 100, 0, 1, 1, 0, SYSDATETIME());
    SET @rootId = SCOPE_IDENTITY();

    INSERT INTO dbo.Menu (ParentID, MenuCode, MenuTitle, MenuKind, Url, Icon, Level, SortOrder, OpenInNewTab, IsVisible, IsActive, IsHomeTab, CreateDate)
    VALUES (@rootId, N'WF_DEFINITIONS', N'تعریف فرایندها', N'PAGE', N'/workflow/definitions', N'bi bi-diagram-3', 2, 1, 0, 1, 1, 0, SYSDATETIME());

    INSERT INTO dbo.Menu (ParentID, MenuCode, MenuTitle, MenuKind, Url, Icon, Level, SortOrder, OpenInNewTab, IsVisible, IsActive, IsHomeTab, CreateDate)
    VALUES (@rootId, N'WF_TASKS', N'تسک‌های من', N'PAGE', N'/workflow/my-tasks', N'bi bi-check2-square', 2, 2, 0, 1, 1, 0, SYSDATETIME());

    IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
    BEGIN
        INSERT INTO dbo.RoleMenus (RoleID, MenuID, CanView, CreateDate, IsActive)
        SELECT 1, m.MenuID, 1, SYSDATETIME(), 1
        FROM dbo.Menu m
        WHERE m.MenuCode IN (N'WORKFLOW', N'WF_DEFINITIONS', N'WF_TASKS')
          AND NOT EXISTS (SELECT 1 FROM dbo.RoleMenus rm WHERE rm.RoleID = 1 AND rm.MenuID = m.MenuID);
    END
END
GO

/* ========================== پایان پچ 014 ========================== */
