<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\ApiException;
use App\Services\PaymentService;

final class PaymentController
{
    public function notify(string $channel, PaymentService $payments): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $payments->notify($channel, request()->isMethod('GET') ? request()->query->all() : request()->request->all());
        } catch (ApiException) {
            return response('fail', 400)->header('Content-Type', 'text/plain');
        } catch (\Throwable) {
            logger()->error('Payment notification could not settle', ['channel' => $channel]);

            return response('fail', 500)->header('Content-Type', 'text/plain');
        }

        return response('success')->header('Content-Type', 'text/plain');
    }
}
