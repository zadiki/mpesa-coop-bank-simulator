<?php

namespace App\Services;

use App\Models\CallbackAttempt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Throwable;

/** POSTs a callback to a client URL and records exactly what happened (on a Transaction, CoopTransfer or CoopCollection). */
class CallbackDispatcher
{
    /** @param  array{0: string, 1: string}|null  $basicAuth  username and password, for callbacks the client protects */
    public function send(Model $owner, string $kind, string $url, array $payload, ?array $basicAuth = null): CallbackAttempt
    {
        $started = microtime(true);
        $status = null;
        $body = null;
        $error = null;

        try {
            $request = Http::timeout(config('simulator.callback_timeout_seconds'))
                // A local app on Valet uses a local certificate this process does not trust.
                ->withOptions(['verify' => ! app()->environment('local')])
                ->acceptJson()
                ->withUserAgent('Daraja-Simulator/1.0');
            if ($basicAuth !== null) {
                $request = $request->withBasicAuth($basicAuth[0], $basicAuth[1]);
            }
            $response = $request->post($url, $payload);
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
