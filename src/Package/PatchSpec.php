<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

/** What DocxPatcher writes into php-docx's output. */
final readonly class PatchSpec
{
    public function __construct(
        public string $fontFamily,
        public int $fontSizeHalfPoints,
        public ?string $lang,
        /** Every ZIP entry's time, read as UTC. */
        public \DateTimeInterface $time,
    ) {
    }
}
