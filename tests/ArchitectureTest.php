<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    /** php-docx stays an implementation detail of this package: nothing outside src/ ships code that names it. */
    public function test_only_docx_package_imports_php_docx(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);
        $offenders = [];
        foreach (['src', 'tests'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                if ($file->getExtension() !== 'php' || str_starts_with($path, $root . '/src/')) {
                    continue;
                }
                if (str_contains((string) file_get_contents($path), 'Dskripchenko\\PhpDocx') && !str_starts_with($path, $root . '/tests/')) {
                    $offenders[] = substr($path, strlen($root) + 1);
                }
            }
        }
        self::assertSame([], $offenders);
    }
}
