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
    unset($data['_login']);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    return file_put_contents(user_file($login), $json, LOCK_EX) !== false;
}
function with_user($login, $fn) {
    $file = user_file($login);
    if (!is_file($file)) return null;
    $fp = fopen($file, 'c+');
    if ($fp === false) return null;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return null; }
    try {
        $raw = stream_get_contents($fp);
        $u = json_decode($raw ?: '', true);
        if (!is_array($u)) return null;
        $next = $fn($u);
        if (!is_array($next)) return $u;
        unset($next['_login']);
        $json = json_encode($next, JSON_UNESCAPED_UNICODE);
        if ($json === false) return null;
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        return $next;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
function clip_text($s, $n) {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (function_exists('mb_substr')) return mb_substr($s, 0, $n);
    return substr($s, 0, $n);
}
function issue_cookie($token) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('ss_token', $token, [
        'expires' => time() + 86400 * 90,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
function clear_cookie() {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('ss_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
function clean_people($input) {
    if (!is_array($input) || !array_key_exists('people', $input) || !is_array($input['people'])) return null;
    $people = [];
    foreach ($input['people'] as $name) {
        $name = clip_text($name, 32);
        if ($name !== '') $people[] = $name;
        if (count($people) >= 8) break;
    }
    return $people;
}
function write_room_meta($code, $login, $secret, $title, $people) {
    global $roomsMetaDir;
    if (!$secret) return;
    $prev = [];
    $f = $roomsMetaDir . '/' . $code . '.json';
    if (is_file($f)) $prev = json_decode(file_get_contents($f), true) ?: [];
    $meta = [
        'owner' => $login,
        'host_secret' => $secret,
        'title' => $title ?: ($prev['title'] ?? 'Комната'),
        'people' => $people !== null ? $people : ($prev['people'] ?? []),
        'updated' => time()
    ];
    file_put_contents($f, json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function rooms_from_meta($login) {
    global $roomsMetaDir;
    $out = [];
    foreach (glob($roomsMetaDir . '/*.json') as $f) {
        $m = json_decode(file_get_contents($f), true);
        if (!$m || ($m['owner'] ?? '') !== $login) continue;
        $code = basename($f, '.json');
        $out[] = [
            'code' => $code,
            'host_secret' => (string)($m['host_secret'] ?? ''),
            'role' => 'host',
            'title' => $m['title'] ?? 'Комната',
            'people' => $m['people'] ?? [],
            'created' => (int)($m['updated'] ?? time()),
            'updated' => (int)($m['updated'] ?? time())
        ];
    }
    return $out;
}
function merge_room_lists($primary, $extra) {
    $map = [];
    foreach ($primary as $r) {
        if (!empty($r['code'])) $map[$r['code']] = $r;
    }
    foreach ($extra as $r) {
        if (empty($r['code'])) continue;
        if (!isset($map[$r['code']])) {
            $map[$r['code']] = $r;
            continue;
        }
        if (empty($map[$r['code']]['host_secret']) && !empty($r['host_secret'])) {
            $map[$r['code']]['host_secret'] = $r['host_secret'];
            $map[$r['code']]['role'] = 'host';
        }
        if (empty($map[$r['code']]['people']) && !empty($r['people'])) $map[$r['code']]['people'] = $r['people'];
        if (empty($map[$r['code']]['title']) && !empty($r['title'])) $map[$r['code']]['title'] = $r['title'];
    }
    return array_values($map);
}
function account_rooms($u) {
    $login = $u['login'] ?? '';
    $merged = merge_room_lists($u['rooms'] ?? [], $login ? rooms_from_meta($login) : []);
    return $merged;
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
function token_candidates() {
    global $input;
    $raw = [];
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
    if (preg_match('/Bearer\s+(\S+)/i', (string)$hdr, $m)) $raw[] = $m[1];
    if (!empty($_GET['token'])) $raw[] = (string)$_GET['token'];
    if (is_array($input) && isset($input['token'])) $raw[] = (string)$input['token'];
    if (!empty($_COOKIE['ss_token'])) $raw[] = (string)$_COOKIE['ss_token'];
    $out = [];
    foreach ($raw as $token) {
        $clean = preg_replace('/[^a-fA-F0-9]/', '', $token);
        if ($clean !== '' && !in_array($clean, $out, true)) $out[] = $clean;
    }
    return $out;
}
function auth_user() {
    foreach (token_candidates() as $token) {
        $u = user_by_token($token);
        if ($u) return $u;
    }
    return null;
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
    if (!save_user($login, $data)) {
        echo json_encode(['error' => 'Не удалось записать аккаунт']);
        exit;
    }
    issue_cookie($token);
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
    $saved = with_user($u['login'], function ($fresh) use ($token) {
        if (!isset($fresh['tokens']) || !is_array($fresh['tokens'])) $fresh['tokens'] = [];
        foreach ($fresh['tokens'] as $t => $exp) {
            if ($exp < time()) unset($fresh['tokens'][$t]);
        }
        $fresh['tokens'][$token] = time() + 86400 * 90;
        $fresh['rooms'] = account_rooms($fresh);
        return $fresh;
    });
    if (!$saved) {
        echo json_encode(['error' => 'Не удалось записать аккаунт']);
        exit;
    }
    issue_cookie($token);
    echo json_encode([
        'ok' => true,
        'login' => $saved['login'],
        'token' => $token,
        'rooms' => $saved['rooms'] ?? []
    ]);
    exit;
}

// ===== LOGOUT =====
if ($action === 'logout') {
    clear_cookie();
    echo json_encode(['ok' => true]);
    exit;
}

// ===== ME =====
if ($action === 'me') {
    $u = auth_user();
    if (!$u) {
        echo json_encode(['error' => 'Не авторизован']);
        exit;
    }
    $rooms = account_rooms($u);
    if ($rooms != ($u['rooms'] ?? [])) {
        with_user($u['login'], function ($fresh) use ($rooms) {
            $fresh['rooms'] = merge_room_lists($fresh['rooms'] ?? [], $rooms);
            return $fresh;
        });
        $rooms = account_rooms(load_user($u['login']) ?: $u);
    }
    echo json_encode([
        'ok' => true,
        'login' => $u['login'],
        'rooms' => $rooms
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
    $title = clip_text($input['title'] ?? '', 64);
    $people = clean_people($input);
    if (!$code) {
        echo json_encode(['error' => 'Нет кода комнаты']);
        exit;
    }
    if ($role === 'host' && !$secret) {
        echo json_encode(['error' => 'Нет кода или секрета']);
        exit;
    }
    if ($secret) write_room_meta($code, $u['login'], $secret, $title ?: 'Комната', $people);
    $saved = with_user($u['login'], function ($fresh) use ($code, $secret, $title, $people) {
        $rooms = $fresh['rooms'] ?? [];
        $found = false;
        foreach ($rooms as &$r) {
            if (($r['code'] ?? '') !== $code) continue;
            if ($secret) {
                $r['host_secret'] = $secret;
                $r['role'] = 'host';
            } elseif (empty($r['host_secret'])) {
                $r['role'] = 'viewer';
            }
            if ($title !== '' && ($secret || empty($r['title']))) $r['title'] = $title;
            if ($people !== null) $r['people'] = $people;
            $r['updated'] = time();
            $found = true;
            break;
        }
        unset($r);
        if (!$found) {
            $rooms[] = [
                'code' => $code,
                'host_secret' => $secret,
                'role' => $secret ? 'host' : 'viewer',
                'title' => $title ?: 'Комната',
                'people' => $people ?: [],
                'created' => time(),
                'updated' => time()
            ];
        }
        $fresh['rooms'] = merge_room_lists($rooms, rooms_from_meta($fresh['login'] ?? ''));
        return $fresh;
    });
    if (!$saved) {
        echo json_encode(['error' => 'Не удалось сохранить комнату']);
        exit;
    }
    echo json_encode(['ok' => true, 'rooms' => $saved['rooms'] ?? []]);
    exit;
}

// ===== DELETE ROOM FROM ACCOUNT =====
if ($action === 'delete_room' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = auth_user();
    if (!$u) {
        echo json_encode(['error' => 'Не авторизован']);
        exit;
    }
    $code = preg_replace('/[^a-zA-Z0-9]/', '', $input['room'] ?? '');
    if (!$code) {
        echo json_encode(['error' => 'Нет кода комнаты']);
        exit;
    }
    $removedHost = false;
    $saved = with_user($u['login'], function ($fresh) use ($code, &$removedHost) {
        $kept = [];
        foreach (($fresh['rooms'] ?? []) as $r) {
            if (($r['code'] ?? '') === $code) {
                if (!empty($r['host_secret'])) $removedHost = true;
            } else $kept[] = $r;
        }
        $fresh['rooms'] = $kept;
        return $fresh;
    });
    if (!$saved) {
        echo json_encode(['error' => 'Не удалось удалить комнату']);
        exit;
    }
    if ($removedHost) {
        global $roomsMetaDir;
        $meta = $roomsMetaDir . '/' . $code . '.json';
        if (is_file($meta)) {
            $m = json_decode(file_get_contents($meta), true);
            if (!$m || ($m['owner'] ?? '') === $u['login']) @unlink($meta);
        }
    }
    $rooms = array_values(array_filter($saved['rooms'] ?? [], function ($r) use ($code) {
        return ($r['code'] ?? '') !== $code;
    }));
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
    $rooms = account_rooms($u);
    if ($rooms != ($u['rooms'] ?? [])) {
        with_user($u['login'], function ($fresh) {
            $fresh['rooms'] = account_rooms($fresh);
            return $fresh;
        });
    }
    echo json_encode(['ok' => true, 'rooms' => $rooms]);
    exit;
}

echo json_encode(['error' => 'Неизвестное действие']);
