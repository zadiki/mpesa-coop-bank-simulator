<?php

use App\Http\Controllers\Ui\B2CPageController;
use App\Http\Controllers\Ui\C2BPageController;
use App\Http\Controllers\Ui\CoopPageController;
use App\Http\Controllers\Ui\DashboardController;
use App\Http\Controllers\Ui\SettingsController;
use App\Http\Controllers\Ui\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/forced-outcomes', [DashboardController::class, 'force'])->name('forced.store');
Route::delete('/forced-outcomes/{forcedOutcome}', [DashboardController::class, 'unforce'])->name('forced.destroy');

Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
Route::post('/transactions/{transaction}/resend', [TransactionController::class, 'resend'])->name('transactions.resend');
Route::post('/transactions/{transaction}/process-now', [TransactionController::class, 'processNow'])->name('transactions.process');

Route::get('/c2b', [C2BPageController::class, 'index'])->name('c2b.index');
Route::post('/c2b/pay', [C2BPageController::class, 'pay'])->name('c2b.pay');
Route::post('/c2b/urls', [C2BPageController::class, 'register'])->name('c2b.register');
Route::post('/c2b/shortcodes/{shortcode}/validation', [C2BPageController::class, 'toggleValidation'])->name('c2b.validation');

Route::get('/b2c', [B2CPageController::class, 'index'])->name('b2c.index');
Route::post('/b2c/send', [B2CPageController::class, 'send'])->name('b2c.send');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/settings/apps', [SettingsController::class, 'storeApp'])->name('settings.apps.store');
Route::delete('/settings/apps/{app}', [SettingsController::class, 'destroyApp'])->name('settings.apps.destroy');
Route::post('/settings/shortcodes', [SettingsController::class, 'saveShortcode'])->name('settings.shortcodes.save');

Route::prefix('coop')->name('coop.')->controller(CoopPageController::class)->group(function () {
    Route::get('/', 'accounts')->name('accounts');
    Route::post('/accounts', 'storeAccount')->name('accounts.store');
    Route::get('/accounts/{account}', 'showAccount')->name('accounts.show');
    Route::put('/accounts/{account}', 'updateAccount')->name('accounts.update');
    Route::post('/accounts/{account}/adjust', 'adjust')->name('accounts.adjust');
    Route::get('/transfers', 'transfers')->name('transfers');
    Route::get('/transfers/{transfer}', 'showTransfer')->name('transfers.show');
    Route::post('/transfers/{transfer}/process-now', 'processNow')->name('transfers.process');
    Route::post('/transfers/{transfer}/resend', 'resend')->name('transfers.resend');
    Route::get('/send', 'sendForm')->name('send');
    Route::post('/send', 'send')->name('send.store');
});
