<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Interface languages the application supports.
     *
     * @var array<int, string>
     */
    public const LOCALES = ['ar', 'en'];

    /**
     * Right-to-left interface languages.
     *
     * @var array<int, string>
     */
    public const RTL_LOCALES = ['ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale')
            ?? $request->cookie('masar_locale')
            ?? config('app.locale');

        if (! in_array($locale, self::LOCALES, true)) {
            $locale = 'ar';
        }

        App::setLocale($locale);
        Date::setLocale($locale);

        return $next($request);
    }

    public static function isRtl(?string $locale = null): bool
    {
        return in_array($locale ?? App::getLocale(), self::RTL_LOCALES, true);
    }
}
