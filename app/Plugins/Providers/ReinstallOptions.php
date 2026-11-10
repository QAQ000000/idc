<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

/** Safe projection. No raw header, credentials, URLs or optimistic disk guarantees. */
final readonly class ReinstallOptions
{
    public function __construct(public array $images = [], public string $passwordMode = 'upstream_generated', public string $dataDisk = 'no_format_requested')
    {
        if ($passwordMode !== 'upstream_generated' || $dataDisk !== 'no_format_requested' || ! array_is_list($images) || count($images) > 500) {
            throw new \InvalidArgumentException('Unsupported reinstall options');
        }
        $ids = [];
        foreach ($images as $image) {
            if (! is_array($image) || count($image) !== 2 || ! is_string($image['id'] ?? null) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $image['id'])
                || ! is_string($image['name'] ?? null) || trim($image['name']) === '' || strlen($image['name']) > 160 || preg_match('/[\x00-\x1f\x7f<>]/', $image['name']) || isset($ids[$image['id']])) {
                throw new \InvalidArgumentException('Invalid reinstall image');
            }
            $ids[$image['id']] = true;
        }
    }

    /** MySQL JSON normalizes object key order; compare values strictly without depending on that order. */
    public function matches(array $value): bool
    {
        if (count($value) !== 3 || ($value['password_mode'] ?? null) !== $this->passwordMode || ($value['data_disk'] ?? null) !== $this->dataDisk
            || ! is_array($value['images'] ?? null) || count($value['images']) !== count($this->images)) {
            return false;
        }
        foreach ($this->images as $index => $image) {
            $other = $value['images'][$index] ?? null;
            if (! is_array($other) || count($other) !== 2 || ($other['id'] ?? null) !== $image['id'] || ($other['name'] ?? null) !== $image['name']) {
                return false;
            }
        }

        return true;
    }

    public function projection(): array
    {
        return ['images' => $this->images, 'password_mode' => $this->passwordMode, 'data_disk' => $this->dataDisk];
    }
}
