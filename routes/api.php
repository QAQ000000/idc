<?php
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::get('captcha/config', [ApiController::class, 'captcha']);
Route::post('auth/register', [ApiController::class, 'register'])->middleware('captcha:register');
Route::post('auth/login', [ApiController::class, 'login'])->middleware('captcha:login');
Route::get('products', [ApiController::class, 'products']);
Route::post('products/{id}/calculate', [ApiController::class, 'calculate']);
Route::middleware('client')->group(function (): void {
    Route::get('auth/me', [ApiController::class, 'me']);
    Route::post('orders/checkout', [ApiController::class, 'checkout']);
    Route::get('invoices/{id}', [ApiController::class, 'invoice']);
    Route::post('invoices/{id}/pay', [ApiController::class, 'pay']);
    Route::post('invoices/{id}/cancel', [ApiController::class, 'cancel']);
});
