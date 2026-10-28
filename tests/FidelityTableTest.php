<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Column, Document, Image, Link, Page, PdfPage, Place, Row, Span, Spacer, Text, View};
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\FidelityLevel;
use Papercut\Style\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** One row per approximated (≈) or dropped (✗) DOCX row of the spec's capability table, plus this package's own. */
final class FidelityTableTest extends TestCase
{
    use Styles;


    private static function pdf(): string
    {
        return Document::make(Page::a4()->body('t'))->toPdf()->bytes();
    }

    /** @return iterable<string, array{\Closure(): (Document|Page|Text|View|Image|Link), FidelityLevel, string, string}> */
    public static function rows(): iterable
    {
        $body = 'page > body';
        yield 'later page setup' => [static fn () => Document::make(Page::a4()->body('a'), Page::a5()->body('b')), FidelityLevel::Approximated, "page setup other than the first page's", 'page[1]'];
        yield 'later header' => [static fn () => Document::make(Page::a4()->body('a'), Page::a4()->header('h')->body('b')), FidelityLevel::Approximated, "header or footer other than the first page's", 'page[1]'];
        yield 'background' => [static fn () => Page::a4()->background('bg')->body('x'), FidelityLevel::Dropped, 'background layer', 'page > background'];
        yield 'overlay' => [static fn () => Page::a4()->body('x')->overlay(Place::at(pt(0), pt(0), 'o')), FidelityLevel::Dropped, 'overlay layer', 'page > overlay'];
        yield 'pdf template' => [static fn () => Page::template(PdfPage::bytes(self::pdf()))->body('x'), FidelityLevel::Dropped, 'PDF page template', 'page'];
        yield 'min-lines' => [static fn () => Text::make('x')->minLines(3), FidelityLevel::Approximated, 'min-lines 3', "$body > text[0]"];
        yield 'wrap' => [static fn () => Row::make(View::make('a')->width(pt(300)), View::make('b')->width(pt(300)))->wrap(), FidelityLevel::Approximated, 'wrap', "$body > row[0]"];
        yield 'flexible spacer' => [static fn () => Column::make('a', Spacer::flex(), 'b'), FidelityLevel::Approximated, 'flexible spacer', "$body > column[0] > spacer[1]"];
        yield 'fixed height' => [static fn () => Column::make('a')->padding(pt(2))->height(pt(30)), FidelityLevel::Approximated, 'fixed height', "$body > column[0]"];
        yield 'radius' => [static fn () => Column::make('a')->padding(pt(2))->radius(pt(3)), FidelityLevel::Dropped, 'radius', "$body > column[0]"];
        yield 'font weight' => [static fn () => Text::make('a')->weight(500), FidelityLevel::Approximated, 'font weight 500', "$body > text[0]"];
        yield 'block link' => [static fn () => Link::to('https://x.test', Text::make('a')), FidelityLevel::Approximated, 'block link', "$body > link[0]"];
        yield 'pdf page' => [static fn () => Column::make(PdfPage::bytes(self::pdf())), FidelityLevel::Dropped, 'pdf-page', "$body > column[0] > pdf-page[0]"];
    }

    /** @param \Closure(): (Document|Page|Text|View|Image|Link) $build */
    #[DataProvider('rows')]
    public function test_exactly_one_report(\Closure $build, FidelityLevel $level, string $feature, string $path): void
    {
        self::export($build());
        self::assertCount(1, self::$exported, implode("\n", self::exportedReports()));
        $r = self::$exported[0];
        self::assertSame([$level, 'docx', $feature, $path], [$r->level, $r->format, $r->feature, $r->path]);
    }

    /** @param \Closure(): (Document|Page|Text|View|Image|Link) $build */
    #[DataProvider('rows')]
    public function test_drops_throw_in_strict_mode(\Closure $build, FidelityLevel $level, string $feature, string $path): void
    {
        if ($level !== FidelityLevel::Dropped) {
            self::export($build(), strict: true);
            self::assertCount(1, self::$exported);

            return;
        }
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage("Strict mode: docx cannot render $feature at $path");
        self::export($build(), strict: true);
    }

    public function test_colours_with_alpha_are_blended_silently(): void
    {
        $d = self::export(Column::make('a')->padding(pt(2))->background('rgba(255, 0, 0, 0.5)'));
        self::assertSame('ff8080', $d->value('//w:shd/@w:fill'));
        self::assertSame([], self::exportedReports());
    }
}
