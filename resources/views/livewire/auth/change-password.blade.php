{{-- Layar wajib ganti password. Tampilannya mengikuti halaman login (berdiri sendiri,
     BUKAN di dalam shell tab) - user memang belum boleh masuk aplikasi. --}}
<div class="login-box" style="width: 26rem;">
    <div class="card card-outline card-primary">
        <div class="card-header text-center">
            <span class="h4 fw-light">{{ config('app.name') }}</span>
        </div>
        <div class="card-body">
            <div class="alert alert-warning py-2">
                <i class="fas fa-key me-1"></i>
                Password Anda dibuatkan admin dan bersifat <strong>sementara</strong>.
                Silakan ganti dulu sebelum memakai aplikasi.
            </div>

            <p class="text-muted small mb-3">Masuk sebagai <strong>{{ $namaUser }}</strong></p>

            <form wire:submit="simpan">
                <div class="mb-3">
                    <label class="form-label">Password Lama <span class="text-danger">*</span></label>
                    <input type="password" class="form-control @error('passwordLama') is-invalid @enderror"
                           wire:model="passwordLama" autocomplete="current-password" autofocus>
                    @error('passwordLama') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label">Password Baru <span class="text-danger">*</span></label>
                    <input type="password" class="form-control @error('passwordBaru') is-invalid @enderror"
                           wire:model="passwordBaru" autocomplete="new-password">
                    @error('passwordBaru') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">
                        Minimal {{ config('acl.password.min', 10) }} karakter, harus mengandung huruf dan angka.
                        Hindari nama sendiri, nama klinik, atau kata yang mudah ditebak.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Ulangi Password Baru <span class="text-danger">*</span></label>
                    <input type="password" class="form-control"
                           wire:model="passwordBaruConfirmation" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <span wire:loading wire:target="simpan" class="spinner-border spinner-border-sm me-1"></span>
                    Simpan Password Baru
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="mt-3 text-center">
                @csrf
                <button type="submit" class="btn btn-link btn-sm text-muted">Keluar</button>
            </form>
        </div>
    </div>
</div>
