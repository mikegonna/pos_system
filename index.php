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
header('Referrer-Policy: same-origin');

require __DIR__ . '/assets/config/config.php';

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$error = '';
$notice = isset($_GET['created']) ? 'สร้างบัญชีผู้ดูแลแล้ว กรุณาเข้าสู่ระบบ' : '';

if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string) ($_POST['action'] ?? 'login');
	$submittedToken = (string) ($_POST['csrf_token'] ?? '');

	if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
		$error = 'คำขอหมดอายุ กรุณาลองใหม่';
	} elseif ($action === 'logout') {
		$_SESSION = [];
		session_regenerate_id(true);
		header('Location: index.php');
		exit;
	} elseif ($action === 'setup') {
		$username = trim((string) ($_POST['username'] ?? ''));
		$fullName = trim((string) ($_POST['full_name'] ?? ''));
		$password = (string) ($_POST['password'] ?? '');
		$passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

		if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/D', $username)) {
			$error = 'ชื่อผู้ใช้ต้องยาว 3-50 ตัว และใช้ได้เฉพาะ a-z, 0-9, จุด, ขีดกลาง หรือขีดล่าง';
		} elseif ($fullName === '' || strlen($fullName) > 100) {
			$error = 'กรุณากรอกชื่อ และไม่เกิน 100 ตัวอักษร';
		} elseif (strlen($password) < 6) {
			$error = 'รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร';
		} elseif ($password !== $passwordConfirmation) {
			$error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
		} else {
			$lockAcquired = (int) $pdo->query("SELECT GET_LOCK('pos-system-first-admin', 5)")->fetchColumn() === 1;

			if (!$lockAcquired) {
				$error = 'ตั้งค่าระบบไม่สำเร็จ กรุณาลองใหม่';
			} else {
				try {
					$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
					if ($userCount > 0) {
						$error = 'ระบบถูกตั้งค่าแล้ว กรุณาเข้าสู่ระบบ';
					} else {
						$statement = $pdo->prepare(
							'INSERT INTO users (username, password, full_name, role, status) VALUES (:username, :password, :full_name, :role, :status)'
						);
						$statement->execute([
							'username' => $username,
							'password' => password_hash($password, PASSWORD_DEFAULT),
							'full_name' => $fullName,
							'role' => 'admin',
							'status' => 1,
						]);
						header('Location: index.php?created=1');
						exit;
					}
				} finally {
					$pdo->query("SELECT RELEASE_LOCK('pos-system-first-admin')");
				}
			}
		}
	} else {
		$username = trim((string) ($_POST['username'] ?? ''));
		$password = (string) ($_POST['password'] ?? '');
		$statement = $pdo->prepare('SELECT id, username, password, full_name, role, status FROM users WHERE username = :username LIMIT 1');
		$statement->execute(['username' => $username]);
		$account = $statement->fetch();

		if ($account && (int) $account['status'] === 1 && password_verify($password, $account['password'])) {
			session_regenerate_id(true);
			unset($_SESSION['cart']);
			$_SESSION['user'] = [
				'id' => (int) $account['id'],
				'username' => $account['username'],
				'full_name' => $account['full_name'],
				'role' => $account['role'],
			];
			header('Location: index.php');
			exit;
		}

		$error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
	}
}

$user = $_SESSION['user'] ?? null;
$isSetup = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
?>
<!doctype html>
<html lang="th">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $user ? 'หน้าหลัก' : ($isSetup ? 'ตั้งค่าระบบ' : 'เข้าสู่ระบบ') ?> | POS</title>
	<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
	<main class="shell">
		<section class="panel" aria-labelledby="page-title">
			<div class="brand-mark" aria-hidden="true">P</div>
			<p class="eyebrow">POINT OF SALE</p>

			<?php if ($user): ?>
				<h1 id="page-title">ยินดีต้อนรับ</h1>
				<p class="intro"><?= $escape($user['full_name']) ?> <span class="role"><?= $escape($user['role']) ?></span></p>
				<p class="notice">เข้าสู่ระบบแล้ว</p>
				<nav class="dashboard-actions" aria-label="เมนูหลัก">
					<a class="button dashboard-link" href="sales.php">เปิดหน้าขาย</a>
				<?php if ($user['role'] === 'admin'): ?>
					<a class="button button-secondary dashboard-link" href="products.php">จัดการสินค้า</a>
					<a class="button button-secondary dashboard-link" href="users.php">จัดการผู้ใช้งาน</a>
					<a class="button button-secondary dashboard-link" href="reports.php">รายงาน</a>
				<?php endif; ?>
				</nav>
				<form method="post" class="action-form">
					<input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
					<input type="hidden" name="action" value="logout">
					<button class="button button-secondary" type="submit">ออกจากระบบ</button>
				</form>
			<?php else: ?>
				<h1 id="page-title"><?= $isSetup ? 'เริ่มต้นใช้งาน' : 'เข้าสู่ระบบ' ?></h1>
				<p class="intro"><?= $isSetup ? 'สร้างบัญชีผู้ดูแลระบบคนแรก' : 'เข้าสู่ระบบเพื่อจัดการหน้าร้าน' ?></p>

				<?php if ($notice !== ''): ?>
					<p class="notice" role="status"><?= $escape($notice) ?></p>
				<?php endif; ?>
				<?php if ($error !== ''): ?>
					<p class="error" role="alert"><?= $escape($error) ?></p>
				<?php endif; ?>

				<form method="post" class="login-form">
					<input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
					<input type="hidden" name="action" value="<?= $isSetup ? 'setup' : 'login' ?>">

					<?php if ($isSetup): ?>
						<label for="full_name">ชื่อผู้ดูแล</label>
						<input id="full_name" name="full_name" type="text" maxlength="100" autocomplete="name" required>
					<?php endif; ?>

					<label for="username">ชื่อผู้ใช้</label>
					<input id="username" name="username" type="text" maxlength="50" autocomplete="username" required>

					<label for="password">รหัสผ่าน<?= $isSetup ? ' (อย่างน้อย 6 ตัวอักษร)' : '' ?></label>
					<input id="password" name="password" type="password" autocomplete="<?= $isSetup ? 'new-password' : 'current-password' ?>" required>

					<?php if ($isSetup): ?>
						<label for="password_confirmation">ยืนยันรหัสผ่าน</label>
						<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
					<?php endif; ?>

					<button class="button" type="submit"><?= $isSetup ? 'สร้างบัญชีผู้ดูแล' : 'เข้าสู่ระบบ' ?></button>
				</form>
			<?php endif; ?>
		</section>
		<aside class="side-note" aria-hidden="true">
			<span class="side-index">POS / 01</span>
			<p>ขายง่าย<br>จัดการชัดเจน</p>
			<span class="side-rule"></span>
		</aside>
	</main>
</body>
</html>
