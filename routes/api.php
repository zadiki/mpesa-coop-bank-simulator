<?php

use App\Http\Controllers\CatchController;
use App\Http\Controllers\Coop\CoopApiController;
use App\Http\Controllers\Daraja\B2CController;
use App\Http\Controllers\Daraja\C2BController;
use App\Http\Controllers\Daraja\OAuthController;
use App\Http\Controllers\Daraja\PullController;
use App\Http\Controllers\Daraja\StkController;
use Illuminate\Support\Facades\Route;

/*
| Daraja-compatible endpoints. Paths match api.safaricom.co.ke / sandbox.safaricom.co.ke.
*/
Route::get('/oauth/v1/generate', [OAuthController::class, 'generate']);

Route::middleware('daraja.token')->prefix('mpesa')->group(function () {
    Route::post('/c2b/{version}/registerurl', [C2BController::class, 'registerUrl'])->whereIn('version', ['v1', 'v2']);
    Route::post('/c2b/{version}/simulate', [C2BController::class, 'simulate'])->whereIn('version', ['v1', 'v2']);

    Route::post('/b2c/v1/paymentrequest', [B2CController::class, 'v1']);
    Route::post('/b2c/v3/paymentrequest', [B2CController::class, 'v3']);

    Route::post('/stkpush/v1/processrequest', [StkController::class, 'push']);
    Route::post('/stkpushquery/v1/query', [StkController::class, 'query']);
});

Route::middleware('daraja.token')->prefix('pulltransactions/v1')->group(function () {
    Route::post('/register', [PullController::class, 'register']);
    Route::post('/query', [PullController::class, 'query']);
});

/*
| Built-in callback catcher, so flows can be tested end to end without a client app.
| Use e.g. http://localhost:8090/sim/catch/result as a ResultURL.
| Add ?reject=1 to a validation URL to make it reject payments.
*/
Route::any('/sim/catch/{any}', CatchController::class)->where('any', '.*');

/*
| Co-op Bank Connect endpoints. Paths match openapi-sit.co-opbank.co.ke (SIT collection, Oct 2026).
*/
Route::post('/token', [CoopApiController::class, 'token']);

Route::middleware('coop.token')->group(function () {
    Route::post('/FundsTransfer/Internal/A2A_v3/3.0.0', [CoopApiController::class, 'internalTransfer']);
    Route::post('/FundsTransfer/External/A2A/PesaLink_v4/4.0.0', [CoopApiController::class, 'pesalinkTransfer']);
    Route::post('/FundsTransfer/External/A2M/Mpesa_v2/2.0.0', [CoopApiController::class, 'mpesaTransfer']);

    Route::post('/Enquiry/TransactionStatus/2.0.0', [CoopApiController::class, 'transactionStatus']);
    Route::post('/Enquiry/AccountBalance_v2/2.0.0', [CoopApiController::class, 'accountBalance']);
    Route::post('/Enquiry/MiniStatement/Account_v2/2.0.0', [CoopApiController::class, 'miniStatement']);
    Route::post('/Enquiry/AccountFullStatementPaginated/1.0.0', [CoopApiController::class, 'fullStatement']);
    Route::post('/Enquiry/Validation/IPSL/1.0.0', [CoopApiController::class, 'validateAccount']);
});
