<?php

declare(strict_types=1);

namespace App\Plugins\Captcha;

final class CaptchaVerifyResult
{
    public function __construct(public readonly bool $success) {}
}
