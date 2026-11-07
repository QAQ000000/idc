<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Clock-controlled scheduler requires isolated test database');
}
// Only this test process changes the schedule clock. The host and production commands are untouched.
Illuminate\Support\Carbon::setTestNow($argv[2]??'2026-11-08 00:01:00');
if (($argv[1]??'')==='due') {
    $out=[];
    foreach (app(Illuminate\Console\Scheduling\Schedule::class)->dueEvents(app()) as $event) {
        foreach (['invoices:expire','renewals:generate','renewals:debit'] as $command) {
            if (str_contains($event->command,$command)) { $out[]=$command; }
        }
    }
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;
} elseif (($argv[1]??'')==='run') {
    Illuminate\Support\Facades\Artisan::call('schedule:run',['--no-interaction'=>true]);
    echo Illuminate\Support\Facades\Artisan::output();
} else { throw new RuntimeException('Unknown schedule test mode'); }
