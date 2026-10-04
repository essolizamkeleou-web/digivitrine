<?php

namespace Tests\Unit;

use App\Support\TogoPhone;
use PHPUnit\Framework\TestCase;

class TogoPhoneTest extends TestCase
{
    public function test_every_accepted_format_is_normalised(): void
    {
        $accepted = [
            '90 12 34 56',
            '90123456',
            '+228 90 12 34 56',
            '+22890123456',
            '00228 90123456',
            '0022890123456',
            '90.12.34.56',
            '(90) 12-34-56',
            "  90 12 34 56\n",
        ];

        foreach ($accepted as $input) {
            $this->assertSame('+22890123456', TogoPhone::normalize($input), "Input: {$input}");
        }
    }

    public function test_landline_style_numbers_with_eight_digits_are_accepted(): void
    {
        $this->assertSame('+22822212345', TogoPhone::normalize('22 21 23 45'));
    }

    public function test_invalid_numbers_are_rejected(): void
    {
        $invalid = [
            '', '   ', 'abcdefgh', '9012345', '901234567', '+22890123', '+2289012345678',
            '+33612345678', '0033612345678', '228 90123456', '22890123456', '+228+90123456', '+228 9012 34 5a',
        ];

        foreach ($invalid as $input) {
            $this->assertNull(TogoPhone::normalize($input), "Input: {$input}");
        }
    }

    public function test_non_string_values_are_rejected(): void
    {
        $this->assertNull(TogoPhone::normalize(null));
        $this->assertNull(TogoPhone::normalize(90123456));
        $this->assertNull(TogoPhone::normalize(['90123456']));
        $this->assertNull(TogoPhone::normalize(true));
    }
}
