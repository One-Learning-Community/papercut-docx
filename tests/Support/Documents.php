<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Support;

/** The core visual fixtures and the example documents, which every format is checked against. */
final class Documents
{
    /** @return iterable<string, array{string}> name => [file] */
    public static function all(): iterable
    {
        $core = __DIR__ . '/../../../core/tests/Visual/fixtures';
        $examples = __DIR__ . '/../../../../examples/core/documents';
        foreach (['fixtures' => $core, 'examples' => $examples] as $kind => $dir) {
            $files = glob("$dir/*.php") ?: [];
            sort($files);
            foreach ($files as $file) {
                // The certificate fills in a PDF template, which Word export leaves out, so there is nothing to compare.
                if ($kind === 'examples' && basename($file, '.php') === 'certificate') {
                    continue;
                }
                yield $kind . '-' . basename($file, '.php') => [$file];
            }
        }
    }

    public static function load(string $file): \Papercut\Document
    {
        $doc = (static fn (string $f): mixed => require $f)($file);
        \assert($doc instanceof \Papercut\Document);

        return $doc;
    }
}
