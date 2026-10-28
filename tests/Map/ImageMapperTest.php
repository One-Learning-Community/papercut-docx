<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\{Image, QrCode, Svg};
use Papercut\Backend\DefaultBackend;
use Papercut\Barcode\QrShape;
use Papercut\Docx\Map\{ImageMapper, Raster};
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Papercut\Docx\Walk\Frame;
use Papercut\Export\Fidelity;
use Papercut\Style\ImageFit;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

#[RequiresPhpExtension('gd')]
final class ImageMapperTest extends TestCase
{
    use Styles;

    /**
     * @param positive-int $w
     * @param positive-int $h
     */
    private static function picture(string $type, int $w = 80, int $h = 40): string
    {
        $im = imagecreatetruecolor($w, $h);
        \assert($im !== false);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 120, 200));
        ob_start();
        match ($type) {
            'png' => imagepng($im),
            'jpeg' => imagejpeg($im),
            'webp' => imagewebp($im),
            default => throw new \LogicException($type),
        };

        return (string) ob_get_clean();
    }

    public function test_png_and_jpeg_at_natural_size(): void
    {
        foreach (['png', 'jpeg'] as $type) {
            $d = self::export(Image::bytes(self::picture($type)));
            self::assertSame((string) (60 * 12700), $d->value('//wp:inline/wp:extent/@cx'), $type);
            self::assertSame((string) (30 * 12700), $d->value('//wp:inline/wp:extent/@cy'), $type);
        }
    }

    public function test_explicit_width_keeps_the_aspect_ratio(): void
    {
        $d = self::export(Image::bytes(self::picture('png'))->width(mm(50)));
        $cx = (int) $d->value('//wp:inline/wp:extent/@cx');
        $cy = (int) $d->value('//wp:inline/wp:extent/@cy');
        self::assertEqualsWithDelta(mm(50)->points * 12700, $cx, 1);
        self::assertEqualsWithDelta($cx / 2, $cy, 1);
    }

    /** The one embedded picture's bytes. */
    private static function media(DocxInspector $d): string
    {
        $names = array_values(array_filter($d->names(), static fn (string $n): bool => str_starts_with($n, 'word/media/')));
        self::assertCount(1, $names);

        return $d->part($names[0]);
    }

    /** @return array{int, int} */
    private static function dims(string $bytes): array
    {
        $s = getimagesizefromstring($bytes);
        self::assertNotFalse($s);

        return [$s[0], $s[1]];
    }

    private static function mapper(Raster $raster): ImageMapper
    {
        return new ImageMapper(self::$fidelity = self::fidelity(), DefaultBackend::make(false), $raster);
    }

    private static Fidelity $fidelity;

    public function test_cover_fills_the_box_with_the_middle_of_the_picture(): void
    {
        $d = self::export(Image::bytes(self::picture('png'))->width(pt(100))->height(pt(100))->fit(ImageFit::Cover));
        self::assertSame([], self::exportedReports());
        self::assertSame((string) (100 * 12700), $d->value('//wp:inline/wp:extent/@cx'));
        self::assertSame((string) (100 * 12700), $d->value('//wp:inline/wp:extent/@cy'));
        self::assertSame([40, 40], self::dims(self::media($d)));
    }

    public function test_webp_and_avif_are_converted_to_png(): void
    {
        $d = self::export(Image::bytes(self::picture('webp')));
        self::assertSame([], self::exportedReports());
        self::assertSame(1, $d->count('//wp:inline'));
        self::assertStringStartsWith("\x89PNG", self::media($d));
    }

    public function test_opacity_is_drawn_into_the_picture(): void
    {
        $d = self::export(Image::bytes(self::picture('png'))->opacity(0.5));
        self::assertSame([], self::exportedReports());
        $im = imagecreatefromstring(self::media($d));
        self::assertNotFalse($im);
        self::assertEqualsWithDelta(64, imagecolorsforindex($im, (int) imagecolorat($im, 40, 20))['alpha'], 1);
    }

    public function test_without_gd_cover_is_contain_and_opacity_is_left_out(): void
    {
        $img = Image::bytes(self::picture('png'))->opacity(0.5)->width(pt(60))->height(pt(60))->fit(ImageFit::Cover);
        $s = self::styleOf($img);
        self::mapper(new Raster(gd: false, imagick: false, imagickSvg: false))->image($img, $s, new Frame($img, $s, 60.0, 60.0), 'image[0]');
        self::assertSame(['Approximated image cover @ image[0]', 'Dropped opacity @ image[0]'], self::reports(self::$fidelity));
    }

    public function test_a_plain_qr_code_is_a_picture_of_its_size(): void
    {
        $d = self::export(QrCode::make('https://acme.test')->width(mm(20)));
        self::assertSame([], self::exportedReports());
        self::assertEqualsWithDelta(mm(20)->points * 12700, (int) $d->value('//wp:inline/wp:extent/@cx'), 1);
        self::assertSame('QR code: https://acme.test', $d->value('//wp:docPr/@descr'));
        [$w] = self::dims(self::media($d));
        self::assertGreaterThanOrEqual(Raster::pixels(mm(20)->points), $w);
    }

    public function test_a_qr_code_without_a_width_is_25mm(): void
    {
        $d = self::export(QrCode::make('x'));
        self::assertEqualsWithDelta(mm(25)->points * 12700, (int) $d->value('//wp:inline/wp:extent/@cx'), 1);
    }

    #[RequiresPhpExtension('imagick')]
    public function test_a_styled_qr_code_keeps_its_design_with_imagick(): void
    {
        $d = self::export(QrCode::make('https://acme.test')->shape(QrShape::Dots)->width(mm(20)));
        self::assertSame([], self::exportedReports());
        self::assertSame(1, $d->count('//wp:inline'));
    }

    public function test_a_styled_qr_code_without_imagick_is_drawn_plain(): void
    {
        $qr = QrCode::make('https://acme.test')->shape(QrShape::Dots)->width(mm(20));
        $s = self::styleOf($qr);
        $out = self::mapper(new Raster(gd: true, imagick: false, imagickSvg: false))->qr($qr, $s, new Frame($qr, $s, 400.0, 400.0), 'qr-code[0]');
        self::assertCount(1, $out);
        self::assertSame(['Approximated styled QR code @ qr-code[0]'], self::reports(self::$fidelity));
    }

    public function test_a_qr_code_with_neither_extension_is_left_out(): void
    {
        $qr = QrCode::make('x');
        $s = self::styleOf($qr);
        self::assertSame([], self::mapper(new Raster(gd: false, imagick: false, imagickSvg: false))->qr($qr, $s, new Frame($qr, $s, 400.0, 400.0), 'qr-code[0]'));
        self::assertSame(['Dropped qr-code @ qr-code[0]'], self::reports(self::$fidelity));
    }

    #[RequiresPhpExtension('imagick')]
    public function test_svg_is_a_picture_at_its_natural_size(): void
    {
        $d = self::export(Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20" viewBox="0 0 40 20"><rect width="40" height="20" fill="#0a0"/></svg>'));
        self::assertSame([], self::exportedReports());
        self::assertEqualsWithDelta(30 * 12700, (int) $d->value('//wp:inline/wp:extent/@cx'), 1);
        self::assertEqualsWithDelta(15 * 12700, (int) $d->value('//wp:inline/wp:extent/@cy'), 1);
        self::assertSame([Raster::pixels(30), Raster::pixels(15)], self::dims(self::media($d)));
    }

    public function test_svg_without_imagick_is_left_out(): void
    {
        $svg = Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><rect width="4" height="4"/></svg>');
        $s = self::styleOf($svg);
        self::assertSame([], self::mapper(new Raster(gd: true, imagick: false, imagickSvg: false))->svg($svg, $s, new Frame($svg, $s, 400.0, 400.0), 'svg[0]'));
        self::assertSame(['Dropped svg @ svg[0]'], self::reports(self::$fidelity));
    }
}
