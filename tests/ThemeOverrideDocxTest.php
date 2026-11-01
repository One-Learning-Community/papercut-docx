<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Document, Page, Text, Toc};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Style\ThemeOverride;
use Papercut\Value\Color;
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
}
