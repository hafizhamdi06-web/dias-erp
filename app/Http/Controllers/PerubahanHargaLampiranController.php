<?php

namespace App\Http\Controllers;

use App\Livewire\Master\PerubahanHargaList;
use App\Services\PerubahanHargaWriter as W;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Buka lampiran (surat/IOM kenaikan harga) dokumen Perubahan Harga. File ada di disk privat,
 * jadi SATU-SATUNYA jalan membukanya lewat sini - hak `view` diperiksa, dan jalur file diambil
 * dari DB (tidak pernah dari URL), jadi tidak bisa dipakai membaca file lain di server.
 *
 * Ditampilkan INLINE (PDF/gambar terbuka di tab baru) dgn nama file aslinya. Sidik SHA-256
 * dicocokkan ulang: kalau isi file di disk sudah berubah sejak diunggah, ditolak - bukti audit
 * yg sudah diubah tidak boleh tampil seolah asli.
 */
class PerubahanHargaLampiranController extends Controller
{
    public function show(int $id)
    {
        abort_unless(can_do(PerubahanHargaList::ACL, 'view'), 403);

        $l = DB::table('lv_perubahan_harga_lampiran')->where('id', $id)->first();
        abort_if(! $l, 404);

        $disk = Storage::disk(W::DISK);
        abort_unless($disk->exists($l->path), 404, 'File lampiran tidak ditemukan di server.');
        abort_unless(hash_file('sha256', $disk->path($l->path)) === $l->sha256, 409,
            'Isi file lampiran tidak cocok dengan yang diunggah (kemungkinan diubah di server).');

        return response()->file($disk->path($l->path), [
            'Content-Type'           => $l->mime,
            'Content-Disposition'    => 'inline; filename="' . addcslashes($l->nama_asli, '"\\') . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
