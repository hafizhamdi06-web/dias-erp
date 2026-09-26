<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Log Aktivitas')]
class ActivityLogView extends Component
{
    use WithPagination;

    /** Diteruskan Workspace ke tiap tab. */
    public ?string $tabKey = null;

    public string $q = '';
    public string $action = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    public function updating($name): void
    {
        if (in_array($name, ['q', 'action', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilter(): void
    {
        $this->reset(['q', 'action', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function render()
    {
        $logs = ActivityLog::query()
            ->when($this->q !== '', function ($query) {
                $q = trim($this->q);
                $query->where(function ($w) use ($q) {
                    $w->where('user_label', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%")
                        ->orWhere('module', 'like', "%{$q}%");
                });
            })
            ->when($this->action !== '', fn ($query) => $query->where('action', $this->action))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('id')
            ->paginate(30);

        return view('livewire.admin.activity-log-view', [
            'logs'    => $logs,
            'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
