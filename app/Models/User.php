<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * auser - tabel user legacy, dipakai bersama CI3 & CI4. Dipakai apa adanya.
 *
 * Login memakai kolom UKODE (username unik). Password lama ada di UPASSWORD
 * (MD5 mentah tanpa salt) dan TIDAK PERNAH diubah aplikasi ini supaya CI3 tetap
 * jalan. Hash modern (bcrypt) disimpan terpisah di lv_user_auth.
 *
 * @property int         $UID
 * @property string      $UKODE
 * @property string|null $UNAMA
 * @property string|null $UNAMALENGKAP
 * @property int|null    $UACTIVE
 * @property int|null    $UCABANG
 * @property string|null $UCABANGPILIH
 * @property int|null    $UKID pegawai (bkontak.KID) yg terhubung ke akun login ini - dipakai sbg "kasir" di POS.
 */
class User extends Authenticatable
{
    protected $table = 'auser';

    protected $primaryKey = 'UID';

    public $timestamps = false;

    protected $guarded = ['UID'];

    protected $hidden = ['UPASSWORD'];

    /** Hash password modern (dari lv_user_auth). */
    public function authSecret(): HasOne
    {
        return $this->hasOne(UserAuth::class, 'user_id', 'UID');
    }

    /* ---- Kontrak Authenticatable ------------------------------------- */

    /** Password yang dipakai guard = hash modern di lv_user_auth (bisa kosong). */
    public function getAuthPassword(): string
    {
        return $this->authSecret?->password_hash ?? '';
    }

    public function getAuthPasswordName(): string
    {
        return 'auth_password';
    }

    // auser tidak punya kolom remember_token - matikan fitur "ingat saya".
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // no-op
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    /* ---- Bantu tampilan --------------------------------------------- */

    public function displayName(): string
    {
        return $this->UNAMALENGKAP ?: ($this->UNAMA ?: $this->UKODE);
    }

    /** Daftar GID cabang yang boleh diakses user (dari UCABANGPILIH). */
    public function branchIds(): array
    {
        return array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $this->UCABANGPILIH)
        )));
    }

    /**
     * Super user - definisi SAMA PERSIS `App\Support\Acl` (UID di `super_user_ids` ATAU
     * UKODE di `super_user_codes`). Ditaruh di sini supaya ada SATU definisi kanonik yg
     * bisa dipakai di luar Acl, mis. penyaringan cabang di laporan.
     */
    public function isSuperUser(): bool
    {
        return in_array((int) $this->UID, config('acl.super_user_ids', []), true)
            || in_array((string) $this->UKODE, config('acl.super_user_codes', []), true);
    }

    /**
     * Cabang yang boleh DILIHAT DATANYA (laporan, daftar lintas cabang).
     * **`[]` berarti TANPA BATAS** - dipakai `whereIn` hanya kalau tidak kosong.
     *
     * Super user TIDAK dibatasi, konsisten dgn `Acl` yg melewatkan super user dari semua
     * pemeriksaan hak akses. Ini penting secara praktis: UID 1 (Administrator) justru
     * `UCABANGPILIH`-nya cuma `1`, jadi tanpa pengecualian ini admin malah terkunci ke
     * satu cabang dan laporan lintas cabang mati.
     */
    public function visibleBranchIds(): array
    {
        return $this->isSuperUser() ? [] : $this->branchIds();
    }

    /**
     * Cabang AKTIF - baca dari session (`active_cabang_{UID}`) kalau user PERNAH ganti
     * lewat `switchActiveCabang()` DAN pilihan itu MASIH valid (ada di `branchIds()` user
     * ini - jaga2 kalau `UCABANGPILIH` diubah admin stlh session disimpan), fallback ke
     * `UCABANG` asli DB kalau tidak/belum pernah diganti (2026-09-24, permintaan user -
     * "untuk user yang pilihan cabangnya banyak, mengganti cabang aktif").
     *
     * **Accessor Eloquent** (nama method WAJIB `getUCABANGAttribute`, huruf besar semua -
     * dicek eksplisit via `Str::studly('UCABANG')` = "UCABANG", bukan "Ucabang") - artinya
     * SEMUA kode lain di app ini yg baca `$user->UCABANG` (puluhan Livewire component
     * `mount()` utk default cabang/gudang form baru) OTOMATIS ikut cabang aktif hasil
     * switch, TANPA perlu diubah satu-per-satu.
     */
    public function getUCABANGAttribute($value): ?int
    {
        $override = session('active_cabang_' . $this->UID);
        if ($override !== null && in_array((int) $override, $this->branchIds(), true)) {
            return (int) $override;
        }

        return $value !== null ? (int) $value : null;
    }

    /**
     * Ganti cabang aktif (session-only, TIDAK menulis `auser.UCABANG` di DB - "aktif SAAT
     * INI", bukan ganti default permanen). Ditolak kalau `$gid` bukan bagian
     * `branchIds()` user ini (cegah lompat ke cabang yg tidak diizinkan lewat request
     * manual/devtools).
     */
    public function switchActiveCabang(int $gid): bool
    {
        if (! in_array($gid, $this->branchIds(), true)) {
            return false;
        }

        session(['active_cabang_' . $this->UID => $gid]);

        return true;
    }

    /** Nama cabang aktif (UCABANG) utk ditampilkan di navbar - "Semua Cabang" kalau kosong. */
    public function activeBranchName(): string
    {
        if (! $this->UCABANG) {
            return 'Semua Cabang';
        }

        return \App\Models\Branch::query()->where('GID', $this->UCABANG)->value('GNAMA') ?: 'Semua Cabang';
    }
}
