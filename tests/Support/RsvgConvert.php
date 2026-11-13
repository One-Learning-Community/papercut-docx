<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Support;

use PHPUnit\Framework\TestCase;

/** Finds rsvg-convert for the tests that need it: PAPERCUT_RSVG_CONVERT, else the first on PATH. */
final class RsvgConvert
{
    public static function path(): ?string
    {
        $env = getenv('PAPERCUT_RSVG_CONVERT');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $file = rtrim($dir, '/') . '/rsvg-convert';
            if ($dir !== '' && is_file($file) && is_executable($file)) {
                return $file;
            }
        }

        return null;
    }

    /** The path, or the test is skipped. */
    public static function require(): string
    {
        $path = self::path();
        if ($path === null) {
            TestCase::markTestSkipped('rsvg-convert is not installed.');
        }

        return $path;
    }
}
