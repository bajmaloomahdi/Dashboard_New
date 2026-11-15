<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PivotReportSource
{
    public static function manage(): void
    {
        $menus = DB::select('EXEC dbo.sp_GetUserMenus @UserID = ?', [auth()->id()]);
        abort_unless(collect($menus)->contains(fn ($menu) => trim((string) ($menu->Url ?? ''), '/') === 'reports-manage'), 403);
    }

    public static function columns(string $schema, string $view): array
    {
        $rows = DB::select('SELECT c.name, t.name AS kind, c.scale FROM sys.views v
            JOIN sys.schemas s ON s.schema_id=v.schema_id JOIN sys.columns c ON c.object_id=v.object_id
            JOIN sys.types t ON t.user_type_id=c.system_type_id AND t.user_type_id=t.system_type_id
            WHERE s.name=? AND v.name=? ORDER BY c.column_id', [$schema, $view]);

        return collect($rows)->filter(fn ($column) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column->name)
            && !preg_match('/^(rowMissing|columnMissing|rowRank|maxRank|sum_[01])$/iD', $column->name)
            && in_array($column->kind, ['varchar','nvarchar','char','nchar','bigint','int','smallint','tinyint','decimal','numeric','date','datetime','datetime2','smalldatetime','bit'], true))
            ->keyBy('name')->all();
    }

    public function metadata(Request $request)
    {
        self::manage();
        $source = $request->validate(['ViewSchema'=>'required|regex:/^[A-Za-z_][A-Za-z0-9_]*$/D|max:128','ViewName'=>'required|regex:/^[A-Za-z_][A-Za-z0-9_]*$/D|max:128']);
        return response()->json(array_values(self::columns($source['ViewSchema'], $source['ViewName'])));
    }

    public static function definition(Request $request): array
    {
        $input = $request->validate([
            'ReportType' => 'sometimes|in:NORMAL,PIVOT,CHART',
            'ViewSchema' => 'required_if:ReportType,PIVOT,CHART|nullable|regex:/^[A-Za-z_][A-Za-z0-9_]*$/D|max:128',
            'ViewName' => 'required_if:ReportType,PIVOT,CHART|nullable|regex:/^[A-Za-z_][A-Za-z0-9_]*$/D|max:128',
        ]);
        if (($input['ReportType'] ?? 'NORMAL') === 'NORMAL') return ['NORMAL', null, null, null];
        $columns = self::columns($input['ViewSchema'], $input['ViewName']);
        $config = $request->validate([
            'PivotConfig' => 'required|array:fields,parameters,defaultLayout',
            'PivotConfig.defaultLayout' => 'nullable|array',
            'PivotConfig.fields' => 'required|list|max:100',
            'PivotConfig.fields.*' => 'required|array:name,label',
            'PivotConfig.fields.*.name' => ['required','distinct',Rule::in(array_keys($columns))],
            'PivotConfig.fields.*.label' => 'required|string|max:120',
            'PivotConfig.parameters' => 'present|list|max:20',
            'PivotConfig.parameters.*' => 'required|array:field,title,operator,required,default,master',
            'PivotConfig.parameters.*.field' => ['required','distinct',Rule::in(array_keys($columns))],
            'PivotConfig.parameters.*.title' => 'required|string|max:120',
            'PivotConfig.parameters.*.operator' => 'required|in:eq,gt,lt,between,in',
            'PivotConfig.parameters.*.required' => 'required|boolean',
            'PivotConfig.parameters.*.default' => 'nullable|list|max:20',
            'PivotConfig.parameters.*.default.*' => 'required|string|max:200',
            'PivotConfig.parameters.*.master' => 'nullable|integer|min:1',
        ])['PivotConfig'];
        foreach ($config['parameters'] as &$parameter) {
            $column = $columns[$parameter['field']];
            $parameter['kind'] = $column->kind;
            abort_unless(in_array($parameter['field'], array_column($config['fields'], 'name'), true), 422);
            abort_if(in_array($parameter['operator'], ['gt', 'lt', 'between'], true)
                && !self::numeric($column->kind)
                && !in_array($column->kind, ['date', 'datetime', 'datetime2', 'smalldatetime'], true), 422);
            if (!empty($parameter['master'])) {
                $master = self::master($parameter['master']);
                $masterKind = strtolower((string) ($master->DataType ?? ''));
                abort_unless($master && filled($master->LookupProcedure) && in_array($parameter['operator'], ['eq', 'in'], true)
                    && (self::numeric($masterKind) === self::numeric($column->kind)), 422);
            }
        }
        unset($parameter);
        if (isset($config['defaultLayout'])) {
            $fields = array_column($config['fields'], 'name');
            $measures = array_values(array_filter($fields, fn ($field) => self::numeric($columns[$field]->kind)));
            $config['defaultLayout'] = ($input['ReportType'] === 'CHART' ? ChartReport::validateLayout($config['defaultLayout'], $fields, $measures) : ReportLayoutController::validateLayout($config['defaultLayout'], $fields, $measures));
        }
        return [$input['ReportType'], $input['ViewSchema'], $input['ViewName'], json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }

    public static function master(int $id): ?object
    {
        return collect(DB::select('EXEC dbo.sp_GetMasterParameters @SearchText=NULL, @IsActive=1'))->firstWhere('MasterParameterID', $id);
    }

    public static function lookup(array $parameter): array
    {
        $master = self::master((int) ($parameter['master'] ?? 0));
        $procedure = $master->LookupProcedure ?? '';
        abort_unless(preg_match('/^(?:dbo\.)?[A-Za-z_][A-Za-z0-9_]*$/iD', $procedure), 422);
        return array_map(function ($row) {
            $values = array_change_key_case((array) $row, CASE_LOWER);
            return ['value'=>(string) ($values['value'] ?? ''), 'label'=>(string) ($values['label'] ?? $values['text'] ?? $values['value'] ?? '')];
        }, \Illuminate\Support\LazyCollection::make(fn () => DB::cursor("EXEC $procedure"))->take(200)->all());
    }

    public static function context(object $report): array
    {
        abort_unless(in_array($report->ReportType, ['PIVOT','CHART'], true), 404);
        $config = json_decode($report->PivotConfig, true, flags: JSON_THROW_ON_ERROR);
        $columns = self::columns($report->ViewSchema, $report->ViewName);
        $labels = array_column($config['fields'], 'label', 'name');
        abort_if(array_diff_key($labels, $columns), 422);
        $measures = [];
        foreach ($labels as $name => $label) {
            if (self::numeric($columns[$name]->kind)) $measures[$name] = (int) $columns[$name]->scale;
        }
        foreach ($config['parameters'] as $parameter) {
            abort_unless(($columns[$parameter['field']]->kind ?? null) === $parameter['kind'], 422);
        }
        return [$labels, $measures, $config['parameters']];
    }

    private static function numeric(string $kind): bool
    {
        return in_array($kind, ['bigint','int','smallint','tinyint','decimal','numeric'], true);
    }

    public static function query(object $report, array $parameters, Request $request)
    {
        $input = $request->validate(['parameterValues'=>'sometimes|array','parameterValues.*'=>'list|max:20','parameterValues.*.*'=>'required|string|max:200']);
        $query = DB::table($report->ViewSchema.'.'.$report->ViewName);
        foreach ($parameters as $parameter) {
            $field = $parameter['field'];
            $values = $input['parameterValues'][$field] ?? $parameter['default'] ?? [];
            if (!$values && !$parameter['required']) continue;
            $operator = $parameter['operator'];
            $valid = match ($operator) {
                'between' => count($values) === 2,
                'in' => count($values) >= 1,
                default => count($values) === 1,
            };
            foreach ($values as $value) {
                $kind = $parameter['kind'];
                $valid = $valid && match (true) {
                    self::numeric($kind) => (bool) preg_match('/^-?[0-9]{1,30}(?:\.[0-9]{1,12})?$/D', $value),
                    in_array($kind, ['date','datetime','datetime2','smalldatetime'], true) => (bool) (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$date) && checkdate((int)$date[2],(int)$date[3],(int)$date[1])),
                    $kind === 'bit' => in_array($value,['0','1'],true),
                    default => true,
                };
            }
            if ($valid && $operator === 'between') {
                $valid = self::numeric($parameter['kind'])
                    ? bccomp($values[0], $values[1], 12) <= 0
                    : strcmp($values[0], $values[1]) <= 0;
            }
            if (!$valid) throw ValidationException::withMessages(["parameterValues.$field" => $parameter['title']]);
            match ($operator) {
                'between' => $query->whereBetween($field, $values),
                'in' => $query->whereIn($field, $values),
                default => $query->where($field, ['eq'=>'=','gt'=>'>','lt'=>'<'][$operator], $values[0]),
            };
        }
        return $query;
    }
}
