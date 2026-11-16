<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Document, Equation, Page};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Math\Math;
use PHPUnit\Framework\TestCase;

final class MatrixEquationDocxTest extends TestCase
{
    public function test_matrix_exports_as_m_m(): void
    {
        $doc = Document::make(Page::a4()->body(Equation::of(Math::matrix([['a', 'b'], ['c', 'd']]))));
        $xml = DocxInspector::of($doc->export(new DocxExporter())->bytes())->part('word/document.xml');
        self::assertStringContainsString('<m:m>', $xml);
        self::assertSame(2, substr_count($xml, '<m:mr>'));
    }
}
