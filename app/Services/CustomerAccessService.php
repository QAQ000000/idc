<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException,Security};
use App\Middlewares\AuthMiddleware;
use PDO;

/** Final admission for customer actions; never use for receipts or already-paid delivery. */
final class CustomerAccessService
{
    public static function assertActive(PDO $db,int $id): void
    {
        if(!$db->inTransaction()){throw new \LogicException('Customer admission requires a transaction');}
        $s=$db->prepare('SELECT status,auth_version FROM users WHERE id=? FOR UPDATE');$s->execute([$id]);$u=$s->fetch();
        if(!$u||$u['status']!=='active'){throw new ApiException('Account unavailable',403,403);}
        // CLI/scheduled debit uses current status; a customer HTTP action also retains its original session version.
        if((int)(AuthMiddleware::user()['id']??0)===$id){Security::assertUserVersion(['av'=>AuthMiddleware::authVersion()],(int)$u['auth_version']);}
    }
}
