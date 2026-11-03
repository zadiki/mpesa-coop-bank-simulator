<?php

use App\Http\Middleware\CoopAccessToken;
use App\Http\Middleware\DarajaAccessToken;
use App\Support\Daraja;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Daraja paths live at the root (/oauth/..., /mpesa/...), exactly like
        // sandbox.safaricom.co.ke, so client apps only need to swap the base URL.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'daraja.token' => DarajaAccessToken::class,
            'coop.token' => CoopAccessToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isDaraja = fn (Request $request) => $request->is('oauth/*', 'mpesa/*', 'pulltransactions/*');

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $isDaraja($request) || $request->is('token', 'Enquiry/*', 'FundsTransfer/*', 'sim/catch/*') || $request->expectsJson(),
        );

        // Daraja reports bad payloads as 400 {requestId, errorCode, errorMessage},
        // not Laravel's 422 {message, errors}. Mirror that so client error handling is exercised.
        $exceptions->render(function (ValidationException $e, Request $request) use ($isDaraja) {
            if (! $isDaraja($request)) {
                return null;
            }

            return Daraja::error('400.002.02', 'Bad Request - Invalid '.array_key_first($e->errors()), 400);
        });
    })->create();
