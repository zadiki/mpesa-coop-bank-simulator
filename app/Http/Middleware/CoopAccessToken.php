<?php

namespace App\Http\Middleware;

use App\Models\AccessToken;
use App\Support\Coop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CoopAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $valid = $token && AccessToken::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereHas('app', fn ($q) => $q->where('provider', 'coop'))
            ->exists();

        return $valid ? $next($request) : Coop::gatewayFault();
    }
}
