<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\{ApiException,Request};
use App\Middlewares\{AdminAuthMiddleware,AuthMiddleware};
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
final class TicketController
{
    private function identity(): array { $admin=request()->is('api/v1/admin/*');if(!$admin&&request()->method()!=='GET'&&AuthMiddleware::isImpersonated()){throw new ApiException('Use the administrator public reply endpoint',403,403);}return [(int)($admin?AdminAuthMiddleware::admin()['id']:AuthMiddleware::user()['id']),$admin]; }
    private function response(array $value): JsonResponse {return ApiResponse::success($value)->header('Cache-Control','no-store');}
    public function index(TicketService $s): JsonResponse {[$id,$admin]=$this->identity();return $this->response($s->listing($id,$admin,request()->query()));}
    public function show(string $id,TicketService $s): JsonResponse {[$actor,$admin]=$this->identity();return $this->response($s->detail($actor,$admin,Request::id($id),request()->query()));}
    public function store(TicketService $s): JsonResponse {[$id]=$this->identity();return $this->response($s->create($id,Request::body()));}
    public function instances(TicketService $s): JsonResponse {return $this->response($s->instances((int)AuthMiddleware::user()['id'],request()->query()));}
    public function reply(string $id,TicketService $s): JsonResponse {[$actor,$admin]=$this->identity();return $this->response($s->write($actor,$admin,Request::id($id),Request::body()));}
    public function close(string $id,TicketService $s): JsonResponse {[$actor,$admin]=$this->identity();return $this->response($s->write($actor,$admin,Request::id($id),Request::body(),true));}
}
