<?php
/**
 * Simple XLSX reader - pure PHP, no Composer.
 * Pakai built-in ZipArchive + SimpleXML (tersedia di PHP 7.3).
 * Cukup utk baca file Attendances Moodle (text + numeric cells).
 *
 * Usage:
 *   $rows = XlsxReader::read('/path/to/file.xlsx');
 *   foreach ($rows as $row) { ... }
 *
 * Output: array of array (per row), index numerik 0..n-1.
 * Cell kosong = '' (empty string).
 */
class XlsxReader {

    public static function read($path, $sheetIndex = 0) {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive tidak tersedia di PHP ini.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Exception('Tidak bisa membuka file XLSX (apakah benar file .xlsx?).');
        }

        // Baca sharedStrings.xml (string table)
        $strings = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $xml = @simplexml_load_string($ss);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    // String bisa berupa <t>...</t> atau <r><t>...</t></r> (rich text)
                    if (isset($si->t)) {
                        $strings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $buf = '';
                        foreach ($si->r as $r) {
                            $buf .= (string)$r->t;
                        }
                        $strings[] = $buf;
                    } else {
                        $strings[] = '';
                    }
                }
            }
        }

        // Cari sheet target via workbook.xml + rels
        // Sederhana: pakai sheet1.xml (default sheet pertama). Moodle export = 1 sheet.
        $sheetName = 'xl/worksheets/sheet' . ($sheetIndex + 1) . '.xml';
        $sheetXml = $zip->getFromName($sheetName);
        if ($sheetXml === false) {
            $zip->close();
            throw new Exception('Sheet tidak ditemukan dalam file.');
        }
        $zip->close();

        $xml = @simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new Exception('Sheet XML tidak valid.');
        }

        $rows = [];
        if (!isset($xml->sheetData->row)) return $rows;

        foreach ($xml->sheetData->row as $row) {
            $rowArr = [];
            foreach ($row->c as $c) {
                // Atribut r = referensi sel spt "A1", "B1" → ambil kolom
                $ref = (string)$c['r'];
                $col = self::colIndex($ref);
                // Atribut t = type. 's' = sharedString, 'inlineStr' = string inline, 'b' = bool
                // tanpa t = numeric
                $type = (string)$c['t'];
                $val = '';
                if ($type === 's') {
                    $idx = (int)$c->v;
                    $val = $strings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string)$c->is->t : '';
                } elseif ($type === 'b') {
                    $val = ((string)$c->v === '1') ? 'TRUE' : 'FALSE';
                } elseif ($type === 'e') {
                    $val = (string)$c->v; // error cell
                } else {
                    // numeric
                    $val = (string)$c->v;
                }
                $rowArr[$col] = $val;
            }
            // Normalize: fill gap dengan ''
            if (!empty($rowArr)) {
                $maxCol = max(array_keys($rowArr));
                for ($i = 0; $i <= $maxCol; $i++) {
                    if (!isset($rowArr[$i])) $rowArr[$i] = '';
                }
                ksort($rowArr);
                $rowArr = array_values($rowArr);
            }
            $rows[] = $rowArr;
        }
        return $rows;
    }

    /** "A1" -> 0, "B5" -> 1, "AA7" -> 26 */
    private static function colIndex($ref) {
        $letters = preg_replace('/\d+/', '', $ref);
        $col = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $col - 1;
    }
}
