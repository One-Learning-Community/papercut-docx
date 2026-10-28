<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\Docx\Map\LineMetrics;
use Papercut\Text\{FontFace, FontSet};
use PHPUnit\Framework\TestCase;

final class LineMetricsTest extends TestCase
{
    public function test_bundled_inter_natural_line_height(): void
    {
        $m = new LineMetrics(new FontSet());
        self::assertSame(1.2099609375, $m->natural('Inter'));
        self::assertSame(1.2099609375, $m->natural('inter'));
    }

    public function test_unknown_family_falls_back(): void
    {
        self::assertSame(1.2, (new LineMetrics(new FontSet()))->natural('Nope'));
    }

    public function test_font_with_no_line_height_falls_back(): void
    {
        // hhea ascender, descender and line gap all 0: a ratio of 0 would divide by zero downstream.
        $head = str_repeat("\0", 18) . pack('n', 1000) . str_repeat("\0", 34);
        $hhea = str_repeat("\0", 36);
        $dir = pack('Nnnnn', 0x10000, 2, 0, 0, 0)
            . pack('a4NNN', 'head', 0, 44, strlen($head))
            . pack('a4NNN', 'hhea', 0, 44 + strlen($head), strlen($hhea));
        $path = tempnam(sys_get_temp_dir(), 'flat');
        file_put_contents($path, $dir . $head . $hhea);
        try {
            $m = new LineMetrics((new FontSet())->with(new FontFace('Flat', $path)));
            self::assertSame(1.2, $m->natural('Flat'));
        } finally {
            unlink($path);
        }
    }
}
