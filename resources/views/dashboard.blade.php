@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="row">
        <div class="col-lg-4 col-6">
            <div class="small-box text-bg-primary">
                <div class="inner">
                    <h3>{{ number_format($stats['user_aktif']) }}</h3>
                    <p>User Aktif</p>
                </div>
                <i class="small-box-icon fas fa-users"></i>
            </div>
        </div>
        <div class="col-lg-4 col-6">
            <div class="small-box text-bg-success">
                <div class="inner">
                    <h3>{{ number_format($stats['item_aktif']) }}</h3>
                    <p>Item Aktif</p>
                </div>
                <i class="small-box-icon fas fa-box"></i>
            </div>
        </div>
        <div class="col-lg-4 col-6">
            <div class="small-box text-bg-warning">
                <div class="inner">
                    <h3>{{ number_format($stats['pelanggan']) }}</h3>
                    <p>Pelanggan / Kontak</p>
                </div>
                <i class="small-box-icon fas fa-address-book"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Selamat datang, {{ auth()->user()->displayName() }}</h5>
            <p class="text-muted mb-0">
                Sistem ERP Klinik Kecantikan &mdash; {{ \Carbon\Carbon::parse($today)->translatedFormat('l, d F Y') }}.
                Modul akan aktif bertahap: Administrasi, Master Data, Penjualan, Pembelian, Inventory, Finance/Accounting.
            </p>
        </div>
    </div>
@endsection
