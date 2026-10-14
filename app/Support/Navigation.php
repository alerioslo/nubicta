<?php

namespace App\Support;

use App\Models\User;

final class Navigation
{
    /** @return list<array<string, mixed>> */
    public static function sidebar(?User $user): array
    {
        return array_values(array_filter(
            config('erp.sidebar'),
            fn (array $item) => self::allowed($user, $item['permission'] ?? null),
        ));
    }

    /** @return array<string, list<array<string, string>>> */
    public static function createMenu(?User $user): array
    {
        $menu = [];
        foreach (config('erp.create_menu') as $column => $items) {
            $visible = array_values(array_filter($items, fn (array $i) => self::allowed($user, $i['permission'] ?? null)));
            if ($visible !== []) {
                $menu[$column] = $visible;
            }
        }

        return $menu;
    }

    public static function allowed(?User $user, ?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }
        if ($user === null) {
            return false;
        }

        return $user->hasRole('Administrador') || $user->can($permission);
    }
}
