<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Column, Component, Document, Node, Page, Text};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\{DocxInspector, OoxmlValidator};
use Papercut\Markup\{Markup, MarkupOptions, StyleMode};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ValidWordOutputTest extends TestCase
{
    /** CT_RPr child order, ECMA-376 Part 1 17.3.2.28. */
    private const array RPR_ORDER = [
        'rStyle', 'rFonts', 'b', 'bCs', 'i', 'iCs', 'caps', 'smallCaps', 'strike', 'dstrike', 'outline', 'shadow', 'emboss',
        'imprint', 'noProof', 'snapToGrid', 'vanish', 'webHidden', 'color', 'spacing', 'w', 'kern', 'position', 'sz', 'szCs',
        'highlight', 'u', 'effect', 'bdr', 'shd', 'fitText', 'vertAlign', 'rtl', 'cs', 'em', 'lang', 'eastAsianLayout',
        'specVanish', 'oMath',
    ];

    private static function docx(Node|Component $body): string
    {
        return Document::make(Page::a4()->body(Column::make($body)))->export(new DocxExporter())->bytes();
    }

    private static function assertRunPropertiesOrdered(string $bytes): void
    {
        $d = DocxInspector::of($bytes);
        $count = 0;
        foreach (['word/document.xml', 'word/styles.xml'] as $part) {
            foreach ($d->xpath($part)->query('//w:rPr') ?: [] as $rPr) {
                self::assertInstanceOf(\DOMElement::class, $rPr);
                $last = -1;
                foreach ($rPr->childNodes as $child) {
                    if (!$child instanceof \DOMElement) {
                        continue;
                    }
                    $at = array_search($child->localName, self::RPR_ORDER, true);
                    self::assertNotFalse($at, 'unknown w:rPr child ' . $child->nodeName);
                    self::assertGreaterThan($last, $at, $child->nodeName . ' out of order in ' . $part);
                    $last = $at;
                    $count++;
                }
            }
        }
        self::assertGreaterThan(0, $count);
    }

    public function test_underlined_and_struck_runs_validate(): void
    {
        $one = self::docx(Markup::html('<p><u>x</u> <s>y</s> <sup>z</sup> <b><u>w</u></b></p>'));
        $two = self::docx(Text::p('x')->underline());
        self::assertRunPropertiesOrdered($one);
        self::assertRunPropertiesOrdered($two);
        OoxmlValidator::assertValid($one);
        OoxmlValidator::assertValid($two);
    }

    public function test_huge_sizes_are_clamped(): void
    {
        $html = '<p><span style="font-size:99999999999px">big</span></p><table><tr><td style="padding:99999999px">c</td></tr></table>';
        $bytes = self::docx(Markup::html($html, new MarkupOptions(styles: StyleMode::Original)));
        $d = DocxInspector::of($bytes);
        $sizes = array_map('intval', $d->values('//w:sz/@w:val'));
        self::assertNotSame([], $sizes);
        foreach ($sizes as $size) {
            self::assertLessThanOrEqual(3276, $size);
        }
        self::assertTwipsWithin($d, ['//w:tcW/@w:w', '//w:tcMar/*/@w:w']);
        OoxmlValidator::assertValid($bytes);
    }

    public function test_large_but_legal_sizes_are_kept_and_validate(): void
    {
        $html = '<p><span style="font-size:400pt">big</span></p><table><tr><td style="padding:2000pt">c</td></tr></table>';
        $bytes = self::docx(Markup::html($html, new MarkupOptions(styles: StyleMode::Original)));
        $d = DocxInspector::of($bytes);
        self::assertContains('800', $d->values('//w:sz/@w:val'), 'a 400 pt font is 800 half points, not clamped');
        self::assertTwipsWithin($d, ['//w:tcW/@w:w', '//w:tcMar/*/@w:w']);
        OoxmlValidator::assertValid($bytes);
    }

    public function test_summed_indents_and_borders_of_a_shaded_box_are_clamped(): void
    {
        $block = \Papercut\Decorator\CodeBlock::make("a\nb")->margin(pt(1000))->padding(pt(1000))->border(pt(500));
        $bytes = self::docx($block);
        $d = DocxInspector::of($bytes);
        self::assertTwipsWithin($d, ['//w:ind/@w:left', '//w:ind/@w:right']);
        $sizes = $d->values('//w:pBdr/*/@w:sz');
        self::assertNotSame([], $sizes);
        foreach ($sizes as $sz) {
            self::assertLessThanOrEqual(96, (int) $sz);
        }
        OoxmlValidator::assertValid($bytes);
    }

    public function test_huge_borders_are_clamped(): void
    {
        $html = '<table><tr><td style="border:500pt solid #000">c</td></tr></table>';
        $bytes = self::docx(Markup::html($html, new MarkupOptions(styles: StyleMode::Original)));
        $d = DocxInspector::of($bytes);
        $sizes = $d->values('//w:tcBorders/*/@w:sz');
        self::assertNotSame([], $sizes);
        foreach ($sizes as $sz) {
            self::assertLessThanOrEqual(96, (int) $sz);
        }
        OoxmlValidator::assertValid($bytes);
    }

    /** @param list<string> $queries */
    private static function assertTwipsWithin(DocxInspector $d, array $queries): void
    {
        $seen = 0;
        foreach ($queries as $q) {
            foreach ($d->values($q) as $w) {
                self::assertLessThanOrEqual(31680, abs((int) $w), $q);
                $seen++;
            }
        }
        self::assertGreaterThan(0, $seen, 'no twip values found to check');
    }
}
