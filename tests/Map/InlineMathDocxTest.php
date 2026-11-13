<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\{Column, Document, Equation, Page, Span, Table, Text};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Math\Math;
use Papercut\Style\Style;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

final class InlineMathDocxTest extends TestCase
{
    private static function export(\Papercut\Node ...$body): \DOMXPath
    {
        $d = DocxInspector::of(Document::make(Page::a4()->body(Column::make(...$body)))->export(new DocxExporter())->bytes());
        $xp = $d->xpath();
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/officeDocument/2006/math');
        self::assertStringNotContainsString('papercut-math', $d->part('word/document.xml'));

        return $xp;
    }

    private static function n(\DOMXPath $xp, string $q): int
    {
        return ($xp->query($q) ?: throw new \LogicException('bad query'))->length;
    }

    public function test_inline_omml_between_runs(): void
    {
        $xp = self::export(Text::spans(Span::text('before '), Span::math(Math::frac('a', 'b')), Span::text(' after')));
        $names = [];
        foreach ($xp->query('//w:p[m:oMath]/*[not(self::w:pPr)]') ?: [] as $n) {
            $names[] = $n->nodeName;
        }
        self::assertSame(['w:r', 'm:oMath', 'w:r'], $names);
        self::assertSame(0, self::n($xp, '//m:oMathPara'));
        self::assertSame(1, self::n($xp, '//w:p/m:oMath//m:f'));
    }

    public function test_inline_in_table_cell(): void
    {
        $xp = self::export(Table::make()->columns('auto')->row(Text::spans(Span::text('v '), Span::math(Math::sup('x', '2')))));
        self::assertSame(1, self::n($xp, '//w:tc//w:p/m:oMath//m:sSup'));
    }

    public function test_block_and_inline_ids_do_not_collide(): void
    {
        $xp = self::export(
            Equation::of('a =', Math::frac('b', 'c')),
            Text::spans(Span::math(Math::sup('x', '2')), Span::text(' and '), Span::math(Math::sub('y', '1'))),
        );
        self::assertSame(1, self::n($xp, '//m:oMathPara'));
        self::assertSame(2, self::n($xp, '//w:p/m:oMath[not(parent::m:oMathPara)]'));
        self::assertSame(1, self::n($xp, '//m:sSup'));
        self::assertSame(1, self::n($xp, '//m:sSub'));
    }

    public function test_adjacent_math_spans_stay_separate(): void
    {
        $xp = self::export(Text::spans(Span::math('a'), Span::math('b')));
        self::assertSame(2, self::n($xp, '//w:p/m:oMath'));
    }

    public function test_inline_carries_size_and_colour(): void
    {
        $xp = self::export(Text::spans(Span::math('x'))->size(\Papercut\pt(20))->color('#b91c1c'));
        self::assertSame(1, self::n($xp, '//m:oMath//w:rPr/w:color[@w:val="b91c1c"]'));
        self::assertSame(1, self::n($xp, '//m:oMath//w:rPr/w:sz[@w:val="40"]'));
    }

    public function test_own_colour_fades_with_the_text_opacity(): void
    {
        $xp = self::export(Text::spans(new Span('', Style::make(color: Color::hex('#ff0000')), math: Math::row('x')))->opacity(0.5));
        self::assertSame(1, self::n($xp, '//m:oMath//w:rPr/w:color[@w:val="ff8080"]'));
    }

    public function test_own_size_and_colour_win_over_the_paragraphs(): void
    {
        $xp = self::export(Text::spans(new Span('', Style::make(color: Color::hex('#ff0000'), fontSize: \Papercut\pt(20)), math: Math::row('x')))
            ->size(\Papercut\pt(10))->color('#0000ff'));
        self::assertSame(1, self::n($xp, '//m:oMath//w:rPr/w:color[@w:val="ff0000"]'));
        self::assertSame(1, self::n($xp, '//m:oMath//w:rPr/w:sz[@w:val="40"]'));
    }

    public function test_math_inside_a_link_is_replaced_and_the_document_parses(): void
    {
        $xp = self::export(Text::spans(Span::text('see '), new Span('', href: 'https://example.test', math: Math::frac('a', 'b'))));
        self::assertSame(1, self::n($xp, '//w:hyperlink//m:oMath//m:f'));
    }

    public function test_the_spans_background_shades_the_equation_runs(): void
    {
        $xp = self::export(Text::spans(new Span('', Style::make(background: Color::hex('#ffee00')), math: Math::row('x'))));
        self::assertSame(1, self::n($xp, '//m:oMath//m:r/w:rPr/w:shd[@w:val="clear"][@w:fill="ffee00"]'));
        $order = [];
        foreach ($xp->query('//m:oMath//m:r/w:rPr/*') ?: [] as $n) {
            $order[] = $n->nodeName;
        }
        self::assertSame(['w:rFonts', 'w:color', 'w:sz', 'w:szCs', 'w:shd'], array_slice($order, 0, 5));
    }

    public function test_a_control_character_in_an_equation_does_not_break_the_document(): void
    {
        $bytes = Document::make(Page::a4()->body(Column::make(Equation::of(Math::text("a\x01b")), Text::spans(Span::math(Math::text("c\x02d"))))))
            ->export(new DocxExporter())->bytes();
        $xml = DocxInspector::of($bytes)->part('word/document.xml');
        \Dom\XMLDocument::createFromString($xml);
        self::assertStringContainsString('>ab</m:t>', $xml);
        self::assertStringContainsString('>cd</m:t>', $xml);
    }
}
