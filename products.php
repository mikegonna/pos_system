<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
require __DIR__ . '/assets/config/config.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}
if ($user['role'] !== 'admin') {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$error = '';
$notice = isset($_GET['saved']) ? 'บันทึกข้อมูลแล้ว' : '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = 'คำขอหมดอายุ กรุณาลองใหม่';
    } elseif ($action === 'save_category') {
        $categoryId = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
        $categoryName = trim((string) ($_POST['category_name'] ?? ''));
        $categoryStatus = isset($_POST['category_status']) ? 1 : 0;

        if (preg_match('/^.{1,100}$/us', $categoryName) !== 1) {
            $error = 'ชื่อหมวดหมู่ต้องมี 1-100 ตัวอักษร';
        } elseif ($categoryId === false) {
            $error = 'รหัสหมวดหมู่ไม่ถูกต้อง';
        } else {
            if ($categoryId > 0) {
                $statement = $pdo->prepare('UPDATE categories SET name = :name, status = :status WHERE id = :id');
                $statement->execute(['name' => $categoryName, 'status' => $categoryStatus, 'id' => $categoryId]);
            } else {
                $statement = $pdo->prepare('INSERT INTO categories (name, status) VALUES (:name, :status)');
                $statement->execute(['name' => $categoryName, 'status' => $categoryStatus]);
            }
            header('Location: products.php?saved=1');
            exit;
        }
    } elseif ($action === 'save_product') {
        $productId = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        $barcode = trim((string) ($_POST['barcode'] ?? ''));
        $categoryValue = trim((string) ($_POST['category_id'] ?? ''));
        $categoryId = $categoryValue === '' ? null : filter_var($categoryValue, FILTER_VALIDATE_INT);
        $costPrice = trim((string) ($_POST['cost_price'] ?? ''));
        $sellingPrice = trim((string) ($_POST['selling_price'] ?? ''));
        $minimumStock = trim((string) ($_POST['min_stock'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $status = isset($_POST['status']) ? 1 : 0;
        $initialStock = trim((string) ($_POST['initial_stock'] ?? '0'));
        $validAmount = static fn (string $value): bool => is_numeric($value) && (float) $value >= 0 && (float) $value <= 99999999.99;

        if ($productId === false || ($categoryId !== null && $categoryId === false)) {
            $error = 'รหัสสินค้า หรือหมวดหมู่ไม่ถูกต้อง';
        } elseif (preg_match('/^.{1,200}$/us', $name) !== 1) {
            $error = 'ชื่อสินค้าต้องมี 1-200 ตัวอักษร';
        } elseif (strlen($barcode) > 50) {
            $error = 'บาร์โค้ดต้องไม่เกิน 50 ตัวอักษร';
        } elseif (! $validAmount($costPrice) || ! $validAmount($sellingPrice) || ! $validAmount($minimumStock)) {
            $error = 'ราคาและ stock ขั้นต่ำต้องเป็นตัวเลขตั้งแต่ 0 ถึง 99,999,999.99';
        } elseif (preg_match('/^.{1,30}$/us', $unit) !== 1) {
            $error = 'หน่วยสินค้าต้องมี 1-30 ตัวอักษร';
        } elseif ($productId === 0 && ! $validAmount($initialStock)) {
            $error = 'stock ตั้งต้นไม่ถูกต้อง';
        } else {
            if ($categoryId !== null) {
                $statement = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
                $statement->execute(['id' => $categoryId]);
                if ((int) $statement->fetchColumn() === 0) {
                    $error = 'ไม่พบหมวดหมู่ที่เลือก';
                }
            }

            if ($error === '') {
                try {
                    if ($productId > 0) {
                        $statement = $pdo->prepare(
                            'UPDATE products SET category_id = :category_id, barcode = :barcode, name = :name, cost_price = :cost_price, selling_price = :selling_price, min_stock = :min_stock, unit = :unit, status = :status WHERE id = :id'
                        );
                        $statement->execute([
                            'category_id' => $categoryId,
                            'barcode' => $barcode === '' ? null : $barcode,
                            'name' => $name,
                            'cost_price' => $costPrice,
                            'selling_price' => $sellingPrice,
                            'min_stock' => $minimumStock,
                            'unit' => $unit,
                            'status' => $status,
                            'id' => $productId,
                        ]);
                    } else {
                        $pdo->beginTransaction();
                        $statement = $pdo->prepare(
                            'INSERT INTO products (category_id, barcode, name, cost_price, selling_price, stock, min_stock, unit, status) VALUES (:category_id, :barcode, :name, :cost_price, :selling_price, :stock, :min_stock, :unit, :status)'
                        );
                        $statement->execute([
                            'category_id' => $categoryId,
                            'barcode' => $barcode === '' ? null : $barcode,
                            'name' => $name,
                            'cost_price' => $costPrice,
                            'selling_price' => $sellingPrice,
                            'stock' => $initialStock,
                            'min_stock' => $minimumStock,
                            'unit' => $unit,
                            'status' => $status,
                        ]);
                        $newProductId = (int) $pdo->lastInsertId();

                        if ((float) $initialStock > 0) {
                            $movement = $pdo->prepare(
                                'INSERT INTO stock_movements (product_id, type, quantity, reference_type, note, user_id) VALUES (:product_id, :type, :quantity, :reference_type, :note, :user_id)'
                            );
                            $movement->execute([
                                'product_id' => $newProductId,
                                'type' => 'in',
                                'quantity' => $initialStock,
                                'reference_type' => 'opening_balance',
                                'note' => 'ยอด stock ตั้งต้น',
                                'user_id' => $user['id'],
                            ]);
                        }
                        $pdo->commit();
                    }
                    header('Location: products.php?saved=1');
                    exit;
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $exception->getCode() === '23000'
                        ? 'บาร์โค้ดนี้ถูกใช้แล้ว หรือข้อมูลอ้างอิงไม่ถูกต้อง'
                        : 'บันทึกไม่สำเร็จ กรุณาตรวจสอบข้อมูลแล้วลองใหม่';
                }
            }
        }
    }
}

$editProductId = filter_var($_GET['edit_product'] ?? '', FILTER_VALIDATE_INT);
$editProduct = null;
if ($editProductId && $editProductId > 0) {
    $statement = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $statement->execute(['id' => $editProductId]);
    $editProduct = $statement->fetch() ?: null;
}

$editCategoryId = filter_var($_GET['edit_category'] ?? '', FILTER_VALIDATE_INT);
$editCategory = null;
if ($editCategoryId && $editCategoryId > 0) {
    $statement = $pdo->prepare('SELECT * FROM categories WHERE id = :id');
    $statement->execute(['id' => $editCategoryId]);
    $editCategory = $statement->fetch() ?: null;
}

$categories = $pdo->query('SELECT id, name, status FROM categories ORDER BY name')->fetchAll();
$search = trim((string) ($_GET['q'] ?? ''));
$statement = $pdo->prepare(
    'SELECT p.id, p.barcode, p.name, p.selling_price, p.stock, p.unit, p.status, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE (:search = \'\' OR p.name LIKE :name_search OR p.barcode LIKE :barcode_search) ORDER BY p.id DESC LIMIT 100'
);
$statement->execute([
    'search' => $search,
    'name_search' => '%' . $search . '%',
    'barcode_search' => '%' . $search . '%',
]);
$products = $statement->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>สินค้าและหมวดหมู่ | POS</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="admin-page">
    <main class="admin-shell">
        <header class="admin-header">
            <a class="admin-brand" href="index.php"><span class="brand-mark">P</span><span>POS / จัดการสินค้า</span></a>
            <div class="admin-user"><?= $escape($user['full_name']) ?> <a href="index.php">หน้าหลัก</a></div>
        </header>

        <div class="page-heading">
            <div><p class="eyebrow">คลังสินค้า</p><h1>สินค้าและหมวดหมู่</h1></div>
            <span class="count-label"><?= count($products) ?> รายการ</span>
        </div>

        <?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>

        <div class="management-grid">
            <section class="management-section">
                <h2><?= $editProduct ? 'แก้ไขสินค้า' : 'เพิ่มสินค้า' ?></h2>
                <form method="post" class="data-form">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="product_id" value="<?= $escape((string) ($editProduct['id'] ?? '0')) ?>">
                    <label for="name">ชื่อสินค้า</label>
                    <input id="name" name="name" maxlength="200" value="<?= $escape((string) ($editProduct['name'] ?? '')) ?>" required>
                    <label for="barcode">บาร์โค้ด</label>
                    <input id="barcode" name="barcode" maxlength="50" value="<?= $escape((string) ($editProduct['barcode'] ?? '')) ?>">
                    <label for="category_id">หมวดหมู่</label>
                    <select id="category_id" name="category_id">
                        <option value="">ไม่ระบุ</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>" <?= (string) ($editProduct['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>><?= $escape($category['name']) ?><?= (int) $category['status'] === 1 ? '' : ' (ปิดใช้งาน)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-pair">
                        <div><label for="cost_price">ทุน</label><input id="cost_price" name="cost_price" type="number" min="0" step="0.01" value="<?= $escape((string) ($editProduct['cost_price'] ?? '0.00')) ?>" required></div>
                        <div><label for="selling_price">ราคาขาย</label><input id="selling_price" name="selling_price" type="number" min="0" step="0.01" value="<?= $escape((string) ($editProduct['selling_price'] ?? '0.00')) ?>" required></div>
                    </div>
                    <div class="field-pair">
                        <div><label for="min_stock">แจ้งเตือนเมื่อเหลือ</label><input id="min_stock" name="min_stock" type="number" min="0" step="0.01" value="<?= $escape((string) ($editProduct['min_stock'] ?? '0.00')) ?>" required></div>
                        <div><label for="unit">หน่วย</label><input id="unit" name="unit" maxlength="30" value="<?= $escape((string) ($editProduct['unit'] ?? 'ชิ้น')) ?>" required></div>
                    </div>
                    <?php if ($editProduct): ?>
                        <p class="stock-readonly">คงเหลือ <?= $escape((string) $editProduct['stock']) ?> <?= $escape($editProduct['unit']) ?> · ปรับ stock ผ่านบันทึก movement</p>
                    <?php else: ?>
                        <label for="initial_stock">stock ตั้งต้น</label>
                        <input id="initial_stock" name="initial_stock" type="number" min="0" step="0.01" value="0" required>
                    <?php endif; ?>
                    <label class="check-row"><input name="status" type="checkbox" value="1" <?= !isset($editProduct['status']) || (int) $editProduct['status'] === 1 ? 'checked' : '' ?>> เปิดขาย</label>
                    <button class="button" type="submit">บันทึกสินค้า</button>
                    <?php if ($editProduct): ?><a class="cancel-link" href="products.php">ยกเลิกแก้ไข</a><?php endif; ?>
                </form>
            </section>

            <section class="management-section category-section">
                <h2><?= $editCategory ? 'แก้ไขหมวดหมู่' : 'เพิ่มหมวดหมู่' ?></h2>
                <form method="post" class="data-form">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="category_id" value="<?= $escape((string) ($editCategory['id'] ?? '0')) ?>">
                    <label for="category_name">ชื่อหมวดหมู่</label>
                    <input id="category_name" name="category_name" maxlength="100" value="<?= $escape((string) ($editCategory['name'] ?? '')) ?>" required>
                    <label class="check-row"><input name="category_status" type="checkbox" value="1" <?= !isset($editCategory['status']) || (int) $editCategory['status'] === 1 ? 'checked' : '' ?>> เปิดใช้งาน</label>
                    <button class="button" type="submit">บันทึกหมวดหมู่</button>
                    <?php if ($editCategory): ?><a class="cancel-link" href="products.php">ยกเลิกแก้ไข</a><?php endif; ?>
                </form>
                <div class="category-list">
                    <?php foreach ($categories as $category): ?>
                        <a class="category-row" href="?edit_category=<?= (int) $category['id'] ?>">
                            <span><?= $escape($category['name']) ?></span>
                            <span class="status <?= (int) $category['status'] === 1 ? 'is-active' : '' ?>"><?= (int) $category['status'] === 1 ? 'เปิด' : 'ปิด' ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$categories): ?><p class="empty-state">ยังไม่มีหมวดหมู่</p><?php endif; ?>
                </div>
            </section>
        </div>

        <section class="inventory-section">
            <div class="inventory-heading"><h2>รายการสินค้า</h2>
                <form method="get" class="search-form"><input name="q" type="search" value="<?= $escape($search) ?>" placeholder="ค้นหาชื่อหรือบาร์โค้ด"><button class="button button-secondary" type="submit">ค้นหา</button></form>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>สินค้า</th><th>หมวดหมู่</th><th>ราคาขาย</th><th>คงเหลือ</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><strong><?= $escape($product['name']) ?></strong><small><?= $escape((string) ($product['barcode'] ?? '')) ?></small></td>
                            <td><?= $escape((string) ($product['category_name'] ?? 'ไม่ระบุ')) ?></td>
                            <td><?= number_format((float) $product['selling_price'], 2) ?></td>
                            <td><?= $escape((string) $product['stock']) ?> <?= $escape($product['unit']) ?></td>
                            <td><span class="status <?= (int) $product['status'] === 1 ? 'is-active' : '' ?>"><?= (int) $product['status'] === 1 ? 'เปิดขาย' : 'ปิดขาย' ?></span></td>
                            <td><a class="table-link" href="?edit_product=<?= (int) $product['id'] ?>">แก้ไข</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$products): ?><tr><td colspan="6" class="empty-state">ไม่พบสินค้า</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
