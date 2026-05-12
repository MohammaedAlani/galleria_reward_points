<?php

namespace Tests\Unit;

use App\Helper\PhoneNormalizer;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_normalizes_iraq_local_with_leading_zero(): void
    {
        $this->assertSame('+9647809000055', PhoneNormalizer::normalize('07809000055'));
        $this->assertSame('+9647713529344', PhoneNormalizer::normalize('07713529344'));
        $this->assertSame('+9647702975664', PhoneNormalizer::normalize('07702975664'));
    }

    public function test_normalizes_iraq_local_without_leading_zero(): void
    {
        $this->assertSame('+9647902903681', PhoneNormalizer::normalize('7902903681'));
    }

    public function test_keeps_international_with_double_zero(): void
    {
        $this->assertSame('+963967478616', PhoneNormalizer::normalize('00963967478616'));
    }

    public function test_keeps_plus_prefixed(): void
    {
        $this->assertSame('+9647809000055', PhoneNormalizer::normalize('+9647809000055'));
    }

    public function test_normalizes_bare_international_starting_with_country_code(): void
    {
        $this->assertSame('+9647800010330', PhoneNormalizer::normalize('9647800010330'));
        $this->assertSame('+9647809000055', PhoneNormalizer::normalize('9647809000055'));
    }

    public function test_strips_whitespace_and_dashes(): void
    {
        $this->assertSame('+9647809000055', PhoneNormalizer::normalize('0780-900-0055'));
        $this->assertSame('+9647809000055', PhoneNormalizer::normalize(' 0780 900 0055 '));
    }

    public function test_returns_null_for_invalid(): void
    {
        $this->assertNull(PhoneNormalizer::normalize(null));
        $this->assertNull(PhoneNormalizer::normalize(''));
        $this->assertNull(PhoneNormalizer::normalize('abc'));
        $this->assertNull(PhoneNormalizer::normalize('123'));
    }

    public function test_for_ultramsg_strips_plus(): void
    {
        $this->assertSame('9647809000055', PhoneNormalizer::forUltraMsg('+9647809000055'));
    }
}
