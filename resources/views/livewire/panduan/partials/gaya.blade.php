{{-- Gaya bersama halaman Panduan - dipakai semua topik supaya tampil seragam. --}}
<style>
    .pdn h4 { margin-top: 1.75rem; font-weight: 700; }
    .pdn .langkah { counter-reset: lk; list-style: none; padding-left: 0; }
    .pdn .langkah > li { counter-increment: lk; position: relative; padding: .35rem 0 .35rem 2.4rem; }
    .pdn .langkah > li::before { content: counter(lk); position: absolute; left: 0; top: .3rem; width: 1.7rem; height: 1.7rem;
        border-radius: 50%; background: var(--bs-primary); color: #fff; font-weight: 700; font-size: .85rem;
        display: flex; align-items: center; justify-content: center; }
    .pdn .tiruan { border: 1px dashed var(--bs-secondary-border-subtle, #ccc); border-radius: .5rem; padding: .75rem;
        background: var(--bs-tertiary-bg, #f8f9fa); max-width: 360px; }
    .pdn .tiruan-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color); margin-bottom: .35rem; }
    .pdn .alur { display: flex; flex-wrap: wrap; gap: .5rem; align-items: stretch; }
    .pdn .alur .kotak { flex: 1 1 200px; border: 1px solid var(--bs-border-color); border-radius: .5rem; padding: .6rem .75rem; background: var(--bs-body-bg); }
    .pdn .alur .panah { align-self: center; color: var(--bs-secondary-color); }
    .pdn kbd { font-size: .75rem; }
</style>
