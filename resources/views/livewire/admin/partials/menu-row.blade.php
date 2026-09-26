@php $menu = $node['menu']; @endphp
<tr wire:key="menu-{{ $menu->id }}">
    <td>
        <span style="padding-left: {{ $depth * 1.5 }}rem"></span>
        @if ($menu->icon)<i class="{{ $menu->icon }} text-muted me-1"></i>@endif
        {{ $menu->title }}
    </td>
    <td class="text-muted small">{{ $menu->route ?: '—' }}</td>
    <td>
        <span class="badge {{ $menu->menu_type === 'group' ? 'text-bg-secondary' : 'text-bg-info' }}">
            {{ $menu->menu_type }}
        </span>
    </td>
    <td class="text-center">{{ $menu->sort_order }}</td>
    <td class="text-center">
        @if ($menu->is_active)
            <i class="fas fa-check text-success"></i>
        @else
            <i class="fas fa-xmark text-danger"></i>
        @endif
    </td>
    <td class="text-end text-nowrap">
        @if (can_do('admin/menu', 'edit'))
            <button class="btn btn-outline-secondary btn-sm" wire:click="edit({{ $menu->id }})">
                <i class="fas fa-pen"></i>
            </button>
        @endif
        @if (can_do('admin/menu', 'delete'))
            <button class="btn btn-outline-danger btn-sm"
                    wire:click="delete({{ $menu->id }})"
                    data-confirm="Hapus menu &quot;{{ $menu->title }}&quot;?">
                <i class="fas fa-trash"></i>
            </button>
        @endif
    </td>
</tr>
@foreach ($node['children'] as $child)
    @include('livewire.admin.partials.menu-row', ['node' => $child, 'depth' => $depth + 1])
@endforeach
