<?php

namespace App\Livewire\Fina;

use App\Services\KasBankWriter;

class BankKeluarList extends KasBankListBase
{
    public function sumber(): string { return KasBankWriter::BANK_KELUAR; }
    public function judul(): string { return 'Bank Keluar'; }
    public function icon(): string { return 'fas fa-building-columns'; }
    public function abilityPath(): string { return 'finance/bank-keluar'; }
    public function formComponent(): string { return 'fina.bank-keluar-form'; }
}
