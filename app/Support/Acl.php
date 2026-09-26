<?php

namespace App\Support;

use App\Models\Menu;
use App\Models\User;
use App\Models\UserMenu;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Pusat kontrol akses: identitas user aktif, status super admin, pohon menu
 * sidebar, dan pemeriksaan hak (view/add/edit/delete/print/approve).
 *
 * Di-bind sebagai singleton ('acl') dan di-boot sekali per request.
 */
class Acl
{
    protected bool $booted = false;
    protected ?User $user = null;
    protected bool $super = false;

    /** @var Collection<int,\App\Models\Menu> */
    protected Collection $menus;

    /** @var array<int,array<string,bool>> menu_id => peta hak */
    protected array $abilityMap = [];

    public function __construct()
    {
        $this->menus = collect();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->user = Auth::user();
        if ($this->user === null) {
            return;
        }

        $this->super = in_array((int) $this->user->UID, config('acl.super_user_ids', []), true)
            || in_array((string) $this->user->UKODE, config('acl.super_user_codes', []), true);

        $this->menus = Menu::activeOrdered();

        $this->abilityMap = $this->super
            ? []
            : UserMenu::abilityMapForUser((int) $this->user->UID);
    }

    public function refresh(): void
    {
        $this->booted = false;
        $this->boot();
    }

    /* ---- Identitas -------------------------------------------------- */

    public function check(): bool
    {
        $this->boot();

        return $this->user !== null;
    }

    public function user(): ?User
    {
        $this->boot();

        return $this->user;
    }

    public function id(): ?int
    {
        $this->boot();

        return $this->user?->UID;
    }

    public function isSuper(): bool
    {
        $this->boot();

        return $this->super;
    }

    /* ---- Pemeriksaan hak ------------------------------------------- */

    public function canForMenu(int $menuId, string $ability = 'view'): bool
    {
        $this->boot();

        if ($this->user === null) {
            return false;
        }
        if ($this->super) {
            return true;
        }

        return (bool) ($this->abilityMap[$menuId][$ability] ?? false);
    }

    /**
     * Pemeriksaan hak berdasarkan path URI - dipakai middleware & helper can_do().
     */
    public function canRoute(string $uriPath, string $ability = 'view'): bool
    {
        $this->boot();

        if ($this->user === null) {
            return false;
        }
        if ($this->super) {
            return true;
        }

        $menu = $this->matchMenuByPath($uriPath);
        if ($menu === null) {
            return false;
        }

        return (bool) ($this->abilityMap[(int) $menu->id][$ability] ?? false);
    }

    /**
     * Sama spt canRoute(), TAPI utk USER LAIN (bukan yg sedang login di sesi ini) - dipakai
     * gate re-auth spt "buka kunci harga/diskon POS": supervisor ketik username+password
     * miliknya sendiri sementara kasir tetap login sbg dirinya, lalu dicek APAKAH user yg
     * baru saja diverifikasi itu punya hak yg dimaksud - tanpa mengubah sesi Auth aktif.
     */
    public function canUserRoute(int $userId, string $uriPath, string $ability = 'view'): bool
    {
        $this->boot(); // $this->menus independen dari user aktif, aman dipakai ulang di sini

        if (in_array($userId, config('acl.super_user_ids', []), true)) {
            return true;
        }

        $ukode = User::query()->where('UID', $userId)->value('UKODE');
        if ($ukode !== null && in_array((string) $ukode, config('acl.super_user_codes', []), true)) {
            return true;
        }

        $menu = $this->matchMenuByPath($uriPath);
        if ($menu === null) {
            return false;
        }

        $map = UserMenu::abilityMapForUser($userId);

        return (bool) ($map[(int) $menu->id][$ability] ?? false);
    }

    /** Menu yang route-nya paling cocok (terpanjang) dengan path URI. */
    public function matchMenuByPath(string $uriPath): ?Menu
    {
        $uriPath = trim($uriPath, '/');
        $best    = null;
        $bestLen = -1;

        foreach ($this->menus as $menu) {
            $route = trim((string) $menu->route, '/');
            if ($route === '') {
                continue;
            }

            if ($uriPath === $route || str_starts_with($uriPath, $route . '/')) {
                if (strlen($route) > $bestLen) {
                    $best    = $menu;
                    $bestLen = strlen($route);
                }
            }
        }

        return $best;
    }

    /* ---- Sidebar -------------------------------------------------- */

    /**
     * Pohon menu yang boleh dilihat user aktif (untuk sidebar AdminLTE).
     *
     * @return array<int,array>
     */
    public function sidebarTree(): array
    {
        $this->boot();

        if ($this->user === null) {
            return [];
        }

        $visibleIds = [];
        foreach ($this->menus as $menu) {
            if ($menu->isGroup()) {
                continue; // grup diputuskan lewat anak yang terlihat
            }
            if ($this->super || ($this->abilityMap[$menu->id]['view'] ?? false)) {
                $visibleIds[$menu->id] = true;
            }
        }

        return $this->buildTree(null, $visibleIds);
    }

    /**
     * @param array<int,bool> $visibleLeafIds
     * @return array<int,array>
     */
    protected function buildTree(?int $parentId, array $visibleLeafIds): array
    {
        $nodes = [];

        foreach ($this->menus as $menu) {
            if ((int) $menu->parent_id !== (int) $parentId) {
                continue;
            }

            if ($menu->isGroup()) {
                $children = $this->buildTree($menu->id, $visibleLeafIds);
                if ($children === []) {
                    continue;
                }
                $nodes[] = [
                    'menu'     => $menu,
                    'children' => $children,
                ];
                continue;
            }

            if (! isset($visibleLeafIds[$menu->id])) {
                continue;
            }

            $nodes[] = [
                'menu'     => $menu,
                'children' => $this->buildTree($menu->id, $visibleLeafIds),
            ];
        }

        return $nodes;
    }
}
