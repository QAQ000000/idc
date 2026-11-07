<?php
use App\Core\{Database, Request};
use App\Services\{BillingService, DemoService};
use Illuminate\Support\Facades\{Artisan, Schedule};

Artisan::command('db:init', function (): void {
    if (Database::getInstance()->query('SHOW TABLES')->fetchColumn() !== false) {
        throw new RuntimeException('db:init requires an empty database; use migrate for an existing MVP');
    }
    $this->call('migrate', ['--force' => true]);
})->purpose('Initialize an empty JianYun database with the reviewed schema');
Artisan::command('demo:seed', function (): void {
    $this->line(json_encode(['product_id' => app(DemoService::class)->seed()], JSON_THROW_ON_ERROR));
});
Artisan::command('demo:recharge {user} {amount} {reference}', function (): void {
    $id = app(DemoService::class)->recharge(Request::id($this->argument('user')), $this->argument('amount'), $this->argument('reference'));
    $this->line(json_encode(['invoice_id' => $id], JSON_THROW_ON_ERROR));
});
Artisan::command('invoices:expire', function (): void {
    $rows = Database::getInstance()->query("SELECT id, user_id FROM invoices WHERE type = 'new' AND status = 'unpaid' AND quote_expired_at <= UTC_TIMESTAMP() ORDER BY id LIMIT 500")->fetchAll();
    foreach ($rows as $row) {
        app(BillingService::class)->cancel((int)$row['user_id'], (int)$row['id'], true);
    }
    $this->info('Processed '.count($rows).' expired invoices.');
});
Schedule::command('invoices:expire')->everyMinute();
