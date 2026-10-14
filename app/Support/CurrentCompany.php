<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

final class CurrentCompany
{
    private static ?int $override = null;

    public static function id(): ?int
    {
        if (self::$override !== null) {
            return self::$override;
        }

        return Auth::user()?->company_id;
    }

    public static function get(): ?Company
    {
        $id = self::id();

        return $id ? Company::find($id) : null;
    }

    /** Fija la empresa activa en procesos sin sesion (comandos, colas, importadores). */
    public static function set(?int $companyId): void
    {
        self::$override = $companyId;
    }
}
