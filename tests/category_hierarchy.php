<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\{ApiException, Database, Security};
use App\Services\{AdminAuthService, AdminProductService, CategoryService};

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated database required');
}

$db = Database::getInstance();
if (($argv[1] ?? '') === '--child-create') {
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    echo 'READY:'.$db->query('SELECT CONNECTION_ID()')->fetchColumn().PHP_EOL;
    flush();
    try {
        (new CategoryService())->create($input['admin'], $input['input']);
        echo json_encode(['status' => 200]);
    } catch (ApiException $e) {
        echo json_encode(['status' => $e->httpStatus]);
    }
    exit;
}
$checks = 0;

function catCheck(bool $ok, string $label): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException('FAIL CATEGORY: '.$label);
    }
    $checks++;
    echo 'PASS CATEGORY: '.$label.PHP_EOL;
}

function catHttp(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $url = getenv('TEST_BASE_URL').'/api/v1'.$path;
    $h = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($h);
    $status = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);

    return [$status, json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR)];
}

function catReject(callable $fn, int $status, string $label): void
{
    try {
        $fn();
    } catch (ApiException $e) {
        catCheck($e->httpStatus === $status, $label);

        return;
    }
    catCheck(false, $label);
}

// 1. Setup Admins & Provider
$auth = new AdminAuthService;
$admins = [];
$tokens = [];
foreach (['super_admin', 'support', 'finance', 'operator'] as $role) {
    $admins[$role] = $auth->create([
        'username' => 'cathier-'.$role,
        'email' => 'cathier-'.$role.'@example.test',
        'password' => 'CatHier!2026',
        'role' => $role,
    ]);
    $tokens[$role] = Security::generateToken([
        'uid' => $admins[$role],
        'sub_type' => 'admin',
        'av' => AdminAuthService::sessionVersion($db, $admins[$role]),
    ]);
}
$superToken = $tokens['super_admin'];
$supportToken = $tokens['support'];

$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials,status) VALUES(?,?,?,?,1)')
    ->execute(['层级分类验收上游', 'zjmf', getenv('UPSTREAM_TEST_URL'), json_encode(['username' => Security::encrypt('test'), 'password' => Security::encrypt('test')])]);
$providerId = (int) $db->lastInsertId();

$service = new CategoryService;

// 2. RBAC Checks for Category Management
// Anonymous write rejected
[$status, $resp] = catHttp('POST', '/admin/categories', ['name' => '未授权分组', 'slug' => 'unauth-group'], null);
catCheck($status === 401, 'anonymous category write rejected with 401');

// Non-super admin write rejected
foreach (['support', 'finance', 'operator'] as $role) {
    [$status, $resp] = catHttp('POST', '/admin/categories', ['name' => $role.' 分组', 'slug' => $role.'-group'], $tokens[$role]);
    catCheck($status === 403, $role.' cannot create category');
}

// Non-super admin CAN read categories
[$status, $resp] = catHttp('GET', '/admin/categories', null, $supportToken);
catCheck($status === 200 && $resp['code'] === 0, 'support admin can read admin categories');

// 3. Tree Depth & Validation Tests (Depth <= 2)
// 3.1 Create Level 1 Group
$groupA = $service->create($admins['super_admin'], [
    'name' => '中国大陆',
    'slug' => 'china-mainland',
    'type' => 'cloud',
    'order_num' => 100,
    'status' => 1,
]);
catCheck($groupA['id'] > 0 && $groupA['parent_id'] === null, 'Level 1 group created with parent_id = null');

// Verify slug uniqueness
catReject(fn () => $service->create($admins['super_admin'], [
    'name' => '重复别名',
    'slug' => 'china-mainland',
]), 409, 'duplicate slug rejected with 409');

// Verify slug format
catReject(fn () => $service->create($admins['super_admin'], [
    'name' => '非法字符',
    'slug' => 'bad slug with spaces!',
]), 422, 'invalid slug format rejected with 422');

// 3.2 Create Level 2 Categories under Level 1
$catA1 = $service->create($admins['super_admin'], [
    'parent_id' => $groupA['id'],
    'name' => '宁波高防',
    'slug' => 'ningbo-bgp',
    'type' => 'cloud',
    'order_num' => 50,
    'status' => 1,
]);
catCheck($catA1['parent_id'] === $groupA['id'], 'Level 2 category created under Level 1 group');

$catA2 = $service->create($admins['super_admin'], [
    'parent_id' => $groupA['id'],
    'name' => '隐藏分类区',
    'slug' => 'hidden-zone',
    'type' => 'cloud',
    'order_num' => 10,
    'status' => 0, // Hidden
]);
catCheck($catA2['status'] === 0, 'hidden Level 2 category created with status = 0');

// 3.3 Reject Level 3 Category (Parent cannot be a Level 2 category!)
catReject(fn () => $service->create($admins['super_admin'], [
    'parent_id' => $catA1['id'], // attempting to set Level 2 as parent
    'name' => '三级分类',
    'slug' => 'level-3-attempt',
]), 422, 'Level 3 category creation rejected (parent must be level 1)');

// 3.4 Reject Non-existent parent
catReject(fn () => $service->create($admins['super_admin'], [
    'parent_id' => 999999,
    'name' => '不存在父级',
    'slug' => 'nonexistent-parent',
]), 404, 'Non-existent parent rejected with 404');

// 3.5 Reject self-parenting on update
catReject(fn () => $service->update($admins['super_admin'], $groupA['id'], [
    'parent_id' => $groupA['id'],
    'expected_version' => $groupA['version'],
]), 422, 'Self-parenting rejected with 422');

// 3.6 Reject turning a group with existing children into a child of another group
$groupB = $service->create($admins['super_admin'], [
    'name' => '中国香港',
    'slug' => 'china-hongkong',
    'type' => 'cloud',
    'order_num' => 90,
    'status' => 1,
]);
catReject(fn () => $service->update($admins['super_admin'], $groupA['id'], [
    'parent_id' => $groupB['id'],
    'expected_version' => $groupA['version'],
]), 422, 'Group with sub-categories cannot become a child of another group');

// 3.7 CAS Optimistic Locking on update
catReject(fn () => $service->update($admins['super_admin'], $groupB['id'], [
    'name' => '过期的香港',
    'expected_version' => str_repeat('0', 64),
]), 409, 'CAS expected_version mismatch triggers 409');

// Successful update
$updatedGroupB = $service->update($admins['super_admin'], $groupB['id'], [
    'name' => '中国香港精品',
    'expected_version' => $groupB['version'],
]);
catCheck($updatedGroupB['name'] === '中国香港精品', 'CAS update succeeded with correct version');

// 3.8 Deletion protection: Group with children cannot be deleted
catReject(fn () => $service->delete($admins['super_admin'], $groupA['id'], ['expected_version' => $service->detail($groupA['id'])['version']]), 409, 'Group with children cannot be deleted');

// 4. Product Import Integration with Category
$prodService = new AdminProductService;
file_get_contents(getenv('UPSTREAM_TEST_URL').'/__config_mode?mode=normal');

// 4.1 Preview with Level 1 group -> REJECTED (must be Level 2 leaf!)
$inputBad = [
    'provider_id' => $providerId,
    'remote_pid' => 14,
    'name' => '挂到一级分组商品',
    'type' => 'cloud',
    'category_id' => $groupA['id'],
    'billing_cycles' => ['monthly'],
    'markup_type' => 'none',
    'markup_value' => '0.00',
];
catReject(fn () => $prodService->preview($admins['super_admin'], $inputBad), 422, 'Import preview with Level 1 group rejected (must be Level 2)');

// 4.2 Preview with valid Level 2 category
$inputGood = [
    'provider_id' => $providerId,
    'remote_pid' => 14,
    'name' => '宁波电信 ABM1',
    'type' => 'cloud',
    'category_id' => $catA1['id'],
    'billing_cycles' => ['monthly'],
    'markup_type' => 'none',
    'markup_value' => '0.00',
];
$preview = $prodService->preview($admins['super_admin'], $inputGood);
catCheck($preview['category_id'] === $catA1['id'], 'Preview returned category_id');
catCheck($preview['category_path'] === '中国大陆 / 宁波高防', 'Preview returned category_path summary');

// 4.3 Tampering category_id during import -> HMAC Signature check fails!
$catA3 = $service->create($admins['super_admin'], [
    'parent_id' => $groupA['id'],
    'name' => '上海精品',
    'slug' => 'shanghai-bgp',
    'type' => 'cloud',
    'order_num' => 40,
    'status' => 1,
]);
$tamperedBody = array_merge($inputGood, [
    'category_id' => $catA3['id'], // tampered to another valid category
    'preview_token' => $preview['preview_token'],
    'evidence' => '篡改有效分类导入测试',
]);
[$status, $resp] = catHttp('POST', '/admin/products/import', $tamperedBody, $superToken);
catCheck($status === 409 && $resp['code'] === 40912, 'Tampered category_id rejected by HMAC token signature');

// Tampering with disabled category is rejected at precheck
$tamperedDisabled = array_merge($inputGood, [
    'category_id' => $catA2['id'], // disabled category
    'preview_token' => $preview['preview_token'],
    'evidence' => '篡改禁用分类导入测试',
]);
[$status, $resp] = catHttp('POST', '/admin/products/import', $tamperedDisabled, $superToken);
catCheck($status === 422, 'Tampering with disabled category rejected at precheck with 422');

// 4.4 Category disabled after preview -> Transaction locks and aborts with 409
$service->update($admins['super_admin'], $catA1['id'], [
    'status' => 0,
    'expected_version' => $catA1['version'],
]);
[$status, $resp] = catHttp('POST', '/admin/products/import', $inputGood + [
    'preview_token' => $preview['preview_token'],
    'evidence' => '分类禁用后提交测试',
], $superToken);
catCheck(in_array($status, [409, 422], true), 'Category disabled after preview is rejected');

// Re-enable category
$catA1 = $service->read($catA1['id']);
$catA1 = $service->update($admins['super_admin'], $catA1['id'], [
    'status' => 1,
    'expected_version' => $service->version($catA1),
]);

// 4.5 Fresh preview and successful import
$preview = $prodService->preview($admins['super_admin'], $inputGood);
$importBody = $inputGood + [
    'preview_token' => $preview['preview_token'],
    'evidence' => '首次正常归类导入测试',
];
[$status, $resp] = catHttp('POST', '/admin/products/import', $importBody, $superToken);
catCheck($status === 200 && $resp['data']['outcome'] === 'created', 'Product imported with outcome created');
$productId = (int) $resp['data']['product_id'];

// Check product in database: category_id stored, initial status is hidden
$prodRow = $db->query('SELECT category_id, status FROM products WHERE id='.$productId)->fetch();
catCheck((int) $prodRow['category_id'] === $catA1['id'] && $prodRow['status'] === 'hidden', 'Product stored with category_id and defaults to hidden');

// Check audit log: category_id recorded in context
$auditContext = json_decode((string) $db->query("SELECT context FROM admin_audit_logs WHERE target_type='product' AND target_id=".$productId." AND action='product_import'")->fetchColumn(), true);
catCheck(isset($auditContext['category_id']) && (int) $auditContext['category_id'] === $catA1['id'], 'Import audit log contains category_id in context');

// 4.6 Replay with same preview token -> returns outcome = 'replayed' without creating duplicate
[$status, $resp] = catHttp('POST', '/admin/products/import', $importBody, $superToken);
catCheck($status === 200 && $resp['data']['outcome'] === 'replayed' && (int) $resp['data']['product_id'] === $productId, 'Replay with same token returns replayed outcome');

// 4.7 Duplicate import with different token -> 409 conflict
$diffInput = $inputGood;
$diffInput['name'] = '不同名称尝试二次导入';
$diffPreview = $prodService->preview($admins['super_admin'], $diffInput);
[$status, $resp] = catHttp('POST', '/admin/products/import', $diffInput + [
    'preview_token' => $diffPreview['preview_token'],
    'evidence' => '重复导入测试',
], $superToken);
catCheck($status === 409 && $resp['code'] === 40913, 'Duplicate import with different token rejected with 40913');

// 4.8 Category with associated products CANNOT be deleted
catReject(fn () => $service->delete($admins['super_admin'], $catA1['id'], ['expected_version' => $service->detail($catA1['id'])['version']]), 409, 'Category with associated products cannot be deleted');

// 5. Routine Product Edit (Updating category_id without evidence)
$productDetail = $prodService->detail($productId);
// Moving to disabled category rejected
catReject(fn () => $prodService->update($admins['super_admin'], $productId, [
    'name' => '宁波高防 ABM1 升级版',
    'status' => 'active',
    'markup_type' => 'none',
    'markup_value' => '0.00',
    'category_id' => $catA2['id'], // disabled category
    'expected_version' => $productDetail['version'],
]), 422, 'Moving product to disabled category rejected');

// Moving to active category succeeds
$editResult = $prodService->update($admins['super_admin'], $productId, [
    'name' => '宁波高防 ABM1 升级版',
    'status' => 'active',
    'markup_type' => 'none',
    'markup_value' => '0.00',
    'category_id' => $catA3['id'], // move to active catA3
    'expected_version' => $productDetail['version'],
    // evidence is OPTIONAL for routine edits
]);
catCheck($editResult['product_id'] === $productId, 'Routine edit of product category_id succeeded without evidence');
$updatedCatId = (int) $db->query('SELECT category_id FROM products WHERE id='.$productId)->fetchColumn();
catCheck($updatedCatId === $catA3['id'], 'Product category_id updated in DB');

// Move back to catA1
$productDetail = $prodService->detail($productId);
$prodService->update($admins['super_admin'], $productId, [
    'name' => '宁波高防 ABM1 在售',
    'status' => 'active',
    'markup_type' => 'none',
    'markup_value' => '0.00',
    'category_id' => $catA1['id'],
    'expected_version' => $productDetail['version'],
]);

// 6. Snapshot refresh preserves category_id (including NULL)
// 6.1 Create an uncategorized product (category_id = null)
$uncatInput = [
    'provider_id' => $providerId,
    'remote_pid' => 391,
    'name' => '未分类商品CDN',
    'type' => 'cdn',
    'category_id' => null,
    'billing_cycles' => ['monthly'],
    'markup_type' => 'none',
    'markup_value' => '0.00',
];
$uncatPreview = $prodService->preview($admins['super_admin'], $uncatInput);
$uncatResp = $prodService->snapshot($admins['super_admin'], $uncatInput + [
    'preview_token' => $uncatPreview['preview_token'],
    'evidence' => '导入未分类商品测试',
]);
$uncatId = $uncatResp['product_id'];
$db->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$uncatId]);
catCheck($db->query('SELECT category_id FROM products WHERE id='.$uncatId)->fetchColumn() === null, 'Uncategorized product has NULL category_id');

// 6.2 Refresh the categorized product -> preserves catA1
$prodDetail = $prodService->detail($productId);
$refreshPreview = $prodService->preview($admins['super_admin'], ['expected_version' => $prodDetail['version']], $productId);
$prodService->snapshot($admins['super_admin'], [
    'expected_version' => $prodDetail['version'],
    'preview_token' => $refreshPreview['preview_token'],
    'evidence' => '快照刷新保留原分类',
], $productId);
catCheck((int) $db->query('SELECT category_id FROM products WHERE id='.$productId)->fetchColumn() === $catA1['id'], 'Snapshot refresh preserved assigned category_id');

// 6.3 Refresh the uncategorized product -> preserves NULL
$uncatDetail = $prodService->detail($uncatId);
$refreshUncat = $prodService->preview($admins['super_admin'], ['expected_version' => $uncatDetail['version']], $uncatId);
$prodService->snapshot($admins['super_admin'], [
    'expected_version' => $uncatDetail['version'],
    'preview_token' => $refreshUncat['preview_token'],
    'evidence' => '快照刷新保留NULL分类',
], $uncatId);
catCheck($db->query('SELECT category_id FROM products WHERE id='.$uncatId)->fetchColumn() === null, 'Snapshot refresh preserved NULL category_id');

// 7. Public Catalog API & Navigation Filtering
// 7.1 GET /api/v1/categories
[$status, $resp] = catHttp('GET', '/categories');
catCheck($status === 200 && $resp['code'] === 0, 'Public GET /categories returns 200 code 0');
$publicTree = $resp['data'];
catCheck(count($publicTree) >= 2, 'Public category tree contains groups');

$mainlandGroup = null;
foreach ($publicTree as $g) {
    if ($g['slug'] === 'china-mainland') {
        $mainlandGroup = $g;
    }
}
catCheck($mainlandGroup !== null, 'Public tree has china-mainland group');
// catA1 is status 1, catA2 is status 0 (hidden)
$childrenSlugs = array_column($mainlandGroup['children'], 'slug');
catCheck(in_array('ningbo-bgp', $childrenSlugs, true), 'Active subcategory is in public tree');
catCheck(! in_array('hidden-zone', $childrenSlugs, true), 'Hidden subcategory (status=0) is excluded from public tree');

// 7.2 GET /api/v1/products includes category_id and retains full gates
[$status, $resp] = catHttp('GET', '/products');
catCheck($status === 200 && $resp['code'] === 0, 'Public GET /products returns 200 code 0');
$prods = array_column($resp['data'], null, 'id');

catCheck(isset($prods[$productId]), 'Active categorized product appears in public products');
catCheck($prods[$productId]['category_id'] === $catA1['id'], 'Product item includes correct category_id');

catCheck(isset($prods[$uncatId]), 'Uncategorized product appears in public products');
catCheck($prods[$uncatId]['category_id'] === null, 'Uncategorized product has category_id: null');

// If a product's category is subsequently hidden, it still appears in all products (CatalogService:16)
$catA4 = $service->create($admins['super_admin'], [
    'parent_id' => $groupA['id'],
    'name' => '待隐藏机房',
    'slug' => 'to-hide-room',
    'type' => 'cloud',
    'order_num' => 50,
    'status' => 1,
]);
$hiddenCatProdInput = [
    'provider_id' => $providerId,
    'remote_pid' => 901,
    'name' => '隐藏分类下在售独立服务器',
    'type' => 'dedicated',
    'category_id' => $catA4['id'],
    'billing_cycles' => ['monthly'],
    'markup_type' => 'none',
    'markup_value' => '0.00',
];
$hPreview = $prodService->preview($admins['super_admin'], $hiddenCatProdInput);
$hResp = $prodService->snapshot($admins['super_admin'], $hiddenCatProdInput + [
    'preview_token' => $hPreview['preview_token'],
    'evidence' => '隐藏分类商品上架测试',
]);
$hProdId = $hResp['product_id'];
$db->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$hProdId]);

// Now hide category catA4
$catA4 = $service->read($catA4['id']);
$service->update($admins['super_admin'], $catA4['id'], [
    'status' => 0,
    'expected_version' => $service->version($catA4),
]);

[$status, $resp] = catHttp('GET', '/products');
$prods = array_column($resp['data'], null, 'id');
catCheck(isset($prods[$hProdId]), 'Product belonging to hidden category still appears in all products (CatalogService:16)');
catCheck($prods[$hProdId]['category_id'] === $catA4['id'], 'Hidden category product has correct category_id');

// 8. Targeted regressions: lifecycle roles, maintenance, path confirmations and stale deletes.
catReject(fn () => $service->update($admins['super_admin'], $catA1['id'], [
    'parent_id' => null, 'expected_version' => $service->detail($catA1['id'])['version'],
]), 422, 'Bound second-level category cannot become a root');
catCheck($service->detail($catA1['id'])['parent_id'] === $groupA['id'], 'Rejected promotion leaves category binding intact');

$maint = $prodService->detail($hProdId);
$prodService->update($admins['super_admin'], $hProdId, ['name' => 'Hidden category maintenance', 'status' => $maint['status'], 'markup_type' => 'none', 'markup_value' => '0.00', 'expected_version' => $maint['version']]);
catCheck($prodService->detail($hProdId)['name'] === 'Hidden category maintenance', 'Hidden child permits name maintenance without changing assignment');
$maint = $prodService->detail($hProdId);
$prodService->update($admins['super_admin'], $hProdId, ['name' => $maint['name'], 'status' => 'hidden', 'markup_type' => 'none', 'markup_value' => '0.00', 'category_id' => $catA4['id'], 'expected_version' => $maint['version']]);
catCheck($prodService->detail($hProdId)['status'] === 'hidden', 'Hidden child permits taking original product off sale');
$hiddenGroup = $service->update($admins['super_admin'], $groupA['id'], ['status' => 0, 'expected_version' => $service->detail($groupA['id'])['version']]);
$maint = $prodService->detail($hProdId);
$refreshInput = ['expected_version' => $maint['version'], 'billing_cycles' => ['monthly']];
$hp = $prodService->preview($admins['super_admin'], $refreshInput, $hProdId);
$prodService->snapshot($admins['super_admin'], $refreshInput + ['preview_token' => $hp['preview_token'], 'evidence' => 'Maintain original hidden child and group'], $hProdId);
catCheck($prodService->detail($hProdId)['category_id'] === $catA4['id'], 'Manual snapshot refresh preserves hidden child under hidden parent');
$scheduleInput = ['product_id' => $hProdId] + $hiddenCatProdInput;
unset($scheduleInput['category_id']);
$scheduled = (new App\Services\ProductSnapshotService())->prepare($scheduleInput);
catCheck($scheduled['category_id'] === $catA4['id'], 'Scheduled preparation retains hidden category even with no category input');
catReject(fn () => $prodService->preview($admins['super_admin'], $inputGood), 422, 'New import into hidden parent is still refused');
$maint = $prodService->detail($uncatId);
catReject(fn () => $prodService->update($admins['super_admin'], $uncatId, ['name' => $maint['name'], 'status' => $maint['status'], 'markup_type' => 'none', 'markup_value' => '0.00', 'category_id' => $catA4['id'], 'expected_version' => $maint['version']]), 422, 'Existing unrelated product cannot move into hidden category');
$service->update($admins['super_admin'], $groupA['id'], ['status' => 1, 'expected_version' => $hiddenGroup['version']]);

$move = $service->create($admins['super_admin'], ['name' => 'Movable leaf', 'slug' => 'move-confirmation', 'parent_id' => $groupA['id']]);
$moveInput = array_replace($inputGood, ['remote_pid' => 902, 'category_id' => $move['id']]);
$mp = $prodService->preview($admins['super_admin'], $moveInput);
$prepared = (new App\Services\ProductSnapshotService())->prepare($moveInput);
$service->update($admins['super_admin'], $move['id'], ['parent_id' => $groupB['id'], 'expected_version' => $move['version']]);
catReject(fn () => $prodService->snapshot($admins['super_admin'], $moveInput + ['preview_token' => $mp['preview_token'], 'evidence' => 'Reject stale category path confirmation']), 409, 'Moving category invalidates old import confirmation');
catReject(fn () => Database::transaction(fn (PDO $tx) => (new App\Services\ProductSnapshotService())->persist($tx, $prepared, 'hidden')), 409, 'Path moved after preparation is rejected inside persistence transaction');
catCheck((int) $db->query('SELECT COUNT(*) FROM products WHERE provider_id='.$providerId.' AND remote_pid=902')->fetchColumn() === 0, 'Stale path failures create neither product nor audit');
$mp2 = $prodService->preview($admins['super_admin'], $moveInput);
catCheck($mp2['category_path'] === $updatedGroupB['name'].' / Movable leaf', 'New confirmation displays authoritative moved path');
$service->update($admins['super_admin'], $move['id'], ['parent_id' => $groupA['id'], 'expected_version' => $service->detail($move['id'])['version']]);
catReject(fn () => $prodService->snapshot($admins['super_admin'], $moveInput + ['preview_token' => $mp['preview_token'], 'evidence' => 'Reject path move away and back']), 409, 'Category audit version prevents move-away-and-back confirmation reuse');

$del = $service->create($admins['super_admin'], ['name' => 'Delete version', 'slug' => 'delete-version', 'headline' => 'old headline', 'description' => 'old description', 'seo_title' => 'old title', 'seo_keywords' => 'old keywords', 'seo_description' => 'old seo']);
$changed = $service->update($admins['super_admin'], $del['id'], ['name' => 'Updated before delete', 'headline' => '', 'description' => '', 'seo_title' => '', 'seo_keywords' => '', 'seo_description' => '', 'expected_version' => $del['version']]);
catCheck($changed['headline'] === '' && $changed['description'] === '' && $changed['seo_title'] === '' && $changed['seo_keywords'] === '' && $changed['seo_description'] === '', 'Empty strings clear all optional category content');
[$status] = catHttp('DELETE', '/admin/categories/'.$del['id'], ['expected_version' => $del['version']], $superToken);
catCheck($status === 409, 'HTTP stale delete rejects renamed category version');
[$status] = catHttp('DELETE', '/admin/categories/'.$del['id'], [], $superToken);
catCheck($status === 400, 'HTTP delete requires expected_version');
[$status] = catHttp('DELETE', '/admin/categories/'.$del['id'], ['expected_version' => $changed['version']], $supportToken);
catCheck($status === 403, 'Non-super cannot delete with valid version');
[$status] = catHttp('DELETE', '/admin/categories/'.$del['id'], ['expected_version' => $changed['version']], $superToken);
catCheck($status === 200, 'HTTP current version delete succeeds');
$moveOld = $service->detail($move['id']);
$service->update($admins['super_admin'], $move['id'], ['parent_id' => $groupB['id'], 'expected_version' => $moveOld['version']]);
catReject(fn () => $service->delete($admins['super_admin'], $move['id'], ['expected_version' => $moveOld['version']]), 409, 'Stale delete rejects moved category');

// Force an actual parent-row lock race: no timing-only concurrency assertion.
$race = $service->create($admins['super_admin'], ['name' => 'Parent role race', 'slug' => 'parent-role-race']);
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $db->query('SELECT id FROM product_categories WHERE id='.$race['id'].' FOR UPDATE')->fetch();
    $process = proc_open([PHP_BINARY, __FILE__, '--child-create'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
    fwrite($pipes[0], json_encode(['admin' => $admins['super_admin'], 'input' => ['name' => 'Racing child', 'slug' => 'racing-child', 'parent_id' => $race['id']]], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 15);
    $ready = trim(fgets($pipes[1]) ?: '');
    if (! preg_match('/^READY:([0-9]+)$/D', $ready, $m)) {
        throw new RuntimeException('Child not ready');
    }
    $waiting = false;
    $deadline = microtime(true) + 8;
    do {
        $s = $db->prepare('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?');
        $s->execute([(int) $m[1]]);
        if ($s->fetchColumn() === 'LOCK WAIT') {
            $waiting = true;
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    catCheck($waiting, 'Child creation waits on locked parent row');
    $service->update($admins['super_admin'], $race['id'], ['parent_id' => $groupB['id'], 'expected_version' => $race['version']]);
    Illuminate\Support\Facades\DB::commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        Illuminate\Support\Facades\DB::rollBack();
    }
    throw $e;
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
catCheck(proc_close($process) === 0 && json_decode(trim($out), true)['status'] === 422, 'Child creation rechecks changed parent role after lock release');
catCheck((int) $db->query("SELECT COUNT(*) FROM product_categories WHERE slug='racing-child'")->fetchColumn() === 0, 'Concurrent parent demotion cannot create a third-level node');

echo 'Category hierarchy checks: '.$checks.PHP_EOL;
