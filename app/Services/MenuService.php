// D:\Project\Dashboard_New\app\Services\MenuService.php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MenuService
{
    public function getActiveMenus()
    {
        try {
            // اجرای Stored Procedure در دیتابیس Dashboard_DB
            $query = "EXECUTE [dbo].[Get_Menu_Tree_With_Details]";
            
            // نتایج را به آرایه تبدیل می‌کنیم
            $result = DB::select($query);
            
            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}