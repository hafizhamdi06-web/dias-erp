<?php

namespace App\Http\Controllers;

use App\Services\PosSaleWriter;
use Illuminate\Support\Facades\DB;

class PosReceiptController extends Controller
{
    public function show(int $id, PosSaleWriter $writer)
    {
        $h = $writer->header($id);
        abort_if(! $h, 404);

        $kontak = $h->SUKONTAK
            ? DB::table('bkontak')->where('KID', $h->SUKONTAK)->first(['KNAMA', 'KKODE'])
            : null;

        $branch = DB::table('bgudang')->where('GID', $h->SUCABANG)->first(['GNAMA', 'GALAMAT1', 'GTELP']);
        $kasir = DB::table('auser')->where('UID', $h->SUCREATEU)->value('UNAMA');

        return view('pos-receipt', [
            'h'      => $h,
            'lines'  => $writer->lines($id),
            'kontak' => $kontak,
            'branch' => $branch,
            'kasir'  => $kasir,
        ]);
    }
}
