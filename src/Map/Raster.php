<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\ImageFormat;
use Papercut\Backend\PhpPdf\GdImageConverter;
use Papercut\Barcode\Bacon\BaconQrSvg;
use Papercut\QrCode;
use Papercut\Value\Color;
use Papercut\Vector\SvgDocument;

/**
 * Pictures for Word, which takes only PNG, JPEG, GIF and BMP and draws them as they are: other formats are
 * converted, and what the PDF does to an image (cropping, opacity), along with QR codes
 * and SVG, is baked into the pixels. GD does the bitmap work; Imagick draws styled QR codes and SVG.
 */
final readonly class Raster
{
    /** Print resolution for what Papercut draws itself (QR codes, SVG). */
    public const int DPI = 300;

    public function __construct(
        public bool $gd,
        public bool $imagick,
        /** Whether this Imagick build can read SVG. */
        public bool $imagickSvg,
    ) {
    }

    public static function detect(): self
    {
        $imagick = extension_loaded('imagick');

        return new self(extension_loaded('gd'), $imagick, $imagick && \Imagick::queryFormats('SVG') !== []);
    }

    /**
     * Pixels across for $points at print resolution.
     *
     * @return positive-int
     */
    public static function pixels(float $points): int
    {
        return max(1, (int) ceil($points / 72 * self::DPI));
    }

    /**
     * The image as Word can take it: its own bytes for PNG, JPEG, GIF and BMP, else converted to PNG.
     *
     * @return ?array{string, ImageFormat} null when it can't be converted
     */
    public function forWord(string $bytes, string $mime): ?array
    {
        $format = self::format($mime);
        if ($format !== null) {
            return [$bytes, $format];
        }
        $gd = new GdImageConverter($this->gd ? imagetypes() : null);
        if (!$gd->handles($mime)) {
            return null;
        }
        try {
            return [$gd->toPng($bytes, $mime), ImageFormat::Png];
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * What the PDF does to an image drawn $width x $height pt, baked in: $crop cuts it to that shape from the
     * middle (cover), $opacity fades it. Null when there is nothing to do, or no GD to do it with. (Corner
     * radius shapes only the background and border in the PDF, never the picture, so it isn't applied.)
     *
     * @return ?array{string, ImageFormat}
     */
    public function finish(string $bytes, string $mime, float $width, float $height, bool $crop, float $opacity): ?array
    {
        if (!$this->gd || $width <= 0.0 || $height <= 0.0 || (!$crop && $opacity >= 1.0)) {
            return null;
        }
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return null;
        }
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($crop) {
            $want = $width / $height;
            [$cw, $ch] = $w / $h > $want ? [max(1, (int) round($h * $want)), $h] : [$w, max(1, (int) round($w / $want))];
            if ($cw !== $w || $ch !== $h) {
                $cut = imagecrop($im, ['x' => intdiv($w - $cw, 2), 'y' => intdiv($h - $ch, 2), 'width' => $cw, 'height' => $ch]);
                if ($cut === false) {
                    return null;
                }
                [$im, $w, $h] = [$cut, $cw, $ch];
            }
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        if ($opacity < 1.0) {
            imagefilter($im, IMG_FILTER_COLORIZE, 0, 0, 0, (int) round(127 * (1 - max(0.0, $opacity))));
        }
        if ($mime === 'image/jpeg' && $opacity >= 1.0) {
            return [self::encode(static fn () => imagejpeg($im, null, 92)), ImageFormat::Jpeg];
        }

        return [self::encode(static fn () => imagepng($im)), ImageFormat::Png];
    }

    /**
     * A plain QR code as sharp squares in $color, at least $pixels across with a whole number of pixels
     * per module and a see-through quiet zone. Null without GD.
     */
    public function qr(QrCode $qr, Color $color, int $pixels): ?string
    {
        if (!$this->gd) {
            return null;
        }
        $span = max(1, $qr->span());
        $m = max(1, (int) ceil($pixels / $span));
        $im = imagecreatetruecolor($span * $m, $span * $m);
        if ($im === false) {
            return null;
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 255, 255, 255, 127));
        $ink = (int) imagecolorallocatealpha($im, self::byte($color->r), self::byte($color->g), self::byte($color->b), self::alpha(127 * (1 - $color->alpha)));
        $q = $qr->quietZoneModules();
        foreach ($qr->modules() as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    imagefilledrectangle($im, ($x + $q) * $m, ($y + $q) * $m, ($x + $q + 1) * $m - 1, ($y + $q + 1) * $m - 1, $ink);
                }
            }
        }

        return self::encode(static fn () => imagepng($im));
    }

    /** A styled QR code drawn by Bacon through Imagick; null when either is missing. */
    public function styledQr(QrCode $qr, Color $color, int $pixels): ?string
    {
        $design = $qr->design();
        if (!$this->imagick || $design === null || !class_exists(\BaconQrCode\Renderer\ImageRenderer::class)) {
            return null;
        }

        return BaconQrSvg::png($qr->modules(), $qr->quietZoneModules(), $qr->level(), $design, $color, max(1, $pixels));
    }

    /**
     * The SVG as a PNG of $width x $height pixels, through Imagick. Anything that could reach outside the
     * document (images, links to files, external url()s) is removed first: the PDF draws none of it either.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public function svg(SvgDocument $doc, int $width, int $height): ?string
    {
        if (!$this->imagickSvg) {
            return null;
        }
        $xml = self::contained($doc->xml, $width, $height);
        if ($xml === null) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->setBackgroundColor(new \ImagickPixel('transparent'));
            $im->setFormat('SVG');
            $im->readImageBlob($xml, 'image.svg');
            // The reader's format sticks to the wand until it is set again.
            $im->setFormat('PNG');
            $im->setImageFormat('PNG');
            $im->setImageDepth(8);
            if ($im->getImageWidth() !== $width || $im->getImageHeight() !== $height) {
                $im->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
            }
            $png = $im->getImageBlob();
            $im->clear();
        } catch (\ImagickException) {
            return null;
        }

        return $png === '' ? null : $png;
    }

    /** The SVG with nothing external left in it, sized $width x $height px around its view box. */
    private static function contained(string $xml, int $width, int $height): ?string
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return null;
        }
        $root = $dom->documentElement;
        if ($root === null) {
            return null;
        }
        $xpath = new \DOMXPath($dom);
        foreach (self::elements($xpath, '//*[local-name()="image" or local-name()="foreignObject" or local-name()="feImage" or local-name()="script"]') as $el) {
            $el->parentNode?->removeChild($el);
        }
        $outside = '/url\(\s*[\'"]?\s*(?!#)[^)]*\)/i';
        $external = static fn (string $v): bool => preg_match($outside, $v) === 1 || stripos($v, '@import') !== false;
        foreach (iterator_to_array($xpath->query('//@*') ?: []) as $attr) {
            \assert($attr instanceof \DOMAttr);
            if ($attr->ownerElement === null) {
                continue;
            }
            if ($attr->localName === 'href' && !str_starts_with(trim($attr->value), '#')) {
                $attr->ownerElement->removeAttributeNode($attr);
            } elseif ($external($attr->value)) {
                // A paint that can't be found draws nothing, as it would in a browser.
                $attr->value = (string) preg_replace([$outside, '/@import/i'], ['none', ''], $attr->value);
            }
        }
        foreach (self::elements($xpath, '//*[local-name()="style"]') as $el) {
            if ($external($el->textContent)) {
                $el->parentNode?->removeChild($el);
            }
        }
        if (!$root->hasAttribute('viewBox')) {
            $w = (float) $root->getAttribute('width');
            $h = (float) $root->getAttribute('height');
            if ($w > 0.0 && $h > 0.0) {
                $root->setAttribute('viewBox', "0 0 $w $h");
            }
        }
        $root->setAttribute('width', (string) $width);
        $root->setAttribute('height', (string) $height);
        $out = $dom->saveXML($root);

        return $out === false ? null : $out;
    }

    private static function format(string $mime): ?ImageFormat
    {
        return match ($mime) {
            'image/png' => ImageFormat::Png,
            'image/jpeg' => ImageFormat::Jpeg,
            'image/gif' => ImageFormat::Gif,
            'image/bmp' => ImageFormat::Bmp,
            default => null,
        };
    }

    /** @return list<\DOMElement> */
    private static function elements(\DOMXPath $xpath, string $query): array
    {
        $found = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /** @return int<0, 255> */
    private static function byte(int $v): int
    {
        return max(0, min(255, $v));
    }

    /** @return int<0, 127> GD's alpha, 0 opaque */
    private static function alpha(float $v): int
    {
        return max(0, min(127, (int) round($v)));
    }

    /** @param \Closure(): bool $write */
    private static function encode(\Closure $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }
}
