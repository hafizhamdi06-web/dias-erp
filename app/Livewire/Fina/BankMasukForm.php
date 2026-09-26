<?php

namespace App\Livewire\Fina;

use App\Services\KasBankWriter;

class BankMasukForm extends KasBankFormBase
{
    public function sumber(): string { return KasBankWriter::BANK_MASUK; }
    public function judul(): string { return 'Bank Masuk'; }
    public function isBank(): bool { return true; }
    public function abilityPath(): string { return 'finance/bank-masuk'; }
}
