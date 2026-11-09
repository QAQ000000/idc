<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware as Identity;
use App\Services\CustomerAccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class CustomerAccountController
{
    private function actor(): int { return (int)Identity::admin()['id']; }
    private function response(array $data): JsonResponse { return ApiResponse::success($data)->header('Cache-Control','no-store'); }
    public function show(string $id,CustomerAccountService $s): JsonResponse
    {
        $key=request()->query('request_key');if($key!==null&&!is_string($key)){throw new \App\Core\ApiException('Invalid request_key');}
        return $this->response($s->detail($this->actor(),Identity::authVersion(),Request::id($id),$key));
    }
    public function status(string $id,CustomerAccountService $s): JsonResponse { return $this->response($s->change($this->actor(),Identity::authVersion(),Request::id($id),Request::body())); }
    public function password(string $id,CustomerAccountService $s): JsonResponse { return $this->response($s->change($this->actor(),Identity::authVersion(),Request::id($id),Request::body(),true)); }
}
