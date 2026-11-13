<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Dskripchenko\PhpDocx\{Document as DocxDocument, Section};
use Dskripchenko\PhpDocx\Element\{Paragraph, Run};
use Dskripchenko\PhpDocx\Style\StyleRegistry;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use Papercut\Docx\Package\{DocxPatcher, PatchSpec};
use Papercut\Docx\Tests\Support\DocxInspector;
use PHPUnit\Framework\TestCase;

final class DocxPatcherMathTest extends TestCase
{
    private const OMML = '<m:oMathPara><m:oMathParaPr><m:jc m:val="left"/></m:oMathParaPr><m:oMath><m:r><m:t>x</m:t></m:r></m:oMath></m:oMathPara>';

    private static function bytes(string ...$texts): string
    {
        $paragraphs = array_values(array_map(static fn (string $t): Paragraph => new Paragraph([new Run($t)]), $texts));

        return (new Word2007Writer(new StyleRegistry()))->write(new DocxDocument(new Section($paragraphs)));
    }

    private static function spec(PatchSpec ...$more): PatchSpec
    {
        return $more[0] ?? new PatchSpec('Inter', 20, null, new \DateTimeImmutable('2000-01-01'));
    }

    public function test_marker_run_is_replaced_by_the_equation(): void
    {
        $spec = self::spec()->withMath(2, self::OMML);
        $d = DocxInspector::of(DocxPatcher::patch(self::bytes('a', DocxPatcher::MATH . '2', 'b'), $spec));
        $xml = $d->part('word/document.xml');
        self::assertStringContainsString(self::OMML . '</w:p>', $xml);
        self::assertStringNotContainsString('papercut-math', $xml);
        self::assertStringContainsString('xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"', $xml);
        self::assertSame(1, substr_count($xml, 'xmlns:m='));
    }

    public function test_unreplaced_marker_throws(): void
    {
        $this->expectException(\LogicException::class);
        DocxPatcher::patch(self::bytes(DocxPatcher::MATH . '7'), self::spec()->withMath(1, self::OMML));
    }

    public function test_a_marker_mangled_inside_longer_text_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Marker-like text was left in');
        DocxPatcher::patch(self::bytes(DocxPatcher::MATH . '1', 'see ' . DocxPatcher::MATH . '1 here'), self::spec()->withMath(1, self::OMML));
    }

    public function test_text_that_looks_like_a_marker_is_left_alone_without_equations(): void
    {
        $d = DocxInspector::of(DocxPatcher::patch(self::bytes(DocxPatcher::MATH . '3'), self::spec()));
        self::assertStringContainsString('papercut-math-3', $d->part('word/document.xml'));
    }

    public function test_block_marker_with_a_sibling_run_throws(): void
    {
        $bytes = (new Word2007Writer(new StyleRegistry()))->write(new DocxDocument(new Section([new Paragraph([new Run('Let '), new Run(DocxPatcher::MATH . '1')])])));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Equation 1');
        DocxPatcher::patch($bytes, self::spec()->withMath(1, self::OMML));
    }

    public function test_inline_marker_may_sit_among_runs(): void
    {
        $bytes = (new Word2007Writer(new StyleRegistry()))->write(new DocxDocument(new Section([new Paragraph([new Run('Let '), new Run(DocxPatcher::MATH . '1'), new Run(' be')])])));
        $d = DocxInspector::of(DocxPatcher::patch($bytes, self::spec()->withMath(1, '<m:oMath/>', true)));
        self::assertStringContainsString('<m:oMath/>', $d->part('word/document.xml'));
    }

    public function test_missing_marker_for_a_declared_equation_throws(): void
    {
        $this->expectException(\LogicException::class);
        DocxPatcher::patch(self::bytes('plain'), self::spec()->withMath(1, self::OMML));
    }

    public function test_with_math_keeps_the_other_fields(): void
    {
        $a = new PatchSpec('Inter', 20, 'en', new \DateTimeImmutable('2000-01-01'), mirrorMargins: true, columnBreaks: 3);
        $b = $a->withMath(1, 'x');
        self::assertTrue($b->mirrorMargins);
        self::assertSame(3, $b->columnBreaks);
        self::assertSame([1 => 'x'], $b->maths);
        self::assertSame([], $a->maths);
    }
}
