<?php

declare(strict_types=1);

namespace Quoin\Docx\Tests\Support;

use PHPUnit\Framework\TestCase;

/** Runs the OOXML validator (@xarsh/ooxml-validator) on a DOCX when one is installed, and says so when it is not. */
final class OoxmlValidator
{
    /** The validator's cli.js: $OOXML_VALIDATOR, then the npx cache, then the global npm root. */
    public static function cli(): ?string
    {
        $env = getenv('OOXML_VALIDATOR');
        if (is_string($env) && is_file($env)) {
            return $env;
        }
        $found = glob(getenv('HOME') . '/.npm/_npx/*/node_modules/@xarsh/ooxml-validator/dist/src/cli.js') ?: [];
        if ($found !== []) {
            return $found[0];
        }
        $root = trim((string) @shell_exec('npm root -g 2>/dev/null'));
        $global = $root . '/@xarsh/ooxml-validator/dist/src/cli.js';

        return $root !== '' && is_file($global) ? $global : null;
    }

    /**
     * Asserts the DOCX validates; marks the test skipped (after its other assertions ran) when no validator is installed,
     * so a missing validator shows in the run instead of passing silently.
     */
    public static function assertValid(string $bytes): void
    {
        $cli = self::cli();
        if ($cli === null) {
            TestCase::markTestSkipped('OOXML validator not found (set OOXML_VALIDATOR to its cli.js, or run `npx @xarsh/ooxml-validator` once)');
        }
        $file = tempnam(sys_get_temp_dir(), 'pcx');
        $docx = $file . '.docx';
        try {
            file_put_contents($docx, $bytes);
            $out = (string) shell_exec('node ' . escapeshellarg($cli) . ' ' . escapeshellarg($docx) . ' 2>&1');
        } finally {
            @unlink($docx);
            @unlink($file);
        }
        $json = json_decode($out, true);
        TestCase::assertTrue(is_array($json) && ($json['ok'] ?? false) === true, 'OOXML validator: ' . substr($out, 0, 800));
    }
}
