<?php

namespace App\Livewire\Fina;

use App\Services\KasBankWriter;

class KasKeluarList extends KasBankListBase
{
    public function sumber(): string { return KasBankWriter::KAS_KELUAR; }
    public function judul(): string { return 'Kas Keluar'; }
    public function icon(): string { return 'fas fa-money-bill-transfer'; }
    public function abilityPath(): string { return 'finance/kas-keluar'; }
    public function formComponent(): string { return 'fina.kas-keluar-form'; }
    public function printRoute(): ?string { return 'finance.kas-keluar.print'; }
}
