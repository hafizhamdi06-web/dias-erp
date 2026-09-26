@php
    $menu     = $node['menu'];
    $children = $node['children'] ?? [];
    $isGroup  = $menu->menu_type === 'group' || count($children) > 0;
    $icon     = $menu->icon ?: 'fas fa-circle';
@endphp

@if ($isGroup)
    <li class="nav-item">
        <a href="#" class="nav-link" onclick="event.preventDefault()">
            <i class="nav-icon {{ $icon }}"></i>
            <p>{{ $menu->title }} <i class="nav-arrow fas fa-angle-right"></i></p>
        </a>
        <ul class="nav nav-treeview">
            @foreach ($children as $child)
                @include('livewire.partials.ws-sidebar-node', ['node' => $child])
            @endforeach
        </ul>
    </li>
@else
    <li class="nav-item">
        <a href="#" class="nav-link" onclick="event.preventDefault()"
           wire:click="openFromSidebar('{{ $menu->segment_key }}')">
            <i class="nav-icon {{ $icon }}"></i>
            <p>{{ $menu->title }}</p>
        </a>
    </li>
@endif
