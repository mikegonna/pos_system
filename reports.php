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
$dateInput = (string) ($_GET['date'] ?? date('Y-m-d'));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateInput) {
    $parsedDate = new DateTimeImmutable('today');
}
$reportDate = $parsedDate->format('Y-m-d');
$startDateTime = $reportDate . ' 00:00:00';
$endDateTime = $parsedDate->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$summaryStatement = $pdo->prepare(
    "SELECT COUNT(*) AS sale_count, COALESCE(SUM(total), 0) AS total_sales, COALESCE(SUM(discount), 0) AS total_discount FROM sales WHERE status = 'completed' AND created_at >= :start_at AND created_at < :end_at"
);
$summaryStatement->execute(['start_at' => $startDateTime, 'end_at' => $endDateTime]);
$summary = $summaryStatement->fetch();

$paymentStatement = $pdo->prepare(
    "SELECT p.payment_method, COUNT(DISTINCT s.id) AS sale_count, SUM(p.amount) AS amount FROM payments p INNER JOIN sales s ON s.id = p.sale_id WHERE s.status = 'completed' AND s.created_at >= :start_at AND s.created_at < :end_at GROUP BY p.payment_method ORDER BY amount DESC"
);
$paymentStatement->execute(['start_at' => $startDateTime, 'end_at' => $endDateTime]);
$payments = $paymentStatement->fetchAll();

$salesStatement = $pdo->prepare(
    "SELECT s.id, s.invoice_no, s.created_at, s.total, s.status, u.full_name, (SELECT GROUP_CONCAT(DISTINCT p.payment_method ORDER BY p.payment_method SEPARATOR ', ') FROM payments p WHERE p.sale_id = s.id) AS payment_methods FROM sales s LEFT JOIN users u ON u.id = s.user_id WHERE s.created_at >= :start_at AND s.created_at < :end_at ORDER BY s.id DESC LIMIT 100"
);
$salesStatement->execute(['start_at' => $startDateTime, 'end_at' => $endDateTime]);
$sales = $salesStatement->fetchAll();

$lowStockStatement = $pdo->query(
    'SELECT id, name, stock, min_stock, unit FROM products WHERE status = 1 AND stock <= min_stock ORDER BY stock ASC, name LIMIT 20'
);
$lowStockProducts = $lowStockStatement->fetchAll();

$trendStartDate = $parsedDate->modify('-6 days');
$trendStartDateTime = $trendStartDate->format('Y-m-d') . ' 00:00:00';
$trendEndDateTime = $parsedDate->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
$trendStatement = $pdo->prepare(
    'SELECT DATE(created_at) AS sale_date, SUM(total) AS total FROM sales WHERE status = "completed" AND created_at >= :start_at AND created_at < :end_at GROUP BY DATE(created_at)'
);
$trendStatement->execute(['start_at' => $trendStartDateTime, 'end_at' => $trendEndDateTime]);
$trendRows = $trendStatement->fetchAll();
$trendMap = [];
foreach ($trendRows as $row) {
    $trendMap[(string) $row['sale_date']] = (float) $row['total'];
}
$trendData = [];
for ($dayOffset = 6; $dayOffset >= 0; --$dayOffset) {
    $pointDate = $parsedDate->modify('-' . $dayOffset . ' days');
    $dateKey = $pointDate->format('Y-m-d');
    $trendData[] = [
        'label' => $pointDate->format('d'),
        'short_label' => $pointDate->format('d/M'),
        'value' => $trendMap[$dateKey] ?? 0.0,
    ];
}
$maxTrendValue = max(1.0, ...array_map(static fn (array $point): float => (float) $point['value'], $trendData));
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายงาน | POS</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="admin-page">
    <main class="admin-shell">
        <header class="admin-header">
            <a class="admin-brand" href="index.php"><span class="brand-mark">P</span><span>POS / รายงาน</span></a>
            <div class="admin-user"><?= $escape($user['full_name']) ?> <a href="index.php">หน้าหลัก</a></div>
        </header>

        <div class="page-heading">
            <div><p class="eyebrow">ภาพรวมร้าน</p><h1>รายงาน</h1></div>
            <form method="get" class="report-date"><label for="date">วันที่</label><input id="date" name="date" type="date" value="<?= $escape($reportDate) ?>"><button class="button button-secondary" type="submit">ดูรายงาน</button></form>
        </div>

        <section class="report-metrics" aria-label="สรุปยอดขาย">
            <article><span>ยอดขายสุทธิ</span><strong><?= number_format((float) $summary['total_sales'], 2) ?></strong><small>บาท</small></article>
            <article><span>จำนวนบิล</span><strong><?= number_format((int) $summary['sale_count']) ?></strong><small>บิล</small></article>
            <article><span>ส่วนลด</span><strong><?= number_format((float) $summary['total_discount'], 2) ?></strong><small>บาท</small></article>
            <article><span>สินค้าใกล้หมด</span><strong><?= number_format(count($lowStockProducts)) ?></strong><small>รายการที่แสดง</small></article>
        </section>

        <section class="chart-panel" aria-label="กราฟยอดขาย 7 วัน">
            <div class="chart-heading">
                <h2>แนวโน้มยอดขาย 7 วัน</h2>
                <span><?= $escape($trendStartDate->format('d/m')) ?> - <?= $escape($parsedDate->format('d/m')) ?></span>
            </div>
            <div class="chart-wrap">
                <?php foreach ($trendData as $point): ?>
                    <?php $barHeight = $maxTrendValue > 0 ? max(8, (float) $point['value'] / $maxTrendValue * 100) : 0; ?>
                    <div class="chart-column">
                        <div class="chart-bar" style="height: <?= $barHeight ?>%;">
                            <span><?= number_format((float) $point['value'], 0) ?></span>
                        </div>
                        <small><?= $escape((string) $point['short_label']) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="report-grid">
            <section class="inventory-section">
                <h2>รายการขาย · <?= $escape($reportDate) ?></h2>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>เวลา / เลขที่บิล</th><th>พนักงาน</th><th>ชำระด้วย</th><th>ยอดสุทธิ</th><th>สถานะ</th></tr></thead>
                        <tbody>
                        <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td><strong><?= $escape($sale['invoice_no']) ?></strong><small><?= $escape($sale['created_at']) ?></small></td>
                                <td><?= $escape((string) ($sale['full_name'] ?? 'ไม่ระบุ')) ?></td>
                                <td><?= $escape((string) ($sale['payment_methods'] ?? 'ไม่ระบุ')) ?></td>
                                <td><?= number_format((float) $sale['total'], 2) ?></td>
                                <td><span class="status <?= $sale['status'] === 'completed' ? 'is-active' : '' ?>"><?= $sale['status'] === 'completed' ? 'สำเร็จ' : 'ยกเลิก' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$sales): ?><tr><td colspan="5" class="empty-state">วันนี้ยังไม่มีรายการขาย</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="report-side">
                <section class="management-section">
                    <h2>ยอดตามวิธีชำระ</h2>
                    <?php foreach ($payments as $payment): ?>
                        <div class="report-row"><span><?= $escape($payment['payment_method']) ?> <small><?= (int) $payment['sale_count'] ?> บิล</small></span><strong><?= number_format((float) $payment['amount'], 2) ?></strong></div>
                    <?php endforeach; ?>
                    <?php if (!$payments): ?><p class="empty-state">ยังไม่มีข้อมูลการชำระ</p><?php endif; ?>
                </section>

                <section class="management-section low-stock-section">
                    <h2>สินค้าใกล้หมด</h2>
                    <?php foreach ($lowStockProducts as $product): ?>
                        <div class="report-row"><span><?= $escape($product['name']) ?><small>ขั้นต่ำ <?= $escape((string) $product['min_stock']) ?> <?= $escape($product['unit']) ?></small></span><strong><?= $escape((string) $product['stock']) ?></strong></div>
                    <?php endforeach; ?>
                    <?php if (!$lowStockProducts): ?><p class="empty-state">ไม่มีสินค้าใกล้หมด</p><?php endif; ?>
                </section>
            </aside>
        </div>
    </main>
</body>
</html>
