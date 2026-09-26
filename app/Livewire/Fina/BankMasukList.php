<?php

namespace App\Livewire\Fina;

use App\Services\KasBankWriter;

class BankMasukList extends KasBankListBase
{
    public function sumber(): string { return KasBankWriter::BANK_MASUK; }
    public function judul(): string { return 'Bank Masuk'; }
    public function icon(): string { return 'fas fa-building-columns'; }
    public function abilityPath(): string { return 'finance/bank-masuk'; }
    public function formComponent(): string { return 'fina.bank-masuk-form'; }
}
