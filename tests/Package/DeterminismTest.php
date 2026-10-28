<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Papercut\Document;
use Papercut\Docx\DocxExporter;
use PHPUnit\Framework\TestCase;

final class DeterminismTest extends TestCase
{
    private static function invoice(): Document
    {
        return require __DIR__ . '/../../../../examples/core/documents/invoice.php';
    }

    public function test_the_same_document_gives_the_same_bytes(): void
    {
        $a = self::invoice()->export(new DocxExporter())->bytes();
        usleep(1_100_000);
        $b = self::invoice()->export(new DocxExporter())->bytes();
        self::assertSame(md5($a), md5($b));
    }

    public function test_dated_documents_are_deterministic_too(): void
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $a = self::invoice()->createdAt($at)->export(new DocxExporter())->bytes();
        $b = self::invoice()->createdAt($at)->export(new DocxExporter())->bytes();
        self::assertSame(md5($a), md5($b));
    }
}
