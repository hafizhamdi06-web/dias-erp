@php $menu = $node['menu']; @endphp

@if ($menu->menu_type === 'group')
    <tr class="table-active">
        <td colspan="{{ count($abilities) + 1 }}" class="fw-semibold">
            <span style="padding-left: {{ $depth * 1.25 }}rem"></span>
            @if ($menu->icon)<i class="{{ $menu->icon }} me-1"></i>@endif
            {{ $menu->title }}
        </td>
    </tr>
@else
    <tr wire:key="acc-{{ $menu->id }}">
        <td>
            <span style="padding-left: {{ $depth * 1.25 }}rem"></span>
            @if ($menu->icon)<i class="{{ $menu->icon }} text-muted me-1"></i>@endif
            {{ $menu->title }}
            <span class="text-muted small">({{ $menu->route }})</span>
        </td>
        @foreach ($abilities as $ab)
            <td class="text-center">
                <input type="checkbox" class="form-check-input"
                       wire:model.live="grid.{{ $menu->id }}.{{ $ab }}">
            </td>
        @endforeach
    </tr>
@endif

@foreach ($node['children'] as $child)
    @include('livewire.admin.partials.access-row', ['node' => $child, 'depth' => $depth + 1, 'abilities' => $abilities])
@endforeach
