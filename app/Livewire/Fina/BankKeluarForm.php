<?php

namespace App\Livewire\Fina;

use App\Services\KasBankWriter;

class BankKeluarForm extends KasBankFormBase
{
    public function sumber(): string { return KasBankWriter::BANK_KELUAR; }
    public function judul(): string { return 'Bank Keluar'; }
    public function isBank(): bool { return true; }
    public function abilityPath(): string { return 'finance/bank-keluar'; }
}
