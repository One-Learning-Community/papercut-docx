<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Papercut\{Column, Text};
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Exception\UnsupportedFeature;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class StrictModeTest extends TestCase
{
    use Styles;

    public function test_strict_mode_throws_on_the_first_drop(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('Strict mode: docx cannot render radius at page > body > column[0]');
        self::export(Column::make(Text::make('x'))->padding(pt(4))->radius(pt(4)), strict: true);
    }
}
