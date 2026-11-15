<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ChartReport
{
    public static function validateLayout(mixed $layout, array $fields, array $measures): array
    {
        $data = Validator::make(['chart' => $layout], [
            'chart' => 'required|array:type,dimension,series,limit,sort,title,legend,labels,color,parameterValues,filters',
            'chart.type' => 'required|in:column,bar,line,area,pie,donut,treemap,kpi',
            'chart.dimension' => ['nullable', Rule::in($fields)],
            'chart.series' => 'required|list|min:1|max:4',
            'chart.series.*' => 'required|array:field,operation',
            'chart.series.*.field' => ['required', Rule::in($fields)],
            'chart.series.*.operation' => 'required|in:SUM,AVG,MIN,MAX,COUNT',
            'chart.limit' => 'required|integer|min:1|max:100',
            'chart.sort' => 'required|in:dimension,asc,desc',
            'chart.title' => 'nullable|string|max:120',
            'chart.legend' => 'sometimes|boolean',
            'chart.labels' => 'sometimes|boolean',
            'chart.color' => 'nullable|regex:/^#[a-fA-F0-9]{6}$/D',
            'chart.filters' => 'sometimes|list|max:20',
            'chart.filters.*' => 'required|array:field,values',
            'chart.filters.*.field' => ['required', 'string', 'distinct', Rule::in($fields)],
            'chart.filters.*.values' => 'present|list|max:20',
            'chart.filters.*.values.*' => 'required|string|max:200',
            'chart.parameterValues' => 'sometimes|array|max:20',
            'chart.parameterValues.*' => 'list|max:20',
            'chart.parameterValues.*.*' => 'required|string|max:200',
        ])->validate()['chart'];
        foreach ($data['series'] as $series) {
            if ($series['operation'] !== 'COUNT' && !in_array($series['field'], $measures, true)) {
                throw ValidationException::withMessages(['chart.series' => 'این محاسبه به فیلد عددی نیاز دارد.']);
            }
        }
        if (($data['type'] !== 'kpi' && empty($data['dimension'])) ||
            (in_array($data['type'], ['pie','donut','treemap'], true) && count($data['series']) !== 1)) {
            throw ValidationException::withMessages(['chart' => 'برای این نمودار، گروه‌بندی و تعداد شاخص مناسب را انتخاب کنید.']);
        }

        return $data;
    }

    public static function execute(Request $request, Builder $query, array $fields, array $measures)
    {
        $layout = self::validateLayout($request->input('chart'), $fields, $measures);
        $page = (int) ($request->validate(['page' => 'sometimes|integer|min:1|max:21474836'])['page'] ?? 1);
        $dimension = $layout['type'] === 'kpi' ? null : $layout['dimension'];
        foreach ($layout['filters'] ?? [] as $filter) {
            if ($filter['values'] !== []) {
                $query->whereIn($filter['field'], $filter['values']);
            }
        }
        $grammar = $query->getGrammar();
        foreach ($layout['series'] as $i => $series) {
            $field = $grammar->wrap($series['field']);
            $operation = $series['operation'];
            $expression = match ($operation) {
                'COUNT' => "COUNT_BIG($field)",
                'SUM', 'AVG' => "$operation(CAST($field AS decimal(38,12)))",
                default => "$operation($field)",
            };
            $query->selectRaw("$expression AS [metric_$i]");
        }
        if ($dimension !== null) {
            $query->selectRaw($grammar->wrap($dimension).' AS [category]')->groupBy($dimension);
            if ($layout['sort'] !== 'dimension') {
                $query->orderBy('metric_0', $layout['sort']);
            }
            $query->orderBy($dimension);
        }
        try {
            if ($dimension === null) {
                return response()->json(['rows' => $query->get(), 'total' => 1]);
            }

            $results = $query->paginate((int) $layout['limit'], ['*'], 'page', $page);
            return response()->json(['rows' => $results->items(), 'total' => $results->total()]);
        } catch (QueryException $error) {
            report($error);

            return response()->json(['message' => 'خواندن داده‌های نمودار انجام نشد.'], 503);
        }

    }
}
