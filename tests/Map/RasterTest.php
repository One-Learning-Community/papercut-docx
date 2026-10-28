<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Dskripchenko\PhpDocx\Element\ImageFormat;
use Papercut\Docx\Map\Raster;
use Papercut\QrCode;
use Papercut\Value\Color;
use Papercut\Vector\SvgDocument;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class RasterTest extends TestCase
{
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

    /** @return array{int, int, int, int} red, green, blue, alpha (0 opaque … 127 clear) */
    private static function pixel(string $bytes, int $x, int $y): array
    {
        $im = imagecreatefromstring($bytes);
        self::assertNotFalse($im);
        $c = imagecolorsforindex($im, (int) imagecolorat($im, $x, $y));

        return [$c['red'], $c['green'], $c['blue'], $c['alpha']];
    }

    /** @return array{int, int} */
    private static function dims(string $bytes): array
    {
        $s = getimagesizefromstring($bytes);
        self::assertNotFalse($s);

        return [$s[0], $s[1]];
    }

    private static function gdOnly(): Raster
    {
        return new Raster(gd: true, imagick: false, imagickSvg: false);
    }

    public function test_word_formats_pass_through_and_others_become_png(): void
    {
        $png = self::picture('png');
        self::assertSame([$png, ImageFormat::Png], self::gdOnly()->forWord($png, 'image/png'));

        $converted = self::gdOnly()->forWord(self::picture('webp'), 'image/webp');
        self::assertNotNull($converted);
        self::assertSame(ImageFormat::Png, $converted[1]);
        self::assertSame([80, 40], self::dims($converted[0]));
        self::assertNull((new Raster(gd: false, imagick: false, imagickSvg: false))->forWord(self::picture('webp'), 'image/webp'));
    }

    public function test_cover_crops_to_the_box_shape_from_the_middle(): void
    {
        $out = self::gdOnly()->finish(self::picture('png'), 'image/png', 100.0, 100.0, crop: true, opacity: 1.0);
        self::assertNotNull($out);
        self::assertSame([40, 40], self::dims($out[0]));
    }

    public function test_a_cropped_jpeg_stays_a_jpeg(): void
    {
        $out = self::gdOnly()->finish(self::picture('jpeg'), 'image/jpeg', 100.0, 100.0, crop: true, opacity: 1.0);
        self::assertNotNull($out);
        self::assertSame(ImageFormat::Jpeg, $out[1]);
    }

    public function test_opacity_is_baked_into_the_pixels(): void
    {
        $out = self::gdOnly()->finish(self::picture('png'), 'image/png', 60.0, 30.0, crop: false, opacity: 0.5);
        self::assertNotNull($out);
        self::assertSame(ImageFormat::Png, $out[1]);
        self::assertEqualsWithDelta(64, self::pixel($out[0], 40, 20)[3], 1);
    }

    public function test_nothing_to_do_returns_null(): void
    {
        self::assertNull(self::gdOnly()->finish(self::picture('png'), 'image/png', 60.0, 30.0, crop: false, opacity: 1.0));
    }

    public function test_a_plain_qr_code_as_crisp_squares(): void
    {
        $qr = QrCode::fromModules([[true, false], [false, true]])->quietZone(1);
        $png = self::gdOnly()->qr($qr, Color::hex('#112233'), 400);
        self::assertNotNull($png);
        [$w, $h] = self::dims($png);
        self::assertSame($w, $h);
        self::assertGreaterThanOrEqual(400, $w);
        $m = intdiv($w, 4);
        self::assertSame(127, self::pixel($png, 0, 0)[3]);
        self::assertSame([0x11, 0x22, 0x33, 0], self::pixel($png, $m + 1, $m + 1));
        self::assertSame(127, self::pixel($png, 2 * $m + 1, $m + 1)[3]);
        self::assertNull((new Raster(gd: false, imagick: false, imagickSvg: false))->qr($qr, Color::hex('#112233'), 400));
    }

    #[RequiresPhpExtension('imagick')]
    public function test_svg_at_the_requested_size(): void
    {
        $raster = Raster::detect();
        if (!$raster->imagickSvg) {
            self::markTestSkipped('This Imagick build cannot read SVG.');
        }
        $doc = SvgDocument::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10"><rect width="10" height="10" fill="#ff0000"/></svg>');
        $png = $raster->svg($doc, 200, 100);
        self::assertNotNull($png);
        self::assertSame([200, 100], self::dims($png));
        self::assertSame([255, 0, 0, 0], self::pixel($png, 50, 50));
        self::assertSame(127, self::pixel($png, 150, 50)[3]);
    }

    #[RequiresPhpExtension('imagick')]
    public function test_svg_never_loads_outside_files(): void
    {
        $raster = Raster::detect();
        if (!$raster->imagickSvg) {
            self::markTestSkipped('This Imagick build cannot read SVG.');
        }
        $secret = tempnam(sys_get_temp_dir(), 'leak');
        file_put_contents($secret, self::picture('png', 20, 20));
        try {
            $doc = SvgDocument::parse('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="20" height="20">'
                . '<image href="file://' . $secret . '" width="20" height="20"/><image xlink:href="' . $secret . '" width="20" height="20"/>'
                . '<rect width="20" height="20" fill="url(file://' . $secret . '#x)"/></svg>');
            $png = $raster->svg($doc, 20, 20);
            self::assertNotNull($png);
            self::assertSame(127, self::pixel($png, 10, 10)[3]);
        } finally {
            unlink($secret);
        }
    }

    public function test_svg_without_imagick_is_null(): void
    {
        $doc = SvgDocument::parse('<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><rect width="4" height="4"/></svg>');
        self::assertNull(self::gdOnly()->svg($doc, 4, 4));
    }
}
