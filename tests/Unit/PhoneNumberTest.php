<?php

namespace Tests\Unit;

use App\Services\Verification\PhoneNumber;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Normalisation is load-bearing: one phone means one account, and that rule is
 * most of the anti-bot defence. If 0888123456 and +359888123456 do not fold
 * into the same string, the same person registers four times.
 */
class PhoneNumberTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            'national with zero'   => ['0888123456',      '+359888123456'],
            'spaced'               => ['0888 123 456',    '+359888123456'],
            'dashed'               => ['088-812-3456',    '+359888123456'],
            'e164'                 => ['+359888123456',   '+359888123456'],
            'e164 spaced'          => ['+359 88 812 3456','+359888123456'],
            'country code no plus' => ['359888123456',    '+359888123456'],
            'double zero prefix'   => ['00359888123456',  '+359888123456'],
            'bare national'        => ['888123456',       '+359888123456'],
            'a1 prefix 87'         => ['0876543210',      '+359876543210'],
            'yettel prefix 89'     => ['0898765432',      '+359898765432'],
            'mvno prefix 98'       => ['0987654321',      '+359987654321'],
            'brackets and dots'    => ['(0888) 123.456',  '+359888123456'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_it_normalizes_bulgarian_mobiles(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'empty'            => [''],
            'letters'          => ['not a phone'],
            'too short'        => ['088812345'],
            'too long'         => ['08881234567'],
            'landline sofia'   => ['029876543'],
            'foreign'          => ['+441234567890'],
            'prefix 86 unused' => ['0868123456'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_it_rejects_anything_that_is_not_a_bg_mobile(string $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
        $this->assertFalse(PhoneNumber::isValid($input));
    }

    public function test_all_spellings_of_one_number_collapse_to_one_string(): void
    {
        $spellings = ['0888123456', '+359888123456', '359888123456', '0888 123 456', '888123456'];

        $normalized = array_unique(array_map(
            fn ($s) => PhoneNumber::normalize($s),
            $spellings
        ));

        $this->assertCount(1, $normalized, 'the same number must fold to one form');
    }

    public function test_it_formats_and_masks_for_display(): void
    {
        $this->assertSame('+359 88 812 3456', PhoneNumber::format('+359888123456'));
        $this->assertSame('3456', PhoneNumber::last4('+359888123456'));
    }
}
