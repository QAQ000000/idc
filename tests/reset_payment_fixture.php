<?php

declare(strict_types=1);

/** Only the disposable shared-suite DB may retire a fixture merchant before installing another. */
function resetPaymentFixture(): void
{
    if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
        throw new RuntimeException('Disposable payment fixtures only');
    }
    $db = \App\Core\Database::getInstance();
    // Prior suites retain receipt/ledger facts, but no longer share the new fixture merchant namespace.
    $db->exec("UPDATE payment_records SET channel=CONCAT('fixture_epay_',id) WHERE channel='epay'");
    $db->exec("DELETE FROM system_settings WHERE `key`='payment.epay'");
}
