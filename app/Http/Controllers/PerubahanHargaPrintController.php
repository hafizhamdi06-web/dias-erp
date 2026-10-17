<?php

namespace App\Http\Controllers;

use App\Livewire\Master\PerubahanHargaList;
use App\Services\PdfReport;
use App\Services\PerubahanHargaWriter as W;
use Illuminate\Support\Facades\DB;

/**
 * Cetak dokumen "Perubahan Harga" (PH) - PDF A4 portrait, sekeluarga dgn cetakan dokumen
 * lain (judul tanpa kop, blok info 2 kolom, tabel, tanda tangan).
 *
 * **Dibuat BARU mengikuti isian form** (permintaan user 2026-10-17: "buat baru saja sesuai
 * inputan yang ada") - BUKAN tiruan cetakan VB6 `fRptPerubahanHarga.rpt` (areport 519; file
 * .rpt-nya terkompresi, labelnya tidak terbaca). Isi = isian form:
 * - Info: No Transaksi, Tanggal, Tgl Berlaku, Kontak, Keterangan, Kenaikan % (`SUNILAI`).
 * - Kolom = grid form: Kode | Nama | Harga Lama | Harga Baru (`SDHARGABARU`, sebelum
 *   pembulatan) | Setelah Pembulatan (`SDHARGAAKHIR` - yg DIPASANG ke harga jual) | Naik %.
 *   "Harga Sekarang" di form TIDAK ikut - itu nilai hidup dari master, bukan isian dokumen.
 * - Status dicetak terang: Draft diberi tanda "BELUM DITERAPKAN" supaya kertasnya tidak
 *   disangka harga yg sudah berlaku.
 * - Tanda tangan "Dibuat Oleh" = pembuat dokumen (`SUCREATEU`), "Disetujui Oleh" = user yg
 *   MENERAPKAN (log `terapkan` terakhir) - kosong selama belum diterapkan.
 *
 * Dokumen bisa ribuan baris ("Tampilkan" semua jenis = 1.912 item) -> ditulis BERTAHAP
 * (`PdfReport` opsi `perBagian`, lihat kontrak view-nya di sana). Nomor urut dihitung di sini
 * krn tiap potongan di-reindex.
 */
class PerubahanHargaPrintController extends Controller
{
    private const PER_BAGIAN = 400;

    public function show(int $id, PdfReport $pdf)
    {
        abort_unless(can_do(PerubahanHargaList::ACL, 'print'), 403);

        $h = DB::table('gperubahanhargau as u')
            ->leftJoin('bkontak as k', 'k.KID', '=', 'u.SUKONTAK')
            ->leftJoin('auser as us', 'us.UID', '=', 'u.SUCREATEU')
            ->where('u.SUID', $id)->where('u.SUSUMBER', W::SUMBER)
            ->first(['u.SUID', 'u.SUNOTRANSAKSI', 'u.SUTANGGAL', 'u.SUTANGGALBERLAKU', 'u.SUURAIAN',
                'u.SUSTATUS', 'u.SUNILAI', 'k.KNAMA as kontak', 'us.UNAMA as pembuat']);
        abort_if(! $h, 404);

        $log = DB::table('lv_perubahan_harga_log as l')
            ->leftJoin('auser as us', 'us.UID', '=', 'l.user_id')
            ->where('l.suid', $id)->orderByDesc('l.id')
            ->first(['l.aksi', 'l.created_at', 'l.jumlah_dilewati', 'us.UNAMA as user']);
        $terapkan = DB::table('lv_perubahan_harga_log as l')
            ->leftJoin('auser as us', 'us.UID', '=', 'l.user_id')
            ->where('l.suid', $id)->where('l.aksi', 'terapkan')->orderByDesc('l.id')
            ->first(['l.created_at', 'us.UNAMA as user']);

        // DIKELOMPOKKAN per COA tipe: `bitem.ICOA2021` -> `bcoatipe_perpt.CTTIPEID`, nama =
        // `CTNAMA` (permintaan user 2026-10-17; sempat per `IKELOMPOK2020`, diganti). User
        // menyebut "icoa2020" - kolom itu TIDAK ADA di bitem; yg ada ICOA2021, dan terbukti
        // yg dimaksud: dokumen surat edaran 16 Okt 2026 terbagi PERSIS spt suratnya (OTC 9 item,
        // Obat & Racikan = Produk Racikan 22 + Produk Umum 9). Join pakai `CTTIPEID` (pola
        // `DaftarStokBarang`), bukan `CTID` - keduanya sama kecuali tipe 0 (CTID 100).
        //
        // Nomor urut MULAI LAGI dari 1 di tiap kelompok. Urutan kelompok = `CTTIPEID`; tipe
        // tanpa nama (0, 99 - `CTNAMA` NULL) / tak dikenal = "Tanpa Kelompok" PALING AKHIR.
        // Di dalam kelompok tetap urutan baris dokumen.
        //
        // Penanda kelompok (`awalGrup`/`akhirGrup` + subtotal di baris terakhir) dihitung DI
        // SINI atas SELURUH baris, bukan di view: cetakan ditulis bertahap per 400 baris, jadi
        // view hanya melihat sepotong dan tidak tahu kelompok mana yg berlanjut.
        $rows = DB::table('gperubahanhargad as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bsatuan as s', 's.SID', '=', 'i.ISATUAN')
            ->leftJoin('bcoatipe_perpt as k', fn ($j) => $j->on('k.CTTIPEID', '=', 'i.ICOA2021')->whereNotNull('k.CTNAMA'))
            ->where('d.SDIDSU', $id)
            ->orderByRaw('CASE WHEN k.CTTIPEID IS NULL THEN 1 ELSE 0 END')
            ->orderBy('k.CTTIPEID')->orderBy('d.SDURUTAN')
            ->get(['i.IKODE as kode', 'i.INAMA as nama', 's.SKODE as satuan', 'k.CTTIPEID as grupId', 'k.CTNAMA as grup',
                'd.SDHARGALAMA as lama', 'd.SDHARGABARU as baru', 'd.SDHARGAAKHIR as akhir'])
            ->map(function ($r) {
                $r->grup = trim((string) $r->grup) ?: 'Tanpa Kelompok';
                $r->lama = (float) $r->lama;
                $r->baru = (float) $r->baru;
                $r->akhir = (float) $r->akhir;
                $r->selisih = $r->akhir - $r->lama;
                $r->persen = $r->lama > 0 ? $r->selisih / $r->lama * 100 : null;

                return $r;
            })->values();

        $mulai = 0;
        foreach ($rows as $i => $r) {
            $kunci = $r->grupId ?? 0;
            $r->awalGrup = $i === 0 || ($rows[$i - 1]->grupId ?? 0) !== $kunci;
            if ($r->awalGrup) {
                $mulai = $i;
            }
            $r->no = $i - $mulai + 1;
            $r->akhirGrup = $i === $rows->count() - 1 || ($rows[$i + 1]->grupId ?? 0) !== $kunci;
            if ($r->akhirGrup) {
                $isi = $rows->slice($mulai, $i - $mulai + 1);
                $r->subtotal = ['item' => $isi->count(), 'lama' => $isi->sum('lama'),
                    'baru' => $isi->sum('baru'), 'akhir' => $isi->sum('akhir')];
            }
        }

        return $pdf->preview('reports.perubahan-harga-print', [
            'title'    => 'Perubahan Harga ' . $h->SUNOTRANSAKSI,
            'h'        => $h,
            'log'      => $log,
            'terapkan' => $terapkan,
            'rows'     => $rows,
            'lampiran' => app(W::class)->lampiran($id),
            // Kolom "Setelah Pembulatan" HANYA dicetak kalau ada baris yg benar2 dibulatkan
            // (permintaan user 2026-10-17). Pembulatan tidak disimpan di dokumen, jadi dibaca
            // dari datanya. Dihitung atas SELURUH baris di sini - bukan per potongan di view -
            // supaya semua halaman cetakan bertahap punya kolom yg sama.
            'adaPembulatan' => $rows->contains(fn ($r) => abs($r->baru - $r->akhir) >= 0.005),
            'jmlGrup'  => $rows->pluck('grupId')->unique()->count(),
            'total'    => [
                'item'  => $rows->count(),
                'lama'  => $rows->sum('lama'),
                'baru'  => $rows->sum('baru'),
                'akhir' => $rows->sum('akhir'),
                'naik'  => $rows->filter(fn ($r) => $r->selisih > 0.004)->count(),
                'turun' => $rows->filter(fn ($r) => $r->selisih < -0.004)->count(),
                'tetap' => $rows->filter(fn ($r) => abs($r->selisih) <= 0.004)->count(),
            ],
        ], ['size' => 'A4', 'orientasi' => 'P', 'perBagian' => self::PER_BAGIAN]);
    }
}
