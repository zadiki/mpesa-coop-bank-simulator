<?php

namespace App\Services;

use App\Models\CallbackAttempt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Throwable;

/** POSTs a callback to a client URL and records exactly what happened (on a Transaction or CoopTransfer). */
class CallbackDispatcher
{
    public function send(Model $owner, string $kind, string $url, array $payload): CallbackAttempt
    {
        $started = microtime(true);
        $status = null;
        $body = null;
        $error = null;

        try {
            $response = Http::timeout(config('simulator.callback_timeout_seconds'))
                ->acceptJson()
                ->withUserAgent('Daraja-Simulator/1.0')
                ->post($url, $payload);
            $status = $response->status();
            $body = $response->body();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return $owner->attempts()->create([
            'kind' => $kind,
            'url' => $url,
            'request_body' => $payload,
            'http_status' => $status,
            'response_body' => $body !== null ? mb_substr($body, 0, 20000) : null,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
