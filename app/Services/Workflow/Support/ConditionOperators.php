<?php

namespace App\Services\Workflow\Support;

/**
 * ماتریسِ Operatorهایِ مجاز به‌ازایِ هر DataType — قراردادِ متمرکزِ Backend
 * (طبقِ تصمیمِ Final Design: فعلاً بدونِ جدولِ DB؛ Frontend همین Contract را مصرف می‌کند).
 *
 * Operatorهایِ تک‌عملوندی (UNARY) هرگز نباید کلیدِ «value» در RuleJson داشته باشند —
 * این قاعده در ConditionRuleValidator اعمال می‌شود، نه اینجا.
 */
class ConditionOperators
{
    public const UNARY = ['IS_EMPTY', 'IS_NOT_EMPTY', 'IS_TRUE', 'IS_FALSE'];

    /** @var array<string,string[]> DataType => Operatorهایِ مجاز */
    private const MATRIX = [
        'INTEGER' => ['EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE'],
        'DECIMAL' => ['EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE'],
        'DATE'    => ['EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE'],
        'TIME'    => ['EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE'],
        'BOOLEAN' => ['IS_TRUE', 'IS_FALSE'],
        'STRING'  => ['EQ', 'NE', 'CONTAINS', 'IS_EMPTY', 'IS_NOT_EMPTY'],
        'SELECT'  => ['EQ', 'NE', 'IN', 'NOT_IN'],
        'USER'    => ['EQ', 'NE', 'IN', 'NOT_IN'],
        'UNIT'    => ['EQ', 'NE', 'IN', 'NOT_IN'],
    ];

    /** @return string[] */
    public static function forDataType(string $dataType): array
    {
        return self::MATRIX[$dataType] ?? [];
    }

    public static function isAllowed(string $dataType, string $operator): bool
    {
        return in_array($operator, self::forDataType($dataType), true);
    }

    public static function isUnary(string $operator): bool
    {
        return in_array($operator, self::UNARY, true);
    }
}
