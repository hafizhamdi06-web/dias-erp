<?php

namespace App\Livewire\Fina;

use App\Services\KasBankWriter;

class KasKeluarForm extends KasBankFormBase
{
    public function sumber(): string { return KasBankWriter::KAS_KELUAR; }
    public function judul(): string { return 'Kas Keluar'; }
    public function isBank(): bool { return false; }
    public function abilityPath(): string { return 'finance/kas-keluar'; }
    public function printRoute(): ?string { return 'finance.kas-keluar.print'; }
}
