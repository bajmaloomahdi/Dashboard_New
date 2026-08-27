/* ==========================================================================
   پچ خودکار شماره: 004 | نام: asm_tghyyrat
   تاریخ: 2026-08-27 16:28:08 | شامل 1 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: rpt_105
Create PROCEDURE [dbo].[rpt_105]
AS
BEGIN

    SET NOCOUNT ON;

    SELECT

        c.Code AS N'کد کالا',

        c.Name AS N'نام کالا',

        CONVERT(varchar(10), a.ExecDate, 111) AS N'تاریخ اجرا',

        CONVERT(varchar(30), CAST(b.Cost AS money), 1) AS N'قیمت خرید',

        CONVERT(varchar(30), CAST(b.Price AS money), 1) AS N'قیمت فروش',

        CONVERT(varchar(30), CAST(b.UserPrice AS money), 1) AS N'قیمت مصرف کننده'

    FROM MabnaErp.dbo.invProductPrices a

        INNER JOIN MabnaErp.dbo.invProductPriceDetails b
            ON a.ID = b.ProductPriceID

        INNER JOIN MabnaErp.dbo.invProducts c
            ON b.ProductID = c.ID

    ORDER BY

        c.Code,
        a.ExecDate;

END
GO

