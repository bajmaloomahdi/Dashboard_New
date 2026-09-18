/* ==========================================================================
   پچ خودکار شماره: 032 | نام: direct_manager_position_hierarchy
   تاریخ: 2026-09-17 20:51:50 | شامل 1 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_ResolveAssignees
ALTER PROCEDURE dbo.sp_Wf_ResolveAssignees
    @AssigneeType       NVARCHAR(30),
    @RefID              INT           = NULL,
    @RefExpression      NVARCHAR(400) = NULL,
    @InitiatorUserID    INT           = NULL,
    @EntityOwnerUserID  INT           = NULL,
    @EntityUnitID       INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Res TABLE (UserID INT PRIMARY KEY);

    IF @AssigneeType = N'USER' AND @RefID IS NOT NULL
        INSERT INTO @Res (UserID) SELECT @RefID;

    ELSE IF @AssigneeType = N'INITIATOR' AND @InitiatorUserID IS NOT NULL
        INSERT INTO @Res (UserID) SELECT @InitiatorUserID;

    ELSE IF @AssigneeType = N'ENTITY_OWNER' AND @EntityOwnerUserID IS NOT NULL
        INSERT INTO @Res (UserID) SELECT @EntityOwnerUserID;

    ELSE IF @AssigneeType = N'ROLE' AND @RefID IS NOT NULL
        INSERT INTO @Res (UserID)
        SELECT DISTINCT ur.UserID FROM dbo.UserRoles ur WHERE ur.RoleID = @RefID AND ur.IsActive = 1;

    ELSE IF @AssigneeType = N'POSITION' AND @RefID IS NOT NULL
        INSERT INTO @Res (UserID)
        SELECT DISTINCT up.UserID FROM dbo.UserPositions up WHERE up.PositionID = @RefID AND up.IsActive = 1;

    ELSE IF @AssigneeType = N'UNIT' AND @RefID IS NOT NULL
        INSERT INTO @Res (UserID)
        SELECT DISTINCT up.UserID FROM dbo.UserPositions up WHERE up.UnitID = @RefID AND up.IsActive = 1;

    ELSE IF @AssigneeType = N'UNIT_MANAGER'
    BEGIN
        DECLARE @UnitForMgr INT = ISNULL(@RefID, @EntityUnitID);
        IF @UnitForMgr IS NOT NULL
            INSERT INTO @Res (UserID)
            SELECT DISTINCT up.UserID
            FROM dbo.UserPositions up
            JOIN dbo.Positions p ON p.PositionID = up.PositionID
            WHERE up.UnitID = @UnitForMgr AND up.IsActive = 1 AND p.IsUnitManager = 1 AND p.IsActive = 1;
    END

    ELSE IF @AssigneeType = N'DIRECT_MANAGER' AND @InitiatorUserID IS NOT NULL
    BEGIN
        DECLARE @InitPositionID INT, @InitUnit INT, @ParentPositionID INT;

        SELECT TOP 1 @InitPositionID = up.PositionID, @InitUnit = up.UnitID
        FROM dbo.UserPositions up
        WHERE up.UserID = @InitiatorUserID AND up.IsActive = 1
        ORDER BY up.CreateDate DESC, up.UserPositionID DESC;

        IF @InitPositionID IS NOT NULL
            SELECT @ParentPositionID = p.ParentPositionID FROM dbo.Positions p WHERE p.PositionID = @InitPositionID;

        -- اولویتِ اول: زنجیرهٔ صریحِ سمت (ParentPositionID) — دقیق‌ترین منبعِ حقیقت،
        -- به‌خصوص وقتی سمتِ خودِ آغازگر مدیرِ واحدِ خودش باشد (پس مدیرش در واحدِ دیگری است).
        IF @ParentPositionID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.Positions WHERE PositionID = @ParentPositionID AND IsActive = 1)
            INSERT INTO @Res (UserID)
            SELECT DISTINCT up.UserID
            FROM dbo.UserPositions up
            WHERE up.PositionID = @ParentPositionID AND up.IsActive = 1
              AND up.UserID <> @InitiatorUserID;

        -- بازگشتی: اگر زنجیرهٔ سمت چیزی نداد (بدونِ Parent یا بدونِ دارندهٔ فعال)،
        -- به مدیرِ همان واحد (IsUnitManager) برمی‌گردیم — سازگار با رفتارِ قبلی.
        IF NOT EXISTS (SELECT 1 FROM @Res) AND @InitUnit IS NOT NULL
            INSERT INTO @Res (UserID)
            SELECT DISTINCT up.UserID
            FROM dbo.UserPositions up
            JOIN dbo.Positions p ON p.PositionID = up.PositionID
            WHERE up.UnitID = @InitUnit AND up.IsActive = 1 AND p.IsUnitManager = 1 AND p.IsActive = 1
              AND up.UserID <> @InitiatorUserID;
    END

    SELECT DISTINCT r.UserID, u.FullName
    FROM @Res r
    JOIN dbo.Users u ON u.UserID = r.UserID
    WHERE u.IsActive = 1 AND u.IsLocked = 0;
END
GO

