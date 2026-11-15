<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SalesReportController extends Controller
{
    private const PAGE_SIZE = 50;
    private const COLUMNS = ['tc_code', 'tc_name', 'iop_code', 'iop_name', 'YM', 'tmp_code', 'tmp_name', 'i_code', 'i_name', 'hr_code', 'Visitor', 'AmountPrice', 'QtyConfirmed'];
    private const MEASURES = ['AmountPrice', 'QtyConfirmed'];

    private function authorizeReport(Request $request): void
    {
        abort_unless(in_array((string) $request->user()->getAuthIdentifier(), config('sales_report.user_ids'), true), 403);
    }

    public function show(Request $request)
    {
        $this->authorizeReport($request);

        return Inertia::render('SalesReport/Index', ['fields' => self::COLUMNS, 'userId' => (string) $request->user()->getAuthIdentifier()]);
    }

    public function execute(Request $request)
    {
        $this->authorizeReport($request);
        return $this->pivot($request, $this->connection()->table('dbo.vw_Sales'), self::COLUMNS, array_fill_keys(self::MEASURES, 0));
    }

    public function pivot(Request $request, Builder $query, array $fields, array $measures)
    {
        $input = $request->validate([
            'rows' => 'present|list|max:5',
            'columns' => 'present|list|max:5',
            'values' => 'required|list|max:2',
            'rows.*' => ['required', 'string', 'distinct', Rule::in($fields)],
            'columns.*' => ['required', 'string', 'distinct', Rule::in($fields)],
            'values.*' => ['required', 'string', 'distinct', Rule::in(array_keys($measures))],
            'filters' => 'present|list|max:13',
            'filters.*' => 'required|array:field,values',
            'filters.*.field' => ['required', 'string', 'distinct', Rule::in($fields)],
            'filters.*.values' => 'required|list|max:20',
            'filters.*.values.*' => 'required|string|max:200',
            'page' => 'sometimes|integer|min:1|max:100000000',
        ]);
        $rows = $input['rows'];
        $columns = $input['columns'];
        abort_if(array_intersect($rows, $columns), 422);

        $connection = $query->getConnection();
        foreach ($input['filters'] as $filter) {
            $values = array_map(function ($value) use ($filter, $measures) {
                if (!isset($measures[$filter['field']])) return $value;
                if ($measures[$filter['field']] > 0 && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)) return $value;
                $number = filter_var($value, FILTER_VALIDATE_INT);
                abort_if($number === false, 422);
                return $number;
            }, $filter['values']);
            $query->whereIn($filter['field'], $values);
        }
        $rowKeys = implode(', ', array_map(fn ($field) => "[$field]", $rows));
        $columnKeys = implode(', ', array_map(fn ($field) => "[$field]", $columns));
        $dimensions = array_merge($rows, $columns);
        foreach ($dimensions as $field) {
            $query->addSelect($field);
        }
        foreach ($input['values'] as $index => $field) {
            $scale = (int) $measures[$field];
            $query->selectRaw("SUM(CAST([$field] AS decimal(38,$scale))) AS [sum_$index]");
        }

        $prefixes = fn ($fields) => array_map(fn ($n) => array_slice($fields, 0, $n), range(0, count($fields)));
        $sets = [];
        foreach ($prefixes($rows) as $rowPrefix) {
            foreach ($prefixes($columns) as $columnPrefix) {
                $sets[] = implode(', ', array_map(fn ($field) => "[$field]", array_merge($rowPrefix, $columnPrefix)));
            }
        }
        $query->selectRaw($rowKeys ? "GROUPING_ID($rowKeys) AS rowMissing" : '0 AS rowMissing');
        $query->selectRaw($columnKeys ? "GROUPING_ID($columnKeys) AS columnMissing" : '0 AS columnMissing');
        if ($dimensions) {
            $query->groupByRaw('GROUPING SETS ('.implode(', ', array_map(fn ($set) => "($set)", $sets)).')');
        }
        $order = [];
        foreach ($rows as $index => $field) {
            $bit = 1 << (count($rows) - $index - 1);
            $order[] = "([rowMissing] & $bit)";
            $order[] = "[$field]";
        }
        $rowOrder = $order ? implode(', ', $order) : '(SELECT NULL)';
        $ranked = $connection->query()->fromSub($query, 'pivot_groups')->select('*')
            ->selectRaw("DENSE_RANK() OVER (ORDER BY $rowOrder) AS rowRank");
        $windowed = $connection->query()->fromSub($ranked, 'ranked_groups')->select('*')
            ->selectRaw('MAX(rowRank) OVER () AS maxRank');
        $requestedPage = (int) ($input['page'] ?? 1);
        $start = ($requestedPage - 1) * self::PAGE_SIZE + 1;
        $end = $start + self::PAGE_SIZE - 1;
        $globalMask = (1 << count($rows)) - 1;
        $records = $connection->query()->fromSub($windowed, 'numbered_groups')
            ->where(fn ($page) => $page->whereBetween('rowRank', [$start, $end])->orWhere('rowMissing', $globalMask))
            ->orderBy('rowRank')->limit(1276)->get();
        abort_if($records->count() >= 1276, 422, 'Pivot column limit');
        $totalRows = max(0, (int) ($records->first()->maxRank ?? 0) - ($rows ? 1 : 0));
        $rowItems = $columnItems = $cells = $rowTotals = $columnTotals = [];
        $grand = [];
        foreach ($records as $record) {
            $row = array_map(fn ($field) => $record->{$field}, $rows);
            $column = array_map(fn ($field) => $record->{$field}, $columns);
            $row = array_slice($row, 0, count($rows) - substr_count(decbin((int) $record->rowMissing), '1'));
            $column = array_slice($column, 0, count($columns) - substr_count(decbin((int) $record->columnMissing), '1'));
            $rowId = json_encode($row, JSON_THROW_ON_ERROR);
            $columnId = json_encode($column, JSON_THROW_ON_ERROR);
            $sums = array_map(fn ($index) => (string) ($record->{"sum_$index"} ?? 0), array_keys($input['values']));
            $hasRow = !$rows || (int) $record->rowMissing < (1 << count($rows)) - 1;
            $hasColumn = !$columns || (int) $record->columnMissing < (1 << count($columns)) - 1;
            if ($hasRow) {
                $rowItems[$rowId] = $row;
            }
            if ($hasColumn) {
                $columnItems[$columnId] = $column;
            }
            if ($hasRow && $hasColumn) {
                $cells[$rowId][$columnId] = $sums;
            }
            if ($hasRow && (!$hasColumn || !$columns)) {
                $rowTotals[$rowId] = $sums;
            }
            if ($hasColumn && (!$hasRow || !$rows)) {
                $columnTotals[$columnId] = $sums;
            }
            if ((!$hasRow || !$rows) && (!$hasColumn || !$columns)) {
                $grand = $sums;
            }
        }
        abort_if(count($columnItems) > 24, 422, 'Pivot column limit');
        $compare = function ($a, $b) {
            foreach ($a as $i => $value) {
                if (!array_key_exists($i, $b)) return -1;
                if (($order = strcmp((string) $value, (string) $b[$i])) !== 0) return $order;
            }
            return count($b) <=> count($a);
        };
        uasort($columnItems, $compare);

        return response()->json(compact('rowItems', 'columnItems', 'cells', 'rowTotals', 'columnTotals', 'grand', 'totalRows') + ['pageSize' => self::PAGE_SIZE]);
    }

    public function filterOptions(Request $request)
    {
        $this->authorizeReport($request);
        return $this->options($request, $this->connection()->table('dbo.vw_Sales'), self::COLUMNS);
    }

    public function options(Request $request, Builder $query, array $fields)
    {
        $input = $request->validate([
            'field' => ['required', 'string', Rule::in($fields)],
            'search' => 'nullable|string|max:200',
        ]);
        $field = $input['field'];
        $query->whereNotNull($field);
        if (filled($input['search'] ?? null)) {
            $pattern = strtr($input['search'], ['[' => '[[]', '%' => '[%]', '_' => '[_]']);
            $query->whereRaw("CONVERT(nvarchar(4000), [$field]) LIKE ?", ['%'.$pattern.'%']);
        }

        return response()->json($query->distinct()->orderBy($field)->limit(40)->pluck($field)
            ->map(fn ($value) => trim((string) $value))->filter(fn ($value) => $value !== '')->uniqueStrict()->values());
    }

    private function connection()
    {
        $settings = config('sales_report');
        $base = config('database.connections.sqlsrv');
        $username = $settings['username'];
        $password = $settings['password'];
        if ($settings['use_project_credentials'] && blank($username) && $settings['host'] === $base['host'] && (string) $settings['port'] === (string) $base['port']) {
            $username = $base['username'];
            $password = $base['password'];
        }
        abort_unless(filled($username), 503);
        return DB::build(array_replace($base, [
            'url' => null,
            'database' => 'Raga_Reports',
            'host' => $settings['host'],
            'port' => $settings['port'],
            'username' => $username,
            'password' => $password,
        ]));

    }
}
