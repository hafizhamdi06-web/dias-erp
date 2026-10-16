{{-- Panduan > Kasir / POS. Label, tombol pintas & pesan disalin dari Kasir/POS Laravel
     (App\Livewire\Sales\PosTerminal + resources/views/livewire/sales/pos-terminal.blade.php).
     Tiruan tampilan di sini sengaja NON-AKTIF (disabled) - hanya gambaran. --}}
<div class="container-fluid" style="max-width: 980px">
    @include('livewire.panduan.partials.gaya')

    <div class="pdn py-2">
        <h2 class="h4 fw-bold mb-1"><i class="fas fa-cash-register me-2 text-primary"></i>Panduan Kasir / POS</h2>
        <p class="text-muted mb-3">Untuk kasir &middot; menu <strong>Penjualan &rsaquo; Kasir / POS</strong></p>

        {{-- ============ DAFTAR ISI ============ --}}
        <div class="card mb-3">
            <div class="card-body py-2 small">
                <div class="fw-semibold mb-1">Isi panduan</div>
                <ol class="mb-0">
                    <li><a href="#pos-ringkas">Transaksi dalam 7 langkah</a></li>
                    <li><a href="#pos-pintas">Tombol pintas (keyboard)</a></li>
                    <li><a href="#pos-pasien">Memilih pasien</a></li>
                    <li><a href="#pos-item">Menambah item, operator &amp; dokter</a></li>
                    <li><a href="#pos-kunci">Mengubah harga / diskon (kunci)</a></li>
                    <li><a href="#pos-promo">Harga Khusus, Daftar Paket, Tarik Medlib &amp; Tarik PRO</a></li>
                    <li><a href="#pos-bayar">Pembayaran</a></li>
                    <li><a href="#pos-simpan">Menyimpan &amp; mencetak struk</a></li>
                    <li><a href="#pos-riwayat">Cetak ulang &amp; edit transaksi</a></li>
                    <li><a href="#pos-lain">Data Lainnya</a></li>
                    <li><a href="#pos-pesan">Pesan yang mungkin muncul</a></li>
                </ol>
            </div>
        </div>

        {{-- ============ RINGKAS ============ --}}
        <h4 id="pos-ringkas">1. Transaksi dalam 7 langkah</h4>
        <ol class="langkah">
            <li>Pilih pasien — <kbd>F1</kbd>.</li>
            <li>Tambahkan item / tindakan — <kbd>F2</kbd>. Isi Operator, Dokter, No Ref &amp; No IC bila diminta.</li>
            <li>Bila ada promo, pakai <strong>Harga Khusus</strong> <kbd>F10</kbd>; bila pasien memakai paket, pakai <strong>Daftar Paket</strong> <kbd>F11</kbd>.</li>
            <li>Isi <strong>Catatan Rekam Medis</strong> (wajib).</li>
            <li>Buka pembayaran — <kbd>F8</kbd> — isi cara bayarnya, lalu <strong>OK</strong> (<kbd>Ctrl</kbd>+<kbd>Enter</kbd>).</li>
            <li>Klik <strong>Simpan Transaksi</strong> (<kbd>Ctrl</kbd>+<kbd>Enter</kbd>).</li>
            <li>Klik <strong>Cetak struk</strong>, lalu <strong>Transaksi baru</strong> untuk pasien berikutnya.</li>
        </ol>
        <div class="alert alert-warning small">
            <strong>OK di dialog pembayaran belum menyimpan transaksi.</strong> Transaksi baru tersimpan setelah
            tombol <strong>Simpan Transaksi</strong> di layar utama diklik.
        </div>

        {{-- ============ PINTASAN ============ --}}
        <h4 id="pos-pintas">2. Tombol pintas (keyboard)</h4>
        <p class="small text-muted">Beberapa tombol punya arti berbeda di layar utama dan di dalam dialog pembayaran.</p>
        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm table-bordered small mb-0">
                    <thead class="table-light"><tr><th colspan="2">Di layar utama</th></tr></thead>
                    <tbody>
                        <tr><td><kbd>F1</kbd></td><td>Cari pasien / pelanggan</td></tr>
                        <tr><td><kbd>F2</kbd></td><td>Cari item</td></tr>
                        <tr><td><kbd>F5</kbd></td><td>Voucher pelanggan</td></tr>
                        <tr><td><kbd>F6</kbd></td><td>DP pelanggan</td></tr>
                        <tr><td><kbd>F8</kbd></td><td>Buka dialog Pembayaran</td></tr>
                        <tr><td><kbd>F10</kbd></td><td>Harga Khusus (promo)</td></tr>
                        <tr><td><kbd>F11</kbd></td><td>Daftar Paket</td></tr>
                        <tr><td><kbd>Ctrl</kbd>+<kbd>Enter</kbd></td><td>Simpan Transaksi</td></tr>
                        <tr><td><kbd>Ctrl</kbd>+<kbd>Delete</kbd></td><td>Hapus baris keranjang</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-bordered small mb-0">
                    <thead class="table-light"><tr><th colspan="2">Di dalam dialog Pembayaran</th></tr></thead>
                    <tbody>
                        <tr><td><kbd>F4</kbd></td><td>Tunai — penuh</td></tr>
                        <tr><td><kbd>F5</kbd></td><td>Kartu Debit — penuh</td></tr>
                        <tr><td><kbd>F8</kbd></td><td>Kartu Kredit — penuh</td></tr>
                        <tr><td><kbd>F9</kbd></td><td>Transfer — penuh</td></tr>
                        <tr><td><kbd>F2</kbd></td><td>Merchant — penuh</td></tr>
                        <tr><td><kbd>F6</kbd></td><td>Pilih DP pelanggan</td></tr>
                        <tr><td><kbd>F10</kbd></td><td>Pilih voucher pelanggan</td></tr>
                        <tr><td><kbd>Ctrl</kbd>+<kbd>Enter</kbd> / <kbd>F12</kbd></td><td>OK</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="small text-muted mt-2">"Penuh" = semua isian bayar dikosongkan, lalu seluruh tagihan diisikan ke cara bayar itu.
            <kbd>Esc</kbd> <strong>tidak</strong> menutup dialog Pembayaran agar isian tidak hilang — tutup lewat <strong>Batal</strong>.</p>

        {{-- ============ PASIEN ============ --}}
        <h4 id="pos-pasien">3. Memilih pasien</h4>
        <ul>
            <li>Tekan <kbd>F1</kbd> atau tombol <strong>Cari pelanggan</strong>, ketik nama / kode, pilih dengan <kbd>Enter</kbd>.
                Pasien <strong>wajib</strong> dipilih sebelum transaksi bisa dibayar &amp; disimpan.</li>
            <li>Untuk <strong>member aktif</strong>, diskon member item otomatis terisi di kolom <strong>Disc 1 %</strong>.</li>
            <li>Bila pasien membeli <strong>DP hari ini</strong> dan masih ada sisanya, DP otomatis dipakai sebagai pembayaran.</li>
            <li>Untuk mengganti pasien, klik <strong>Ganti</strong>. Voucher &amp; DP milik pasien sebelumnya otomatis dilepas.</li>
            <li>Isi <strong>Catatan Rekam Medis</strong> di bawah nama pasien — wajib, maksimal 255 huruf.</li>
        </ul>

        {{-- ============ ITEM ============ --}}
        <h4 id="pos-item">4. Menambah item, operator &amp; dokter</h4>
        <ol class="langkah">
            <li>Tekan <kbd>F2</kbd>, ketik kode / nama / barcode, pilih dengan <kbd>&uarr;</kbd><kbd>&darr;</kbd> lalu <kbd>Enter</kbd>.
                Yang tampil hanya item yang boleh dijual di cabang Anda.</li>
            <li>Untuk <strong>tindakan</strong>, sistem langsung meminta <strong>Operator</strong>, lalu <strong>Dokter</strong>, lalu
                <strong>No Ref / No IC</strong>. <kbd>Esc</kbd> melewati sementara — tetapi wajib diisi sebelum transaksi disimpan.
                Untuk mengubahnya lagi, klik nama operator/dokter di baris keranjang.</li>
            <li>Ubah <strong>Qty</strong> langsung di keranjang. Item yang sama ditambah lagi = qty bertambah.</li>
            <li>Barang dengan <strong>stok 0 tidak bisa dijual</strong>.</li>
        </ol>
        <p class="small text-muted">Di cabang <strong>Marketplace</strong>, harga yang dipakai adalah harga khusus marketplace
            (bisa 0 bila belum diisi — buka kunci harga untuk mengisinya).</p>

        {{-- ============ KUNCI ============ --}}
        <h4 id="pos-kunci">5. Mengubah harga / diskon (kunci)</h4>
        <p>Kolom <strong>Harga</strong>, <strong>Disc 1 %</strong> dan <strong>Disc 2 %</strong> terkunci. Untuk mengubahnya:</p>
        <ol class="langkah">
            <li>Klik ikon gembok <span class="btn btn-sm btn-outline-secondary py-0 px-1 disabled"><i class="fas fa-lock"></i></span> di baris itu.</li>
            <li>Atasan yang punya hak <strong>Approve</strong> di menu Kasir/POS mengetik <strong>username &amp; password</strong>-nya, lalu
                <strong>Buka Kunci</strong>. Pembukaan ini tercatat atas nama atasan tersebut.</li>
            <li>Ubah harga/diskon, lalu klik gembok <span class="btn btn-sm btn-outline-success py-0 px-1 disabled"><i class="fas fa-unlock"></i></span>
                untuk mengunci kembali.</li>
        </ol>

        {{-- ============ PROMO & PAKET ============ --}}
        <h4 id="pos-promo">6. Harga Khusus, Daftar Paket, Tarik Medlib &amp; Tarik PRO</h4>
        <p><strong>Harga Khusus</strong> <kbd>F10</kbd> — cari promo yang berlaku hari ini, pilih, lalu ikuti dialognya:</p>
        <ul>
            <li><strong>Promo kombinasi</strong>: pilih baris kombinasi, tentukan item tiap slot (bisa diganti dengan item alternatif), lalu <strong>Terapkan</strong>.</li>
            <li><strong>Promo biasa</strong>: pilih baris promo; item utama dan <strong>item bonus</strong> (harga 0) masuk ke keranjang.</li>
            <li>Kolom "Min Qty" di daftar promo menunjukkan jumlah minimal yang disyaratkan promo.</li>
            <li>Baris dari promo ditandai <span class="badge text-bg-success"><i class="fas fa-tag me-1"></i>Promo</span>; item bonus ditandai <span class="badge text-bg-info">Bonus</span>.</li>
        </ul>
        <p><strong>Daftar Paket</strong> <kbd>F11</kbd> — pilih paket pasien:</p>
        <ul>
            <li><strong>Paket sekali pakai</strong>: semua item paket langsung masuk keranjang.</li>
            <li><strong>Paket beberapa kedatangan</strong>: isi <strong>Nomer Paket</strong>.
                <ul>
                    <li>Nomer baru (kedatangan pertama) = <strong>beli paket</strong>: semua item masuk keranjang.</li>
                    <li>Nomer yang sudah pernah dipakai = <strong>tarik paket</strong>: centang item yang dipakai hari ini dari daftar
                        sisa kuota; item masuk dengan tanda <span class="badge text-bg-info">Gratis - Tarik Paket</span>.</li>
                </ul>
            </li>
        </ul>

        <p><strong>Tarik Medlib</strong> — mengambil transaksi dari aplikasi Medlib / online milik pasien terpilih
            (tombol di bawah Daftar Paket). Ada dua tab:</p>
        <ul>
            <li><strong>Invoice Sudah Dibayar</strong>: invoice yang sudah dibayar online dan belum masuk DIAS, di cabang Anda.
                Harga mengikuti <strong>harga di invoice</strong>, dan pembayaran otomatis terisi sebagai <strong>Merchant DOKU</strong>
                sebesar total invoice — jadi tidak perlu menerima uang lagi.</li>
            <li><strong>Medlib Belum Invoice</strong>: resep dari rekam medis yang belum dibayar (1 bulan terakhir).
                Pembayarannya diisi seperti biasa.</li>
        </ul>
        <ol class="langkah">
            <li>Pilih pasien <kbd>F1</kbd>, lalu klik <strong>Tarik Medlib</strong>.</li>
            <li>Pilih tab, centang baris yang akan diambil (kotak di judul kolom = centang semua), lalu
                <strong>Tambahkan ke Keranjang</strong>.</li>
            <li>Item masuk ke keranjang dengan tanda <span class="text-primary small"><i class="fas fa-notes-medical me-1"></i>Medlib</span>,
                ditambah baris <strong>RESEP SKIN CARE</strong> (atau <strong>RESEP SKIN CARE DKK</strong>) dan <strong>ONGKIR</strong> bila ada.
                Dokter, operator, dan No Ref otomatis terisi dari resepnya.</li>
            <li>Isi Catatan Rekam Medis, periksa pembayaran, lalu <strong>Simpan Transaksi</strong>.</li>
        </ol>
        <p class="small text-muted">Setelah tersimpan, invoice ditandai sudah masuk DIAS sehingga tidak bisa ditarik dua kali.
            Bila kasir lain lebih dulu menyimpan invoice yang sama, transaksi Anda ditolak — hapus baris Medlib-nya lalu tarik ulang.</p>

        <p><strong>Tarik PRO</strong> — mengambil <strong>keranjang rekomendasi PRO</strong> milik pasien (tombol di bawah Tarik Medlib).
            Tab <strong>Belum Bayar</strong> berisi keranjang yang belum dibayar; tab <strong>Sudah Bayar</strong> berisi keranjang yang sudah
            dibayar online (pembayaran otomatis terisi <strong>Merchant DOKU</strong> sebesar total keranjang).</p>
        <ol class="langkah">
            <li>Pilih pasien <kbd>F1</kbd>, lalu klik <strong>Tarik PRO</strong>.</li>
            <li>Baris ditampilkan per keranjang. Klik <strong>Pilih keranjang ini</strong> (atau centang barisnya satu per satu), lalu
                <strong>Tambahkan ke Keranjang</strong>. <strong>Satu transaksi hanya untuk satu keranjang PRO.</strong></li>
            <li>Item masuk dengan tanda <span class="text-primary small"><i class="fas fa-user-tag me-1"></i>PRO</span>, lengkap dengan harga
                &amp; diskon dari keranjang PRO, promo/paketnya, dan nama PRO-nya. Bila ada produk, ditambahkan <strong>RESEP SKIN CARE</strong>.</li>
            <li>Untuk tindakan, dialog <strong>Operator</strong> &rarr; <strong>Dokter</strong> &rarr; <strong>No Ref / No IC</strong> langsung terbuka — isi seperti biasa.</li>
            <li>Isi Catatan Rekam Medis, pembayaran, lalu <strong>Simpan Transaksi</strong>. Keranjang PRO ditandai sudah masuk DIAS.</li>
        </ol>

        {{-- ============ BAYAR ============ --}}
        <h4 id="pos-bayar">7. Pembayaran</h4>
        <ol class="langkah">
            <li>Klik <strong>Isi Pembayaran</strong> / <kbd>F8</kbd>.</li>
            <li>Isi jumlah di cara bayar yang dipakai — boleh lebih dari satu (mis. sebagian Debit, sisanya Tunai).
                <ul class="small">
                    <li><strong>Kartu Debit / Kredit / Transfer</strong>: <strong>no. kartu/ref</strong> dan <strong>bank</strong> wajib diisi.</li>
                    <li><strong>Merchant</strong>: isi nilai, no. merchant, dan jenis merchant.</li>
                    <li><strong>DP</strong> <kbd>F6</kbd> &amp; <strong>Voucher</strong> <kbd>F10</kbd>: dipilih dari saldo milik pasien, tidak bisa diketik bebas.</li>
                </ul>
            </li>
            <li>Lihat ringkasan di bawah dialog: <strong>Sub Total</strong>, <strong>Total Bayar</strong>, dan <strong>Sisa</strong> (merah) atau <strong>Kembalian</strong> (hijau).</li>
            <li>Klik <strong>OK</strong>. Bila ada isian yang salah, dialog tidak tertutup dan pesannya muncul di dekat kolomnya.
                <strong>Batal</strong> mengembalikan isian bayar seperti sebelum dialog dibuka.</li>
        </ol>
        <p class="small text-muted">Bila no. merchant yang sama pernah dipakai pasien yang sama dengan jenis merchant yang sama, muncul info
            <em>"No. merchant sudah pernah digunakan"</em> beserta nota sebelumnya. Periksa nomornya; bila memang benar, tekan <strong>OK</strong> sekali lagi.</p>
        <p class="small">Transaksi <strong>surgery</strong> (dibayar cicil) punya langkah tambahan — lihat <strong>Panduan &rsaquo; Transaksi Surgery</strong>.</p>

        {{-- ============ SIMPAN ============ --}}
        <h4 id="pos-simpan">8. Menyimpan &amp; mencetak struk</h4>
        <ul>
            <li>Klik <strong>Simpan Transaksi</strong> (<kbd>Ctrl</kbd>+<kbd>Enter</kbd>). Sistem memeriksa ulang: pasien, rekam medis, operator/dokter,
                no ref/IC, stok, kuota paket, dan kecukupan pembayaran.</li>
            <li>Setelah tersimpan, muncul <strong>Transaksi tersimpan</strong> dengan No. transaksi, Total, Bayar, dan Kembalian.</li>
            <li>Klik <strong>Cetak struk</strong>. Ukuran struk mengikuti pengaturan user (58 mm atau setengah A4); tombol ukuran di sebelahnya
                untuk mencetak dengan ukuran lain sekali saja.</li>
        </ul>

        {{-- ============ RIWAYAT ============ --}}
        <h4 id="pos-riwayat">9. Cetak ulang &amp; edit transaksi</h4>
        <p>Klik <strong>Riwayat Hari Ini</strong> di bagian atas. Yang tampil hanya transaksi <strong>yang Anda input hari ini</strong>.</p>
        <ul>
            <li><span class="btn btn-sm btn-outline-secondary py-0 px-1 disabled"><i class="fas fa-print"></i></span> <strong>Cetak</strong> — cetak ulang struk.</li>
            <li><span class="btn btn-sm btn-outline-warning py-0 px-1 disabled"><i class="fas fa-pen"></i></span> <strong>Edit</strong> — isi transaksi dimuat ke keranjang.
                Transaksi lama <strong>tetap aktif</strong> sampai Anda menekan Simpan Transaksi; saat itu transaksi lama dibatalkan dan diganti nomor baru.
                Bila batal mengedit, transaksi lama tidak berubah.</li>
            <li>Nota cicilan surgery yang sudah dilanjutkan cicilan berikutnya tidak bisa diedit.</li>
        </ul>

        {{-- ============ DATA LAINNYA ============ --}}
        <h4 id="pos-lain">10. Data Lainnya</h4>
        <p>Tombol <strong>Data Lainnya</strong> di bagian atas berisi isian tambahan (opsional):
            Catatan, Training, Farmasi, Farmasi Asisten, Sales Marketing, Klinik Luar, Kode Tele, Review Nilai &amp; Catatan,
            <strong>Konsul Saja</strong>, <strong>Konsul Kemarin</strong>, dan Teman. ID Medlib &amp; ID PRO hanya tampil (dari sistem lain).</p>

        {{-- ============ PESAN ============ --}}
        <h4 id="pos-pesan">11. Pesan yang mungkin muncul</h4>
        <div class="table-responsive">
            <table class="table table-sm small align-middle">
                <thead class="table-light"><tr><th style="width:42%">Pesan</th><th>Yang harus dilakukan</th></tr></thead>
                <tbody>
                    <tr><td><em>Pelanggan/pasien wajib dipilih.</em></td><td>Pilih pasien dengan <kbd>F1</kbd>.</td></tr>
                    <tr><td><em>Keranjang kosong.</em></td><td>Tambahkan item dengan <kbd>F2</kbd>.</td></tr>
                    <tr><td><em>Catatan Rekam Medis wajib diisi.</em></td><td>Isi kolom Catatan Rekam Medis di bawah nama pasien.</td></tr>
                    <tr><td><em>Baris "…" belum diisi Operator/Dokter.</em> / <em>…belum diisi No Ref/No IC.</em></td><td>Isi lewat dialog yang otomatis terbuka.</td></tr>
                    <tr><td><em>Stok 0: … tidak bisa dijual.</em> / <em>Stok "…" tinggal …</em></td><td>Kurangi qty atau ganti item; hubungi gudang bila stok seharusnya ada.</td></tr>
                    <tr><td><em>No. kartu/ref … wajib diisi.</em> / <em>Bank … wajib dipilih.</em></td><td>Lengkapi isian kartu/transfer di dialog Pembayaran.</td></tr>
                    <tr><td><em>Pembayaran kurang Rp …</em></td><td>Tambah pembayaran sampai Sisa = 0.</td></tr>
                    <tr><td><em>Sisa saldo voucher / DP tinggal …</em></td><td>Kurangi nilai yang dipakai, sisanya bayar dengan cara lain.</td></tr>
                    <tr><td><em>Kuota paket utk item "…" sudah terlampaui</em></td><td>Cari ulang Nomer Paket; pastikan item belum dipakai di kedatangan lain.</td></tr>
                    <tr><td><em>User ini tidak punya hak "Approve" …</em></td><td>Minta atasan yang berwenang membuka kunci harga/diskon.</td></tr>
                    <tr><td><em>Invoice Medlib … sudah masuk DIAS atau bukan milik pasien ini …</em></td><td>Invoice itu sudah disimpan kasir lain. Hapus baris Medlib-nya, lalu Tarik Medlib ulang.</td></tr>
                    <tr><td><em>Satu transaksi hanya untuk SATU keranjang PRO …</em></td><td>Tarik baris dari satu keranjang saja; keranjang lain dibuat transaksi terpisah.</td></tr>
                    <tr><td><em>Keranjang PRO sudah masuk DIAS … atau bukan milik pasien ini …</em></td><td>Keranjang itu sudah disimpan kasir lain. Hapus baris PRO-nya, lalu Tarik PRO ulang.</td></tr>
                </tbody>
            </table>
        </div>

        <hr>
        <p class="small text-muted mb-0">Ada yang belum jelas atau tampilan POS berbeda dari panduan ini? Hubungi admin sistem.</p>
    </div>
</div>
