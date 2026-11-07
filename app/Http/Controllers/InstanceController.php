<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Request;
use App\Middlewares\AuthMiddleware;
use App\Services\InstanceService;
use App\Services\RenewalService;
use App\Support\ApiResponse;
final class InstanceController
{
    public function index(InstanceService $s): \Illuminate\Http\JsonResponse { return ApiResponse::success($s->list((int)AuthMiddleware::user()['id'],Request::id(request()->query('page','1')))); }
    public function show(string $id,InstanceService $s): \Illuminate\Http\JsonResponse { return ApiResponse::success($s->detail((int)AuthMiddleware::user()['id'],Request::id($id))); }
    public function credentials(string $id,InstanceService $s): \Illuminate\Http\JsonResponse { return ApiResponse::success($s->credentials((int)AuthMiddleware::user()['id'],Request::id($id))); }
    public function renew(string $id,RenewalService $s): \Illuminate\Http\JsonResponse
    {
        $body=Request::body();
        // First renewal closes one original billing cycle; arbitrary durations are not enabled.
        if (isset($body['months']) && $body['months']!==1) { throw new \App\Core\ApiException('Only one original billing cycle is enabled'); }
        if (isset($body['use_balance']) && !is_bool($body['use_balance'])) { throw new \App\Core\ApiException('Invalid use_balance'); }
        $base=Request::text($body,'base_due_date',19,19);
        $user=(int)AuthMiddleware::user()['id'];$invoice=$s->issue($user,Request::id($id),$base);
        if($body['use_balance']??false){$invoice=(new \App\Services\BillingService())->pay($user,(int)$invoice['invoice_id']);}
        return ApiResponse::success($invoice);
    }
    public function automatic(string $id,RenewalService $s): \Illuminate\Http\JsonResponse
    {
        $enabled=Request::body()['enabled']??null;
        if(!is_bool($enabled)){throw new \App\Core\ApiException('enabled must be boolean');}
        $s->setAutomatic((int)AuthMiddleware::user()['id'],Request::id($id),$enabled);
        return ApiResponse::success(['auto_renew'=>$enabled]);
    }
}
