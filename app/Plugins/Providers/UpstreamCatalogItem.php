<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

final readonly class UpstreamCatalogItem
{
    public function __construct(
        public int $remotePid,
        public string $name,
        public string $category,
        public string $type,
        public string $stockState,
        public ?int $stockQty = null,
        public bool $hidden = false,
        public ?string $description = null,
        public int $observedAt = 0,
    ) {
        if ($remotePid < 1 || $remotePid > 2147483647 || $name === '' || mb_strlen($name) > 100
            || mb_strlen($category) > 100 || ! in_array($type, ['cloud', 'dedicated', 'cdn', 'hosting', 'software', 'unsupported'], true)
            || ($description !== null && (mb_strlen($description) > 500 || strip_tags($description) !== $description)) || $observedAt < 1) {
            throw new \InvalidArgumentException('Invalid catalog item');
        }
        new StockObservation($stockState, $stockQty);
    }

    public function projection(): array
    {
        return ['remote_pid' => $this->remotePid, 'name' => $this->name, 'category' => $this->category,
            'type' => $this->type, 'stock_state' => $this->stockState, 'stock_qty' => $this->stockQty,
            'hidden' => $this->hidden, 'description' => $this->description, 'observed_at' => $this->observedAt];
    }
}
