<?php

namespace App\Livewire\Fina;

use App\Services\KasBankWriter;

class KasMasukForm extends KasBankFormBase
{
    public function sumber(): string { return KasBankWriter::KAS_MASUK; }
    public function judul(): string { return 'Kas Masuk'; }
    public function isBank(): bool { return false; }
    public function abilityPath(): string { return 'finance/kas-masuk'; }
}
