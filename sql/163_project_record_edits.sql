ALTER TABLE dbo.ProjectComments ADD EditedAt datetime2(0) NULL, EditedByUserID int NULL;
ALTER TABLE dbo.ProjectAttachments ADD Description nvarchar(500) NULL, EditedAt datetime2(0) NULL, EditedByUserID int NULL;
GO
ALTER PROCEDURE dbo.sp_GetProjectComments @ProjectID bigint
AS
BEGIN
    SET NOCOUNT ON;
    SELECT c.ProjectCommentID, c.ProjectID, c.UserID, u.FullName, c.Comment, c.CreateDate,
           c.EditedAt, c.EditedByUserID, editor.FullName AS EditedByName
    FROM dbo.ProjectComments c
    LEFT JOIN dbo.Users u ON u.UserID = c.UserID
    LEFT JOIN dbo.Users editor ON editor.UserID = c.EditedByUserID
    WHERE c.ProjectID = @ProjectID
    ORDER BY c.CreateDate ASC, c.ProjectCommentID ASC;
END
GO
ALTER PROCEDURE dbo.sp_GetProjectAttachmentsList @ProjectID bigint
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.ProjectAttachmentID, a.FileName, a.FileExtension, a.FileSize, a.FilePath,
           a.CreateDate, a.CreateUser, u.FullName AS CreateUserName,
           a.Description, a.EditedAt, a.EditedByUserID, editor.FullName AS EditedByName
    FROM dbo.ProjectAttachments a
    LEFT JOIN dbo.Users u ON u.UserID = a.CreateUser
    LEFT JOIN dbo.Users editor ON editor.UserID = a.EditedByUserID
    WHERE a.ProjectID = @ProjectID
    ORDER BY a.ProjectAttachmentID DESC;
END
GO
CREATE OR ALTER PROCEDURE dbo.sp_UpdateProjectComment
    @ProjectID bigint, @CommentID int, @UserID int, @Comment nvarchar(3000)
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE c SET Comment = @Comment, EditedAt = SYSDATETIME(), EditedByUserID = @UserID
    FROM dbo.ProjectComments c
    WHERE c.ProjectID = @ProjectID AND c.ProjectCommentID = @CommentID AND c.UserID = @UserID
      AND EXISTS (SELECT 1 FROM dbo.ProjectMembers m WHERE m.ProjectID = @ProjectID AND m.UserID = @UserID AND m.IsActive = 1);
    SELECT @@ROWCOUNT AS Updated;
END
GO
CREATE OR ALTER PROCEDURE dbo.sp_UpdateProjectAttachmentDescription
    @ProjectID bigint, @AttachmentID int, @UserID int, @Description nvarchar(500)
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE a SET Description = @Description, EditedAt = SYSDATETIME(), EditedByUserID = @UserID
    FROM dbo.ProjectAttachments a
    WHERE a.ProjectID = @ProjectID AND a.ProjectAttachmentID = @AttachmentID AND a.CreateUser = @UserID
      AND EXISTS (SELECT 1 FROM dbo.ProjectMembers m WHERE m.ProjectID = @ProjectID AND m.UserID = @UserID AND m.IsActive = 1);
    SELECT @@ROWCOUNT AS Updated;
END
GO
