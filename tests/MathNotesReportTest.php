<?php

declare(strict_types=1);

namespace Quoin\Docx\Tests;

use Quoin\{Document, Equation, Page};
use Quoin\Docx\{DocxExporter, DocxOptions};
use Quoin\Math\Math;
use PHPUnit\Framework\TestCase;

final class MathNotesReportTest extends TestCase
{
    public function test_docx_export_reports_what_mathml_input_lost(): void
    {
        $messages = [];
        $doc = Document::make(Page::a4()->body(Equation::of(Math::mathml('<math><mtable rowlines="solid"><mtr><mtd><mi>a</mi></mtd></mtr></mtable></math>'))));
        $doc->export(new DocxExporter(), new DocxOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        $math = array_values(array_filter($messages, static fn (string $m): bool => str_contains($m, 'math <mtable>')));
        self::assertCount(1, $math);
        self::assertStringStartsWith('docx: ', $math[0]);
    }
}
