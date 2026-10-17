<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Baca file tabel unggahan user (.xlsx / .csv / .txt) jadi array baris - TANPA library.
 *
 * PhpSpreadsheet TIDAK ada di proyek ini (lihat CLAUDE.md "Referensi spek Excel user"), jadi
 * .xlsx dibaca langsung: file .xlsx = zip berisi XML. Hanya SHEET PERTAMA yg dibaca.
 * Yg ditangani: shared string (`t="s"`, termasuk teks ber-format `<r><t>`), inline string,
 * hasil rumus teks (`t="str"`), boolean, dan angka. Sel kosong yg dilompati Excel (tidak
 * ditulis di XML) diisi '' supaya posisi kolom tetap benar - posisi diambil dari atribut
 * `r` ("C5"), BUKAN dari urutan sel.
 *
 * CSV: pemisah dideteksi dari baris pertama (`;` - default Excel berlocale Indonesia, `,`,
 * atau TAB), BOM UTF-8 dibuang.
 */
class TabelImport
{
    /** Batas baris - pengaman terhadap file salah pilih yg berisi ratusan ribu baris. */
    public const MAKS_BARIS = 10000;

    /** @return list<list<string>> */
    public static function baca(string $path, string $ekstensi): array
    {
        $baris = match (strtolower($ekstensi)) {
            'xlsx'        => self::xlsx($path),
            'csv', 'txt'  => self::csv($path),
            default       => throw new RuntimeException('Format file harus .xlsx atau .csv.'),
        };

        // Baris yg seluruh selnya kosong dibuang (Excel sering menyisakan baris berformat).
        $baris = array_values(array_filter($baris,
            fn ($r) => implode('', array_map(fn ($v) => trim((string) $v), $r)) !== ''));

        if (count($baris) > self::MAKS_BARIS) {
            throw new RuntimeException('File berisi lebih dari ' . number_format(self::MAKS_BARIS, 0, ',', '.') . ' baris.');
        }

        return $baris;
    }

    private static function csv(string $path): array
    {
        $isi = (string) file_get_contents($path);
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);
        $pertama = strtok($isi, "\r\n") ?: '';
        $hitung = [';' => substr_count($pertama, ';'), ',' => substr_count($pertama, ','), "\t" => substr_count($pertama, "\t")];
        arsort($hitung);
        $pemisah = (string) array_key_first($hitung);

        $h = fopen('php://memory', 'r+');
        fwrite($h, $isi);
        rewind($h);
        $baris = [];
        while (($r = fgetcsv($h, 0, $pemisah, '"', '')) !== false) {
            $baris[] = array_map(fn ($v) => (string) $v, $r);
        }
        fclose($h);

        return $baris;
    }

    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File .xlsx tidak bisa dibuka (rusak, atau bukan .xlsx).');
        }

        try {
            $sheet = self::lokasiSheetPertama($zip);
            $shared = self::sharedStrings($zip);
            $xml = $zip->getFromName($sheet);
            if ($xml === false) {
                throw new RuntimeException('Sheet pertama tidak ditemukan di file .xlsx.');
            }
            $doc = new SimpleXMLElement($xml);

            $baris = [];
            foreach ($doc->sheetData->row as $row) {
                $r = [];
                foreach ($row->c as $c) {
                    $kolom = self::indeksKolom((string) $c['r']);
                    $tipe = (string) $c['t'];
                    $nilai = match ($tipe) {
                        's'         => $shared[(int) $c->v] ?? '',
                        'inlineStr' => self::teks($c->is),
                        'b'         => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                        default     => (string) $c->v,   // angka & t="str" (hasil rumus)
                    };
                    $r[$kolom] = $nilai;
                }
                if ($r !== []) {
                    $penuh = array_fill(0, max(array_keys($r)) + 1, '');
                    $baris[] = array_replace($penuh, $r);
                }
            }

            return $baris;
        } finally {
            $zip->close();
        }
    }

    private static function lokasiSheetPertama(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }
        $w = new SimpleXMLElement($wb);
        $sheet = $w->sheets->sheet[0] ?? null;
        $rid = $sheet ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';

        foreach ((new SimpleXMLElement($rels))->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = (string) $rel['Target'];

                // Target bisa relatif ("worksheets/sheet1.xml") atau absolut ("/xl/worksheets/...").
                return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $hasil = [];
        foreach ((new SimpleXMLElement($xml))->si as $si) {
            $hasil[] = self::teks($si);
        }

        return $hasil;
    }

    /** Teks sebuah `<si>`/`<is>`: `<t>` langsung, atau gabungan `<r><t>` (teks ber-format). */
    private static function teks(?SimpleXMLElement $el): string
    {
        if (! $el) {
            return '';
        }
        if (isset($el->t)) {
            return (string) $el->t;
        }
        $s = '';
        foreach ($el->r as $r) {
            $s .= (string) $r->t;
        }

        return $s;
    }

    /** "C5" -> 2, "AA10" -> 26. */
    private static function indeksKolom(string $ref): int
    {
        $huruf = preg_replace('/\d+/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split($huruf) as $h) {
            $n = $n * 26 + (ord($h) - 64);
        }

        return max(0, $n - 1);
    }

    /**
     * Angka dari teks gaya Indonesia MAUPUN Inggris: "55.000", "55000", "Rp 55.000,50",
     * "55,000.50", "55000.5". Null kalau bukan angka.
     *
     * Aturan pemisah: kalau ada '.' DAN ',' -> yg TERAKHIR = desimal. Kalau cuma salah satu
     * dan polanya kelompok ribuan (1-3 digit lalu kelompok 3 digit) -> ribuan; selain itu
     * desimal. Jadi "55.000" = 55000, tapi "55.5" = 55,5.
     */
    public static function angka(string|int|float|null $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^\d.,\-]/', '', (string) $v);
        if ($s === '' || $s === '-' || ! preg_match('/\d/', $s)) {
            return null;
        }

        $titik = strrpos($s, '.');
        $koma = strrpos($s, ',');
        if ($titik !== false && $koma !== false) {
            [$ribuan, $desimal] = $titik > $koma ? [',', '.'] : ['.', ','];
            $s = str_replace([$ribuan, $desimal], ['', '.'], $s);
        } elseif ($titik !== false || $koma !== false) {
            $p = $titik !== false ? '.' : ',';
            $s = preg_match('/^-?\d{1,3}(\\' . $p . '\d{3})+$/', $s)
                ? str_replace($p, '', $s)
                : str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
