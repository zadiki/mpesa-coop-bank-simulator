<?php

namespace App\Http\Controllers\Daraja;

use App\Http\Controllers\Controller;
use App\Models\DarajaApp;
use App\Support\Daraja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OAuthController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        if ($request->query('grant_type') !== 'client_credentials') {
            return Daraja::error('400.008.02', 'Invalid grant type passed', 400);
        }

        $app = DarajaApp::where('provider', 'mpesa')->where('consumer_key', (string) $request->getUser())->first();

        if (! $app || ! hash_equals($app->consumer_secret, (string) $request->getPassword())) {
            return Daraja::error('400.008.01', 'Invalid Authentication passed', 400);
        }

        $token = $app->tokens()->create([
            'token' => Daraja::token(),
            'expires_at' => now()->addSeconds(3599),
        ]);

        return response()->json(['access_token' => $token->token, 'expires_in' => '3599']);
    }
}
