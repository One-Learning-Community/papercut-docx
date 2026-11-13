<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\ImageFormat;
use Papercut\Backend\PhpPdf\GdImageConverter;
use Papercut\Barcode\Bacon\BaconQrSvg;
use Papercut\Exception\InvalidValue;
use Papercut\QrCode;
use Papercut\Value\Color;
use Papercut\Vector\SvgDocument;

/**
 * Pictures for Word, which takes only PNG, JPEG, GIF and BMP and draws them as they are: other formats are
 * converted, and what the PDF does to an image (cropping, opacity), along with QR codes
 * and SVG, is baked into the pixels. GD does the bitmap work; Imagick draws styled QR codes, and SVG unless
 * rsvg-convert is configured: that draws SVG properly whatever the ImageMagick build, which Imagick itself may not.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
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
        /** The rsvg-convert executable that draws SVG ahead of Imagick; null for Imagick alone. */
        public ?string $rsvgConvert = null,
        /** Seconds rsvg-convert gets for one SVG before it is stopped and Imagick tried instead. */
        public float $rsvgTimeout = 30.0,
    ) {
    }

    /** @throws InvalidValue when $rsvgConvert is given but isn't an executable file, or PHP can't start processes */
    public static function detect(?string $rsvgConvert = null): self
    {
        $imagick = extension_loaded('imagick');
        if ($rsvgConvert !== null) {
            $invalid = static fn (string $why): InvalidValue => InvalidValue::for('rsvg-convert path', $rsvgConvert)->because($why);
            // Absolute, so the file checked here is the one run, not one proc_open() finds on PATH.
            if (!str_starts_with($rsvgConvert, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $rsvgConvert) !== 1) {
                throw $invalid('it must be an absolute path');
            }
            if (!is_file($rsvgConvert) || !is_executable($rsvgConvert)) {
                throw $invalid('it is not an executable file');
            }
            if (!function_exists('proc_open')) {
                throw $invalid('proc_open() is disabled, so PHP cannot run it');
            }
            // A wrong program, or one that can't start (another architecture, a noexec mount), would otherwise
            // fall back to Imagick on every SVG without a word.
            if (!str_contains(self::probe($rsvgConvert), 'rsvg-convert')) {
                throw $invalid('it does not run as rsvg-convert (rsvg-convert --version gave nothing recognisable)');
            }
        }

        return new self(extension_loaded('gd'), $imagick, $imagick && \Imagick::queryFormats('SVG') !== [], $rsvgConvert);
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

        return PngTimestamps::strip(BaconQrSvg::png($qr->modules(), $qr->quietZoneModules(), $qr->level(), $design, $color, max(1, $pixels)));
    }

    /**
     * The SVG as a PNG of $width x $height pixels, through rsvg-convert when it is configured and succeeds, else
     * Imagick. Anything that could reach outside the document (images, links to files, external url()s) is
     * removed first: the PDF draws none of it either.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public function svg(SvgDocument $doc, int $width, int $height): ?string
    {
        if (!$this->imagickSvg && $this->rsvgConvert === null) {
            return null;
        }
        $xml = self::contained($doc->xml, $width, $height);
        if ($xml === null) {
            return null;
        }
        $png = $this->rsvgConvert === null ? null : $this->rsvg($this->rsvgConvert, $xml, $width, $height);
        if ($png !== null) {
            return PngTimestamps::strip($png);
        }
        if (!$this->imagickSvg) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->setBackgroundColor(new \ImagickPixel('transparent'));
            $im->setFormat('SVG');
            $im->readImageBlob($xml, 'image.svg');
            // The reader's format sticks to the wand until it is set again.
            // Always RGBA: left to choose, ImageMagick 7.1.2 writes an all-transparent image as 1-bit grey
            // with a tRNS chunk, which not every reader takes as transparent.
            $im->setFormat('PNG32');
            $im->setImageFormat('PNG32');
            $im->setImageDepth(8);
            if ($im->getImageWidth() !== $width || $im->getImageHeight() !== $height) {
                $im->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
            }
            $png = $im->getImageBlob();
            $im->clear();
        } catch (\ImagickException) {
            return null;
        }

        return $png === '' ? null : PngTimestamps::strip($png);
    }

    /** What `$binary --version` prints, or '' when it can't be run. */
    private static function probe(string $binary): string
    {
        $pipes = [];
        $proc = @proc_open([$binary, '--version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return '';
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 5);
        $out = (string) stream_get_contents($pipes[1]);
        $stuck = stream_get_meta_data($pipes[1])['timed_out'];
        fclose($pipes[1]);
        fclose($pipes[2]);
        if ($stuck) {
            proc_terminate($proc, 9);
        }
        proc_close($proc);

        return $stuck ? '' : $out;
    }

    /**
     * The contained SVG drawn by rsvg-convert, read from stdin so it has no directory to resolve anything
     * against. Null when it fails, runs past the timeout, or draws something other than a $width x $height PNG.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    private function rsvg(string $binary, string $xml, int $width, int $height): ?string
    {
        $pipes = [];
        $proc = @proc_open(
            [$binary, '-f', 'png', '-w', (string) $width, '-h', (string) $height],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            return null;
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        // Write and read together: a large SVG and a large PNG would otherwise wait on each other.
        $in = $xml;
        $stdin = $pipes[0];
        $reading = [1 => $pipes[1], 2 => $pipes[2]];
        $out = '';
        $deadline = microtime(true) + $this->rsvgTimeout;
        while ($reading !== []) {
            $left = $deadline - microtime(true);
            if ($left <= 0.0) {
                break;
            }
            $read = array_values($reading);
            $write = $stdin === null ? [] : [$stdin];
            $except = null;
            if (@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1.0) * 1e6)) === false) {
                // Most often a signal (EINTR) in a worker with async signals; the deadline bounds the retries.
                usleep(1000);
                continue;
            }
            if ($stdin !== null && $write !== []) {
                $n = @fwrite($stdin, $in);
                $in = $n === false ? '' : substr($in, $n);
                if ($in === '') {
                    fclose($stdin);
                    $stdin = null;
                }
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $pipes[1]) {
                        $out .= $chunk;
                    }
                } elseif (feof($stream)) {
                    $key = array_search($stream, $reading, true);
                    if ($key !== false) {
                        unset($reading[$key]);
                    }
                    fclose($stream);
                }
            }
        }
        $finished = $reading === [];
        if ($stdin !== null) {
            fclose($stdin);
        }
        foreach ($reading as $stream) {
            fclose($stream);
        }
        // Its pipes closed, it should be done; one that lives on (a wrapper that left work running) is stopped
        // at the deadline too, rather than leaving proc_close() to wait on it.
        $status = proc_get_status($proc);
        while ($finished && $status['running'] && microtime(true) < $deadline) {
            usleep(1000);
            $status = proc_get_status($proc);
        }
        $exit = $status['running'] ? -1 : $status['exitcode'];
        if (!$finished || $status['running']) {
            $finished = false;
            proc_terminate($proc, 9);
        }
        proc_close($proc);
        if (!$finished || $exit !== 0 || strlen($out) < 24 || !str_starts_with($out, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        $size = unpack('Nw/Nh', substr($out, 16, 8));

        return $size !== false && $size['w'] === $width && $size['h'] === $height ? $out : null;
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
            // A CSS escape (\75 rl for url) would slip a reference past the patterns: such a value goes whole.
            if (str_contains($attr->value, '\\')) {
                $attr->ownerElement->removeAttributeNode($attr);
            } elseif ($attr->localName === 'href' && !str_starts_with(trim($attr->value), '#')) {
                $attr->ownerElement->removeAttributeNode($attr);
            } elseif ($external($attr->value)) {
                // A paint that can't be found draws nothing, as it would in a browser.
                $attr->value = (string) preg_replace([$outside, '/@import/i'], ['none', ''], $attr->value);
            }
        }
        foreach (self::elements($xpath, '//*[local-name()="style"]') as $el) {
            if ($external($el->textContent) || str_contains($el->textContent, '\\')) {
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
