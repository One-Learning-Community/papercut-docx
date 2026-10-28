<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\{Documents, DocxInspector};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The XML parts of every fixture and example, canonicalized, against approved copies in tests/golden.
 * Refresh after an intended change: UPDATE_DOCX_GOLDEN=1 vendor/bin/phpunit --filter GoldenDocxTest
 */
final class GoldenDocxTest extends TestCase
{
    private const string DIR = __DIR__ . '/golden';

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        return Documents::all();
    }

    #[DataProvider('documents')]
    public function test_parts_match_the_approved_copies(string $file): void
    {
        $name = $this->dataName();
        \assert(is_string($name));
        $doc = Documents::load($file)->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $docx = DocxInspector::of($doc->export(new DocxExporter())->bytes());
        $dir = self::DIR . '/' . $name;
        $update = getenv('UPDATE_DOCX_GOLDEN') === '1';
        if ($update) {
            array_map('unlink', glob("$dir/*") ?: []);
            @mkdir($dir, 0o777, true);
        }
        $parts = array_values(array_filter($docx->names(), static fn (string $n): bool => preg_match('~^(\[Content_Types\]\.xml|_rels/.+|word/(document|styles|header\d+|footer\d+)\.xml|word/_rels/.+)$~', $n) === 1));
        sort($parts);
        $golden = [];
        foreach ($parts as $part) {
            $file = $dir . '/' . str_replace('/', '__', $part);
            $dom = new \DOMDocument();
            self::assertTrue($dom->loadXML($docx->part($part)), $part);
            $c14n = (string) $dom->C14N();
            if ($update) {
                file_put_contents($file, $c14n);
            }
            self::assertFileExists($file, "No approved copy of $part; run with UPDATE_DOCX_GOLDEN=1 and review it");
            self::assertSame((string) file_get_contents($file), $c14n, $part);
            $golden[] = basename($file);
        }
        $approved = array_map('basename', glob("$dir/*") ?: []);
        sort($approved);
        sort($golden);
        self::assertSame($approved, $golden, 'parts added or removed');
    }
}
