<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\Document;
use Papercut\Docx\DocxExporter;
use Papercut\Docx\DocxOptions;
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Export\ExportOptions;
use Papercut\Page;
use Papercut\Text;
use PHPUnit\Framework\TestCase;

final class DocxExporterTest extends TestCase
{
    public function test_exports_a_word_file(): void
    {
        $out = Document::make(Page::a4()->body(Text::make('Hello')))->export(new DocxExporter());

        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $out->mime());
        self::assertSame('docx', $out->extension());
        self::assertStringStartsWith('PK', $out->bytes());
        self::assertStringContainsString('Hello', DocxInspector::of($out->bytes())->bodyText());
    }

    public function test_strict_export_of_scrubbed_text_keeps_the_replacement_character(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make("caf\xff au lait"), \Papercut\Toc::make()->title("t\xff"), Text::h1('H')));
        $out = $doc->export(new DocxExporter(), new DocxOptions(strict: true));
        self::assertStringContainsString("caf\u{FFFD} au lait", DocxInspector::of($out->bytes())->bodyText());
    }

    public function test_default_options_are_docx_options(): void
    {
        self::assertInstanceOf(DocxOptions::class, (new DocxExporter())->defaultOptions());
        $plain = new ExportOptions(strict: true);
        self::assertTrue(DocxOptions::from($plain)->strict());
        $own = new DocxOptions();
        self::assertSame($own, DocxOptions::from($own));
    }
}
