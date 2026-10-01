<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
require __DIR__ . '/assets/config/config.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$moneyToCents = static function (string $value): ?int {
    if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value) !== 1) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
};
$formatMoney = static fn (int $cents): string => intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
$normalizeQuantity = static function (string $value): ?string {
    if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value) !== 1 || (float) $value <= 0) {
        return null;
    }
    return number_format((float) $value, 2, '.', '');
};

$error = '';
$notice = '';
$completedSale = $_SESSION['completed_sale'] ?? null;
unset($_SESSION['completed_sale']);
if (is_array($completedSale)) {
    $notice = 'บันทึกการขาย ' . $completedSale['invoice_no'] . ' สำเร็จ ยอดสุทธิ ' . $completedSale['total'] . ' บาท';
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = 'คำขอหมดอายุ กรุณาลองใหม่';
    } elseif ($action === 'add_item' || $action === 'update_quantity') {
        $productId = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
        $quantity = $normalizeQuantity((string) ($_POST['quantity'] ?? '1'));
        if (!$productId || $productId < 1 || $quantity === null) {
            $error = 'สินค้า หรือจำนวนไม่ถูกต้อง';
        } else {
            $statement = $pdo->prepare('SELECT id, stock, status FROM products WHERE id = :id');
            $statement->execute(['id' => $productId]);
            $product = $statement->fetch();
            $newQuantity = (float) $quantity;
            if (!$product || (int) $product['status'] !== 1) {
                $error = 'ไม่พบสินค้าที่เปิดขาย';
            } elseif ($action === 'add_item') {
                $newQuantity += (float) ($_SESSION['cart'][$productId] ?? 0);
                if ($newQuantity > (float) $product['stock']) {
                    $error = 'จำนวนสินค้าเกิน stock ที่มี';
                } else {
                    $_SESSION['cart'][$productId] = number_format($newQuantity, 2, '.', '');
                }
            } elseif ($newQuantity > (float) $product['stock']) {
                $error = 'จำนวนสินค้าเกิน stock ที่มี';
            } else {
                $_SESSION['cart'][$productId] = $quantity;
            }
        }
        if ($error === '') {
            header('Location: sales.php');
            exit;
        }
    } elseif ($action === 'remove_item') {
        $productId = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
        if ($productId) {
            unset($_SESSION['cart'][$productId]);
        }
        header('Location: sales.php');
        exit;
    } elseif ($action === 'clear_cart') {
        $_SESSION['cart'] = [];
        header('Location: sales.php');
        exit;
    } elseif ($action === 'checkout') {
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
        $allowedMethods = ['cash', 'transfer', 'qr', 'card'];
        $discountInput = trim((string) ($_POST['discount'] ?? '0'));
        $receivedInput = trim((string) ($_POST['received_amount'] ?? ''));
        $discountCents = $moneyToCents($discountInput);
        $receivedCents = $receivedInput === '' ? null : $moneyToCents($receivedInput);
        $cartItems = $_SESSION['cart'];

        if (!$cartItems) {
            $error = 'กรุณาเพิ่มสินค้าในตะกร้าก่อน';
        } elseif (!in_array($paymentMethod, $allowedMethods, true)) {
            $error = 'วิธีชำระเงินไม่ถูกต้อง';
        } elseif ($discountCents === null || ($receivedInput !== '' && $receivedCents === null)) {
            $error = 'กรุณากรอกส่วนลดและยอดรับชำระเป็นจำนวนเงินที่ถูกต้อง';
        } else {
            ksort($cartItems, SORT_NUMERIC);
            try {
                $pdo->beginTransaction();
                $lockedItems = [];
                $subtotalCents = 0;

                foreach ($cartItems as $productId => $quantityValue) {
                    $productId = (int) $productId;
                    $quantity = $normalizeQuantity((string) $quantityValue);
                    if ($productId < 1 || $quantity === null) {
                        throw new RuntimeException('ข้อมูลในตะกร้าไม่ถูกต้อง');
                    }
                    $statement = $pdo->prepare('SELECT id, name, selling_price, stock, status FROM products WHERE id = :id FOR UPDATE');
                    $statement->execute(['id' => $productId]);
                    $product = $statement->fetch();
                    if (!$product || (int) $product['status'] !== 1) {
                        throw new RuntimeException('มีสินค้าถูกปิดขาย กรุณาตรวจสอบตะกร้า');
                    }
                    if ((float) $product['stock'] < (float) $quantity) {
                        throw new RuntimeException('stock ของ ' . $product['name'] . ' ไม่เพียงพอ');
                    }
                    $priceCents = $moneyToCents((string) $product['selling_price']);
                    if ($priceCents === null) {
                        throw new RuntimeException('ราคาสินค้าไม่ถูกต้อง');
                    }
                    $lineCents = (int) round($priceCents * (float) $quantity, 0, PHP_ROUND_HALF_UP);
                    $subtotalCents += $lineCents;
                    if ($subtotalCents > 9999999999) {
                        throw new RuntimeException('ยอดขายเกินขอบเขตที่ระบบรองรับ');
                    }
                    $lockedItems[] = [
                        'id' => $productId,
                        'name' => $product['name'],
                        'price' => $priceCents,
                        'quantity' => $quantity,
                        'subtotal' => $lineCents,
                    ];
                }

                if ($discountCents > $subtotalCents) {
                    throw new RuntimeException('ส่วนลดต้องไม่เกินยอดก่อนหักส่วนลด');
                }
                $totalCents = $subtotalCents - $discountCents;
                if ($totalCents < 1) {
                    throw new RuntimeException('ยอดสุทธิต้องมากกว่า 0 บาท');
                }
                if ($paymentMethod === 'cash' && ($receivedCents === null || $receivedCents < $totalCents)) {
                    throw new RuntimeException('ยอดเงินสดที่รับต้องไม่น้อยกว่ายอดสุทธิ');
                }

                $invoiceNo = 'POS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
                $statement = $pdo->prepare(
                    'INSERT INTO sales (invoice_no, user_id, subtotal, discount, total, status) VALUES (:invoice_no, :user_id, :subtotal, :discount, :total, :status)'
                );
                $statement->execute([
                    'invoice_no' => $invoiceNo,
                    'user_id' => $user['id'],
                    'subtotal' => $formatMoney($subtotalCents),
                    'discount' => $formatMoney($discountCents),
                    'total' => $formatMoney($totalCents),
                    'status' => 'completed',
                ]);
                $saleId = (int) $pdo->lastInsertId();

                $saleItemStatement = $pdo->prepare(
                    'INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price, discount, subtotal) VALUES (:sale_id, :product_id, :product_name, :quantity, :price, :discount, :subtotal)'
                );
                $stockStatement = $pdo->prepare('UPDATE products SET stock = stock - :quantity WHERE id = :id AND stock >= :minimum_stock');
                $movementStatement = $pdo->prepare(
                    'INSERT INTO stock_movements (product_id, type, quantity, reference_type, reference_id, note, user_id) VALUES (:product_id, :type, :quantity, :reference_type, :reference_id, :note, :user_id)'
                );

                foreach ($lockedItems as $item) {
                    $saleItemStatement->execute([
                        'sale_id' => $saleId,
                        'product_id' => $item['id'],
                        'product_name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'price' => $formatMoney($item['price']),
                        'discount' => '0.00',
                        'subtotal' => $formatMoney($item['subtotal']),
                    ]);
                    $stockStatement->execute([
                        'quantity' => $item['quantity'],
                        'id' => $item['id'],
                        'minimum_stock' => $item['quantity'],
                    ]);
                    if ($stockStatement->rowCount() !== 1) {
                        throw new RuntimeException('ปรับ stock ไม่สำเร็จ กรุณาลองใหม่');
                    }
                    $movementStatement->execute([
                        'product_id' => $item['id'],
                        'type' => 'out',
                        'quantity' => $item['quantity'],
                        'reference_type' => 'sale',
                        'reference_id' => $saleId,
                        'note' => 'ขาย ' . $invoiceNo,
                        'user_id' => $user['id'],
                    ]);
                }

                $receivedAmount = $paymentMethod === 'cash' ? $formatMoney($receivedCents) : null;
                $changeAmount = $paymentMethod === 'cash' ? $formatMoney($receivedCents - $totalCents) : null;
                $paymentStatement = $pdo->prepare(
                    'INSERT INTO payments (sale_id, payment_method, amount, received_amount, change_amount) VALUES (:sale_id, :payment_method, :amount, :received_amount, :change_amount)'
                );
                $paymentStatement->execute([
                    'sale_id' => $saleId,
                    'payment_method' => $paymentMethod,
                    'amount' => $formatMoney($totalCents),
                    'received_amount' => $receivedAmount,
                    'change_amount' => $changeAmount,
                ]);

                $pdo->commit();
                $_SESSION['cart'] = [];
                $_SESSION['completed_sale'] = [
                    'invoice_no' => $invoiceNo,
                    'total' => $formatMoney($totalCents),
                ];
                header('Location: sales.php');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'บันทึกการขายไม่สำเร็จ ระบบยกเลิกการเปลี่ยนแปลงแล้ว';
            }
        }
    }
}

$cart = $_SESSION['cart'];
$cartLines = [];
$cartSubtotalCents = 0;
foreach ($cart as $productId => $quantityValue) {
    $statement = $pdo->prepare('SELECT id, name, barcode, selling_price, stock, unit, status FROM products WHERE id = :id');
    $statement->execute(['id' => (int) $productId]);
    $product = $statement->fetch();
    $quantity = $normalizeQuantity((string) $quantityValue);
    if (!$product || $quantity === null) {
        continue;
    }
    $priceCents = $moneyToCents((string) $product['selling_price']) ?? 0;
    $lineCents = (int) round($priceCents * (float) $quantity, 0, PHP_ROUND_HALF_UP);
    $cartSubtotalCents += $lineCents;
    $cartLines[] = $product + [
        'quantity' => $quantity,
        'price_cents' => $priceCents,
        'line_cents' => $lineCents,
    ];
}

$search = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = filter_var($_GET['category_id'] ?? '0', FILTER_VALIDATE_INT);
$categoryFilter = $categoryFilter === false ? 0 : max(0, $categoryFilter);
$categoryStatement = $pdo->query('SELECT id, name FROM categories WHERE status = 1 ORDER BY name');
$categories = $categoryStatement->fetchAll();
$productStatement = $pdo->prepare(
    'SELECT p.id, p.name, p.barcode, p.selling_price, p.stock, p.unit, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status = 1 AND p.stock > 0 AND (:search = \'\' OR p.name LIKE :name_search OR p.barcode LIKE :barcode_search) AND (:category_filter = 0 OR p.category_id = :category_id) ORDER BY p.name LIMIT 100'
);
$productStatement->execute([
    'search' => $search,
    'name_search' => '%' . $search . '%',
    'barcode_search' => '%' . $search . '%',
    'category_filter' => $categoryFilter,
    'category_id' => $categoryFilter,
]);
$availableProducts = $productStatement->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ขายสินค้า | POS</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="admin-page sales-page">
    <main class="admin-shell">
        <header class="admin-header">
            <a class="admin-brand" href="index.php"><span class="brand-mark">P</span><span>POS / ขายสินค้า</span></a>
            <div class="admin-user"><?= $escape($user['full_name']) ?> <a href="index.php">หน้าหลัก</a></div>
        </header>

        <div class="page-heading sales-heading">
            <div><p class="eyebrow">จุดขาย</p><h1>ทำรายการขาย</h1></div>
            <span class="count-label"><?= count($cartLines) ?> รายการในตะกร้า</span>
        </div>

        <?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>

        <div class="sales-grid">
            <section class="inventory-section product-picker">
                <div class="inventory-heading"><h2>เลือกสินค้า</h2>
                    <form method="get" class="search-form">
                        <input name="q" type="search" value="<?= $escape($search) ?>" placeholder="ชื่อหรือบาร์โค้ด">
                        <?php if ($categoryFilter > 0): ?><input type="hidden" name="category_id" value="<?= $categoryFilter ?>"><?php endif; ?>
                        <button class="button button-secondary" type="submit">ค้นหา</button>
                    </form>
                </div>
                <form method="get" class="category-filter">
                    <label for="category_filter">หมวดหมู่</label>
                    <select id="category_filter" name="category_id" onchange="this.form.submit()">
                        <option value="0">ทุกหมวดหมู่</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= $escape($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="product-list">
                    <?php foreach ($availableProducts as $product): ?>
                        <article class="product-row">
                            <div class="product-details">
                                <strong><?= $escape($product['name']) ?></strong>
                                <small><?= $escape((string) ($product['barcode'] ?? '')) ?><?= $product['category_name'] ? ' · ' . $escape($product['category_name']) : '' ?></small>
                                <span><?= number_format((float) $product['selling_price'], 2) ?> บาท · เหลือ <?= $escape((string) $product['stock']) ?> <?= $escape($product['unit']) ?></span>
                            </div>
                            <form method="post" class="add-form">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="add_item">
                                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                <input aria-label="จำนวน <?= $escape($product['name']) ?>" name="quantity" type="number" min="0.01" max="<?= $escape((string) $product['stock']) ?>" step="0.01" value="1" required>
                                <button class="icon-button" type="submit" title="เพิ่มลงตะกร้า" aria-label="เพิ่ม <?= $escape($product['name']) ?> ลงตะกร้า">+</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$availableProducts): ?><p class="empty-state">ไม่พบสินค้าที่เปิดขายและมี stock</p><?php endif; ?>
                </div>
            </section>

            <aside class="cart-section">
                <div class="cart-heading"><h2>ตะกร้าขาย</h2>
                    <?php if ($cartLines): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="clear_cart">
                            <button class="text-button" type="submit">ล้างตะกร้า</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if (!$cartLines): ?>
                    <p class="empty-cart">ยังไม่มีสินค้าในตะกร้า</p>
                <?php else: ?>
                    <div class="cart-lines">
                        <?php foreach ($cartLines as $line): ?>
                            <article class="cart-line">
                                <div class="cart-line-top"><strong><?= $escape($line['name']) ?></strong><span><?= $formatMoney($line['line_cents']) ?></span></div>
                                <small><?= $formatMoney($line['price_cents']) ?> บาท / <?= $escape($line['unit']) ?><?= (int) $line['status'] === 1 ? '' : ' · ปิดขาย' ?></small>
                                <div class="cart-line-bottom">
                                    <form method="post" class="quantity-form">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="update_quantity">
                                        <input type="hidden" name="product_id" value="<?= (int) $line['id'] ?>">
                                        <input aria-label="จำนวน <?= $escape($line['name']) ?>" name="quantity" type="number" min="0.01" step="0.01" value="<?= $escape($line['quantity']) ?>" required>
                                        <button class="text-button" type="submit">ปรับ</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="remove_item">
                                        <input type="hidden" name="product_id" value="<?= (int) $line['id'] ?>">
                                        <button class="text-button remove-button" type="submit">นำออก</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="cart-subtotal"><span>ยอดก่อนส่วนลด</span><strong><?= $formatMoney($cartSubtotalCents) ?> บาท</strong></div>
                    <form method="post" class="checkout-form">
                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="checkout">
                        <label for="discount">ส่วนลด (บาท)</label>
                        <input id="discount" name="discount" type="number" min="0" max="<?= $formatMoney($cartSubtotalCents) ?>" step="0.01" value="0.00" required>
                        <label for="payment_method">วิธีชำระเงิน</label>
                        <select id="payment_method" name="payment_method">
                            <option value="cash">เงินสด</option>
                            <option value="transfer">โอนเงิน</option>
                            <option value="qr">QR</option>
                            <option value="card">บัตร</option>
                        </select>
                        <label for="received_amount">รับเงินสด (บาท)</label>
                        <input id="received_amount" name="received_amount" type="number" min="0" step="0.01" placeholder="กรอกเมื่อชำระเงินสด">
                        <button class="button checkout-button" type="submit">ยืนยันการขาย</button>
                    </form>
                <?php endif; ?>
            </aside>
        </div>
    </main>
</body>
</html>
