<?php

namespace App\Http\Controllers\Crm;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CrmRecordHistoryController extends CrmApiController
{
    public function index(Request $request, string $entityType, int $entityId)
    {
        $this->authorizeCrm('CRM_VIEW');

        $input = $request->validate(['before' => 'sometimes|integer|min:1']);
        $rows = DB::select(
            'EXEC dbo.sp_Crm_GetRecordHistory @EntityType = ?, @EntityID = ?, @Before = ?',
            [strtoupper($entityType), $entityId, $input['before'] ?? null],
        );
        $items = array_slice($rows, 0, 50);

        return response()->json([
            'success' => true,
            'items' => $items,
            'next' => count($rows) > 50 ? (string) $items[49]->HistoryID : null,
        ])->header('Cache-Control', 'private, no-store');
    }
}