<?php

namespace App\Receivables\Infrastructure;

use App\Models\Receivables\Invoice;

final class InvoicePdfRenderer
{
    public function render(Invoice $invoice): string
    {
        $text = 'Invoice '.str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], (string) $invoice->document_number);
        $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $objects = ["1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n", "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n", "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj\n", '4 0 obj << /Length '.strlen($stream)." >> stream\n{$stream}\nendstream endobj\n", "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }$xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i])."\n";
        }$pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
