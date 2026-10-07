<?php
/**
 * Простая регистрация / вход. Токены в файлах.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$usersDir = __DIR__ . '/data/users';
$roomsMetaDir = __DIR__ . '/data/rooms';
if (!is_dir($usersDir)) mkdir($usersDir, 0755, true);
if (!is_dir($roomsMetaDir)) mkdir($roomsMetaDir, 0755, true);

function json_input() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}
function user_file($login) {
    global $usersDir;
    $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $login);
    return $usersDir . '/' . strtolower($safe) . '.json';
}
function load_user($login) {
    $f = user_file($login);
    if (!file_exists($f)) return null;
    return json_decode(file_get_contents($f), true);
}
function save_user($login, $data) {
    file_put_contents(user_file($login), json_encode($data, JSON_UNESCAPED_UNICODE));
}
function make_token() {
    return bin2hex(random_bytes(24));
}
function user_by_token($token) {
    global $usersDir;
    if (!$token) return null;
    foreach (glob($usersDir . '/*.json') as $f) {
        $u = json_decode(file_get_contents($f), true);
        if (!$u) continue;
        foreach (($u['tokens'] ?? []) as $t => $exp) {
            if ($t === $token && $exp > time()) {
                $u['_login'] = $u['login'];
                return $u;
            }
        }
    }
    return null;
}
function request_token() {
    global $input;
    $hdr = $_SERVER['HTTP_AUTHORIZATION']
        ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')
        ?: ($_SERVER['Authorization'] ?? '');
    if (!$hdr) {
        $headers = [];
        if (function_exists('getallheaders')) $headers = getallheaders() ?: [];
        elseif (function_exists('apache_request_headers')) $headers = apache_request_headers() ?: [];
        foreach ($headers as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    $token = '';
    if (preg_match('/Bearer\s+(\S+)/i', (string)$hdr, $m)) $token = $m[1];
    if (!$token) $token = (string)($_GET['token'] ?? '');
    if (!$token && is_array($input) && isset($input['token'])) $token = (string)$input['token'];
    return preg_replace('/[^a-fA-F0-9]/', '', $token);
}
function auth_user() {
    return user_by_token(request_token());
}

$action = $_GET['action'] ?? '';
$input = json_input();

// ===== REGISTER =====
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($input['login'] ?? '');
    $pass  = $input['password'] ?? '';
    if (strlen($login) < 3 || strlen($login) > 32) {
        echo json_encode(['error' => 'Логин 3–32 символа']);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $login)) {
        echo json_encode(['error' => 'Логин: только латиница, цифры, _ - .']);
        exit;
    }
    if (strlen($pass) < 4) {
        echo json_encode(['error' => 'Пароль минимум 4 символа']);
        exit;
    }
    if (load_user($login)) {
        echo json_encode(['error' => 'Такой логин уже есть']);
        exit;
    }
    $token = make_token();
    $data = [
        'login' => $login,
        'pass'  => password_hash($pass, PASSWORD_DEFAULT),
        'created' => time(),
        'tokens' => [$token => time() + 86400 * 90],
        'rooms' => []
    ];
    save_user($login, $data);
    echo json_encode(['ok' => true, 'login' => $login, 'token' => $token]);
    exit;
}

// ===== LOGIN =====
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($input['login'] ?? '');
    $pass  = $input['password'] ?? '';
    $u = load_user($login);
    if (!$u || !password_verify($pass, $u['pass'])) {
        echo json_encode(['error' => 'Неверный логин или пароль']);
        exit;
    }
    $token = make_token();
    if (!isset($u['tokens']) || !is_array($u['tokens'])) $u['tokens'] = [];
    // чистим старые
    foreach ($u['tokens'] as $t => $exp) {
        if ($exp < time()) unset($u['tokens'][$t]);
    }
    $u['tokens'][$token] = time() + 86400 * 90;
    save_user($login, $u);
    echo json_encode([
        'ok' => true,
        'login' => $u['login'],
        'token' => $token,
        'rooms' => $u['rooms'] ?? []
    ]);
    exit;
}

// ===== ME =====
if ($action === 'me') {
    $u = auth_user();
    if (!$u) {
        echo json_encode(['error' => 'Не авторизован']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'login' => $u['login'],
        'rooms' => $u['rooms'] ?? []
    ]);
    exit;
}

// ===== SAVE ROOM TO ACCOUNT =====
if ($action === 'save_room' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = auth_user();
    if (!$u) {
        echo json_encode(['error' => 'Не авторизован']);
        exit;
    }
    $code = preg_replace('/[^a-zA-Z0-9]/', '', $input['room'] ?? '');
    $secret = preg_replace('/[^a-zA-Z0-9]/', '', $input['host_secret'] ?? '');
    $role = (($input['role'] ?? '') === 'viewer') ? 'viewer' : 'host';
    $title = trim(mb_substr($input['title'] ?? $code, 0, 64));
    if (!$code) {
        echo json_encode(['error' => 'Нет кода комнаты']);
        exit;
    }
    if ($role === 'host' && !$secret) {
        echo json_encode(['error' => 'Нет кода или секрета']);
        exit;
    }
    $rooms = $u['rooms'] ?? [];
    // обновить или добавить. Секрет хоста не затирается, если человек зашёл как зритель.
    $found = false;
    foreach ($rooms as &$r) {
        if (($r['code'] ?? '') === $code) {
            if ($secret) {
                $r['host_secret'] = $secret;
                $r['role'] = 'host';
            } elseif (empty($r['host_secret'])) {
                $r['role'] = 'viewer';
            }
            $r['title'] = $title ?: ($r['title'] ?? $code);
            $r['updated'] = time();
            $found = true;
            break;
        }
    }
    unset($r);
    if (!$found) {
        $rooms[] = [
            'code' => $code,
            'host_secret' => $secret,
            'role' => $secret ? 'host' : 'viewer',
            'title' => $title,
            'created' => time(),
            'updated' => time()
        ];
    }
    $u['rooms'] = $rooms;
    save_user($u['login'], $u);

    if ($secret) {
        global $roomsMetaDir;
        file_put_contents($roomsMetaDir . '/' . $code . '.json', json_encode([
            'owner' => $u['login'],
            'host_secret' => $secret,
            'title' => $title,
            'updated' => time()
        ], JSON_UNESCAPED_UNICODE));
    }

    echo json_encode(['ok' => true, 'rooms' => $rooms]);
    exit;
}

// ===== LIST ROOMS =====
if ($action === 'my_rooms') {
    $u = auth_user();
    if (!$u) {
        echo json_encode(['error' => 'Не авторизован']);
        exit;
    }
    echo json_encode(['ok' => true, 'rooms' => $u['rooms'] ?? []]);
    exit;
}

echo json_encode(['error' => 'Неизвестное действие']);
