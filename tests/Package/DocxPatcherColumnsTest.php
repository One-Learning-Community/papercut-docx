<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Dskripchenko\PhpDocx\{Document as DocxDocument, Section};
use Dskripchenko\PhpDocx\Element\{Paragraph, Run};
use Dskripchenko\PhpDocx\Style\{RunStyle, SectionStart, StyleRegistry};
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use Papercut\Docx\Package\{DocxPatcher, PatchSpec};
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Value\{Color, ColumnRule, ColumnSpec};
use PHPUnit\Framework\TestCase;

final class DocxPatcherColumnsTest extends TestCase
{
    /** @param array<int, string> $columns */
    private static function patched(array $columns, string $text = 'x'): DocxInspector
    {
        $one = new Section([new Paragraph([new Run($text)])]);
        $two = new Section([new Paragraph([new Run('two')])], start: SectionStart::Continuous);
        $three = new Section([new Paragraph([new Run('three')])], start: SectionStart::Continuous);
        $docx = new DocxDocument($one, followingSections: [$two, $three]);
        $bytes = (new Word2007Writer(new StyleRegistry()))->write($docx);

        return DocxInspector::of(DocxPatcher::patch($bytes, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), columns: $columns)));
    }

    public function test_cols_xml_for_equal_columns(): void
    {
        self::assertSame('<w:cols w:num="2" w:space="720"/>', DocxPatcher::colsXml(ColumnSpec::equal(2), 468.0));
        $rule = new ColumnSpec(3, null, [18.0, 18.0], new ColumnRule(0.5, Color::hex('#000000')));
        self::assertSame('<w:cols w:num="3" w:space="360" w:sep="1"/>', DocxPatcher::colsXml($rule, 468.0));
    }

    public function test_cols_xml_for_shares_and_rule(): void
    {
        $spec = new ColumnSpec(2, [1.0, 2.0], [36.0], new ColumnRule(0.5, Color::hex('#000000')));
        // 468 - 36 = 432 pt to share: 144 and 288 pt.
        self::assertSame(
            '<w:cols w:num="2" w:equalWidth="0" w:sep="1"><w:col w:w="2880" w:space="720"/><w:col w:w="5760"/></w:cols>',
            DocxPatcher::colsXml($spec, 468.0),
        );
        // Equal widths with unequal gaps need the explicit form too.
        $gaps = new ColumnSpec(3, null, [10.0, 20.0]);
        self::assertSame(
            '<w:cols w:num="3" w:equalWidth="0"><w:col w:w="2920" w:space="200"/><w:col w:w="2920" w:space="400"/><w:col w:w="2920"/></w:cols>',
            DocxPatcher::colsXml($gaps, 468.0),
        );
    }

    public function test_inserts_cols_after_pgmar_of_the_right_section(): void
    {
        $cols = '<w:cols w:num="2" w:space="720"/>';
        $d = self::patched([1 => $cols]);
        self::assertSame(1, $d->count('//w:cols'));
        // The second sectPr, in the second paragraph's properties; cols follows pgMar and nothing else.
        self::assertSame(1, $d->count('/w:document/w:body/w:p[2]/w:pPr/w:sectPr/w:pgMar/following-sibling::*[1][self::w:cols]'));
        self::assertSame(0, $d->count('/w:document/w:body/w:p[1]/w:pPr/w:sectPr/w:cols'));
        self::assertSame(0, $d->count('/w:document/w:body/w:sectPr/w:cols'));

        $last = self::patched([2 => $cols]);
        self::assertSame(1, $last->count('/w:document/w:body/w:sectPr/w:pgMar/following-sibling::*[1][self::w:cols]'));
    }

    public function test_throws_when_sectpr_is_missing(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('php-docx output changed: section 5 properties not found');
        self::patched([5 => '<w:cols w:num="2" w:space="720"/>']);
    }

    public function test_column_break_sentinel_becomes_a_column_break(): void
    {
        $one = new Section([
            new Paragraph([new Run('a')]),
            new Paragraph([new Run(DocxPatcher::COLUMN_BREAK, new RunStyle(sizeHalfPoints: 2))]),
            new Paragraph([new Run('b')]),
        ]);
        $bytes = (new Word2007Writer(new StyleRegistry()))->write(new DocxDocument($one));
        $d = DocxInspector::of(DocxPatcher::patch($bytes, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), columnBreaks: 1)));
        self::assertSame(1, $d->count("//w:p/w:r/w:br[@w:type='column']"));
        self::assertSame(0, $d->count("//w:t[contains(., 'papercut')]"));
        self::assertSame(['a', 'b'], $d->values('//w:t'));
    }

    public function test_column_break_in_unexpected_markup_throws(): void
    {
        // A sentinel inside a paragraph that also holds other text isn't a run php-docx writes alone.
        $one = new Section([new Paragraph([new Run('a' . DocxPatcher::COLUMN_BREAK)])]);
        $bytes = (new Word2007Writer(new StyleRegistry()))->write(new DocxDocument($one));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('php-docx output changed: ');
        DocxPatcher::patch($bytes, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), columnBreaks: 1));
    }

    /** @return array<string, array{int}> */
    public static function counts(): array
    {
        return ['without real breaks' => [0], 'with a real break' => [1]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('counts')]
    public function test_body_text_holding_the_sentinel_is_left_alone(int $breaks): void
    {
        $paragraphs = [
            new Paragraph([new Run(DocxPatcher::COLUMN_BREAK)]),
            new Paragraph([new Run('the marker ' . DocxPatcher::COLUMN_BREAK . ' here')]),
        ];
        if ($breaks > 0) {
            $paragraphs[] = new Paragraph([new Run(DocxPatcher::COLUMN_BREAK, new RunStyle(sizeHalfPoints: 2))]);
        }
        $bytes = (new Word2007Writer(new StyleRegistry()))->write(new DocxDocument(new Section($paragraphs)));
        $d = DocxInspector::of(DocxPatcher::patch($bytes, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), columnBreaks: $breaks)));
        self::assertSame($breaks, $d->count("//w:br[@w:type='column']"));
        $texts = $d->values('//w:t');
        self::assertSame([DocxPatcher::COLUMN_BREAK, 'the marker ' . DocxPatcher::COLUMN_BREAK . ' here'], array_slice($texts, 0, 2));
    }

    public function test_fewer_runs_than_counted_fails_loudly(): void
    {
        $bytes = (new Word2007Writer(new StyleRegistry()))->write(new DocxDocument(new Section([new Paragraph([new Run('x')])])));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('column break run not found');
        DocxPatcher::patch($bytes, new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'), columnBreaks: 1));
    }
}
