<?php

namespace App\Services;

use ZipArchive;

class PptxSlideParser
{
    /**
     * Extract text slides from a PPTX (ZIP) binary so they can be rendered directly on stage.
     *
     * @return list<array{index: int, title: string, lines: list<string>}>
     */
    public function parseBytes(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gr_pptx_');
        if ($tmp === false) {
            return [];
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                return [];
            }

            $slideFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                    $slideFiles[(int) $m[1]] = $name;
                }
            }
            ksort($slideFiles);

            $slides = [];
            $idx = 1;
            foreach ($slideFiles as $file) {
                $xml = $zip->getFromName($file);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }
                $lines = $this->extractParagraphs($xml);
                $title = $lines[0] ?? 'Slide '.$idx;
                $body = array_slice($lines, 1);
                $slides[] = [
                    'index' => $idx,
                    'title' => $title,
                    'lines' => $body,
                ];
                $idx++;
            }

            $zip->close();

            return $slides;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return list<string>
     */
    private function extractParagraphs(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $paragraphs = $xpath->query('//a:p');
        if ($paragraphs === false) {
            return [];
        }

        $lines = [];
        foreach ($paragraphs as $p) {
            $runs = $xpath->query('.//a:t', $p);
            if ($runs === false) {
                continue;
            }
            $text = '';
            foreach ($runs as $run) {
                $text .= $run->textContent;
            }
            $clean = trim(preg_replace('/\s+/', ' ', $text) ?? '');
            if ($clean !== '') {
                $lines[] = $clean;
            }
        }

        return $lines;
    }
}
