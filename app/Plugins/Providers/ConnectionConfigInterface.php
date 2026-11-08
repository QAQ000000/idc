<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

/** Optional installed-driver connection form. Values must never be returned by an admin projection. */
interface ConnectionConfigInterface
{
    /** [{key,label,type:url|secret,required,max_length}]; endpoint is separate from credentials. */
    public function connectionFields(): array;
    /** Validate and normalize a new immutable account namespace. No network or business writes. */
    public function validateConnection(array $input): array;
}
