<?php

declare(strict_types=1);

namespace Papercut\Docx;

/**
 * Ends the page layout behind a table of contents' page numbers early: every heading it lists has its page.
 *
 * @internal Part of the Word export's engine, not its public API. It may change in any release.
 */
final class AllHeadingsPlaced extends \RuntimeException
{
}
