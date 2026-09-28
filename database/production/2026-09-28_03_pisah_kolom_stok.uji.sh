#!/bin/sh
# ============================================================================
#  Uji 2026-09-28_03_pisah_kolom_stok.sql di DB SEMENTARA `uji_pisahstok_dias`.
#  TIDAK menyentuh data_pos_nmw_2023 (hanya MEMBACA struktur & rutinnya).
#  DB uji dibuat & dibuang sendiri. Jalankan: sh <file ini>
#
#  sql_mode dikosongkan: tabel legacy punya default '0000-00-00' yg ditolak mode ketat.
#  Data uji memakai SDPRDID=-1 supaya UPDATE trigger ke `official_nmw.ops_invoice_header`
#  (database NYATA, dirujuk absolut di dalam trigger) tidak mengenai baris apa pun,
#  dan item 999001 (bukan 5076/5720) supaya blok IF + F_Tanggalsu tidak ikut jalan.
# ============================================================================
#  DUA sumber, karena setelah migrasi terpasang DB proyek tidak lagi bisa dipakai utk
#  membuktikan cacat LAMA-nya:
#    SRC     - struktur tabel diambil dari sini (butuh SEMUA tabel; default DB proyek).
#    SRCLAMA - routine & trigger LAMA diambil dari sini. Selama migrasi BELUM terpasang
#              sama dgn SRC. Sesudah terpasang, setel ke salinan PRA-migrasi, mis:
#                SRCLAMA=gen_prapisah sh <file ini>
MY="/c/xampp/mysql/bin/mysql.exe -uroot"
INIT="SET SESSION sql_mode='';"
SRC=${SRC:-data_pos_nmw_2023}
SRCLAMA=${SRCLAMA:-$SRC}
DB=uji_pisahstok_dias
F="/c/xampp/htdocs/dias-laravel/database/production/2026-09-28_03_pisah_kolom_stok.sql"
ITEM=999001
gagal=0

q ()   { $MY $DB -e "$INIT $1"; }
nilai () { $MY $DB -N -e "SELECT COALESCE(\`$1\`,'NULL') FROM bitem WHERE IID=$ITEM;" 2>/dev/null; }
adaKolom () {
  $MY $DB -N -e "SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='bitem' AND COLUMN_NAME='$1';"
}
cek () { # $1=judul  $2=dapat  $3=harap
  if [ "$2" = "$3" ]; then printf '  OK   %-52s %s\n' "$1" "$2"
  else printf '  GAGAL %-51s dapat=%s harap=%s\n' "$1" "$2" "$3"; gagal=$((gagal+1)); fi
}
# U_fstokd unik atas (SDIDSU,SDURUTAN) -> SDURUTAN harus beda tiap baris.
masuk () { # $1=gudang $2=masuk $3=sdid/urutan $4=keluar(opsional)
  q "INSERT INTO fstokd (SDID,SDIDSU,SDURUTAN,SDITEM,SDGUDANG,SDMASUK,SDKELUAR,SDPRDID,SDPBDID,SDSODID,SDIDJOP)
     VALUES ($3,1,$3,$ITEM,$1,$2,${4:-0},-1,-1,-1,-1);" >/dev/null
}
# Urutan WAJIB: hapus baris dulu (trigger DELETE ikut jalan), BARU nolkan kolom stok.
bersih () {
  q "DELETE FROM fstokd;" >/dev/null
  $MY $DB -N -e "$INIT SELECT CONCAT('UPDATE bitem SET ', GROUP_CONCAT(CONCAT('\`',COLUMN_NAME,'\`=0')), ' WHERE IID=$ITEM;')
     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='bitem' AND COLUMN_NAME LIKE 'ISTOK%';" \
    | $MY $DB >/dev/null
}

echo "=== siapkan DB uji: struktur tabel + rutin, TANPA data ==="
$MY -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB;"
# Tabel yg ADA di SRCLAMA diambil dari situ - kalau tidak, `bitem` akan terbawa dari DB
# yg SUDAH dimigrasi (kolom barunya sudah ada) dan `ADD COLUMN` di file SQL bentrok.
# Tabel yg tidak tersentuh migrasi cukup dari SRC.
for t in bitem fstokd fstoku bgudang fpermintaanbarangd fperintahkirimbarangd esalesorderd fproduksid bkontak; do
  asal=$SRC
  if [ "$($MY -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SRCLAMA' AND TABLE_NAME='$t'")" = "1" ]; then
    asal=$SRCLAMA
  fi
  q "CREATE TABLE $t LIKE $asal.$t;" || { echo "  GAGAL salin struktur $t"; gagal=$((gagal+1)); }
  [ "$asal" = "$SRCLAMA" ] && [ "$SRCLAMA" != "$SRC" ] && echo "  struktur $t diambil dari $SRCLAMA (pra-migrasi)"
done
q "INSERT INTO bgudang SELECT * FROM $SRC.bgudang;" >/dev/null
echo "  bgudang: $($MY $DB -N -e 'SELECT COUNT(*) FROM bgudang') gudang disalin (dibutuhkan P_RESETSTOK*)"

# Rutin & trigger LAMA disalin apa adanya dari DB asli.
/c/php82/php.exe -r "
\$s = new mysqli('localhost','root','','$SRCLAMA');
\$o = new mysqli('localhost','root','','$DB');
\$o->query(\"SET sql_mode=''\");
foreach (['F_KOLOMGUDANG','F_STOKPERGUDANG','P_RESETSTOK','P_RESETSTOKPERBARANG','P_RESETSTOKPERBARANGGUDANG'] as \$n) {
  \$r = \$s->query(\"SHOW CREATE \" . (\$n[0] === 'F' ? 'FUNCTION' : 'PROCEDURE') . \" \`$SRCLAMA\`.\`\$n\`\")->fetch_assoc();
  \$ddl = \$r['Create Function'] ?? \$r['Create Procedure'];
  \$ddl = preg_replace('/DEFINER=\`[^\`]*\`@\`[^\`]*\`\s*/', '', \$ddl);
  \$o->query(\$ddl) or print('  GAGAL rutin ' . \$n . ': ' . \$o->error . PHP_EOL);
}
foreach ([['fstokd_add','AFTER INSERT'],['fstokd_edit','AFTER UPDATE'],['fstokd_DELL','AFTER DELETE']] as [\$n,\$w]) {
  \$b = \$s->query(\"SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
                   WHERE TRIGGER_SCHEMA='$SRCLAMA' AND TRIGGER_NAME='\$n'\")->fetch_row()[0];
  \$o->query(\"CREATE TRIGGER \`\$n\` \$w ON \`fstokd\` FOR EACH ROW \" . \$b)
    or print('  GAGAL trigger ' . \$n . ': ' . \$o->error . PHP_EOL);
}
print('  5 rutin + 3 trigger LAMA terpasang' . PHP_EOL);
"
q "INSERT INTO bitem (IID, IKODE, INAMA) VALUES ($ITEM,'UJI','Item Uji');" >/dev/null

echo
echo "=== 1. KEADAAN LAMA - membuktikan cacatnya memang ada ==="
$MY $DB -N -e "SELECT CONCAT('  gudang berbagi kolom: ', GROUP_CONCAT(CONCAT(k,'<-',g) SEPARATOR '   '))
  FROM (SELECT F_KOLOMGUDANG(GID) k, GROUP_CONCAT(GID) g FROM bgudang GROUP BY 1 HAVING COUNT(*)>1) x;"
bersih
# Nilai basi ISTOKOL ditiru SETELAH bersih (bersih menolkan semua kolom ISTOK),
# utk membuktikan BAGIAN 2 file SQL membersihkannya.
q "UPDATE bitem SET ISTOKOL=-77 WHERE IID=$ITEM;" >/dev/null
masuk 20 50 1; masuk 10 50 2; masuk 42 50 3; masuk 1 50 4
cek "gudang 20 Depo -> ISTOKDE (dobel)"          "$(nilai ISTOKDE)" "100"
cek "gudang 10 Bizpark -> ISTOKBZ (dobel)"       "$(nilai ISTOKBZ)" "100"
cek "gudang 42 Depo Research: kolom ISTOKDR belum ada" "$(adaKolom ISTOKDR)" "0"
cek "  mutasinya hilang, tidak tercatat ke mana pun" "$(nilai ISTOKPG)" "50"
cek "ISTOKOL berisi nilai basi & tak terbaca"    "$(nilai ISTOKOL)" "-77"

echo
echo "=== 2. jalankan file SQL ==="
$MY $DB < "$F" && echo "  terpasang tanpa error" || { echo "  GAGAL memasang file SQL"; gagal=$((gagal+1)); }

echo
echo "=== 3. pemetaan sekarang 1:1 utk SEMUA gudang ==="
sisa=$($MY $DB -N -e "SELECT COUNT(*) FROM (SELECT F_KOLOMGUDANG(GID) FROM bgudang GROUP BY 1 HAVING COUNT(*)>1) x;")
cek "gudang yg masih berbagi kolom"              "$sisa" "0"
cek "jumlah kolom stok berbeda utk 49 gudang"    "$($MY $DB -N -e 'SELECT COUNT(DISTINCT F_KOLOMGUDANG(GID)) FROM bgudang;')" "49"
cek "gudang belum dipetakan -> ISTOKXX"          "$($MY $DB -N -e 'SELECT F_KOLOMGUDANG(9999);')" "ISTOKXX"

echo
echo "=== 4. BAGIAN 2 membersihkan nilai basi ISTOKOL ==="
cek "ISTOKOL dinolkan"                           "$(nilai ISTOKOL)" "0"

echo
echo "=== 5. tiap gudang menulis ke kolomnya SENDIRI, SEKALI saja ==="
bersih
for g in 1 6 9 10 14 15 18 20 39 40 42 49; do
  k=$($MY $DB -N -e "SELECT F_KOLOMGUDANG($g);")
  masuk $g 50 $((100+g))
  cek "gudang $g -> $k" "$(nilai $k)" "50"
done
cek "ISTOKPG tidak lagi kena gudang lain"        "$(nilai ISTOKPG)" "50"
cek "ISTOKMP tidak lagi kena gudang 9"           "$(nilai ISTOKMP)" "50"

echo
echo "=== 6. UPDATE & DELETE di kolom yg BARU dibuat (gudang 42 -> ISTOKDR) ==="
bersih
masuk 42 30 201
cek "masuk 30"                  "$(nilai ISTOKDR)" "30"
q "UPDATE fstokd SET SDMASUK=80 WHERE SDID=201;" >/dev/null
cek "qty 30 -> 80"              "$(nilai ISTOKDR)" "80"
q "DELETE FROM fstokd WHERE SDID=201;" >/dev/null
cek "baris dihapus"             "$(nilai ISTOKDR)" "0"

echo
echo "=== 7. UPDATE & DELETE di gudang yg tadinya DOBEL (20 -> ISTOKDE) ==="
bersih
masuk 20 30 202
cek "masuk 30 (dulu jadi 60)"   "$(nilai ISTOKDE)" "30"
q "UPDATE fstokd SET SDMASUK=80 WHERE SDID=202;" >/dev/null
cek "qty 30 -> 80"              "$(nilai ISTOKDE)" "80"
q "DELETE FROM fstokd WHERE SDID=202;" >/dev/null
cek "baris dihapus"             "$(nilai ISTOKDE)" "0"
bersih
masuk 20 100 203; masuk 20 0 204 40
cek "masuk 100 lalu keluar 40"  "$(nilai ISTOKDE)" "60"

echo
echo "=== 8. penampung ISTOKXX: gudang yg tidak ada di peta ==="
bersih
masuk 77 25 205
cek "gudang 77 (tidak terdaftar) -> ISTOKXX"     "$(nilai ISTOKXX)" "25"
cek "  dan TIDAK mengotori ISTOKPG"              "$(nilai ISTOKPG)" "0"
q "DELETE FROM fstokd WHERE SDID=205;" >/dev/null
cek "  dihapus -> kembali 0"                     "$(nilai ISTOKXX)" "0"

echo
echo "=== 9. tidak ada statement kembar (total vs unik per trigger) ==="
/c/php82/php.exe -r "
\$m = new mysqli('localhost','root','','$DB');
\$gagal = 0;
foreach (['$SRCLAMA' => 'LAMA', '$DB' => 'BARU'] as \$sch => \$label) {
  \$r = \$m->query(\"SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS
                    WHERE TRIGGER_SCHEMA='\$sch' AND EVENT_OBJECT_TABLE='fstokd' ORDER BY TRIGGER_NAME\");
  while (\$x = \$r->fetch_row()) {
    \$s = array_values(array_filter(array_map('trim', explode(';', \$x[1])),
         fn(\$v) => preg_match('/^update\s+bitem\s+set\s+ISTOK/i', \$v)));
    \$u = array_unique(array_map(fn(\$v) => strtolower(preg_replace('/\s+/',' ',\$v)), \$s));
    \$ok = count(\$s) === count(\$u);
    // Di trigger LAMA duplikat MEMANG ada - itu justru yang dibuktikan, bukan kegagalan.
    \$harap = \$label === 'LAMA' ? ! \$ok : \$ok;
    if (! \$harap) { \$gagal++; }
    printf(\"  %-5s %-5s %-14s total=%3d unik=%3d %s\n\", \$harap ? 'OK' : 'GAGAL', \$label, \$x[0],
           count(\$s), count(\$u),
           \$ok ? 'tidak ada kembar' : 'ada ' . (count(\$s)-count(\$u)) . ' kembar'
                 . (\$label === 'LAMA' ? ' <- cacat yg diperbaiki' : ' <- TIDAK BOLEH'));
  }
}
exit(\$gagal);
" || gagal=$((gagal+1))

echo
echo "=== 10. logika NON-STOK trigger tersalin utuh ==="
/c/php82/php.exe -r "
\$m = new mysqli('localhost','root','','$DB');
\$amb = function (\$sch) use (\$m) {
  \$o = [];
  \$r = \$m->query(\"SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS
                    WHERE TRIGGER_SCHEMA='\$sch' AND EVENT_OBJECT_TABLE='fstokd'\");
  while (\$x = \$r->fetch_row()) {
    \$p = array_values(array_filter(array_map(fn(\$v) => strtolower(trim(preg_replace('/\s+/',' ',\$v))),
         explode(';', \$x[1])), fn(\$v) => \$v !== '' && ! preg_match('/^update bitem set istok/i', \$v)));
    \$o[\$x[0]] = \$p;
  }
  return \$o;
};
\$a = \$amb('$SRCLAMA'); \$b = \$amb('$DB'); \$gagal = 0;
foreach (\$a as \$n => \$p) {
  \$sama = (\$b[\$n] ?? []) === \$p;
  if (! \$sama) { \$gagal++; }
  printf(\"  %-5s %-14s %d potongan non-stok lama vs %d baru%s\n\", \$sama ? 'OK' : 'GAGAL', \$n,
         count(\$p), count(\$b[\$n] ?? []), \$sama ? ' - IDENTIK' : ' - BERBEDA');
}
exit(\$gagal);
" || gagal=$((gagal+1))

echo
echo "=== 11. P_RESETSTOKPERBARANG tidak lagi merusak seluruh tabel ==="
bersih
q "INSERT INTO bitem (IID,IKODE,INAMA) VALUES (999002,'UJI2','Item Uji 2');" >/dev/null
q "INSERT INTO fstoku (SUID,SUSTATUS) VALUES (1,0);" >/dev/null
masuk 1 40 301
q "UPDATE bitem SET ISTOKPG=12345 WHERE IID=999002;" >/dev/null
q "CALL P_RESETSTOKPERBARANG($ITEM);" >/dev/null
cek "item yg direset dihitung ulang"              "$(nilai ISTOKPG)" "40"
cek "item LAIN tidak tersentuh (dulu ikut ditimpa)" \
    "$($MY $DB -N -e "SELECT ISTOKPG FROM bitem WHERE IID=999002;")" "12345"
q "CALL P_RESETSTOKPERBARANGGUDANG($ITEM,42);" >/dev/null
cek "reset per gudang memakai kolom dari F_KOLOMGUDANG" "$(nilai ISTOKDR)" "0"
q "DELETE FROM bitem WHERE IID=999002;" >/dev/null
bersih
q "DELETE FROM fstoku;" >/dev/null

echo
echo "=== 12. sisa & bersih-bersih ==="
cek "baris fstokd sisa di DB uji"                "$($MY $DB -N -e 'SELECT COUNT(*) FROM fstokd;')" "0"
$MY -e "DROP DATABASE $DB;"
cek "DB uji terhapus"                            "$($MY -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB'")" "0"
cek "trigger di $SRCLAMA masih 3 & tidak tersentuh"  "$($MY -N -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$SRCLAMA' AND EVENT_OBJECT_TABLE='fstokd'")" "3"
cek "F_KOLOMGUDANG di $SRCLAMA masih yg LAMA (6->PG)" "$($MY $SRCLAMA -N -e 'SELECT F_KOLOMGUDANG(6);')" "ISTOKPG"

echo
if [ "$gagal" -eq 0 ]; then echo "HASIL: SEMUA LULUS"; else echo "HASIL: $gagal PEMERIKSAAN GAGAL"; fi
exit $gagal
