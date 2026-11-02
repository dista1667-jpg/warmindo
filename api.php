<?php
declare(strict_types=1);

/* WARMINDO_SH – API menu & admin (tanpa database, data disimpan di folder data/) */

const DEFAULT_PASSWORD = 'admin123';   // hanya dipakai saat pertama kali; ganti lewat Panel Admin

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$dataDir  = __DIR__ . '/data';
$upDir    = __DIR__ . '/uploads';
$menuFile = $dataDir . '/menu.json';
$admFile  = $dataDir . '/admin.json';

foreach ([$dataDir, $upDir] as $d) {
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
}
if (!file_exists($dataDir . '/.htaccess')) {
    @file_put_contents($dataDir . '/.htaccess',
        "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
}

const SUBS = ['food' => ['Cemilan', 'Nasi'], 'drink' => ['Hot', 'Ice']];

function out(array $data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400) { out(['error' => $msg], $code); }

function defaultMenu(): array {
    $f = fn($id, $n, $p, $t, $s, $i, $d = null) =>
        array_filter(['id' => $id, 'n' => $n, 'p' => $p, 't' => $t, 's' => $s, 'd' => $d, 'i' => $i], fn($v) => $v !== null);
    return [
        $f(1, 'Siomay', 10000, 'food', 'Cemilan', 'siomay'),
        $f(16, 'Cireng', 10000, 'food', 'Cemilan', 'cireng', 'Renyah di luar, kenyal di dalam'),
        $f(3, 'Kentang', 10000, 'food', 'Cemilan', 'kentang'),
        $f(4, 'Mix Platter', 10000, 'food', 'Cemilan', 'mix-platter'),
        $f(5, 'Otak-otak', 10000, 'food', 'Cemilan', 'otak-otak'),
        $f(6, 'Teh Tarik', 9000, 'drink', 'Hot', 'teh-tarik', 'Klasik dan nikmat'),
        $f(7, 'Es Teh Manis', 9000, 'drink', 'Ice', 'es-teh-manis', 'Segar dan dingin'),
        $f(8, 'Es Jeruk', 9000, 'drink', 'Ice', 'es-jeruk', 'Asam manis segar'),
        $f(9, 'Es Milo', 9000, 'drink', 'Ice', 'es-milo', 'Lembut dan manis'),
        $f(10, 'Chocolatos', 9000, 'drink', 'Ice', 'chocolatos', 'Coklat yang bikin nagih'),
        $f(11, 'Matcha', 9000, 'drink', 'Ice', 'matcha', 'Rasa teh hijau premium'),
        $f(12, 'Jus', 9000, 'drink', 'Ice', 'jus', 'Aneka rasa buah'),
        $f(13, 'Mix Tea/Teh Tarik', 9000, 'drink', 'Ice', 'mix-tea'),
        $f(15, 'Milo', 9000, 'drink', 'Hot', 'milo'),
        $f(14, 'Drink Bengbeng', 9000, 'drink', 'Ice', 'drink-bengbeng'),
    ];
}

function ensureMenu(): void {
    global $menuFile;
    if (!file_exists($menuFile)) {
        file_put_contents($menuFile, json_encode(defaultMenu(), JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
function readMenu(): array {
    global $menuFile;
    ensureMenu();
    $m = json_decode((string)file_get_contents($menuFile), true);
    return is_array($m) ? array_values($m) : [];
}
/** Ubah menu dengan kunci file supaya dua perubahan bersamaan tidak saling menimpa. */
function mutateMenu(callable $fn): array {
    global $menuFile;
    ensureMenu();
    $fp = fopen($menuFile, 'c+');
    if (!$fp) fail('Tidak bisa menulis data. Cek izin folder data/.', 500);
    flock($fp, LOCK_EX);
    $menu = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($menu)) $menu = [];
    $menu = array_values($fn($menu));
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($menu, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $menu;
}

function body(): array {
    $j = json_decode((string)file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}
function isAdmin(): bool { return ($_SESSION['admin'] ?? false) === true; }
function requireAdmin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metode tidak valid.', 405);
    if (!isAdmin()) fail('Sesi admin berakhir. Silakan login lagi.', 401);
    $tok = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($tok) || !hash_equals((string)($_SESSION['csrf'] ?? ''), $tok)) fail('Token tidak valid. Muat ulang halaman.', 403);
}
function adminHash(): string {
    global $admFile;
    if (file_exists($admFile)) {
        $j = json_decode((string)file_get_contents($admFile), true);
        if (!empty($j['hash'])) return $j['hash'];
    }
    $h = password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT);
    file_put_contents($admFile, json_encode(['hash' => $h]), LOCK_EX);
    return $h;
}
function deleteUpload(?string $path): void {
    if ($path && preg_match('#^uploads/[a-f0-9]{16}\.(jpg|png|webp|gif)$#', $path)) {
        @unlink(__DIR__ . '/' . $path);
    }
}

$a = $_GET['a'] ?? '';

switch ($a) {
    case 'menu':
        out(['menu' => readMenu()]);

    case 'me':
        out(['admin' => isAdmin(), 'csrf' => isAdmin() ? ($_SESSION['csrf'] ?? '') : '']);

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metode tidak valid.', 405);
        $pw = (string)(body()['password'] ?? '');
        if (!password_verify($pw, adminHash())) { usleep(800000); fail('Password salah.', 401); }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf']  = bin2hex(random_bytes(16));
        out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

    case 'logout':
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    case 'password':
        requireAdmin();
        $pw = (string)(body()['password'] ?? '');
        if (strlen($pw) < 4) fail('Password minimal 4 karakter.');
        file_put_contents($admFile, json_encode(['hash' => password_hash($pw, PASSWORD_DEFAULT)]), LOCK_EX);
        out(['ok' => true]);

    case 'save': // tambah / ubah menu (multipart/form-data)
        requireAdmin();
        $id = ($_POST['id'] ?? '') === '' ? null : (int)$_POST['id'];
        $n  = trim(mb_substr((string)($_POST['n'] ?? ''), 0, 40));
        $p  = filter_var($_POST['p'] ?? null, FILTER_VALIDATE_INT);
        $t  = (string)($_POST['t'] ?? '');
        $s  = (string)($_POST['s'] ?? '');
        $d  = trim(mb_substr((string)($_POST['d'] ?? ''), 0, 60));
        if ($n === '') fail('Nama menu wajib diisi.');
        if ($p === false || $p < 0 || $p > 10000000) fail('Harga tidak valid.');
        if (!isset(SUBS[$t]) || !in_array($s, SUBS[$t], true)) fail('Kategori tidak valid.');

        // gambar baru (opsional)
        $newImg = null;
        if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['image'];
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) fail('Gambar gagal diunggah (maks. 2 MB).');
            $info = @getimagesize($f['tmp_name']);
            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
            if (!$ext) fail('Format gambar harus JPG, PNG, WEBP, atau GIF.');
            $name = 'uploads/' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/' . $name)) fail('Gagal menyimpan gambar. Cek izin folder uploads/.', 500);
            $newImg = $name;
        }
        $clear = ($_POST['clear'] ?? '') === '1';

        $menu = mutateMenu(function (array $menu) use ($id, $n, $p, $t, $s, $d, $newImg, $clear) {
            $idx = null;
            foreach ($menu as $k => $m) if ($id !== null && (int)$m['id'] === $id) $idx = $k;
            $old = $idx !== null ? $menu[$idx] : [];
            $item = ['id' => $id ?? (max(array_map(fn($m) => (int)$m['id'], $menu) ?: [0]) + 1), 'n' => $n, 'p' => $p, 't' => $t, 's' => $s];
            if ($d !== '') $item['d'] = $d;
            if ($newImg) {
                $item['img'] = $newImg;
                deleteUpload($old['img'] ?? null);
            } elseif ($clear) {
                deleteUpload($old['img'] ?? null);
            } else {
                if (!empty($old['img'])) $item['img'] = $old['img'];
                if (!empty($old['i']))   $item['i']   = $old['i'];
            }
            if ($idx === null) $menu[] = $item; else $menu[$idx] = $item;
            return $menu;
        });
        out(['ok' => true, 'menu' => $menu]);

    case 'delete':
        requireAdmin();
        $id = (int)(body()['id'] ?? 0);
        $menu = mutateMenu(function (array $menu) use ($id) {
            foreach ($menu as $m) if ((int)$m['id'] === $id) deleteUpload($m['img'] ?? null);
            return array_filter($menu, fn($m) => (int)$m['id'] !== $id);
        });
        out(['ok' => true, 'menu' => $menu]);

    default:
        fail('Aksi tidak dikenal.', 404);
}
