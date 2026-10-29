<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Papercut\Docx\Package\Numbering;
use Papercut\Style\ListMarker;
use PHPUnit\Framework\TestCase;

final class NumberingTest extends TestCase
{
    private static function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $x = new \DOMXPath($dom);
        $x->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        return $x;
    }

    private static function n(\DOMXPath $x, string $query): int
    {
        $r = $x->query($query);

        return $r === false ? throw new \LogicException($query) : $r->length;
    }

    public function test_every_instance_is_new(): void
    {
        $n = new Numbering();
        self::assertTrue($n->isEmpty());
        self::assertSame(1, $n->instance(ListMarker::Decimal, 1));
        self::assertSame(2, $n->instance(ListMarker::Decimal, 1));
        self::assertFalse($n->isEmpty());
    }

    public function test_abstract_numbering_per_marker_used(): void
    {
        $n = new Numbering();
        $n->instance(ListMarker::Decimal, 1);
        $n->instance(ListMarker::Disc, 1);
        $n->instance(ListMarker::Decimal, 3);
        $x = self::xpath($n->xml());
        self::assertSame(2, self::n($x, '//w:abstractNum'));
        self::assertSame(9, self::n($x, "//w:abstractNum[@w:abstractNumId='0']/w:lvl"));
        $l1 = "//w:abstractNum[@w:abstractNumId='0']/w:lvl[@w:ilvl='1']";
        self::assertSame(1, self::n($x, "$l1/w:numFmt[@w:val='decimal']"));
        self::assertSame(1, self::n($x, "$l1/w:lvlText[@w:val='%2.']"));
        self::assertSame(1, self::n($x, "$l1/w:start[@w:val='1']"));
        self::assertSame(1, self::n($x, "$l1/w:lvlJc[@w:val='left']"));
        self::assertSame(1, self::n($x, "$l1/w:suff[@w:val='tab']"));
        self::assertSame(1, self::n($x, "$l1/w:pPr/w:ind[@w:left='720'][@w:hanging='360']"));
        $d0 = "//w:abstractNum[@w:abstractNumId='1']/w:lvl[@w:ilvl='0']";
        self::assertSame(1, self::n($x, "$d0/w:numFmt[@w:val='bullet']"));
        self::assertSame(1, self::n($x, "$d0/w:lvlText[@w:val='•']"));
        self::assertSame(0, self::n($x, '//w:rPr'));
        self::assertSame(3, self::n($x, '//w:num'));
        self::assertSame(1, self::n($x, "//w:num[@w:numId='2']/w:abstractNumId[@w:val='1']"));
        self::assertSame(1, self::n($x, "//w:num[@w:numId='3']/w:abstractNumId[@w:val='0']"));
    }

    public function test_start_override_on_every_level(): void
    {
        $n = new Numbering();
        $n->instance(ListMarker::LowerRoman, 5);
        $x = self::xpath($n->xml());
        self::assertSame(9, self::n($x, '//w:num/w:lvlOverride'));
        self::assertSame(9, self::n($x, "//w:num/w:lvlOverride/w:startOverride[@w:val='5']"));
        self::assertSame(1, self::n($x, "//w:lvl[@w:ilvl='0']/w:numFmt[@w:val='lowerRoman']"));
    }

    public function test_marker_style_ids(): void
    {
        self::assertSame('papercut-num-3-1', Numbering::marker(3, 1, null));
        self::assertSame('papercut-num-3-0-h2', Numbering::marker(3, 0, 2));
    }

    public function test_an_instance_of_no_marker_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Numbering())->instance(ListMarker::None, 1);
    }

    public function test_invalid_heading_level_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Numbering::marker(1, 0, 7);
    }
}
