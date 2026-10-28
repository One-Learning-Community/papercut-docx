<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\Docx\Map\Units;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase
{
    public function test_twips_and_emu(): void
    {
        self::assertSame(200, Units::tw(10.0));
        self::assertSame(15, Units::tw(0.74));
        self::assertSame(127000, Units::emu(10.0));
    }

    public function test_hex_blends_alpha_onto_white(): void
    {
        self::assertSame('ff8080', Units::hex(Color::rgb(255, 0, 0, 0.5)));
        self::assertSame('0f172a', Units::hex(Color::rgb(15, 23, 42)));
    }
}
