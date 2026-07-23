<?php

namespace App\Reporting\Application;

/** API Contracts §13.13: the export result — raw bytes only, never re-derived figures. */
final readonly class ReportRunExport
{
    public function __construct(public string $content, public string $mimeType, public string $filename) {}
}
