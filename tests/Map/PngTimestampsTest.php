<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\Docx\Map\PngTimestamps;
use PHPUnit\Framework\TestCase;

final class PngTimestampsTest extends TestCase
{
    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function png(string ...$chunks): string
    {
        return "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', str_repeat("\0", 13)) . implode('', $chunks) . self::chunk('IDAT', 'pixels') . self::chunk('IEND', '');
    }

    public function test_drops_the_time_chunk_and_date_text_and_keeps_the_rest(): void
    {
        $comment = self::chunk('tEXt', "Comment\0kept");
        $stamped = self::png(
            self::chunk('tIME', "\x07\xea\x0b\x0c\x00\x28\x32"),
            self::chunk('tEXt', "date:create\x002026-11-12T00:40:50+00:00"),
            $comment,
            self::chunk('tEXt', "date:modify\x002026-11-12T00:40:50+00:00"),
        );
        self::assertSame(self::png($comment), PngTimestamps::strip($stamped));
    }

    public function test_a_png_without_timestamps_and_other_bytes_come_back_unchanged(): void
    {
        $plain = self::png(self::chunk('tEXt', "Comment\0kept"));
        self::assertSame($plain, PngTimestamps::strip($plain));
        self::assertSame('not a png', PngTimestamps::strip('not a png'));
        $truncated = substr(self::png(self::chunk('tIME', '1234567')), 0, 40);
        self::assertSame($truncated, PngTimestamps::strip($truncated));
    }
}
