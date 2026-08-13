<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class SimplePdfService
{
    public function html(string $html): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    /** @param list<string> $lines */
    public function make(array $lines): string
    {
        $wrapped = [];
        foreach ($lines as $line) {
            foreach (explode("\n", wordwrap($this->ascii($line), 92, "\n", true)) as $part) {
                $wrapped[] = $part;
            }
        }

        $pages = array_chunk($wrapped, 48);
        $fontObject = 3 + count($pages) * 2;
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
        ];
        $pageObjects = [];

        foreach ($pages as $index => $pageLines) {
            $pageObject = 3 + $index * 2;
            $contentObject = $pageObject + 1;
            $pageObjects[] = "{$pageObject} 0 R";
            $stream = "BT\n/F1 10 Tf\n50 742 Td\n14 TL\n";
            foreach ($pageLines as $line) {
                $stream .= '('.$this->escape($line).") Tj\nT*\n";
            }
            $stream .= 'ET';
            $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 {$fontObject} 0 R >> >> /Contents {$contentObject} 0 R >>";
            $objects[$contentObject] = '<< /Length '.strlen($stream).">>\nstream\n{$stream}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageObjects).'] /Count '.count($pages).' >>';
        $objects[$fontObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($number = 1; $number <= count($objects); $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    /** @param list<list<string>> $pages */
    public function makePages(array $pages): string
    {
        $normalized = [];
        foreach ($pages as $page) {
            $wrapped = [];
            foreach ($page as $line) {
                foreach (explode("\n", wordwrap($this->ascii($line), 92, "\n", true)) as $part) {
                    $wrapped[] = $part;
                }
            }
            foreach (array_chunk($wrapped, 48) as $chunk) {
                $normalized[] = $chunk;
            }
        }

        return $this->build($normalized);
    }

    /** @param list<list<string>> $pages */
    private function build(array $pages): string
    {
        $fontObject = 3 + count($pages) * 2;
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => ''];
        $pageObjects = [];
        foreach ($pages as $index => $pageLines) {
            $pageObject = 3 + $index * 2;
            $contentObject = $pageObject + 1;
            $pageObjects[] = "{$pageObject} 0 R";
            $stream = "BT\n/F1 10 Tf\n50 742 Td\n14 TL\n";
            foreach ($pageLines as $line) {
                $stream .= '('.$this->escape($line).") Tj\nT*\n";
            }
            $stream .= 'ET';
            $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 {$fontObject} 0 R >> >> /Contents {$contentObject} 0 R >>";
            $objects[$contentObject] = '<< /Length '.strlen($stream).">>\nstream\n{$stream}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageObjects).'] /Count '.count($pages).' >>';
        $objects[$fontObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($number = 1; $number <= count($objects); $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function ascii(string $value): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
}
