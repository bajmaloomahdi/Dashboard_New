<?php

namespace Tests\Unit;

use App\Http\Controllers\ChartReport;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ChartReportTest extends TestCase
{
    public function test_sales_chart_accepts_a_bounded_monthly_sum(): void
    {
        $layout = ['type'=>'line', 'dimension'=>'YM', 'series'=>[['field'=>'AmountPrice','operation'=>'SUM']], 'limit'=>12, 'sort'=>'dimension'];

        $this->assertSame($layout, ChartReport::validateLayout($layout, ['YM','AmountPrice'], ['AmountPrice']));
    }

    public function test_chart_rejects_a_dimension_outside_the_view_allowlist(): void
    {
        $this->expectException(ValidationException::class);
        ChartReport::validateLayout(
            ['type'=>'column','dimension'=>'Users','series'=>[['field'=>'AmountPrice','operation'=>'SUM']], 'limit'=>12,'sort'=>'dimension'],
            ['YM','AmountPrice'], ['AmountPrice'],
        );
    }

    public function test_chart_rejects_numeric_aggregation_on_text(): void
    {
        $this->expectException(ValidationException::class);
        ChartReport::validateLayout(
            ['type'=>'bar','dimension'=>'YM','series'=>[['field'=>'Visitor','operation'=>'AVG']], 'limit'=>10,'sort'=>'desc'],
            ['YM','Visitor'], [],
        );
    }
    public function test_chart_filters_use_only_report_fields(): void
    {
        $layout = [
            'type' => 'line', 'dimension' => 'YM',
            'series' => [['field' => 'AmountPrice', 'operation' => 'SUM']],
            'limit' => 12, 'sort' => 'dimension',
            'filters' => [['field' => 'YM', 'values' => ['140501', '140502']]],
        ];

        $this->assertSame($layout, ChartReport::validateLayout($layout, ['YM', 'AmountPrice'], ['AmountPrice']));

        $layout['filters'][0]['field'] = 'UserID';
        $this->expectException(ValidationException::class);
        ChartReport::validateLayout($layout, ['YM', 'AmountPrice'], ['AmountPrice']);
    }
}
