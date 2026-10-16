<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim struk POS ke email pasien - port VB6 `cModTransaksi.KirimStrukKeEmail`
 * (permintaan user 2026-10-16).
 *
 * Bukan kirim email sendiri: POST `{"ip": "<No IP>"}` ke API klinik (yg menyusun & mengirim
 * email ke alamat pasien), header `Authorization`. URL & token dari tabel `aconfig`
 * (CGROUP 'KirimEmail', CFIELD 'Link' / 'Auth') - sumber yg SAMA dgn VB6 `FindConfig`
 * (permintaan user 2026-10-16). `.env` POS_EMAIL_STRUK_URL/AUTH, kalau diisi, MENIMPA
 * aconfig (mis. server uji yg tidak boleh mengirim email sungguhan). JANGAN taruh token
 * di kode.
 *
 * Tiap percobaan dicatat ke `zlogemail` spt VB6 (LENOIP, LESTATUS 1/0, LERESPON 255 huruf).
 * Hanya utk cabang `bgudang.GKIRIMEMAIL = 1`.
 */
class EmailStruk
{
    /** Nilai `aconfig` grup KirimEmail (perbandingan teks tidak peka huruf besar/kecil). */
    private function aconfig(string $field): string
    {
        return trim((string) DB::table('aconfig')->where('CGROUP', 'KirimEmail')->where('CFIELD', $field)->value('CVALUE'));
    }

    public function cabangKirim(int $gid): bool
    {
        return (int) DB::table('bgudang')->where('GID', $gid)->value('GKIRIMEMAIL') === 1;
    }

    /** @return array{ok:bool,pesan:string} */
    public function kirim(int $suid, string $noIp): array
    {
        $url = (string) config('pos.email_struk.url') ?: $this->aconfig('Link');
        $auth = (string) config('pos.email_struk.auth') ?: $this->aconfig('Auth');

        if ($url === '' || $auth === '') {
            return ['ok' => false, 'pesan' => 'Pengiriman struk ke email belum dikonfigurasi (aconfig KirimEmail Link/Auth) - hubungi admin.'];
        }

        $status = 0;
        $respon = '';
        try {
            $res = Http::withHeaders(['Authorization' => $auth])
                ->acceptJson()
                ->timeout((int) config('pos.email_struk.timeout', 15))
                ->post($url, ['ip' => $noIp]);
            $respon = (string) $res->body();
            // VB6: UCase(p.Item("status")) = "TRUE" - bisa boolean true atau teks "true".
            $s = $res->json('status');
            $status = ($s === true || strtoupper((string) $s) === 'TRUE') ? 1 : 0;
        } catch (\Throwable $e) {
            $respon = 'GALAT: ' . $e->getMessage();
            Log::warning('Kirim struk email gagal', ['ip' => $noIp, 'galat' => $e->getMessage()]);
        }

        DB::table('zlogemail')->insert([
            'LENOIP'   => $noIp,
            'LEIDIP'   => $suid,
            'LESTATUS' => $status,
            'LERESPON' => mb_substr($respon, 0, 255),
        ]);

        return $status === 1
            ? ['ok' => true, 'pesan' => 'Struk penjualan sukses dikirim ke email pasien.']
            : ['ok' => false, 'pesan' => 'Struk penjualan gagal dikirim ke email pasien. Pastikan alamat email ada di database pasien.'];
    }
}
