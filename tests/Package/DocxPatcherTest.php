<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Papercut\{Column, Document, Page, Text};
use Papercut\Docx\Package\{DocxPatcher, Numbering, PatchSpec};
use Papercut\Style\ListMarker;
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Dskripchenko\PhpDocx\{Document as DocxDocument, Section};
use Dskripchenko\PhpDocx\Element\{Paragraph, Run};
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\StyleRegistry;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
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
        DocxPatcher::patch($broken, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01')));
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
        DocxPatcher::patch($changed, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01')));
    }

    private const string MIRROR = '<w:mirrorMargins/>';

    private static function spec(bool $mirror): PatchSpec
    {
        return new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), $mirror);
    }

    /** @param array<string, ?string> $changes part => new content (null removes it) */
    private static function rewrite(string $bytes, array $changes): string
    {
        $zip = new \ZipArchive();
        $file = (string) tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($file, $bytes);
        $zip->open($file);
        foreach ($changes as $name => $content) {
            $content === null ? $zip->deleteName($name) : $zip->addFromString($name, $content);
        }
        $zip->close();
        $out = (string) file_get_contents($file);
        unlink($file);

        return $out;
    }

    private static function part(string $bytes, string $name): string
    {
        return DocxInspector::of($bytes)->part($name);
    }

    /** php-docx's own output, as DocxPatcher receives it. */
    private static function raw(?Section $section = null): string
    {
        $section ??= new Section([new Paragraph([new Run('x')])]);

        return (new Word2007Writer(new StyleRegistry()))->write(new DocxDocument($section));
    }

    private static function plain(): string
    {
        return self::raw();
    }

    private static function withEven(): string
    {
        return self::raw(new Section([new Paragraph([new Run('x')])], evenHeader: [new Paragraph([new Run('e')])]));
    }

    public function test_mirror_margins_creates_the_settings_part(): void
    {
        $out = DocxPatcher::patch(self::plain(), self::spec(true));
        $d = DocxInspector::of($out);
        self::assertStringContainsString(self::MIRROR, $d->part('word/settings.xml'));
        self::assertStringContainsString('<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">', $d->part('word/settings.xml'));
        $rels = $d->part('word/_rels/document.xml.rels');
        self::assertStringContainsString('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"', $rels);
        self::assertStringContainsString('<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>', $d->part('[Content_Types].xml'));
        preg_match_all('~Id="([^"]+)"~', $rels, $m);
        self::assertSame($m[1], array_values(array_unique($m[1])));
    }

    public function test_mirror_margins_goes_before_even_and_odd_headers(): void
    {
        $out = DocxPatcher::patch(self::withEven(), self::spec(true));
        self::assertStringContainsString('<w:mirrorMargins/><w:evenAndOddHeaders/>', self::part($out, 'word/settings.xml'));
        self::assertSame(1, substr_count(self::part($out, 'word/_rels/document.xml.rels'), 'relationships/settings"'));
    }

    public function test_mirror_margins_off_leaves_settings_alone(): void
    {
        $base = new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'));
        foreach ([self::plain(), self::withEven()] as $bytes) {
            $off = DocxPatcher::patch($bytes, self::spec(false));
            self::assertSame(DocxPatcher::patch($bytes, $base), $off);
            $settings = DocxInspector::of($off);
            if ($settings->has('word/settings.xml')) {
                self::assertStringNotContainsString('w:mirrorMargins', $settings->part('word/settings.xml'));
            }
            self::assertStringContainsString('w:mirrorMargins', self::part(DocxPatcher::patch($bytes, self::spec(true)), 'word/settings.xml'));
        }
        self::assertFalse(DocxInspector::of(DocxPatcher::patch(self::plain(), self::spec(false)))->has('word/settings.xml'));
    }

    /** @return array<string, array{string}> */
    public static function existingMirrorForms(): array
    {
        return ['plain' => ['<w:mirrorMargins/>'], 'with a value' => ['<w:mirrorMargins w:val="1"/>'], 'with a space' => ['<w:mirrorMargins />']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('existingMirrorForms')]
    public function test_mirror_margins_is_not_added_twice(string $existing): void
    {
        $bytes = self::withEven();
        $settings = str_replace('<w:evenAndOddHeaders/>', $existing . '<w:evenAndOddHeaders/>', self::part($bytes, 'word/settings.xml'));
        $out = DocxPatcher::patch(self::rewrite($bytes, ['word/settings.xml' => $settings]), self::spec(true));
        self::assertSame(1, substr_count(self::part($out, 'word/settings.xml'), 'w:mirrorMargins'));
    }

    /** @return array<string, array{string, string}> settings body and what must follow w:mirrorMargins */
    public static function settingsBodies(): array
    {
        $open = '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';

        return [
            'without even and odd headers' => ['<w:defaultTabStop w:val="720"/>', $open . '<w:mirrorMargins/><w:defaultTabStop w:val="720"/>'],
            'after the zoom and view it follows' => ['<w:view w:val="print"/><w:zoom w:percent="100"/><w:defaultTabStop w:val="720"/>', $open . '<w:view w:val="print"/><w:zoom w:percent="100"/><w:mirrorMargins/><w:defaultTabStop w:val="720"/>'],
            'before later settings' => ['<w:evenAndOddHeaders/><w:compat/>', $open . '<w:mirrorMargins/><w:evenAndOddHeaders/><w:compat/>'],
            'in an empty element' => ['', '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:mirrorMargins/></w:settings>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('settingsBodies')]
    public function test_mirror_margins_is_placed_by_schema_order_in_any_settings(string $body, string $expected): void
    {
        $bytes = self::withEven();
        $open = '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
        $settings = $body === '' ? '<?xml version="1.0"?>' . substr($open, 0, -1) . '/>' : '<?xml version="1.0"?>' . $open . $body . '</w:settings>';
        $out = DocxPatcher::patch(self::rewrite($bytes, ['word/settings.xml' => $settings]), self::spec(true));
        self::assertStringContainsString($expected, self::part($out, 'word/settings.xml'));
        self::assertSame(1, substr_count(self::part($out, 'word/settings.xml'), 'w:mirrorMargins'));
    }

    public function test_mirror_patch_fails_loudly_when_markup_changes(): void
    {
        $cases = [
            [self::plain(), 'word/_rels/document.xml.rels', '</Relationships>'],
            [self::plain(), '[Content_Types].xml', '</Types>'],
            [self::withEven(), 'word/settings.xml', '<w:settings'],
        ];
        foreach ($cases as [$bytes, $name, $remove]) {
            $broken = self::rewrite($bytes, [$name => str_replace($remove, '', self::part($bytes, $name))]);
            try {
                DocxPatcher::patch($broken, self::spec(true));
                self::fail('expected a LogicException for ' . $remove);
            } catch (\LogicException $e) {
                self::assertStringContainsString('php-docx output changed', $e->getMessage());
            }
        }
    }

    public function test_add_part_refuses_a_name_that_already_exists(): void
    {
        $add = new \ReflectionMethod(DocxPatcher::class, 'addPart');
        try {
            $add->invoke(null, ['word/settings.xml' => '<w:settings/>'], 'word/settings.xml', '<x/>', 'type', 'content/type');
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertSame('php-docx output changed: word/settings.xml already exists', $e->getMessage());
        }
    }

    private static function numSpec(?Numbering $n): PatchSpec
    {
        return new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), false, $n);
    }

    private static function numbering(): Numbering
    {
        $n = new Numbering();
        $n->instance(ListMarker::Decimal, 1);

        return $n;
    }

    private static function marked(?string $styleId = null, ?ParagraphStyle $style = null): string
    {
        return self::raw(new Section([new Paragraph([new Run('x')], $style ?? new ParagraphStyle(), null, $styleId ?? Numbering::marker(1, 0, null))]));
    }

    public function test_numbering_part_is_created(): void
    {
        $d = DocxInspector::of(DocxPatcher::patch(self::marked(), self::numSpec(self::numbering())));
        self::assertStringContainsString('<w:abstractNum ', $d->part('word/numbering.xml'));
        $rels = $d->part('word/_rels/document.xml.rels');
        self::assertStringContainsString('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"', $rels);
        self::assertStringContainsString('<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>', $d->part('[Content_Types].xml'));
        preg_match_all('~Id="([^"]+)"~', $rels, $m);
        self::assertSame($m[1], array_values(array_unique($m[1])));
    }

    public function test_num_pr_goes_after_widow_control(): void
    {
        $out = DocxPatcher::patch(self::marked(null, new ParagraphStyle(widowControl: true, spaceAfterTwips: 100)), self::numSpec(self::numbering()));
        $doc = self::part($out, 'word/document.xml');
        self::assertStringContainsString('<w:pPr><w:widowControl/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr><w:spacing', $doc);
        self::assertStringNotContainsString('papercut-num-', $doc);
        self::assertStringNotContainsString('<w:pStyle', substr($doc, strpos($doc, '<w:body>') ?: 0, 200));
    }

    public function test_num_pr_goes_after_style_and_every_keep_element(): void
    {
        $style = new ParagraphStyle(keepWithNext: true, keepLines: true, pageBreakBefore: true, widowControl: true, spaceAfterTwips: 100);
        $raw = self::raw(new Section([new Paragraph([new Run('x')], $style, null, Numbering::marker(1, 0, 2))]));
        $out = DocxPatcher::patch($raw, self::numSpec(self::numbering()));
        self::assertStringContainsString('<w:pPr><w:pStyle w:val="Heading2"/><w:keepNext/><w:keepLines/><w:pageBreakBefore/><w:widowControl/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr><w:spacing', self::part($out, 'word/document.xml'));
    }

    public function test_text_mentioning_the_marker_prefix_is_fine(): void
    {
        $raw = self::raw(new Section([new Paragraph([new Run('see papercut-num-3-0')]), new Paragraph([new Run('x')], new ParagraphStyle(), null, Numbering::marker(1, 0, null))]));
        $out = DocxPatcher::patch($raw, self::numSpec(self::numbering()));
        self::assertStringContainsString('see papercut-num-3-0', self::part($out, 'word/document.xml'));
    }

    public function test_text_with_the_quoted_marker_survives(): void
    {
        $raw = self::raw(new Section([new Paragraph([new Run('see w:val="papercut-num-1-0"')]), new Paragraph([new Run('x')], new ParagraphStyle(), null, Numbering::marker(1, 0, null))]));
        $out = DocxPatcher::patch($raw, self::numSpec(self::numbering()));
        $doc = self::part($out, 'word/document.xml');
        self::assertStringContainsString('see w:val=&quot;papercut-num-1-0&quot;', $doc);
        self::assertSame(1, substr_count($doc, '<w:numPr>'));
    }

    public function test_unregistered_num_id_fails_loudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('php-docx output changed: list paragraph refers to unregistered numId 9');
        DocxPatcher::patch(self::marked('papercut-num-9-0'), self::numSpec(self::numbering()));
    }

    public function test_num_id_zero_fails_loudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('php-docx output changed: list paragraph refers to unregistered numId 0');
        DocxPatcher::patch(self::marked('papercut-num-0-0'), self::numSpec(self::numbering()));
    }

    public function test_heading_marker_restores_heading_style(): void
    {
        $n = self::numbering();
        $out = DocxPatcher::patch(self::marked(Numbering::marker(1, 0, 2)), self::numSpec($n));
        self::assertStringContainsString('<w:pPr><w:pStyle w:val="Heading2"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>', self::part($out, 'word/document.xml'));
    }

    public function test_markers_in_headers_are_patched(): void
    {
        $raw = self::raw(new Section([new Paragraph([new Run('b')])], header: [new Paragraph([new Run('h')], new ParagraphStyle(), null, Numbering::marker(1, 2, null))]));
        $out = DocxPatcher::patch($raw, self::numSpec(self::numbering()));
        self::assertStringContainsString('<w:pPr><w:numPr><w:ilvl w:val="2"/><w:numId w:val="1"/></w:numPr>', self::part($out, 'word/header1.xml'));
        self::assertStringNotContainsString('papercut-num-', self::part($out, 'word/header1.xml'));
    }

    public function test_leftover_marker_fails_loudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('php-docx output changed: list paragraph style in word/document.xml not found');
        DocxPatcher::patch(self::marked('papercut-num-odd'), self::numSpec(self::numbering()));
    }

    public function test_existing_numbering_part_fails_loudly(): void
    {
        $raw = self::rewrite(self::marked(), ['word/numbering.xml' => '<x/>']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('php-docx output changed');
        DocxPatcher::patch($raw, self::numSpec(self::numbering()));
    }

    public function test_no_numbering_leaves_bytes_unchanged(): void
    {
        $bytes = self::plain();
        $base = DocxPatcher::patch($bytes, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01')));
        self::assertSame($base, DocxPatcher::patch($bytes, self::numSpec(null)));
        self::assertSame($base, DocxPatcher::patch($bytes, self::numSpec(new Numbering())));
    }
}
