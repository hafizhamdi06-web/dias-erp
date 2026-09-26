@php
    /** @var array $node */
    $menu     = $node['menu'];
    $children = $node['children'] ?? [];
    $isGroup  = $menu->menu_type === 'group' || count($children) > 0;
    $active   = $menu->route && request()->is(trim($menu->route, '/') . '*');
    $icon     = $menu->icon ?: 'fas fa-circle';
@endphp

@if ($isGroup)
    <li class="nav-item {{ $active ? 'menu-open' : '' }}">
        <a href="#" class="nav-link {{ $active ? 'active' : '' }}">
            <i class="nav-icon {{ $icon }}"></i>
            <p>{{ $menu->title }} <i class="nav-arrow fas fa-angle-right"></i></p>
        </a>
        <ul class="nav nav-treeview">
            @foreach ($children as $child)
                @include('partials.sidebar-node', ['node' => $child])
            @endforeach
        </ul>
    </li>
@else
    <li class="nav-item">
        <a href="{{ $menu->route ? url($menu->route) : '#' }}"
           class="nav-link {{ $active ? 'active' : '' }}">
            <i class="nav-icon {{ $icon }}"></i>
            <p>{{ $menu->title }}</p>
        </a>
    </li>
@endif
