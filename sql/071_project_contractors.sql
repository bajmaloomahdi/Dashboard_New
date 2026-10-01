/* ==========================================================================
   پچ خودکار شماره: 071 | نام: project_contractors
   تاریخ: 2026-10-01 07:26:06 | شامل 2 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: ProjectContractors
CREATE TABLE dbo.ProjectContractors (
    ProjectContractorID BIGINT IDENTITY(1,1) NOT NULL,
    RowGuid UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_ProjectContractors_RowGuid DEFAULT (NEWSEQUENTIALID()),
    ProjectID BIGINT NOT NULL,
    PartyID INT NOT NULL,
    IsActive BIT NOT NULL CONSTRAINT DF_ProjectContractors_IsActive DEFAULT ((1)),
    Date_InsertFirst DATETIME2 NOT NULL CONSTRAINT DF_ProjectContractors_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate DATETIME2 NULL,
    UserID_LastUpdate INT NULL,
    CONSTRAINT PK_ProjectContractors PRIMARY KEY (ProjectContractorID),
    CONSTRAINT FK_ProjectContractors_Project FOREIGN KEY (ProjectID) REFERENCES dbo.Projects(ProjectID),
    CONSTRAINT FK_ProjectContractors_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT UQ_ProjectContractors_Project_Party UNIQUE (ProjectID, PartyID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_ProjectContractors_ProjectID_IsActive
CREATE INDEX IX_ProjectContractors_ProjectID_IsActive ON dbo.ProjectContractors (ProjectID, IsActive)
GO

