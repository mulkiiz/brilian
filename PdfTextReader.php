<?php
/**
 * PdfTextReader — ekstraktor teks PDF sederhana, PHP murni (tanpa Composer).
 *
 * Dirancang untuk PDF "Submissions" dari Moodle/Joglo:
 *  - stream ter-kompresi FlateDecode  (pakai gzuncompress / zlib)
 *  - teks berupa hex-string glyph + font subset
 *  - decode glyph -> unicode via /ToUnicode CMap (beginbfchar / beginbfrange)
 *
 * Pemakaian:
 *   $teks = PdfTextReader::extract('/path/file.pdf');
 *
 * Catatan: ini bukan parser PDF lengkap — cukup untuk PDF berbasis teks
 * dengan ToUnicode CMap. PDF hasil scan (gambar) tidak akan terbaca.
 */
class PdfTextReader
{
    /** Ekstrak seluruh teks PDF jadi satu string. */
    public static function extract($path)
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            throw new Exception('File PDF tidak terbaca.');
        }

        // Ambil semua blok stream...endstream
        $streams = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $m)) {
            $streams = $m[1];
        }
        if (!$streams) {
            throw new Exception('Tidak ada stream dalam PDF.');
        }

        // Decompress tiap stream (yang FlateDecode)
        $decoded = [];
        foreach ($streams as $s) {
            $raw = @gzuncompress($s);
            if ($raw === false) $raw = @gzinflate($s);       // fallback
            if ($raw === false) $raw = @gzinflate(substr($s, 2)); // skip zlib header
            if ($raw !== false && $raw !== '') $decoded[] = $raw;
        }
        if (!$decoded) {
            throw new Exception('Stream PDF gagal di-dekompresi (zlib).');
        }

        // Bangun peta glyph->unicode dari semua ToUnicode CMap
        $cmap = [];
        foreach ($decoded as $d) {
            if (strpos($d, 'beginbfchar') !== false ||
                strpos($d, 'beginbfrange') !== false) {
                self::parseCMap($d, $cmap);
            }
        }

        // Kumpulkan teks dari content stream (yang punya operator Tj/TJ)
        $teks = '';
        foreach ($decoded as $d) {
            if (strpos($d, 'Tj') === false && strpos($d, 'TJ') === false) continue;
            $teks .= self::extractFromContent($d, $cmap);
        }
        return $teks;
    }

    /** Parse ToUnicode CMap -> isi $cmap[hexGlyph] = "char". */
    private static function parseCMap($s, &$cmap)
    {
        // beginbfchar:  <25> <0041>
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $s, $blocks)) {
            foreach ($blocks[1] as $blk) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $pp, PREG_SET_ORDER)) {
                    foreach ($pp as $p) {
                        $cmap[strtoupper($p[1])] = self::hexToUtf8($p[2]);
                    }
                }
            }
        }
        // beginbfrange: <31> <32> <004D>   (rentang glyph berurutan)
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $s, $blocks)) {
            foreach ($blocks[1] as $blk) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/',
                                   $blk, $pp, PREG_SET_ORDER)) {
                    foreach ($pp as $p) {
                        $lo  = hexdec($p[1]);
                        $hi  = hexdec($p[2]);
                        $uni = hexdec($p[3]);
                        for ($g = $lo; $g <= $hi; $g++) {
                            $key = strtoupper(str_pad(dechex($g), strlen($p[1]), '0', STR_PAD_LEFT));
                            $cmap[$key] = self::hexToUtf8(dechex($uni + ($g - $lo)));
                        }
                    }
                }
            }
        }
    }

    /** Ekstrak teks dari satu content stream pakai peta cmap. */
    private static function extractFromContent($d, $cmap)
    {
        $out = '';
        // Token teks: hex <..>, atau literal (..), diikuti Tj; atau array [..] TJ
        // Tangani Tj tunggal:
        if (preg_match_all('/(<[0-9A-Fa-f\s]+>|\((?:[^()\\\\]|\\\\.)*\))\s*Tj/s',
                           $d, $m, PREG_SET_ORDER)) {
            foreach ($m as $tok) {
                $out .= self::decodeToken($tok[1], $cmap);
            }
        }
        // Tangani array TJ: [ <..> num <..> ... ] TJ
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $d, $m)) {
            foreach ($m[1] as $arr) {
                if (preg_match_all('/(<[0-9A-Fa-f\s]+>|\((?:[^()\\\\]|\\\\.)*\))/s',
                                   $arr, $mm)) {
                    foreach ($mm[1] as $tok) {
                        $out .= self::decodeToken($tok, $cmap);
                    }
                    $out .= ' ';
                }
            }
        }
        return $out;
    }

    /** Decode satu token (hex atau literal) jadi teks. */
    private static function decodeToken($tok, $cmap)
    {
        $tok = trim($tok);
        if ($tok === '') return '';
        // Hex string <....>
        if ($tok[0] === '<') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', $tok);
            $res = '';
            // glyph 1 byte (2 hex digit) — sesuai codespacerange <00><FF>
            for ($i = 0; $i + 2 <= strlen($hex); $i += 2) {
                $g = strtoupper(substr($hex, $i, 2));
                $res .= isset($cmap[$g]) ? $cmap[$g] : '';
            }
            return $res;
        }
        // Literal string (....)
        $lit = substr($tok, 1, -1);
        $lit = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $lit);
        return $lit;
    }

    /** Konversi codepoint hex -> karakter UTF-8. */
    private static function hexToUtf8($hex)
    {
        $cp = hexdec($hex);
        if ($cp <= 0)      return '';
        if ($cp < 0x80)    return chr($cp);
        if ($cp < 0x800)   return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        return chr(0xE0 | ($cp >> 12)) .
               chr(0x80 | (($cp >> 6) & 0x3F)) .
               chr(0x80 | ($cp & 0x3F));
    }
}
