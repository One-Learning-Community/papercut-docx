<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

/**
 * Takes the time of drawing out of a PNG. Imagick writes it into every PNG it encodes (a tIME chunk, and
 * date:create, date:modify and date:timestamp text), so the same picture made twice would differ, and with
 * it the Word file.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class PngTimestamps
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** The PNG without its time chunk and date text; anything that is not a well-formed PNG comes back as it is. */
    public static function strip(string $png): string
    {
        if (!str_starts_with($png, self::SIGNATURE)) {
            return $png;
        }
        $out = self::SIGNATURE;
        $end = strlen($png);
        for ($i = 8; $i < $end; $i += 12 + $length) {
            if ($i + 8 > $end) {
                return $png;
            }
            $length = (ord($png[$i]) << 24) | (ord($png[$i + 1]) << 16) | (ord($png[$i + 2]) << 8) | ord($png[$i + 3]);
            if ($i + 12 + $length > $end) {
                return $png;
            }
            $type = substr($png, $i + 4, 4);
            if ($type === 'tIME' || ($type === 'tEXt' && str_starts_with(substr($png, $i + 8, $length), 'date:'))) {
                continue;
            }
            $out .= substr($png, $i, 12 + $length);
        }

        return $out;
    }
}
