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
Artisan::command('renewals:generate', function():void {
    $this->info('Renewal invoices checked: '.app(\App\Services\RenewalService::class)->generateDue());
})->purpose('Generate unique fixed renewal invoices seven days before expiry; never debit');
Artisan::command('renewals:debit', function():void {
    $this->info('Renewal invoices paid: '.app(\App\Services\RenewalService::class)->debitDue());
})->purpose('Settle eligible unpaid renewal invoices using opted-in wallet balance; never call upstream');
Schedule::command('renewals:generate')->hourly();
Schedule::command('renewals:debit')->everyFifteenMinutes();

Artisan::command('outbox:work {--once}', function (): void {
    $worker = 'worker:' . bin2hex(random_bytes(12));
    do {
        try { $worked = app(\App\Services\OutboxWorker::class)->runOnce($worker); }
        catch (Throwable) {
            logger()->error('Outbox scan failed'); $worked = false;
            \Illuminate\Support\Facades\DB::purge(); // The next scan reconnects after a database outage.
        }
        finally {
            // Long-lived CLI may never carry HTTP authorization or leaked transactions between jobs.
            \App\Middlewares\AuthMiddleware::reset();
            \App\Middlewares\AdminAuthMiddleware::reset();
            $connection = \Illuminate\Support\Facades\DB::connection();
            while ($connection->transactionLevel() > 0) { $connection->rollBack(); }
        }
        if (!$this->option('once') && !$worked) { usleep(500000); }
    } while (!$this->option('once'));
})->purpose('Run fenced create tasks; expired claims are investigated, never blindly recreated');

Artisan::command('connections:install', function (): void {
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 64, JSON_THROW_ON_ERROR);
    $result = app(\App\Services\ConnectionSetupService::class)->install($input);
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));
})->purpose('Install one new upstream account/product/payment config from stdin; secrets are encrypted');
Artisan::command('outbox:resolve {task} {admin}', function (): void {
    $input = json_decode(stream_get_contents(STDIN, 8192), true, 32, JSON_THROW_ON_ERROR);
    app(\App\Services\TaskResolutionService::class)->resolve(Request::id($this->argument('admin')), Request::id($this->argument('task')), $input);
    $this->info('Resolution committed with audit.');
})->purpose('Super-admin evidence-based resolution from stdin, with expected_execution_token');
Artisan::command('outbox:inspect', function (): void {
    $rows = Database::getInstance()->query("SELECT id,instance_id,invoice_id,status,execution_token,lease_until,next_retry_at,upstream_task_id,last_error FROM tasks WHERE status IN ('pending','running','investigating') OR (status='failed' AND last_error LIKE 'MANUAL_AUDIT_REQUIRED:%') ORDER BY id LIMIT 100")->fetchAll();
    $this->line(json_encode($rows, JSON_THROW_ON_ERROR));
})->purpose('Inspect unresolved tasks without dumping payloads, credentials or raw upstream responses');
