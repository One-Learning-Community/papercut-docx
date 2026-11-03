<?php

declare(strict_types=1);

/**
 * Word export benchmark. Exports core's two benchmark documents once to warm up, then $argv[1] (default 5) timed runs,
 * and prints JSON for core's bench/check.php: {median_ms, runs_ms[], peak_mb, toc_off_median_ms, toc_off_peak_mb,
 * transcript_median_ms, transcript_peak_mb, bytes, opcache, php}.
 *
 * median_ms is the prose article, whose table of contents makes Word export lay out its pages for the page numbers;
 * toc_off_* is the same export with DocxOptions(tocPageNumbers: false), and transcript_* the 10-page table (no layout).
 *
 * Run from packages/docx with Xdebug off and OPcache on:
 *   php -d xdebug.mode=off -d opcache.enable_cli=1 bench/run.php | PAPERCUT_BASELINE=bench/baseline.json php ../core/bench/check.php
 */

use Papercut\Docx\{DocxExporter, DocxOptions};

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../core/bench/lib.php';

$runs = max(1, (int) ($argv[1] ?? 5));
$article = static fn (): Papercut\Document => require __DIR__ . '/../../core/bench/article.php';
$transcript = static fn (): Papercut\Document => require __DIR__ . '/../../core/bench/transcript.php';

$toc = papercut_bench(static fn (): string => $article()->export(new DocxExporter())->bytes(), $runs);
$off = papercut_bench(static fn (): string => $article()->export(new DocxExporter(), new DocxOptions(tocPageNumbers: false))->bytes(), $runs);
$table = papercut_bench(static fn (): string => $transcript()->export(new DocxExporter())->bytes(), $runs);

echo json_encode([
    'median_ms' => $toc['median_ms'],
    'runs_ms' => $toc['runs_ms'],
    'peak_mb' => $toc['peak_mb'],
    'toc_off_median_ms' => $off['median_ms'],
    'toc_off_peak_mb' => $off['peak_mb'],
    'transcript_median_ms' => $table['median_ms'],
    'transcript_peak_mb' => $table['peak_mb'],
    'bytes' => strlen($toc['result']),
    'opcache' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
    'php' => PHP_VERSION,
], JSON_PRETTY_PRINT), "\n";
