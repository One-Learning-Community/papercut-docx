<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

/**
 * A minimal ZIP writer whose bytes depend only on its input: every entry is deflated at one level and
 * stamped with one time, read as UTC (libzip converts times through the process's local time zone).
 */
final class DeterministicZip
{
    /** @param array<string, string> $entries name => bytes, written in this order */
    public static function write(array $entries, ?\DateTimeInterface $at = null): string
    {
        [$time, $date] = self::dos($at);
        $out = '';
        $central = '';
        foreach ($entries as $name => $data) {
            $deflated = gzdeflate($data, 6);
            if ($deflated === false) {
                throw new \RuntimeException('Could not compress ' . $name);
            }
            $crc = crc32($data);
            $common = pack('vvvvvVVVv', 20, 0, 8, $time, $date, $crc, strlen($deflated), strlen($data), strlen($name));
            $central .= "PK\x01\x02" . pack('v', 20) . $common . pack('vvvvVV', 0, 0, 0, 0, 0, strlen($out)) . $name;
            $out .= "PK\x03\x04" . $common . pack('v', 0) . $name . $deflated;
        }

        return $out . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($entries), count($entries), strlen($central), strlen($out), 0);
    }

    /** @return array{int, int} DOS time and date */
    private static function dos(?\DateTimeInterface $at): array
    {
        $utc = $at === null ? null : \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));
        $year = $utc === null ? 0 : (int) $utc->format('Y');
        // A ZIP can't say a time before 1980 or after 2107: 1980-01-01 00:00 stands in.
        if ($utc === null || $year < 1980 || $year > 2107) {
            return [0, (1 << 5) | 1];
        }

        return [
            ((int) $utc->format('G') << 11) | ((int) $utc->format('i') << 5) | intdiv((int) $utc->format('s'), 2),
            (($year - 1980) << 9) | ((int) $utc->format('n') << 5) | (int) $utc->format('j'),
        ];
    }
}
