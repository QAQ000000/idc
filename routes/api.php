<?php
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::get('captcha/config', [ApiController::class, 'captcha']);
Route::post('auth/register', [ApiController::class, 'register'])->middleware('captcha:register');
Route::post('auth/login', [ApiController::class, 'login'])->middleware('captcha:login');
Route::post('auth/password/forgot',[ApiController::class,'forgot'])->middleware('captcha:password_reset');
Route::post('auth/password/reset',[ApiController::class,'resetPassword']);
Route::get('products', [ApiController::class, 'products']);
Route::get('products/{id}/config', [ApiController::class, 'configuration']);
Route::post('products/{id}/calculate', [ApiController::class, 'calculate']);
Route::middleware('client')->group(function (): void {
    Route::get('wallet', [\App\Http\Controllers\WalletController::class, 'show']);
    Route::post('wallet/recharges', [\App\Http\Controllers\WalletController::class, 'recharge']);
    Route::get('wallet/transactions', [\App\Http\Controllers\WalletController::class, 'transactions']);
    Route::get('auth/me', [ApiController::class, 'me']);
    Route::post('auth/password',[ApiController::class,'changePassword']);
    Route::post('orders/checkout', [ApiController::class, 'checkout']);
    Route::get('invoices', [ApiController::class, 'invoices']);
    Route::get('invoices/{id}', [ApiController::class, 'invoice']);
    Route::post('invoices/{id}/pay', [ApiController::class, 'pay']);
    Route::post('invoices/{id}/cancel', [ApiController::class, 'cancel']);
});

Route::match(['get', 'post'], 'payments/{channel}/notify', [\App\Http\Controllers\PaymentController::class, 'notify']);
Route::middleware('client')->group(function (): void {
    Route::get('instances', [\App\Http\Controllers\InstanceController::class, 'index']);
    Route::get('instances/{id}', [\App\Http\Controllers\InstanceController::class, 'show']);
    Route::get('instances/{id}/credentials', [\App\Http\Controllers\InstanceController::class, 'credentials']);
    Route::post('instances/{id}/console/check', [\App\Http\Controllers\InstanceController::class, 'consoleCheck']);
    Route::post('instances/{id}/console', [\App\Http\Controllers\InstanceController::class, 'console']);
    Route::get('instances/{id}/runtime', [\App\Http\Controllers\InstanceController::class, 'runtime']);
    Route::post('instances/{id}/power', [\App\Http\Controllers\InstanceController::class, 'power']);
    Route::post('instances/{id}/renew', [\App\Http\Controllers\InstanceController::class, 'renew']);
    Route::patch('instances/{id}/auto-renew', [\App\Http\Controllers\InstanceController::class, 'automatic']);
});
Route::post('admin/auth/login', [\App\Http\Controllers\AdminAuthController::class, 'login'])->middleware('captcha:login');
Route::middleware('admin')->group(function (): void {
    Route::get('admin/customers', [\App\Http\Controllers\AdminQueryController::class, 'customers']);
    Route::get('admin/customers/{id}', [\App\Http\Controllers\AdminQueryController::class, 'customer']);
    Route::get('admin/instances', [\App\Http\Controllers\AdminQueryController::class, 'instances']);
    Route::get('admin/instances/{id}', [\App\Http\Controllers\AdminQueryController::class, 'instance']);
    Route::get('admin/finance/invoices/{id}', [\App\Http\Controllers\FinanceController::class, 'show']);
    Route::get('admin/finance/{kind}', [\App\Http\Controllers\FinanceController::class, 'index'])->whereIn('kind',['invoices','receipts','transactions']);
    Route::get('admin/providers', [\App\Http\Controllers\AdminProductController::class, 'providers']);
    Route::get('admin/products', [\App\Http\Controllers\AdminProductController::class, 'index']);
    Route::post('admin/products/preview', [\App\Http\Controllers\AdminProductController::class, 'preview']);
    Route::post('admin/products/import', [\App\Http\Controllers\AdminProductController::class, 'import']);
    Route::get('admin/products/{id}', [\App\Http\Controllers\AdminProductController::class, 'show']);
    Route::patch('admin/products/{id}', [\App\Http\Controllers\AdminProductController::class, 'update']);
    Route::post('admin/products/{id}/snapshot/preview', [\App\Http\Controllers\AdminProductController::class, 'previewRefresh']);
    Route::post('admin/products/{id}/snapshot', [\App\Http\Controllers\AdminProductController::class, 'refresh']);
    Route::get('admin/auth/me', [\App\Http\Controllers\AdminAuthController::class, 'me']);
    Route::get('admin/tasks', [\App\Http\Controllers\TaskController::class, 'index']);
    Route::get('admin/tasks/{id}', [\App\Http\Controllers\TaskController::class, 'show']);
    Route::post('admin/tasks/{id}/resolve', [\App\Http\Controllers\TaskController::class, 'resolve']);
});
