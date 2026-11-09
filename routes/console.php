<?php
use App\Core\{Database, Request};
use App\Services\{BillingService, DemoService};
use Illuminate\Support\Facades\{Artisan, Schedule};

// Laravel otherwise discards scheduled child output. Forward its captured result to the service log.
$scheduledOutput = static function (\Illuminate\Support\Stringable $output): void {
    fwrite(STDERR, (string)$output);
};
$scheduledFailure = static fn(string $command): \Closure => static function () use ($command): void {
    logger()->error('Scheduled command failed', ['command' => $command]);
};

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
Schedule::command('invoices:expire')->everyMinute()->after($scheduledOutput)->onFailure($scheduledFailure('invoices:expire'));
Artisan::command('renewals:generate', function():void {
    $this->info('Renewal invoices checked: '.app(\App\Services\RenewalService::class)->generateDue());
})->purpose('Generate unique fixed renewal invoices seven days before expiry; never debit');
Artisan::command('renewals:debit', function():void {
    $this->info('Renewal invoices paid: '.app(\App\Services\RenewalService::class)->debitDue());
})->purpose('Settle eligible unpaid renewal invoices using opted-in wallet balance; never call upstream');
Schedule::command('renewals:generate')->hourly()->after($scheduledOutput)->onFailure($scheduledFailure('renewals:generate'));
Schedule::command('renewals:debit')->everyFifteenMinutes()->after($scheduledOutput)->onFailure($scheduledFailure('renewals:debit'));

Artisan::command('outbox:work {--once}', function (): void {
    $worker = 'worker:' . bin2hex(random_bytes(12));
    do {
        try {
            $woken = app(\App\Services\RenewalWakeupService::class)->runOnce();
            $delivered = app(\App\Services\OutboxWorker::class)->runOnce($worker);
            $worked = $woken || $delivered;
        }
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
})->purpose('Run fenced create, renew and power tasks; expired claims are investigated, never blindly repeated');

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
    $rows = Database::getInstance()->query("SELECT id,instance_id,invoice_id,action,status,execution_token,lease_until,next_retry_at,upstream_task_id,last_error FROM tasks WHERE status IN ('pending','running','investigating') OR (status='failed' AND last_error LIKE 'MANUAL_AUDIT_REQUIRED:%') ORDER BY id LIMIT 100")->fetchAll();
    $this->line(json_encode($rows, JSON_THROW_ON_ERROR));
})->purpose('Inspect unresolved tasks without dumping payloads, credentials or raw upstream responses');

Artisan::command('products:snapshot', function (): void {
    $input=json_decode(stream_get_contents(STDIN,65536),true,64,JSON_THROW_ON_ERROR);
    $this->line(json_encode(app(\App\Services\ProductSnapshotService::class)->save($input),JSON_THROW_ON_ERROR));
})->purpose('Import or refresh a JSON schema product using an existing provider; read upstream only');

Artisan::command('admin:create', function (): void {
    $input = json_decode(stream_get_contents(STDIN, 8192), true, 32, JSON_THROW_ON_ERROR);
    $id = app(\App\Services\AdminAuthService::class)->create($input);
    $this->line(json_encode(['admin_id'=>$id], JSON_THROW_ON_ERROR));
})->purpose('Create an administrator from private stdin JSON; never reset existing accounts or print credentials');

Artisan::command('notifications:configure {admin}', function():void {
    $input=json_decode(stream_get_contents(STDIN,16384),true,32,JSON_THROW_ON_ERROR);
    app(\App\Services\NotificationSettingsService::class)->configure(Request::id($this->argument('admin')),$input);
    $this->info('Mail settings encrypted and audited.');
})->purpose('Configure the single mail channel from private stdin; no message is sent');
Artisan::command('notifications:tickets {admin}', function():void {
    $input=json_decode(stream_get_contents(STDIN,8192),true,32,JSON_THROW_ON_ERROR);
    $this->line(json_encode(app(\App\Services\NotificationSettingsService::class)->configureTickets(Request::id($this->argument('admin')),$input),JSON_THROW_ON_ERROR));
})->purpose('Start or disable ticket mail from this configuration commit; never backfill old messages');
Artisan::command('notifications:dispatch', function():void {
    $this->line(json_encode(app(\App\Services\NotificationService::class)->dispatch(),JSON_THROW_ON_ERROR));
})->purpose('Sweep manual task alerts and due reminders, then send each unclaimed mail at most once');
Artisan::command('notifications:inspect', function():void {
    $this->line(json_encode(app(\App\Services\NotificationService::class)->inspect(),JSON_THROW_ON_ERROR));
})->purpose('Inspect persistent notification outcomes without private configuration or message contents');
Artisan::command('notifications:resolve {delivery} {admin}', function():void {
    $input=json_decode(stream_get_contents(STDIN,8192),true,32,JSON_THROW_ON_ERROR);
    app(\App\Services\NotificationService::class)->resolve(Request::id($this->argument('admin')),Request::id($this->argument('delivery')),$input);
    $this->info('Notification outcome audited; no resend.');
})->purpose('Close an unknown mail outcome with evidence; never resend or alter the underlying business');
Artisan::command('notifications:sweep', function():void {
    $this->line(json_encode(app(\App\Services\NotificationService::class)->sweep(),JSON_THROW_ON_ERROR));
})->purpose('Queue current notifications and quarantine stale claims; never call SMTP');
Artisan::command('notifications:work {--once}', function():void {
    do {
        try { $worked=app(\App\Services\NotificationService::class)->runOnce(); }
        catch (Throwable) {
            logger()->error('Notification sender requires recovery');$worked=false;
            \Illuminate\Support\Facades\DB::purge();
        } finally {
            \App\Middlewares\AuthMiddleware::reset();\App\Middlewares\AdminAuthMiddleware::reset();
            $connection=\Illuminate\Support\Facades\DB::connection();
            while ($connection->transactionLevel()>0) { $connection->rollBack(); }
        }
        if (!$this->option('once') && !$worked) { usleep(1000000); }
    } while (!$this->option('once'));
})->purpose('Send pending mail independently from the financial Worker and scheduler');
Schedule::command('notifications:sweep')->everyMinute()->after($scheduledOutput)->onFailure($scheduledFailure('notifications:sweep'));
