<?php

namespace App\Support;

use Illuminate\Http\Request;

class ResolvesRequestLocation
{
    public static function country(?Request $request = null): ?string
    {
        $request ??= request();

        $country = $request->header('CF-IPCountry')
            ?? $request->header('X-Vercel-IP-Country')
            ?? $request->header('X-Appengine-Country');

        if (! is_string($country) || $country === '') {
            return null;
        }

        return strtoupper(substr($country, 0, 2));
    }
}
