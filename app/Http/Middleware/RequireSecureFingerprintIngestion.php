<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device ingestion secrets travel in the URL path (ADMS) or a bearer header
 * (bridge), so production ingestion is only served over HTTPS. Local and
 * testing environments allow plain HTTP for development and test runs.
 */
class RequireSecureFingerprintIngestion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->secure()) {
            return response('HTTPS is required for device ingestion.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $next($request);
    }
}
