<?php

namespace Tests\Unit\GlobalSearch;

use App\Services\GlobalSearch\PersianNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * بردارهایِ مشترکِ §4 مشخصات — همان فایل بعداً برایِ آزمونِ همتایِ TypeScript استفاده می‌شود.
 * بدونِ دیتابیس و بدونِ بوتِ لاراول.
 */
class PersianNormalizerTest extends TestCase
{
    public static function vectors(): array
    {
        $data = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/global_search_normalization_vectors.json'), true, 512, JSON_THROW_ON_ERROR);

        $cases = [];
        foreach ($data['vectors'] as $v) {
            $cases[$v['name']] = [$v['input'], $v['expected']];
        }

        return $cases;
    }

    #[DataProvider('vectors')]
    public function test_shared_vectors(string $input, string $expected): void
    {
        $this->assertSame($expected, (new PersianNormalizer())->normalize($input));
    }

    public function test_normalization_is_idempotent(): void
    {
        $n = new PersianNormalizer();
        foreach (self::vectors() as [$input]) {
            $once = $n->normalize($input);
            $this->assertSame($once, $n->normalize($once));
        }
    }

    public function test_tokenize_splits_on_single_spaces_and_drops_empty(): void
    {
        $n = new PersianNormalizer();
        $this->assertSame(['tsk', '1405'], $n->tokenize($n->normalize('  TSK   ۱۴۰۵ ')));
        $this->assertSame([], $n->tokenize(''));
    }

    public function test_escape_like_neutralises_sql_server_wildcards(): void
    {
        $n = new PersianNormalizer();
        $this->assertSame('50[%][_][[]x]', $n->escapeLike('50%_[x]'));
        $this->assertSame('plain', $n->escapeLike('plain'));
    }
}
