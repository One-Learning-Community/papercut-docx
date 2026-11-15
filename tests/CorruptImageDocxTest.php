<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Document, Page};
use Papercut\Docx\{DocxExporter, DocxOptions};
use Papercut\Markup\{Markup, MarkupOptions};
use PHPUnit\Framework\TestCase;

/** A corrupt resolver image is a missing one in Word too: no exception, no PHP diagnostic. */
final class CorruptImageDocxTest extends TestCase
{
    public function test_corrupt_resolver_images_export_without_a_diagnostic(): void
    {
        $diagnostics = [];
        set_error_handler(static function (int $no, string $msg) use (&$diagnostics): bool {
            $diagnostics[] = $msg;
            return true;
        });
        try {
            $dir = __DIR__ . '/../../core/tests/fixtures/images/';
            $cases = ["\xFF\xD8\xFF" . str_repeat("\x01", 80), "\x89PNG\r\n\x1a\n" . str_repeat("\x02", 80), 'garbage'];
            foreach (['gif_garbage.gif', 'gif_cut.gif', 'webp_garbage.webp', 'anim.webp', 'bmp_cut.bmp', 'fill.jpg'] as $f) {
                $cases[] = (string) file_get_contents($dir . $f);
            }
            foreach ($cases as $bytes) {
                $warnings = [];
                $doc = Document::make(Page::a4()->body(Markup::html('<p>a<img src="x.jpg" alt="Chart"><img src="y.png"></p>', new MarkupOptions(images: static fn (string $s): string => $bytes))));
                self::assertNotSame('', $doc->export(new DocxExporter(), new DocxOptions(onWarning: static function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                }))->bytes());
                self::assertCount(1, array_filter($warnings, static fn (string $m): bool => str_contains($m, 'shown as its alt text')));
            }
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $diagnostics);
    }
}
