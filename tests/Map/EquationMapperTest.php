<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\{Column, Document, Equation, ListItem, ListNode, Page, Row, Table, Text};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Math\Math;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class EquationMapperTest extends TestCase
{
    private static function export(\Papercut\Node ...$body): DocxInspector
    {
        return DocxInspector::of(Document::make(Page::a4()->body(Column::make(...$body)))->export(new DocxExporter())->bytes());
    }

    private static function math(DocxInspector $d): \DOMXPath
    {
        $xp = $d->xpath();
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/officeDocument/2006/math');

        return $xp;
    }

    private static function n(\DOMXPath $xp, string $query): int
    {
        $nodes = $xp->query($query);
        \assert($nodes !== false);

        return $nodes->length;
    }

    private static function frac(): Equation
    {
        return Equation::of('a =', Math::frac('b', 'c'));
    }

    public function test_block_equation_paragraph(): void
    {
        $d = self::export(Text::make('before'), self::frac()->margin(pt(10)), Text::make('after'));
        $xp = self::math($d);
        self::assertSame(1, self::n($xp, '//w:p[m:oMathPara]'));
        self::assertSame(0, self::n($xp, '//w:p[m:oMathPara]/w:r'));
        // After w:pPr the paragraph holds nothing but the equation.
        self::assertSame(1, self::n($xp, '//w:p[m:oMathPara]/w:pPr/following-sibling::*'));
        self::assertSame(1, self::n($xp, '//w:p/w:pPr/following-sibling::*[1][self::m:oMathPara]/m:oMathParaPr/m:jc[@m:val="left"]'));
        self::assertSame(1, self::n($xp, '//m:oMathPara/m:oMath//m:f'));
        // The margins are the paragraph's spacing.
        self::assertSame(1, self::n($xp, '//w:p[m:oMathPara]/w:pPr/w:spacing[@w:before="200"]'));
        self::assertSame(1, self::n($xp, '//w:p[m:oMathPara]/w:pPr/w:spacing[@w:after="200"]'));
        self::assertStringNotContainsString('papercut-math', $d->part('word/document.xml'));
    }

    public function test_centered_equation_jc(): void
    {
        $d = self::export(self::frac()->textAlign(\Papercut\Style\TextAlign::Center), self::frac()->textAlign(\Papercut\Style\TextAlign::Right), self::frac()->textAlign(\Papercut\Style\TextAlign::Justify));
        self::assertSame(['center', 'right', 'left'], self::vals(self::math($d), '//m:jc/@m:val'));
    }

    /** @return list<string> */
    private static function vals(\DOMXPath $xp, string $q): array
    {
        $out = [];
        foreach ($xp->query($q) ?: [] as $n) {
            $out[] = (string) $n->nodeValue;
        }

        return $out;
    }

    public function test_namespace_declared(): void
    {
        $d = self::export(self::frac());
        self::assertSame(1, preg_match('~^<\?xml[^>]*\?>\s*<w:document\b[^>]*xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"~', $d->part('word/document.xml')));
        self::assertSame(1, substr_count($d->part('word/document.xml'), 'xmlns:m='));
    }

    public function test_no_equation_no_namespace(): void
    {
        $d = self::export(Text::make('plain'));
        self::assertStringNotContainsString('xmlns:m=', $d->part('word/document.xml'));
    }

    public function test_in_table_cell_and_list_item(): void
    {
        $d = self::export(
            Table::make()->row(self::frac()),
            ListNode::ordered(ListItem::make(self::frac())),
        );
        $xp = self::math($d);
        self::assertSame(1, self::n($xp, '//w:tc/w:p/m:oMathPara'));
        self::assertSame(1, self::n($xp, '//w:tc/w:p/w:pPr/following-sibling::*[1][self::m:oMathPara]'));
        self::assertSame(1, self::n($xp, '//w:body/w:p[w:pPr/w:numPr]/m:oMathPara'));
        self::assertSame(1, self::n($xp, '//w:body/w:p[w:pPr/w:numPr and m:oMathPara]/*[not(self::w:pPr)]'));
        self::assertSame(2, self::n($xp, '//m:oMathPara'));
    }

    public function test_colour_and_size(): void
    {
        $d = self::export(self::frac()->color('#cc0000')->size(pt(20)));
        $xp = self::math($d);
        self::assertGreaterThan(0, self::n($xp, '//m:oMath//m:r'));
        // Every run carries both.
        self::assertSame(self::n($xp, '//m:oMath//m:r'), self::n($xp, '//m:oMath//m:r/w:rPr/w:sz[@w:val="40"]'));
        self::assertSame(self::n($xp, '//m:oMath//m:r'), self::n($xp, '//m:oMath//m:r/w:rPr/w:color[@w:val="cc0000"]'));
    }

    public function test_two_equations_get_their_own_markup(): void
    {
        $d = self::export(Equation::of('x'), Equation::of(Math::sqrt('y')));
        $xp = self::math($d);
        self::assertSame(2, self::n($xp, '//m:oMathPara'));
        self::assertSame(1, self::n($xp, '//m:rad'));
    }

    public function test_equation_in_a_header_declares_the_namespace_there(): void
    {
        $d = DocxInspector::of(Document::make(Page::a4()->header(self::frac())->body(Text::make('body')))->export(new DocxExporter())->bytes());
        $header = array_values(array_filter($d->names(), static fn (string $n): bool => preg_match('~^word/header\d+\.xml$~', $n) === 1));
        self::assertNotSame([], $header);
        self::assertStringContainsString('xmlns:m=', $d->part($header[0]));
        self::assertStringContainsString('<m:oMathPara>', $d->part($header[0]));
        self::assertStringNotContainsString('xmlns:m=', $d->part('word/document.xml'));
    }

    public function test_equation_in_a_row_keeps_its_paragraph_to_itself(): void
    {
        $d = self::export(Row::make(Text::make('Let'), Equation::of('x')->margin(pt(0))));
        $xp = self::math($d);
        self::assertSame(1, self::n($xp, '//w:p[m:oMathPara]'));
        self::assertSame(1, self::n($xp, '//w:p[m:oMathPara]/*[not(self::w:pPr)]'));
        self::assertStringContainsString('Let', $d->bodyText());
    }

    public function test_equation_in_a_typed_list_item_keeps_its_paragraph_to_itself(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Equation::of('y')))->start(0));
        $xp = self::math($d);
        self::assertSame(1, self::n($xp, '//w:p[m:oMathPara]'));
        self::assertSame(1, self::n($xp, '//w:p[m:oMathPara]/*[not(self::w:pPr)]'));
        self::assertStringContainsString('0.', $d->bodyText());
    }
}
