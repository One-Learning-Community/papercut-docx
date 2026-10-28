<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Papercut\{Document, Image, Page, Place, QrCode, Text};
use Papercut\Export\FidelityLevel;
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Papercut\Style\{Align, Anchor};
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, percent, pt};

final class LayersTest extends TestCase
{
    use Styles;

    private const string ANCHOR = '//wp:anchor';

    /** A 4x2 px PNG: 3pt by 1.5pt at its natural size. */
    private static function png(): string
    {
        $im = imagecreatetruecolor(4, 2);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** @return array{int, int, string, string, string} x and y in EMU, behind, relative-from on each axis */
    private static function placed(DocxInspector $d, int $n = 1): array
    {
        $a = '(' . self::ANCHOR . ")[$n]";

        return [
            (int) $d->value("$a/wp:positionH/wp:posOffset", 'word/header1.xml'),
            (int) $d->value("$a/wp:positionV/wp:posOffset", 'word/header1.xml'),
            (string) $d->value("$a/@behindDoc", 'word/header1.xml'),
            (string) $d->value("$a/wp:positionH/@relativeFrom", 'word/header1.xml'),
            (string) $d->value("$a/wp:positionV/@relativeFrom", 'word/header1.xml'),
        ];
    }

    public function test_a_background_picture_floats_behind_the_text_from_the_header(): void
    {
        $d = self::export(Page::a4()->background(Image::bytes(self::png())->width(percent(100)))->body(Text::make('x')));
        self::assertSame(0, $d->count(self::ANCHOR));
        self::assertSame(1, $d->count(self::ANCHOR, 'word/header1.xml'));
        self::assertSame([0, 0, '1', 'page', 'page'], self::placed($d));
        // The page's full width, at the image's aspect ratio.
        self::assertSame((string) (int) round(595.28 * 12700), $d->value(self::ANCHOR . '/wp:extent/@cx', 'word/header1.xml'));
        self::assertSame([], self::exportedReports());
    }

    public function test_background_pictures_stack_down_the_page(): void
    {
        $d = self::export(Page::a4()->background(
            Image::bytes(self::png())->height(pt(10)),
            QrCode::make('x')->width(mm(20))->margin(pt(5))->alignSelf(Align::Center),
        )->body(Text::make('x')));
        self::assertSame(2, $d->count(self::ANCHOR, 'word/header1.xml'));
        [, $y] = self::placed($d, 2);
        self::assertSame((int) round(15 * 12700), $y);
        [$x] = self::placed($d, 2);
        self::assertSame((int) round((595.28 - mm(20)->points) / 2 * 12700), $x);
    }

    public function test_a_background_with_text_keeps_the_pictures_above_it(): void
    {
        $d = self::export(Page::a4()->background(Image::bytes(self::png()), Text::make('t'), Image::bytes(self::png()))->body(Text::make('x')));
        self::assertSame(1, $d->count(self::ANCHOR, 'word/header1.xml'));
        self::assertSame([
            'Dropped background layer @ page > background > text[1]',
            'Dropped background layer @ page > background > image[2]',
        ], self::exportedReports());
    }

    public function test_an_overlay_picture_sits_at_its_anchor_in_front(): void
    {
        $d = self::export(Page::a4()->body(Text::make('x'))->overlay(
            Place::at(percent(50), mm(30), QrCode::make('x')->width(pt(40)))->anchor(Anchor::Center),
        ));
        self::assertSame([
            (int) round((595.28 / 2 - 20) * 12700),
            (int) round((mm(30)->points - 20) * 12700),
            '0', 'page', 'page',
        ], self::placed($d));
    }

    public function test_an_overlay_place_with_a_width_anchors_its_box(): void
    {
        $d = self::export(Page::a4()->body(Text::make('x'))->overlay(
            Place::at(pt(500), pt(100), Image::bytes(self::png()))->width(pt(100))->anchor(Anchor::TopRight),
        ));
        [$x, $y] = self::placed($d);
        self::assertSame([400 * 12700, 100 * 12700], [$x, $y]);
    }

    public function test_a_percentage_width_in_an_unsized_place_is_a_share_of_the_natural_size(): void
    {
        $d = self::export(Page::a5()->body(Text::make('x'))->overlay(Place::at(pt(10), pt(10), Image::bytes(self::png())->width(percent(50)))));
        // 4 px is 3pt wide by nature, so half of it.
        self::assertSame((string) (int) round(1.5 * 12700), $d->value(self::ANCHOR . '/wp:extent/@cx', 'word/header1.xml'));
    }

    public function test_padding_and_border_size_the_picture_inside_its_box(): void
    {
        $d = self::export(Page::a4()->background(
            Image::bytes(self::png())->width(pt(100))->padding(pt(10)),
            Image::bytes(self::png())->width(pt(50))->border(pt(4), 'black'),
        )->body(Text::make('x')));
        [$x, $y] = self::placed($d);
        self::assertSame([10 * 12700, 10 * 12700], [$x, $y]);
        self::assertSame((string) (80 * 12700), $d->value('(' . self::ANCHOR . ')[1]/wp:extent/@cx', 'word/header1.xml'));
        // The second sits below the first's whole box, inside its own border.
        [$x, $y] = self::placed($d, 2);
        self::assertSame([4 * 12700, 64 * 12700], [$x, $y]);
        // Word can't draw the border.
        self::assertSame(['Dropped border or background on a layer picture @ page > background > image[1]'], self::exportedReports());
    }

    public function test_a_picture_aligns_inside_a_place_with_a_width(): void
    {
        $d = self::export(Page::a4()->body(Text::make('x'))->overlay(
            Place::at(pt(100), pt(100), Image::bytes(self::png())->width(pt(50))->alignSelf(Align::Center))->width(pt(200)),
        ));
        self::assertSame(175 * 12700, self::placed($d)[0]);
    }

    public function test_an_unsized_place_is_as_wide_as_its_picture_asks(): void
    {
        // A height alone doesn't widen the place, so the picture shrinks to its natural width, as in the PDF.
        $d = self::export(Page::a4()->body(Text::make('x'))->overlay(
            Place::at(pt(100), pt(100), Image::bytes(self::png())->height(pt(50)))->anchor(Anchor::BottomRight),
        ));
        self::assertSame([97 * 12700, (int) round(98.5 * 12700)], array_slice(self::placed($d), 0, 2));
    }

    public function test_an_empty_place_is_ignored(): void
    {
        self::export(Page::a4()->body(Text::make('x'))->overlay(Place::at(pt(0), pt(0))), strict: true);
        self::assertSame([], self::exportedReports());
    }

    public function test_overlays_without_a_single_picture_are_dropped(): void
    {
        self::export(Page::a4()->body(Text::make('x'))->overlay(
            Place::at(pt(0), pt(0), Image::bytes(self::png()), Text::make('caption')),
        ));
        self::assertSame(['Dropped overlay layer @ page > overlay > place[0]'], self::exportedReports());
    }

    public function test_layer_pictures_go_in_the_existing_header(): void
    {
        $d = self::export(Page::a4()->header(Text::make('Head'))->background(Image::bytes(self::png()))->body(Text::make('x')));
        self::assertSame(1, $d->count(self::ANCHOR, 'word/header1.xml'));
        self::assertSame('Head', trim($d->bodyText('word/header1.xml')));
    }

    public function test_pages_with_different_layers_get_their_own_sections(): void
    {
        $d = self::export(Document::make(
            Page::a4()->background(Image::bytes(self::png()))->body(Text::make('one')),
            Page::a4()->body(Text::make('two')),
        ));
        self::assertSame(2, $d->count('//w:sectPr'));
        self::assertSame(1, $d->count(self::ANCHOR, 'word/header1.xml'));
    }
}
