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
$notice = isset($_GET['saved']) ? 'เพิ่มผู้ใช้งานแล้ว' : '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = 'คำขอหมดอายุ กรุณาลองใหม่';
    } elseif ($action === 'save_user') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'cashier');
        $status = isset($_POST['status']) ? 1 : 0;

        if ($fullName === '' || strlen($fullName) > 100) {
            $error = 'กรุณากรอกชื่อผู้ใช้และไม่เกิน 100 ตัวอักษร';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/D', $username)) {
            $error = 'ชื่อผู้ใช้ต้องยาว 3-50 ตัว และใช้ได้เฉพาะ a-z, 0-9, จุด, ขีดกลาง หรือขีดล่าง';
        } elseif (strlen($password) < 6) {
            $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif (!in_array($role, ['admin', 'cashier'], true)) {
            $error = 'ประเภทผู้ใช้งานไม่ถูกต้อง';
        } else {
            $existing = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
            $existing->execute(['username' => $username]);

            if ($existing->fetch()) {
                $error = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO users (username, password, full_name, role, status) VALUES (:username, :password, :full_name, :role, :status)'
                );
                $statement->execute([
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $fullName,
                    'role' => $role,
                    'status' => $status,
                ]);

                header('Location: users.php?saved=1');
                exit;
            }
        }
    }
}

$users = $pdo->query('SELECT id, username, full_name, role, status, created_at FROM users ORDER BY created_at DESC LIMIT 100')->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ผู้ใช้งาน | POS</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="admin-page">
    <main class="admin-shell">
        <header class="admin-header">
            <a class="admin-brand" href="index.php"><span class="brand-mark">P</span><span>POS / ผู้ใช้งาน</span></a>
            <div class="admin-user">
                <?= $escape($user['full_name']) ?>
                <a href="index.php">หน้าหลัก</a>
            </div>
        </header>

        <div class="page-heading">
            <div>
                <p class="eyebrow">ระบบผู้ใช้งาน</p>
                <h1>เพิ่มผู้ใช้งาน</h1>
            </div>
            <span class="count-label"><?= count($users) ?> คน</span>
        </div>

        <?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>

        <div class="management-grid">
            <section class="management-section">
                <h2>เพิ่มผู้ใช้งานใหม่</h2>
                <form method="post" class="data-form">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_user">

                    <label for="full_name">ชื่อ-นามสกุล</label>
                    <input id="full_name" name="full_name" type="text" maxlength="100" required>

                    <label for="username">ชื่อผู้ใช้</label>
                    <input id="username" name="username" type="text" maxlength="50" autocomplete="username" required>

                    <label for="password">รหัสผ่าน</label>
                    <input id="password" name="password" type="password" minlength="6" autocomplete="new-password" required>

                    <div class="field-pair">
                        <div>
                            <label for="role">ประเภท</label>
                            <select id="role" name="role">
                                <option value="cashier">Cashier</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div>
                            <label>&nbsp;</label>
                            <label class="check-row" for="status">
                                <input id="status" name="status" type="checkbox" checked>
                                <span>เปิดใช้งานทันที</span>
                            </label>
                        </div>
                    </div>

                    <button class="button" type="submit">เพิ่มผู้ใช้งาน</button>
                </form>
            </section>

            <section class="management-section">
                <h2>รายชื่อผู้ใช้งาน</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ชื่อ</th>
                                <th>ชื่อผู้ใช้</th>
                                <th>สิทธิ์</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $row): ?>
                                <tr>
                                    <td><?= $escape((string) $row['full_name']) ?></td>
                                    <td><?= $escape((string) $row['username']) ?></td>
                                    <td><span class="status <?= $row['role'] === 'admin' ? 'is-active' : '' ?>"><?= $escape((string) $row['role']) ?></span></td>
                                    <td><span class="status <?= ((int) $row['status'] === 1 ? 'is-active' : '') ?>"><?= ((int) $row['status'] === 1 ? 'เปิดใช้งาน' : 'ปิดใช้งาน') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
