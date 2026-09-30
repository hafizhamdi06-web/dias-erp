<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PdfReport;
use App\Services\PosSaleWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cetak struk POS. DUA ukuran (permintaan user 2026-09-30):
 *
 *   '58' - printer termal 58 mm ke bawah
 *   'a5' - setengah A4 / LX300, layout mengikuti CI3
 *          (`views/modul/laporan/formulir-penjualan-tunai.php`)
 *
 * Yang dipakai = preferensi user (`lv_user_pref.struk_pos`), jatuh ke
 * `config('pos.struk_default')` kalau belum disetel. Bisa ditimpa sekali jalan lewat
 * `?struk=58|a5` - supaya kasir tetap bisa mencetak ulang di ukuran lain tanpa mengubah
 * setelan (mis. struk termalnya habis, dicetak sementara ke printer biasa).
 */
class PosReceiptController extends Controller
{
    public function show(int $id, Request $r, PosSaleWriter $writer)
    {
        $h = $writer->header($id);
        abort_if(! $h, 404);

        /** @var User $user */
        $user = auth()->user();

        $minta = (string) $r->query('struk', '');
        $ukuran = array_key_exists($minta, config('pos.struk_pilihan', []))
            ? $minta
            : $user->strukPos();

        $kontak = $h->SUKONTAK
            ? DB::table('bkontak')->where('KID', $h->SUKONTAK)->first(['KNAMA', 'KKODE', 'KIDPASIEN'])
            : null;

        $branch = DB::table('bgudang')->where('GID', $h->SUCABANG)
            ->first(['GID', 'GKODE', 'GNAMA', 'GALAMAT1', 'GALAMAT2', 'GTELP', 'GNOHP']);

        $kasir = DB::table('auser')->where('UID', $h->SUCREATEU)->value('UNAMA');

        $data = [
            'h'      => $h,
            'lines'  => $writer->lines($id),
            'kontak' => $kontak,
            'branch' => $branch,
            'kasir'  => $kasir,
            'ukuran' => $ukuran,
        ];

        /*
         * 58 mm tetap HALAMAN HTML yang auto-print: printer termal dicetak langsung, dan
         * PDF selebar 58mm justru merepotkan.
         *
         * Setengah A4 dirender jadi PDF (permintaan user 2026-09-30): kasir jarang mencetak
         * fisik, lebih sering screenshot lalu dikirim lewat WA/email - pratinjau PDF lebih
         * rapi & ukurannya pasti. `preview()` = INLINE, jadi langsung tampil di tab browser.
         */
        if ($ukuran === '58') {
            return view('pos-receipt-58', $data);
        }

        $data['baris'] = $this->barisA5($id);
        $data['bank'] = $this->namaBank($h);
        $data['point'] = $this->point($h);
        $data['title'] = 'Struk ' . $h->SUNOTRANSAKSI;

        return app(PdfReport::class)->preview('pos-receipt-a5', $data, [
            'size'         => 'A5',
            'orientasi'    => 'L',
            'marginLeft'   => 8,
            'marginTop'    => 6,
            'marginBottom' => 6,
        ]);
    }

    /**
     * Baris item versi setengah A4 - butuh kolom yang TIDAK ada di `PosSaleWriter::lines()`:
     * keterangan "Ref-IC-Dokter-Perawat" dan info paket (untuk penggabungan baris).
     * Query & urutannya menyalin CI3 (`ORDER BY kodepaket, sdurutan`).
     */
    private function barisA5(int $id): array
    {
        return DB::table('fstokd as d')
            ->leftJoin('bitem as i', 'i.IID', '=', 'd.SDITEM')
            ->leftJoin('bkontak as dok', 'dok.KID', '=', 'd.SDDOKTER')
            ->leftJoin('bkontak as kar', 'kar.KID', '=', 'd.SDKARYAWAN')
            ->leftJoin('epaketd as pd', 'pd.PDID', '=', 'd.SDSODURUTAN')
            ->leftJoin('epaketu as pu', 'pu.PUID', '=', 'pd.PDIDU')
            ->where('d.SDIDSU', $id)
            ->orderByRaw('COALESCE(pu.PUKODE, "")')
            ->orderBy('d.SDURUTAN')
            ->get([
                DB::raw('i.INAMA as item'),
                DB::raw("CONCAT_WS('-', NULLIF(d.SDNOREF,''), NULLIF(d.SDLANTAI2,''), LEFT(dok.KNAMA,10), LEFT(kar.KNAMA,10)) as ket"),
                DB::raw('d.SDHARGA as harga'),
                DB::raw('d.SDDISKONPERSEN as dis1'),
                DB::raw('d.SDDISKON as diskon'),
                DB::raw('d.SDKELUAR as qty'),
                DB::raw('d.SDKELUAR * (d.SDHARGA - d.SDDISKON) as subtotal'),
                DB::raw('COALESCE(pu.PUKODE, "") as kodePaket'),
                DB::raw('COALESCE(pu.PUCETAKHEADERSAJA, 0) as headerSaja'),
                DB::raw('COALESCE(pu.PUJUMLAH, 0) as jumlahPaket'),
                DB::raw('d.SDCATATANKOLI as nomerPaket'),
                DB::raw('d.SDKEDATANGAN as kedatangan'),
                DB::raw('CONCAT(COALESCE(pu.PUKODE,""), COALESCE(d.SDKEDATANGAN,"")) as kodePaketLengkap'),
            ])
            ->all();
    }

    /** Nama bank untuk label "Debit <bank>" / "Kredit <bank>" / "Transfer <bank>". */
    private function namaBank(object $h): array
    {
        $ids = array_filter([$h->SUBANKDEBIT ?? null, $h->SUBANKKREDIT ?? null, $h->SUBANKTRANSFER ?? null]);

        $nama = $ids === []
            ? collect()
            : DB::table('bbank')->whereIn('BID', $ids)->pluck('BNAMA', 'BID');

        return [
            'debit'    => $nama[$h->SUBANKDEBIT ?? 0] ?? '',
            'kredit'   => $nama[$h->SUBANKKREDIT ?? 0] ?? '',
            'transfer' => $nama[$h->SUBANKTRANSFER ?? 0] ?? '',
        ];
    }

    /**
     * Jumlah point - rumus CI3: hanya kalau `SUSTATUSTADA = 1` DAN `SUTOTALTADA >= 100.000`,
     * lalu 1 point per 10.000 (dibulatkan ke bawah).
     */
    private function point(object $h): int
    {
        $tada = (float) ($h->SUTOTALTADA ?? 0);

        if ((int) ($h->SUSTATUSTADA ?? 0) !== 1 || $tada < 100000) {
            return 0;
        }

        return (int) floor($tada / 10000);
    }
}
