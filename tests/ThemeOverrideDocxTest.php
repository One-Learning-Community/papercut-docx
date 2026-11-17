<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Column, Document, Page, Text, Toc};
use Papercut\Decorator\ChoiceList;
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Style\{Style, ThemeOverride};
use Papercut\Value\{Color, Length};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ThemeOverrideDocxTest extends TestCase
{
    private static function export(Document $doc): DocxInspector
    {
        return DocxInspector::of($doc->export(new DocxExporter())->bytes());
    }

    public function test_document_override_sets_doc_defaults(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Text::make('Hello')))->theme(new ThemeOverride(fontSize: pt(12))));

        self::assertSame('24', $d->value('//w:docDefaults//w:rPrDefault//w:sz/@w:val', 'word/styles.xml'));
    }

    public function test_page_override_changes_runs_in_its_section_only(): void
    {
        $red = new ThemeOverride(color: Color::hex('#EF4444'));
        $d = self::export(Document::make(
            Page::a4()->body(Text::make('First')),
            Page::a4()->theme($red)->body(Text::make('Second')),
            Page::a4()->body(Text::make('Third')),
        ));

        self::assertSame(['ef4444'], $d->values('//w:r[w:t="Second"]/w:rPr/w:color/@w:val'));
        self::assertNotContains('ef4444', $d->values('//w:r[w:t="First"]/w:rPr/w:color/@w:val'));
        self::assertNotContains('ef4444', $d->values('//w:r[w:t="Third"]/w:rPr/w:color/@w:val'));
    }

    public function test_toc_entries_and_title_use_the_page_that_holds_the_toc(): void
    {
        $d = self::export(Document::make(
            Page::a4()->theme(new ThemeOverride(color: Color::hex('#EF4444')))->body(Toc::make()->title('Contents')),
            Page::a4()->body(Text::h1('Alpha')),
        ));

        self::assertContains('ef4444', $d->values('//w:r[w:t="Contents"]/w:rPr/w:color/@w:val'));
        self::assertNotContains('ef4444', $d->values('//w:p[w:pPr/w:pStyle/@w:val="Heading1"]//w:color/@w:val'));
    }

    public function test_no_overrides_is_byte_identical(): void
    {
        $make = static fn (): Document => Document::make(Page::a4()->body(Text::make('Hello')), Page::a4()->body(Text::h1('Two')));
        $plain = $make()->export(new DocxExporter())->bytes();
        $empty = $make()->theme(new ThemeOverride())->export(new DocxExporter())->bytes();

        self::assertSame($plain, $empty);
    }

    public function test_choice_markers_in_a_sized_variant_keep_their_shape(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Column::make(
            ChoiceList::radio()->variant('large')->option('Yes', true)->option('No'),
            ChoiceList::checkbox()->variant('large')->option('Tea', true)->option('Coffee'),
        )))->theme(new ThemeOverride(styles: ['choice.marker.large' => Style::make(width: Length::em(1), height: Length::em(1))])));

        self::assertSame(['◉', '○', '▣', '☐'], array_values(array_filter(
            $d->values('//w:t'),
            static fn (string $t): bool => in_array($t, ['◉', '○', '▣', '☐'], true),
        )));
        // Sized from the 1em marker (10pt) at the glyph's 1.25 scale: 12.5pt, 25 half-points.
        self::assertSame('25', $d->value('//w:r[w:t="○"]/w:rPr/w:sz/@w:val'));
        // One symbol font for all four, listed in the font table with its PANOSE number for readers without it.
        self::assertSame(['Segoe UI Symbol'], array_values(array_unique($d->values('//w:r[w:t="○" or w:t="☐"]/w:rPr/w:rFonts/@w:ascii'))));
        self::assertSame('020B0502040204020203', $d->value('//w:font[@w:name="Segoe UI Symbol"]/w:panose1/@w:val', 'word/fontTable.xml'));
    }

    public function test_radio_markers_stay_round_up_to_the_largest_marker(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Column::make(
            ChoiceList::radio()->variant('big')->option('Yes', true)->option('No'),
        )))->theme(new ThemeOverride(styles: ['choice.marker.big' => Style::make(width: Length::em(2), height: Length::em(2))])));

        self::assertSame(['◉', '○'], array_values(array_filter($d->values('//w:t'), static fn (string $t): bool => in_array($t, ['◉', '○', '▣', '☐'], true))));
    }
}
