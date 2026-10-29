<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Papercut\{Document, Page, Text};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Package\{DocxPatcher, PatchSpec};
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Export\ExportOptions;
use Papercut\Text\{FontClass, FontInfo, FontSet};
use PHPUnit\Framework\TestCase;

final class FontTableTest extends TestCase
{
    private const string REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable';
    private const string TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml';

    private static function export(Document $doc, ?FontSet $fonts = null): DocxInspector
    {
        return DocxInspector::of($doc->export(new DocxExporter(), new ExportOptions(fonts: $fonts))->bytes());
    }

    private static function seeded(string $table): string
    {
        $raw = (new \Dskripchenko\PhpDocx\Writer\Word2007Writer(new \Dskripchenko\PhpDocx\Style\StyleRegistry()))->write(
            new \Dskripchenko\PhpDocx\Document(new \Dskripchenko\PhpDocx\Section([new \Dskripchenko\PhpDocx\Element\Paragraph([new \Dskripchenko\PhpDocx\Element\Run('x')])])),
        );
        $file = (string) tempnam(sys_get_temp_dir(), 'docx');
        try {
            file_put_contents($file, $raw);
            $zip = new \ZipArchive();
            $zip->open($file);
            $zip->addFromString('word/fontTable.xml', $table);
            $rels = (string) $zip->getFromName('word/_rels/document.xml.rels');
            $zip->addFromString('word/_rels/document.xml.rels', str_replace('</Relationships>', '<Relationship Id="rId99" Type="' . self::REL . '" Target="fontTable.xml"/></Relationships>', $rels));
            $types = (string) $zip->getFromName('[Content_Types].xml');
            $zip->addFromString('[Content_Types].xml', str_replace('</Types>', '<Override PartName="/word/fontTable.xml" ContentType="' . self::TYPE . '"/></Types>', $types));
            $zip->close();

            return (string) file_get_contents($file);
        } finally {
            unlink($file);
        }
    }

    public function test_runs_use_word_name(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('hello'), Text::make('bold')->bold()));
        $d = self::export($doc, (new FontSet())->withWordName('Inter', 'Arial'));

        self::assertGreaterThan(0, $d->count('//w:rFonts', 'word/document.xml'));
        self::assertSame(0, $d->count("//w:rFonts[@w:ascii!='Arial' or @w:hAnsi!='Arial']", 'word/document.xml'));
        self::assertSame('Arial', $d->value('//w:docDefaults//w:rFonts/@w:ascii', 'word/styles.xml'));
        self::assertStringNotContainsString('Inter', $d->part('word/document.xml'));
        self::assertStringNotContainsString('Inter', $d->part('word/styles.xml'));
        self::assertSame(1, $d->count("//w:font[@w:name='Arial']", 'word/fontTable.xml'));
        self::assertSame(0, $d->count("//w:font[@w:name='Inter']", 'word/fontTable.xml'));
    }

    public function test_font_table_lists_written_fonts(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Text::make('hello'))));
        $t = 'word/fontTable.xml';
        self::assertSame(1, $d->count('//w:font', $t));
        self::assertSame(1, $d->count("//w:font[@w:name='Inter']", $t));
        self::assertMatchesRegularExpression('/^[0-9A-F]{20}$/', (string) $d->value("//w:font[@w:name='Inter']/w:panose1/@w:val", $t));
        self::assertSame('00', $d->value('//w:font/w:charset/@w:val', $t));
        self::assertSame('swiss', $d->value('//w:font/w:family/@w:val', $t));
        self::assertSame('variable', $d->value('//w:font/w:pitch/@w:val', $t));

        $d = self::export(Document::make(Page::a4()->body(Text::make('hello'), Text::make('code')->font('Cousine'))));
        self::assertSame(2, $d->count('//w:font', $t));
        self::assertSame('modern', $d->value("//w:font[@w:name='Courier New']/w:family/@w:val", $t));
        self::assertSame('fixed', $d->value("//w:font[@w:name='Courier New']/w:pitch/@w:val", $t));
        self::assertSame(0, $d->count("//w:font[@w:name='Cousine']", $t));
    }

    public function test_font_table_relationship_and_content_type(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Text::make('x'))));
        self::assertSame(1, substr_count($d->part('word/_rels/document.xml.rels'), 'Type="' . self::REL . '" Target="fontTable.xml"'));
        self::assertStringContainsString('<Override PartName="/word/fontTable.xml" ContentType="' . self::TYPE . '"/>', $d->part('[Content_Types].xml'));
    }

    public function test_existing_font_table_is_filled_not_duplicated(): void
    {
        $seeded = self::seeded('<w:fonts xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:font w:name="Symbol"/></w:fonts>');

        $fonts = ['Inter' => new FontInfo(FontClass::Sans, '02000503000000000000', false), 'Symbol' => null];
        $d = DocxInspector::of(DocxPatcher::patch($seeded, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), fonts: $fonts)));

        self::assertSame(2, $d->count('//w:font', 'word/fontTable.xml'));
        self::assertSame(1, $d->count("//w:font[@w:name='Symbol']", 'word/fontTable.xml'));
        self::assertSame(1, $d->count("//w:font[@w:name='Inter']", 'word/fontTable.xml'));
        self::assertSame(1, substr_count($d->part('word/_rels/document.xml.rels'), self::REL));
        self::assertSame(1, substr_count($d->part('[Content_Types].xml'), '/word/fontTable.xml'));
    }

    /** @return array<string, array{string}> */
    public static function existingTables(): array
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

        return [
            'single quotes' => ["<w:fonts $w><w:font w:name='Inter'/></w:fonts>"],
            'other attributes and spacing' => ["<w:fonts $w><w:font w:x=\"1\"  w:name = \"Inter\" /></w:fonts>"],
            'self-closing root' => ["<w:fonts $w/>"],
            'self-closing root with space' => ["<w:fonts $w />"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('existingTables')]
    public function test_existing_table_forms(string $table): void
    {
        $d = DocxInspector::of(DocxPatcher::patch(self::seeded($table), new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), fonts: ['Inter' => new FontInfo(FontClass::Sans, null, false), 'Other' => null])));

        self::assertSame(1, $d->count("//w:font[@w:name='Inter']", 'word/fontTable.xml'));
        self::assertSame(1, $d->count("//w:font[@w:name='Other']", 'word/fontTable.xml'));
        self::assertSame(1, substr_count($d->part('word/_rels/document.xml.rels'), self::REL));
    }

    public function test_existing_entry_with_an_escaped_name_is_not_duplicated(): void
    {
        $seeded = self::seeded('<w:fonts xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:font w:name="A &amp; B"/></w:fonts>');
        $name = (new FontSet())->withWordName('Inter', 'A & B')->wordName('Inter');

        $d = DocxInspector::of(DocxPatcher::patch($seeded, new PatchSpec($name, 20, null, new \DateTimeImmutable('2000-01-01'), fonts: [$name => new FontInfo(FontClass::Sans, null, false)])));

        self::assertSame(1, $d->count('//w:font', 'word/fontTable.xml'));
        self::assertSame(1, $d->count('//w:font[@w:name="A & B"]', 'word/fontTable.xml'));
    }

    public function test_word_name_is_escaped(): void
    {
        $name = 'A & "B"';
        $d = self::export(Document::make(Page::a4()->body(Text::make('x'))), (new FontSet())->withWordName('Inter', $name));

        self::assertSame(1, $d->count('//w:font[@w:name=\'A & "B"\']', 'word/fontTable.xml'));
        self::assertSame($name, $d->value('//w:docDefaults//w:rFonts/@w:ascii', 'word/styles.xml'));
        self::assertSame($name, $d->value('//w:r/w:rPr/w:rFonts/@w:ascii'));
    }

    public function test_font_without_info_gets_auto(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Text::make('x')->font('Georgia'))));
        $t = 'word/fontTable.xml';
        self::assertSame('auto', $d->value("//w:font[@w:name='Georgia']/w:family/@w:val", $t));
        self::assertSame('variable', $d->value("//w:font[@w:name='Georgia']/w:pitch/@w:val", $t));
        self::assertSame(0, $d->count("//w:font[@w:name='Georgia']/w:panose1", $t));
    }
}
