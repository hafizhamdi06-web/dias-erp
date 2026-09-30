<?php

namespace App\Livewire\Fina;

use App\Services\KasBankWriter;

class KasMasukList extends KasBankListBase
{
    public function sumber(): string { return KasBankWriter::KAS_MASUK; }
    public function judul(): string { return 'Kas Masuk'; }
    public function icon(): string { return 'fas fa-money-bill-trend-up'; }
    public function abilityPath(): string { return 'finance/kas-masuk'; }
    public function formComponent(): string { return 'fina.kas-masuk-form'; }
    public function printRoute(): ?string { return 'finance.kas-masuk.print'; }
}
