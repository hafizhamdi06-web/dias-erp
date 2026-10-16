{{-- Panduan > Transaksi Surgery. Label & pesan disalin dari Kasir/POS Laravel
     (resources/views/livewire/sales/pos-terminal.blade.php, App\Livewire\Sales\PosTerminal).
     Tiruan tampilan di sini sengaja NON-AKTIF (disabled) - hanya gambaran. --}}
<div class="container-fluid" style="max-width: 980px">
    @include('livewire.panduan.partials.gaya')

    <div class="pdn py-2">
        <h2 class="h4 fw-bold mb-1"><i class="fas fa-user-doctor me-2 text-primary"></i>Panduan Transaksi Surgery</h2>
        <p class="text-muted mb-3">Untuk kasir &amp; admin klinik &middot; menu <strong>Penjualan &rsaquo; Kasir / POS</strong></p>

        {{-- ============ APA ITU ============ --}}
        <div class="alert alert-primary">
            <strong>Transaksi surgery</strong> adalah tindakan bernilai besar yang <strong>dibayar dicicil</strong>
            (2, 3 kali atau lebih). Semua pembayarannya tercatat sebagai <strong>satu rangkaian nota</strong>,
            dan <strong>operasi dilakukan pada pembayaran terakhir (pelunasan)</strong>.
        </div>

        <h4>Aturan penting</h4>
        <ul>
            <li>Setiap nota surgery <strong>wajib menyalakan "Surgery"</strong> — pembayaran pertama, cicilan, maupun pelunasan.</li>
            <li>Sisa yang belum dibayar dicatat di kolom <strong>Piutang</strong>.</li>
            <li>Cicilan berikutnya <strong>selalu lewat tombol "No IP Surgery…"</strong> — jangan membuat nota baru dengan mengetik ulang tindakannya,
                karena rangkaian cicilannya akan terputus.</li>
            <li><strong>Hari operasi = nota pelunasan</strong> (Piutang 0). Alkes diinput untuk nota pelunasan ini.</li>
            <li>Jangan menyalakan "Surgery" untuk transaksi biasa.</li>
        </ul>

        {{-- ============ ALUR ============ --}}
        <h4>Alur singkat</h4>
        <div class="alur mb-2">
            <div class="kotak">
                <div class="fw-semibold">1. Pembayaran pertama</div>
                <div class="small text-muted">Input tindakan lengkap (harga penuh), bayar sebagian, sisanya Piutang.</div>
            </div>
            <div class="panah"><i class="fas fa-arrow-right"></i></div>
            <div class="kotak">
                <div class="fw-semibold">2. Cicilan berikutnya</div>
                <div class="small text-muted">"No IP Surgery…" &rarr; pilih nota sebelumnya &rarr; bayar lagi, sisa tetap Piutang.</div>
            </div>
            <div class="panah"><i class="fas fa-arrow-right"></i></div>
            <div class="kotak border-success">
                <div class="fw-semibold text-success">3. Pelunasan = hari operasi</div>
                <div class="small text-muted">"No IP Surgery…" &rarr; bayar sisanya, Piutang 0 &rarr; input alkes.</div>
            </div>
        </div>
        <p class="small text-muted">Langkah 2 bisa diulang berapa kali pun sesuai jumlah cicilan.</p>

        {{-- ============ LANGKAH 1 ============ --}}
        <h4>Langkah 1 — Pembayaran pertama</h4>
        <ol class="langkah">
            <li>Buka <strong>Kasir / POS</strong>, pilih pasien dengan <strong>Cari pelanggan</strong> <kbd>F1</kbd>.</li>
            <li>Tambahkan tindakan surgery <strong>lengkap dengan harga penuh</strong> lewat pencarian item <kbd>F2</kbd>,
                lalu lengkapi Operator/Dokter dan No Ref/No IC seperti biasa.</li>
            <li>Isi <strong>Catatan Rekam Medis</strong>.</li>
            <li>Di panel pasien (kanan), <strong>nyalakan "Surgery"</strong>.
                <div class="tiruan mt-2">
                    <div class="tiruan-label">Tampilan di POS</div>
                    <div class="form-check form-switch mb-1">
                        <input type="checkbox" class="form-check-input" checked disabled>
                        <label class="form-check-label small fw-semibold">Surgery</label>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm w-100 text-start" disabled>
                        <i class="fas fa-user-doctor me-1"></i> No IP Surgery… <span class="text-muted">(cicilan berikutnya)</span>
                    </button>
                    <div class="form-text">Nota pertama: isi tindakan lengkap, sisa pembayaran diisi di kolom <strong>Piutang</strong>.</div>
                </div>
                <div class="small text-muted mt-1">Pada pembayaran pertama, tombol "No IP Surgery…" <strong>tidak perlu</strong> diklik.</div>
            </li>
            <li>Klik <strong>Isi Pembayaran</strong> <kbd>F8</kbd>. Isi uang yang dibayar pasien hari ini (Tunai, Debit, Transfer, dll).</li>
            <li>Di kotak <strong>Surgery</strong> pada dialog bayar, klik <strong>Sisa &rarr; Piutang</strong> — sisa tagihan otomatis menjadi piutang.
                <div class="tiruan mt-2">
                    <div class="tiruan-label">Tampilan di dialog Pembayaran</div>
                    <div class="border border-primary rounded p-2 bg-body">
                        <div class="small fw-semibold mb-1"><i class="fas fa-user-doctor me-1"></i>Surgery</div>
                        <label class="form-label small mb-1">Piutang (sisa belum dibayar)</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control text-end" value="14000000" disabled>
                            <button type="button" class="btn btn-outline-primary" disabled>Sisa → Piutang</button>
                        </div>
                        <div class="form-text">Piutang 0 = lunas = hari operasi.</div>
                    </div>
                </div>
            </li>
            <li>Klik <strong>OK</strong> <kbd>Ctrl</kbd>+<kbd>Enter</kbd>, lalu <strong>Simpan Transaksi</strong> di layar utama.</li>
        </ol>

        {{-- ============ LANGKAH 2 ============ --}}
        <h4>Langkah 2 — Cicilan berikutnya</h4>
        <ol class="langkah">
            <li>Pilih pasien yang sama <kbd>F1</kbd>, lalu <strong>nyalakan "Surgery"</strong>.</li>
            <li>Klik <strong>No IP Surgery…</strong>. Yang tampil hanya surgery pasien itu yang <strong>masih berjalan</strong>
                (nota terakhir yang masih ada sisanya), lengkap dengan Total, Dibayar, dan Sisa. Pilih notanya.</li>
            <li>Tindakan dari nota sebelumnya <strong>otomatis masuk ke keranjang</strong>, dan muncul keterangan:
                <div class="tiruan mt-2">
                    <div class="tiruan-label">Tampilan di POS</div>
                    <div class="border rounded p-2 small bg-light">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold">Lanjutan dari BL-IP26100001</div>
                                <div class="text-muted">Sudah dibayar 6.000.000 &middot; sisa 14.000.000</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Lepas</button>
                        </div>
                    </div>
                </div>
                <div class="small text-muted mt-1"><strong>Lepas</strong> = batal menarik nota itu (isi keranjang tidak dihapus).
                    Jangan mengubah tindakan kecuali memang ada perubahan tindakan.</div>
            </li>
            <li>Isi <strong>Catatan Rekam Medis</strong>.</li>
            <li>Klik <strong>Isi Pembayaran</strong> <kbd>F8</kbd>. Di kotak Surgery, <strong>"Sudah dibayar"</strong> dari nota sebelumnya
                sudah terhitung otomatis. Isi uang yang dibayar hari ini, lalu klik <strong>Sisa &rarr; Piutang</strong>.</li>
            <li><strong>OK</strong>, lalu <strong>Simpan Transaksi</strong>.</li>
        </ol>

        {{-- ============ LANGKAH 3 ============ --}}
        <h4>Langkah 3 — Pelunasan (hari operasi)</h4>
        <ol class="langkah">
            <li>Sama seperti Langkah 2: pilih pasien, nyalakan "Surgery", klik <strong>No IP Surgery…</strong>, pilih nota terakhir.</li>
            <li>Di dialog bayar, isi <strong>seluruh sisa</strong> tagihan sehingga <strong>Piutang = 0</strong>. Simpan.</li>
            <li>Setelah operasi, input alkes di menu <strong>Penjualan &rsaquo; Input Alkes Depo</strong> untuk <strong>nota pelunasan ini</strong>. Nota ini otomatis muncul di daftar <strong>Perlu diinput alkes</strong> di bagian atas menu tersebut — klik <strong>Input Alkes</strong> di barisnya.</li>
            <li>Cek menu <strong>Closing Harian IP</strong> — nota pelunasan akan muncul di sana sampai alkesnya diinput.
                Nota cicilan (yang masih ada piutang) <strong>tidak</strong> ditagih alkes.</li>
        </ol>

        {{-- ============ CONTOH ============ --}}
        <h4>Contoh</h4>
        <p>Surgery senilai <strong>20.000.000</strong>, dibayar 3 kali:</p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered small" style="max-width: 640px">
                <thead class="table-light"><tr><th>Nota</th><th>Cara</th><th class="text-end">Dibayar</th><th class="text-end">Sudah dibayar sebelumnya</th><th class="text-end">Piutang</th></tr></thead>
                <tbody>
                    <tr><td>1</td><td>Surgery + tindakan lengkap</td><td class="text-end">6.000.000</td><td class="text-end">0</td><td class="text-end">14.000.000</td></tr>
                    <tr><td>2</td><td>No IP Surgery &rarr; nota 1</td><td class="text-end">8.000.000</td><td class="text-end">6.000.000</td><td class="text-end">6.000.000</td></tr>
                    <tr class="table-success"><td>3</td><td>No IP Surgery &rarr; nota 2</td><td class="text-end">6.000.000</td><td class="text-end">14.000.000</td><td class="text-end fw-bold">0 (lunas, operasi)</td></tr>
                </tbody>
            </table>
        </div>
        <p class="small text-muted">Penjualan tercatat sesuai uang yang diterima di setiap nota: 6 jt + 8 jt + 6 jt = 20 jt.
            Biaya (COGS) dan alkes hanya dihitung di nota pelunasan.</p>

        {{-- ============ PESAN ============ --}}
        <h4>Pesan yang mungkin muncul</h4>
        <div class="table-responsive">
            <table class="table table-sm small align-middle">
                <thead class="table-light"><tr><th style="width:45%">Pesan</th><th>Artinya &amp; yang harus dilakukan</th></tr></thead>
                <tbody>
                    <tr><td><em>Piutang hanya untuk transaksi Surgery - centang "Surgery" dulu.</em></td>
                        <td>Piutang diisi tapi "Surgery" belum dinyalakan. Nyalakan "Surgery", atau kosongkan Piutang kalau ini transaksi biasa.</td></tr>
                    <tr><td><em>Piutang melebihi sisa tagihan - maksimal …</em></td>
                        <td>Pembayaran + piutang lebih dari tagihan. Gunakan tombol <strong>Sisa &rarr; Piutang</strong> agar angkanya pas.</td></tr>
                    <tr><td><em>Pilih pasien dulu sebelum menarik No IP Surgery.</em></td>
                        <td>Pilih pasien <kbd>F1</kbd> terlebih dahulu.</td></tr>
                    <tr><td><em>Tidak ada surgery yang masih berjalan untuk pasien ini.</em></td>
                        <td>Pasien belum punya surgery, atau surgery-nya sudah lunas. Untuk surgery baru, ikuti Langkah 1.</td></tr>
                    <tr><td><em>Nota surgery … tidak bisa dilanjutkan lagi …</em></td>
                        <td>Nota itu sudah dilanjutkan oleh kasir lain atau sudah lunas. Klik <strong>Lepas</strong>, lalu tarik ulang lewat <strong>No IP Surgery…</strong>.</td></tr>
                    <tr><td><em>Total transaksi (…) lebih kecil dari yang sudah dibayar (…).</em></td>
                        <td>Tindakan di keranjang dikurangi sampai nilainya di bawah yang sudah dibayar pasien. Periksa kembali tindakannya; hubungi admin bila memang ada pengembalian dana.</td></tr>
                    <tr><td><em>Nota … sudah dilanjutkan cicilan surgery berikutnya - tidak bisa diedit.</em></td>
                        <td>Nota cicilan yang sudah dilanjutkan tidak boleh diedit agar rangkaiannya tidak putus. Bila ada kesalahan, hubungi admin.</td></tr>
                </tbody>
            </table>
        </div>

        {{-- ============ PEMANTAUAN ============ --}}
        <h4>Memantau surgery</h4>
        <p>Buka <strong>Penjualan &rsaquo; Dashboard Surgery</strong>, pilih cabang &amp; periode, lalu klik <strong>Tampilkan</strong>.</p>
        <ul>
            <li><span class="badge text-bg-warning">Berjalan</span> masih ada piutang — operasi belum dilakukan.</li>
            <li><span class="badge text-bg-success">Selesai</span> sudah lunas — operasi sudah dilakukan.</li>
            <li><span class="badge text-bg-danger">Perlu dicek</span> data tidak wajar (mis. nilai 0, atau nota sebelumnya tidak ditemukan) — laporkan ke admin.</li>
        </ul>
        <p>Tombol <strong>Detail</strong> menampilkan riwayat setiap cicilan dan status alkes tindakan di nota terakhir.</p>

        <hr>
        <p class="small text-muted mb-0">Ada yang belum jelas atau tampilan POS berbeda dari panduan ini? Hubungi admin sistem.</p>
    </div>
</div>
