<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Papercut\{Column, Document, Page, Text};
use Papercut\Docx\Package\{DocxPatcher, PatchSpec};
use Papercut\Docx\Tests\Support\Styles;
use PHPUnit\Framework\TestCase;

final class DocxPatcherTest extends TestCase
{
    use Styles;

    public function test_alignment_is_written_as_left_and_right(): void
    {
        $page = Page::a4()->header(Text::make('h')->right())->body(Column::make(Text::make('r')->right(), Text::make('l')->left()));
        $d = self::export($page);
        foreach (['word/document.xml', 'word/header1.xml'] as $part) {
            self::assertSame(0, $d->count("//w:jc[@w:val='start' or @w:val='end']", $part), $part);
        }
        self::assertSame(1, $d->count("//w:p[.//w:t='r']/w:pPr/w:jc[@w:val='right']"));
        self::assertSame(1, $d->count("//w:jc[@w:val='right']", 'word/header1.xml'));
    }

    public function test_doc_defaults_take_the_theme_font_and_the_language(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Text::make('x')))->lang('en-GB'));
        $styles = 'word/styles.xml';
        self::assertSame('Inter', $d->value('//w:docDefaults//w:rFonts/@w:ascii', $styles));
        self::assertSame('20', $d->value('//w:docDefaults//w:sz/@w:val', $styles));
        self::assertSame('en-GB', $d->value('//w:docDefaults//w:lang/@w:val', $styles));
        self::assertSame([], self::exportedReports());

        $d = self::export(Page::a4()->body(Text::make('x')));
        self::assertSame(0, $d->count('//w:docDefaults//w:lang', $styles));
    }

    public function test_a_missing_anchor_fails_loudly(): void
    {
        $bytes = Document::make(Page::a4()->body(Text::make('x')))->export(new \Papercut\Docx\DocxExporter())->bytes();
        $zip = new \ZipArchive();
        $file = (string) tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($file, $bytes);
        $zip->open($file);
        $zip->addFromString('word/styles.xml', '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $zip->close();
        $broken = (string) file_get_contents($file);
        unlink($file);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('php-docx output changed: ');
        DocxPatcher::patch($broken, new PatchSpec(11906, 16838, 'Inter', 20, null, new \DateTimeImmutable('2000-01-01')));
    }

    public function test_a_shading_marker_left_unpatched_fails_loudly(): void
    {
        $bytes = Document::make(Page::a4()->body(Text::make('x')))->export(new \Papercut\Docx\DocxExporter())->bytes();
        $zip = new \ZipArchive();
        $file = (string) tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($file, $bytes);
        $zip->open($file);
        $doc = (string) $zip->getFromName('word/document.xml');
        // as if php-docx wrote the highlight another way
        $zip->addFromString('word/document.xml', str_replace('<w:t', '<w:rPr><w:highlight w:val="' . DocxPatcher::shading('ffff00') . '"></w:highlight></w:rPr><w:t', $doc));
        $zip->close();
        $changed = (string) file_get_contents($file);
        unlink($file);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('php-docx output changed: run highlight in word/document.xml not found');
        DocxPatcher::patch($changed, new PatchSpec(11906, 16838, 'Inter', 20, null, new \DateTimeImmutable('2000-01-01')));
    }
}
