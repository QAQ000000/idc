<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::get('site', [\App\Http\Controllers\SiteSettingsController::class, 'publicConfig']);
Route::get('captcha/config', [ApiController::class, 'captcha']);
Route::post('auth/register', [ApiController::class, 'register'])->middleware(['customer.throttle:register', 'captcha:register']);
Route::post('auth/login', [ApiController::class, 'login'])->middleware(['customer.throttle:login', 'captcha:login']);
Route::post('auth/password/forgot', [ApiController::class, 'forgot'])->middleware('captcha:password_reset');
Route::post('auth/password/reset', [ApiController::class, 'resetPassword']);
Route::get('products', [ApiController::class, 'products']);
Route::get('products/{id}/config', [ApiController::class, 'configuration']);
Route::post('products/{id}/calculate', [ApiController::class, 'calculate']);
Route::post('products/{id}/preview', [ApiController::class, 'preview']);
Route::middleware('client')->group(function (): void {
    Route::get('tickets', [\App\Http\Controllers\TicketController::class, 'index']);
    Route::get('tickets/instances', [\App\Http\Controllers\TicketController::class, 'instances']);
    Route::post('tickets', [\App\Http\Controllers\TicketController::class, 'store']);
    Route::get('tickets/{id}', [\App\Http\Controllers\TicketController::class, 'show']);
    Route::post('tickets/{id}/replies', [\App\Http\Controllers\TicketController::class, 'reply']);
    Route::post('tickets/{id}/close', [\App\Http\Controllers\TicketController::class, 'close']);

    Route::get('wallet', [\App\Http\Controllers\WalletController::class, 'show']);
    Route::post('wallet/recharges', [\App\Http\Controllers\WalletController::class, 'recharge']);
    Route::get('wallet/transactions', [\App\Http\Controllers\WalletController::class, 'transactions']);
    Route::get('auth/me', [ApiController::class, 'me']);
    Route::post('auth/password', [ApiController::class, 'changePassword']);
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
    Route::post('instances/{id}/service-status', [\App\Http\Controllers\InstanceController::class, 'serviceStatus']);
    Route::get('instances/{id}/runtime', [\App\Http\Controllers\InstanceController::class, 'runtime']);
    Route::get('instances/{id}/reinstall', [\App\Http\Controllers\InstanceController::class, 'reinstallOptions']);
    Route::post('instances/{id}/reinstall', [\App\Http\Controllers\InstanceController::class, 'reinstall']);
    Route::post('instances/{id}/power', [\App\Http\Controllers\InstanceController::class, 'power']);
    Route::post('instances/{id}/renew', [\App\Http\Controllers\InstanceController::class, 'renew']);
    Route::patch('instances/{id}/auto-renew', [\App\Http\Controllers\InstanceController::class, 'automatic']);
});
Route::post('admin/auth/login', [\App\Http\Controllers\AdminAuthController::class, 'login'])->middleware('captcha:login');
Route::middleware('admin')->group(function (): void {
    Route::get('admin/captcha-settings', [\App\Http\Controllers\CaptchaSettingsController::class, 'show']);
    Route::patch('admin/captcha-settings/{scene}', [\App\Http\Controllers\CaptchaSettingsController::class, 'save']);
    Route::get('admin/site-settings', [\App\Http\Controllers\SiteSettingsController::class, 'show']);
    Route::patch('admin/site-settings', [\App\Http\Controllers\SiteSettingsController::class, 'save']);
    Route::get('admin/payment-settings/epay', [\App\Http\Controllers\PaymentSettingsController::class, 'show']);
    Route::patch('admin/payment-settings/epay', [\App\Http\Controllers\PaymentSettingsController::class, 'save']);
    Route::get('admin/auth/security', [\App\Http\Controllers\AdminAccountController::class, 'security']);
    Route::post('admin/auth/password', [\App\Http\Controllers\AdminAccountController::class, 'password']);
    Route::get('admin/accounts', [\App\Http\Controllers\AdminAccountController::class, 'index']);
    Route::post('admin/accounts', [\App\Http\Controllers\AdminAccountController::class, 'store']);
    Route::get('admin/accounts/{id}', [\App\Http\Controllers\AdminAccountController::class, 'show']);
    Route::patch('admin/accounts/{id}', [\App\Http\Controllers\AdminAccountController::class, 'update']);
    Route::post('admin/accounts/{id}/password', [\App\Http\Controllers\AdminAccountController::class, 'reset']);
    Route::get('admin/notification-settings', [\App\Http\Controllers\NotificationSettingsController::class, 'show']);
    Route::patch('admin/notification-settings/mail', [\App\Http\Controllers\NotificationSettingsController::class, 'save']);
    Route::patch('admin/notification-settings/tickets', [\App\Http\Controllers\NotificationSettingsController::class, 'tickets']);

    Route::get('admin/tickets', [\App\Http\Controllers\TicketController::class, 'index']);
    Route::get('admin/tickets/{id}', [\App\Http\Controllers\TicketController::class, 'show']);
    Route::post('admin/tickets/{id}/replies', [\App\Http\Controllers\TicketController::class, 'reply']);
    Route::post('admin/tickets/{id}/close', [\App\Http\Controllers\TicketController::class, 'close']);

    Route::get('admin/provider-drivers', [\App\Http\Controllers\AdminProviderController::class, 'drivers']);
    Route::get('admin/provider-connections', [\App\Http\Controllers\AdminProviderController::class, 'index']);
    Route::post('admin/provider-connections', [\App\Http\Controllers\AdminProviderController::class, 'store']);
    Route::get('admin/provider-connections/{id}', [\App\Http\Controllers\AdminProviderController::class, 'show']);
    Route::patch('admin/provider-connections/{id}', [\App\Http\Controllers\AdminProviderController::class, 'update']);
    Route::post('admin/provider-connections/{id}/verify', [\App\Http\Controllers\AdminProviderController::class, 'verify']);
    Route::get('admin/customers', [\App\Http\Controllers\AdminQueryController::class, 'customers']);
    Route::get('admin/customers/{id}', [\App\Http\Controllers\AdminQueryController::class, 'customer']);
    Route::get('admin/customers/{id}/account', [\App\Http\Controllers\CustomerAccountController::class, 'show']);
    Route::patch('admin/customers/{id}/status', [\App\Http\Controllers\CustomerAccountController::class, 'status']);
    Route::post('admin/customers/{id}/password', [\App\Http\Controllers\CustomerAccountController::class, 'password']);
    Route::get('admin/instances', [\App\Http\Controllers\AdminQueryController::class, 'instances']);
    Route::get('admin/instances/{id}', [\App\Http\Controllers\AdminQueryController::class, 'instance']);
    Route::post('admin/instances/{id}/service-status', [\App\Http\Controllers\InstanceController::class, 'adminServiceStatus']);
    Route::get('admin/instances/{id}/renewal-price', [\App\Http\Controllers\InstanceRenewalPriceController::class, 'show']);
    Route::patch('admin/instances/{id}/renewal-price', [\App\Http\Controllers\InstanceRenewalPriceController::class, 'update']);
    Route::get('admin/instances/{id}/manual-adjustment', [\App\Http\Controllers\ManualAdjustmentController::class, 'show']);
    Route::post('admin/instances/{id}/manual-adjustment/preview', [\App\Http\Controllers\ManualAdjustmentController::class, 'preview']);
    Route::post('admin/instances/{id}/manual-adjustment/settle', [\App\Http\Controllers\ManualAdjustmentController::class, 'settle']);
    Route::get('admin/finance/invoices/{id}', [\App\Http\Controllers\FinanceController::class, 'show']);
    Route::get('admin/finance/{kind}', [\App\Http\Controllers\FinanceController::class, 'index'])->whereIn('kind', ['invoices', 'receipts', 'transactions']);
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
