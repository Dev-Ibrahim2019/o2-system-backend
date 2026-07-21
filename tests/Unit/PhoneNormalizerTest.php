<?php
namespace Tests\Unit;
use App\Services\Support\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
class PhoneNormalizerTest extends TestCase
{
    public static function phones():array{return [['0599123456','+970599123456'],['+970 599 123 456','+970599123456'],['00970-599-123-456','+970599123456']];}
    #[DataProvider('phones')]
    public function test_it_normalizes_palestinian_numbers(string $input,string $expected):void{$this->assertSame($expected,(new PhoneNormalizer())->normalize($input));}
}
