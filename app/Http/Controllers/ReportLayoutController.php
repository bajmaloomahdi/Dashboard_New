<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportLayoutController
{
    private function report(Request $request, string $code): object
    {
        $report = DB::selectOne('EXEC dbo.sp_GetReportInfo @ReportCode=?, @UserID=?', [$code, $request->user()->getAuthIdentifier()]);
        abort_unless($report, 403);
        abort_unless(in_array($report->ReportType, ['PIVOT','CHART'], true), 404);

        return $report;
    }

    public static function validateLayout(mixed $layout, array $fields, array $measures): array
    {
        $data = Validator::make(['layout' => $layout], [
            'layout' => 'required|array:rows,columns,values,filters,criteria,aggregation',
            'layout.rows' => 'present|list|max:5',
            'layout.columns' => 'present|list|max:5',
            'layout.values' => 'present|list|max:2',
            'layout.filters' => 'present|list|max:13',
            'layout.rows.*' => ['string', 'distinct', Rule::in($fields)],
            'layout.columns.*' => ['string', 'distinct', Rule::in($fields)],
            'layout.values.*' => ['string', 'distinct', Rule::in($measures)],
            'layout.filters.*' => ['string', 'distinct', Rule::in($fields)],
            'layout.criteria' => 'present|array|max:13',
            'layout.criteria.*' => 'list|max:20',
            'layout.criteria.*.*' => 'required|string|max:200',
            'layout.aggregation' => 'required|in:SUM',
        ])->validate()['layout'];

        if (array_intersect($data['rows'], $data['columns']) || array_diff(array_keys($data['criteria']), $data['filters'])) {
            throw ValidationException::withMessages(['layout' => 'فیلدهای چیدمان با تعریف گزارش سازگار نیستند.']);
        }

        return $data;
    }

    public function show(Request $request, string $code)
    {
        $report = $this->report($request, $code);
        [$labels, $measures] = PivotReportSource::context($report);
        $id = $request->validate(['id' => 'sometimes|integer|min:1|max:2147483647'])['id'] ?? null;
        $scope = [$report->ReportID, $request->user()->getAuthIdentifier()];
        $saved = DB::selectOne('EXEC dbo.sp_GetReportUserLayout @ReportID=?, @UserID=?, @LayoutID=?', [...$scope, $id]);
        abort_if($id !== null && !$saved, 404);
        $layouts = DB::select('EXEC dbo.sp_GetReportUserLayout @ReportID=?, @UserID=?, @List=1', $scope);
        $config = json_decode($report->PivotConfig, true, flags: JSON_THROW_ON_ERROR);
        $invalid = false;
        $read = function ($layout) use ($report, $labels, $measures, &$invalid) {
            if ($layout === null) {
                return null;
            }
            try {
                return ($report->ReportType === 'CHART' ? ChartReport::class : self::class)::validateLayout($layout, array_keys($labels), array_keys($measures));
            } catch (ValidationException) {
                $invalid = true;
                return null;
            }
        };
        $personal = $read($saved ? json_decode($saved->LayoutJson, true) : null);
        $default = $read($config['defaultLayout'] ?? null);

        return response()->json(compact('personal', 'default', 'invalid', 'layouts') + [
            'selectedId' => $personal ? (int) $saved->LayoutID : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $code)
    {
        $report = $this->report($request, $code);
        $input = $request->validate([
            'action' => 'required|in:save,rename,delete,default',
            'id' => 'nullable|required_unless:action,save|integer|min:1|max:2147483647',
            'name' => 'nullable|required_if:action,rename|string|max:120',
        ]);
        $id = $input['id'] ?? null;
        $name = trim($input['name'] ?? '');
        $action = $input['action'];
        abort_if(($action === 'rename' || ($action === 'save' && !$id)) && $name === '', 422);
        $json = null;
        if ($action === 'save') {
            [$labels, $measures] = PivotReportSource::context($report);
            $layout = ($report->ReportType === 'CHART' ? ChartReport::class : self::class)::validateLayout($request->input('layout'), array_keys($labels), array_keys($measures));
            $json = json_encode($layout, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        try {
            $saved = DB::selectOne('EXEC dbo.sp_SaveReportUserLayout @ReportID=?, @UserID=?, @LayoutJson=?, @LayoutID=?, @LayoutName=?, @Action=?', [
                $report->ReportID, $request->user()->getAuthIdentifier(), $json, $id, $name ?: null, $action,
            ]);
        } catch (\Illuminate\Database\QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [2601, 2627], true)) {
                throw ValidationException::withMessages(['name' => 'این نام قبلاً برای همین گزارش استفاده شده است.']);
            }
            throw $error;
        }
        abort_unless($saved?->LayoutID, 404);
        return response()->json(['id' => (int) $saved->LayoutID]);
    }
}
