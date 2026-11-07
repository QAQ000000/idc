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

Route::match(['get', 'post'], 'payments/{channel}/notify', [\App\Http\Controllers\PaymentController::class, 'notify']);
Route::middleware('client')->group(function (): void {
    Route::get('instances', [\App\Http\Controllers\InstanceController::class, 'index']);
    Route::get('instances/{id}', [\App\Http\Controllers\InstanceController::class, 'show']);
    Route::get('instances/{id}/credentials', [\App\Http\Controllers\InstanceController::class, 'credentials']);
});
Route::post('admin/tasks/{id}/resolve', [\App\Http\Controllers\TaskController::class, 'resolve']);
