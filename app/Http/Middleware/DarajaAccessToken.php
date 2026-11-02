<?php

namespace App\Http\Middleware;

use App\Models\AccessToken;
use App\Support\Daraja;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DarajaAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $valid = $token && AccessToken::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereHas('app', fn ($q) => $q->where('provider', 'mpesa'))
            ->exists();

        if (! $valid) {
            return Daraja::error('404.001.03', 'Invalid Access Token', 401);
        }

        return $next($request);
    }
}
