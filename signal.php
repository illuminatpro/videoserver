<?php
/**
 * Сигналинг комнаты: один хост, несколько зрителей.
 * Комната переживает смену устройства хоста (host_secret + host_session).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$roomsDir = __DIR__ . '/rooms';
if (!is_dir($roomsDir)) mkdir($roomsDir, 0755, true);

foreach (glob($roomsDir . '/*.json') as $file) {
    if (filemtime($file) < time() - 86400 * 7) @unlink($file);
}

$action = $_GET['action'] ?? '';
$room   = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['room'] ?? '');
$viewer = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['viewer'] ?? '');
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

function out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function rand_token($len, $alphabet) {
    $s = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) $s .= $alphabet[random_int(0, $max)];
    return $s;
}
function secret_ok($data, $secret) {
    $have = (string)($data['host_secret'] ?? '');
    $secret = (string)$secret;
    if ($have === '' || $secret === '') return false;
    return hash_equals($have, $secret);
}
function session_ok($data, $session) {
    $have = (string)($data['host_session'] ?? '');
    $session = (string)$session;
    if ($have === '' || $session === '') return false;
    return hash_equals($have, $session);
}
function blank_viewer() {
    return [
        'joined' => time(),
        'seen' => time(),
        'reset' => false,
        'need_keyframe' => false,
        'offer' => null,
        'answer' => null
    ];
}
function normalize_candidate($c) {
    if (!is_array($c) || empty($c['candidate']) || !is_string($c['candidate'])) return null;
    if (strlen($c['candidate']) > 2000) return null;
    return [
        'candidate' => $c['candidate'],
        'sdpMid' => isset($c['sdpMid']) ? (string)$c['sdpMid'] : null,
        'sdpMLineIndex' => isset($c['sdpMLineIndex']) ? (int)$c['sdpMLineIndex'] : null
    ];
}
function normalize_sdp($sdp) {
    if (!is_array($sdp) || empty($sdp['type']) || empty($sdp['sdp']) || !is_string($sdp['sdp'])) return null;
    if (strlen($sdp['sdp']) > 200000) return null;
    $type = $sdp['type'] === 'offer' ? 'offer' : ($sdp['type'] === 'answer' ? 'answer' : '');
    if ($type === '') return null;
    return ['type' => $type, 'sdp' => $sdp['sdp']];
}

/**
 * Эксклюзивная блокировка файла комнаты, чтобы host_poll не затирал answer/offer.
 */
function with_room($file, callable $fn) {
    if (!file_exists($file)) {
        $fn(null, static function () {});
        return;
    }
    $fp = fopen($file, 'c+');
    if ($fp === false) out(['error' => 'Не удалось открыть комнату']);
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        out(['error' => 'Комната занята, повторите']);
    }
    try {
        $raw = stream_get_contents($fp);
        $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($data)) $data = null;
        $save = function ($newData) use ($fp) {
            $json = json_encode($newData, JSON_UNESCAPED_UNICODE);
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
        };
        $fn($data, $save);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function host_auth_or_fail($data) {
    global $input;
    $secret = $_GET['host_secret'] ?? ($input['host_secret'] ?? '');
    $session = $_GET['host_session'] ?? ($input['host_session'] ?? '');
    if (!secret_ok($data, $secret) || !session_ok($data, $session)) {
        out(['error' => 'Комната открыта на другом устройстве', 'stale' => true]);
    }
}

function fresh_viewers($data) {
    $viewers = (array)($data['viewers'] ?? []);
    $gone = [];
    foreach ($viewers as $vid => $v) {
        $v = (array)$v;
        $seen = (int)($v['seen'] ?? $v['joined'] ?? 0);
        if ($seen < time() - 30) {
            unset($viewers[$vid]);
            $gone[] = $vid;
        } else {
            $viewers[$vid] = $v;
        }
    }
    return [$viewers, $gone];
}

// ===== CREATE =====
if ($action === 'create') {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $roomFile = '';
    $room = '';
    for ($n = 0; $n < 8; $n++) {
        $room = rand_token(6, $chars);
        $roomFile = $roomsDir . '/' . $room . '.json';
        $fp = @fopen($roomFile, 'x');
        if ($fp) {
            $secret = bin2hex(random_bytes(16));
            $session = bin2hex(random_bytes(8));
            fwrite($fp, json_encode([
                'created' => time(),
                'host_secret' => $secret,
                'host_session' => $session,
                'host_seen' => time(),
                'sharing' => false,
                'epoch' => 0,
                'viewers' => new stdClass()
            ], JSON_UNESCAPED_UNICODE));
            fclose($fp);
            out(['room' => $room, 'host_secret' => $secret, 'host_session' => $session]);
        }
    }
    out(['error' => 'Не удалось создать комнату']);
}

// ===== CLAIM HOST =====
if ($action === 'claim_host') {
    $code = preg_replace('/[^a-zA-Z0-9]/', '', $input['room'] ?? $room);
    $secret = preg_replace('/[^a-zA-Z0-9]/', '', $input['host_secret'] ?? '');
    if (!$code || !$secret) out(['error' => 'Нужны код комнаты и секрет хоста']);
    $roomFile = $roomsDir . '/' . $code . '.json';
    with_room($roomFile, function ($data, $save) use ($secret, $code) {
        if (!$data) out(['error' => 'Комната не найдена']);
        if (!secret_ok($data, $secret)) out(['error' => 'Неверный секрет хоста']);
        $session = bin2hex(random_bytes(8));
        $viewers = (array)($data['viewers'] ?? []);
        foreach ($viewers as $vid => $v) {
            $v = (array)$v;
            $v['offer'] = null;
            $v['answer'] = null;
            $v['reset'] = false;
            $viewers[$vid] = $v;
        }
        $data['viewers'] = $viewers ?: new stdClass();
        $data['host_session'] = $session;
        $data['host_online'] = true;
        $data['host_seen'] = time();
        $data['sharing'] = false;
        $data['epoch'] = ((int)($data['epoch'] ?? 0)) + 1;
        $data['claimed_at'] = time();
        $save($data);
        out(['ok' => true, 'room' => $code, 'host_secret' => $data['host_secret'], 'host_session' => $session, 'epoch' => $data['epoch']]);
    });
    exit;
}

if (!$room) out(['error' => 'Нет кода комнаты']);
$roomFile = $roomsDir . '/' . $room . '.json';

// ===== JOIN =====
if ($action === 'join') {
    with_room($roomFile, function ($data, $save) {
        if (!$data) out(['error' => 'Комната не найдена']);
        $viewerId = rand_token(8, 'abcdefghijkmnpqrstuvwxyz23456789');
        $viewers = (array)($data['viewers'] ?? []);
        $view = blank_viewer();
        $view['name'] = trim(mb_substr($GLOBALS['input']['name'] ?? 'Зритель', 0, 32)) ?: 'Зритель';
        $viewers[$viewerId] = $view;
        $data['viewers'] = $viewers;
        $save($data);
        out([
            'viewer' => $viewerId,
            'epoch' => (int)($data['epoch'] ?? 0),
            'sharing' => !empty($data['sharing'])
        ]);
    });
    exit;
}

// ===== HOST POLL =====
if ($action === 'host_poll') {
    with_room($roomFile, function ($data, $save) {
        if (!$data) out(['error' => 'Комната не найдена']);
        host_auth_or_fail($data);
        [$viewers, $gone] = fresh_viewers($data);
        $needOffer = [];
        $answers = [];
        $keyframes = [];
        $resets = [];
        $epoch = (int)($data['epoch'] ?? 0);
        foreach ($viewers as $vid => $v) {
            $v = (array)$v;
            if (!empty($v['reset'])) {
                $resets[] = $vid;
                $v['reset'] = false;
                $v['offer'] = null;
                $v['answer'] = null;
            }
            if (!empty($v['need_keyframe'])) {
                $keyframes[] = $vid;
                $v['need_keyframe'] = false;
            }
            $offerEpoch = (int)($v['offer']['epoch'] ?? -1);
            if (!empty($data['sharing']) && (empty($v['offer']) || $offerEpoch !== $epoch)) $needOffer[] = $vid;
            if (!empty($v['answer']) && is_array($v['answer'])) {
                $answers[] = [
                    'viewer' => $vid,
                    'offer_id' => $v['answer']['offer_id'] ?? '',
                    'sdp' => $v['answer']['sdp'] ?? null,
                    'candidates' => $v['answer']['candidates'] ?? []
                ];
            }
            $viewers[$vid] = $v;
        }
        $data['viewers'] = $viewers ?: new stdClass();
        $data['host_seen'] = time();
        $data['host_online'] = true;
        $save($data);
        out([
            'need_offer' => $needOffer,
            'answers' => $answers,
            'gone' => $gone,
            'resets' => $resets,
            'keyframes' => $keyframes,
            'epoch' => $epoch,
            'sharing' => !empty($data['sharing'])
        ]);
    });
    exit;
}

// ===== BEGIN / END SHARE =====
if ($action === 'begin_share' || $action === 'end_share') {
    with_room($roomFile, function ($data, $save) use ($action) {
        if (!$data) out(['error' => 'Комната не найдена']);
        host_auth_or_fail($data);
        $viewers = (array)($data['viewers'] ?? []);
        foreach ($viewers as $vid => $v) {
            $v = (array)$v;
            $v['offer'] = null;
            $v['answer'] = null;
            $v['reset'] = false;
            $viewers[$vid] = $v;
        }
        $data['viewers'] = $viewers ?: new stdClass();
        if ($action === 'begin_share') {
            $data['sharing'] = true;
            $data['epoch'] = ((int)($data['epoch'] ?? 0)) + 1;
        } else {
            $data['sharing'] = false;
        }
        $data['host_seen'] = time();
        $save($data);
        out(['ok' => true, 'epoch' => (int)$data['epoch'], 'sharing' => !empty($data['sharing'])]);
    });
    exit;
}

// ===== SET OFFER =====
if ($action === 'set_offer') {
    if (!$viewer) out(['error' => 'Нет viewer id']);
    $sdp = normalize_sdp($input['sdp'] ?? null);
    if (!$sdp || $sdp['type'] !== 'offer') out(['error' => 'Нет SDP']);
    $offerId = preg_replace('/[^a-zA-Z0-9]/', '', $input['id'] ?? '');
    if (strlen($offerId) < 4) out(['error' => 'Нет id оффера']);
    $candidates = [];
    foreach ((array)($input['candidates'] ?? []) as $c) {
        $n = normalize_candidate($c);
        if ($n) $candidates[] = $n;
    }
    with_room($roomFile, function ($data, $save) use ($viewer, $sdp, $offerId, $candidates) {
        if (!$data) out(['error' => 'Комната не найдена']);
        host_auth_or_fail($data);
        $viewers = (array)($data['viewers'] ?? []);
        if (!isset($viewers[$viewer])) out(['error' => 'Зритель не найден']);
        $v = (array)$viewers[$viewer];
        $v['offer'] = [
            'id' => $offerId,
            'epoch' => (int)($data['epoch'] ?? 0),
            'sdp' => $sdp,
            'candidates' => array_slice($candidates, 0, 80)
        ];
        $v['answer'] = null;
        $viewers[$viewer] = $v;
        $data['viewers'] = $viewers;
        $save($data);
        out(['ok' => true, 'id' => $offerId, 'epoch' => (int)$data['epoch']]);
    });
    exit;
}

// ===== ADD OFFER CANDIDATES =====
if ($action === 'add_offer_candidates') {
    if (!$viewer) out(['error' => 'Нет viewer id']);
    $offerId = preg_replace('/[^a-zA-Z0-9]/', '', $input['id'] ?? '');
    with_room($roomFile, function ($data, $save) use ($viewer, $offerId, $input) {
        if (!$data) out(['error' => 'Комната не найдена']);
        host_auth_or_fail($data);
        $viewers = (array)($data['viewers'] ?? []);
        if (!isset($viewers[$viewer])) out(['error' => 'Зритель не найден']);
        $v = (array)$viewers[$viewer];
        if (empty($v['offer']['id']) || $v['offer']['id'] !== $offerId) out(['ok' => true, 'ignored' => true]);
        $list = (array)($v['offer']['candidates'] ?? []);
        foreach ((array)($input['candidates'] ?? []) as $c) {
            if (count($list) >= 80) break;
            $n = normalize_candidate($c);
            if ($n) $list[] = $n;
        }
        $v['offer']['candidates'] = $list;
        $viewers[$viewer] = $v;
        $data['viewers'] = $viewers;
        $save($data);
        out(['ok' => true, 'count' => count($list)]);
    });
    exit;
}

// ===== VIEWER POLL =====
if ($action === 'viewer_poll') {
    if (!$viewer) out(['error' => 'Нет viewer id']);
    with_room($roomFile, function ($data, $save) use ($viewer) {
        if (!$data) out(['error' => 'Комната не найдена']);
        $viewers = (array)($data['viewers'] ?? []);
        if (!isset($viewers[$viewer])) out(['error' => 'Сначала войдите в комнату', 'left' => true]);
        $v = (array)$viewers[$viewer];
        $v['seen'] = time();
        if (!empty($_GET['keyframe'])) $v['need_keyframe'] = true;
        if (!empty($_GET['reset'])) {
            $v['reset'] = true;
            $v['offer'] = null;
            $v['answer'] = null;
        }
        $viewers[$viewer] = $v;
        $data['viewers'] = $viewers;
        $save($data);
        $epoch = (int)($data['epoch'] ?? 0);
        $offer = null;
        if (!empty($data['sharing']) && !empty($v['offer']) && (int)($v['offer']['epoch'] ?? -1) === $epoch) {
            $offer = $v['offer'];
        }
        $hostSeen = (int)($data['host_seen'] ?? 0);
        out([
            'sharing' => !empty($data['sharing']),
            'epoch' => $epoch,
            'host_online' => $hostSeen > time() - 8,
            'offer' => $offer
        ]);
    });
    exit;
}

// ===== SET ANSWER =====
if ($action === 'set_answer') {
    if (!$viewer) out(['error' => 'Нет viewer id']);
    $sdp = normalize_sdp($input['sdp'] ?? null);
    if (!$sdp || $sdp['type'] !== 'answer') out(['error' => 'Нет SDP']);
    $offerId = preg_replace('/[^a-zA-Z0-9]/', '', $input['offer_id'] ?? '');
    $candidates = [];
    foreach ((array)($input['candidates'] ?? []) as $c) {
        $n = normalize_candidate($c);
        if ($n) $candidates[] = $n;
    }
    with_room($roomFile, function ($data, $save) use ($viewer, $sdp, $offerId, $candidates) {
        if (!$data) out(['error' => 'Комната не найдена']);
        $viewers = (array)($data['viewers'] ?? []);
        if (!isset($viewers[$viewer])) out(['error' => 'Зритель не найден']);
        $v = (array)$viewers[$viewer];
        if (empty($v['offer']['id']) || ($offerId && $v['offer']['id'] !== $offerId)) {
            out(['error' => 'Оффер устарел', 'stale_offer' => true]);
        }
        $v['answer'] = [
            'offer_id' => $v['offer']['id'],
            'sdp' => $sdp,
            'candidates' => array_slice($candidates, 0, 80)
        ];
        $viewers[$viewer] = $v;
        $data['viewers'] = $viewers;
        $save($data);
        out(['ok' => true]);
    });
    exit;
}

// ===== ADD ANSWER CANDIDATES =====
if ($action === 'add_answer_candidates') {
    if (!$viewer) out(['error' => 'Нет viewer id']);
    $offerId = preg_replace('/[^a-zA-Z0-9]/', '', $input['offer_id'] ?? '');
    with_room($roomFile, function ($data, $save) use ($viewer, $offerId, $input) {
        if (!$data) out(['error' => 'Комната не найдена']);
        $viewers = (array)($data['viewers'] ?? []);
        if (!isset($viewers[$viewer])) out(['error' => 'Зритель не найден']);
        $v = (array)$viewers[$viewer];
        if (empty($v['answer']) || ($v['answer']['offer_id'] ?? '') !== $offerId) out(['ok' => true, 'ignored' => true]);
        $list = (array)($v['answer']['candidates'] ?? []);
        foreach ((array)($input['candidates'] ?? []) as $c) {
            if (count($list) >= 80) break;
            $n = normalize_candidate($c);
            if ($n) $list[] = $n;
        }
        $v['answer']['candidates'] = $list;
        $viewers[$viewer] = $v;
        $data['viewers'] = $viewers;
        $save($data);
        out(['ok' => true]);
    });
    exit;
}

// ===== ACK ANSWER (SDP applied, candidates stay) =====
if ($action === 'ack_answer') {
    if (!$viewer) out(['error' => 'Нет viewer id']);
    with_room($roomFile, function ($data, $save) use ($viewer) {
        if (!$data) out(['ok' => true]);
        $viewers = (array)($data['viewers'] ?? []);
        if (isset($viewers[$viewer]) && !empty($viewers[$viewer]['answer'])) {
            $v = (array)$viewers[$viewer];
            $v['answer']['sdp'] = null;
            $viewers[$viewer] = $v;
            $data['viewers'] = $viewers;
            $save($data);
        }
        out(['ok' => true]);
    });
    exit;
}

function room_people($data) {
    $people = [];
    $hostSeen = (int)($data['host_seen'] ?? 0);
    if (!empty($data['host_name']) && $hostSeen > time() - 12) {
        $people[] = ['name' => $data['host_name'], 'role' => 'host'];
    }
    foreach ((array)($data['viewers'] ?? []) as $v) {
        $v = (array)$v;
        if ((int)($v['seen'] ?? 0) > time() - 12) {
            $people[] = ['name' => ($v['name'] ?? '') !== '' ? $v['name'] : 'Зритель', 'role' => 'viewer'];
        }
    }
    return $people;
}

// ===== CHAT =====
if ($action === 'chat_send') {
    $name = trim(mb_substr($input['name'] ?? 'Гость', 0, 32));
    $text = trim(mb_substr($input['text'] ?? '', 0, 400));
    if ($name === '') $name = 'Гость';
    if ($text === '') out(['error' => 'Пустое сообщение']);
    with_room($roomFile, function ($data, $save) use ($name, $text) {
        if (!$data) out(['error' => 'Комната не найдена']);
        $seq = (int)($data['chat_seq'] ?? 0) + 1;
        $chat = array_values((array)($data['chat'] ?? []));
        $chat[] = ['id' => $seq, 'name' => $name, 'text' => $text, 't' => time()];
        if (count($chat) > 100) $chat = array_slice($chat, -100);
        $data['chat'] = $chat;
        $data['chat_seq'] = $seq;
        $save($data);
        out(['ok' => true, 'id' => $seq]);
    });
    exit;
}
if ($action === 'chat_poll') {
    $since = (int)($_GET['since'] ?? 0);
    $name = trim(mb_substr($_GET['name'] ?? '', 0, 32));
    with_room($roomFile, function ($data, $save) use ($since, $name, $viewer) {
        if (!$data) out(['error' => 'Комната не найдена']);
        $dirty = false;
        if ($viewer) {
            $viewers = (array)($data['viewers'] ?? []);
            if (isset($viewers[$viewer])) {
                $v = (array)$viewers[$viewer];
                $v['seen'] = time();
                if ($name !== '') $v['name'] = $name;
                $viewers[$viewer] = $v;
                $data['viewers'] = $viewers;
                $dirty = true;
            }
        }
        $secret = $_GET['host_secret'] ?? '';
        $session = $_GET['host_session'] ?? '';
        if ($secret && $session && secret_ok($data, $secret) && session_ok($data, $session)) {
            $data['host_seen'] = time();
            $data['host_online'] = true;
            if ($name !== '') $data['host_name'] = $name;
            $dirty = true;
        }
        if ($dirty) $save($data);
        $messages = [];
        foreach ((array)($data['chat'] ?? []) as $m) {
            $m = (array)$m;
            if ((int)($m['id'] ?? 0) > $since) $messages[] = $m;
        }
        out([
            'messages' => $messages,
            'people' => room_people($data),
            'title' => $data['title'] ?? ''
        ]);
    });
    exit;
}
if ($action === 'set_title') {
    $title = trim(mb_substr($input['title'] ?? '', 0, 64));
    if ($title === '') out(['error' => 'Пустое название']);
    with_room($roomFile, function ($data, $save) use ($title) {
        if (!$data) out(['error' => 'Комната не найдена']);
        host_auth_or_fail($data);
        $data['title'] = $title;
        $save($data);
        out(['ok' => true, 'title' => $title]);
    });
    exit;
}

// ===== LEAVE =====
if ($action === 'leave') {
    if (!$viewer) out(['error' => 'Нет viewer id']);
    with_room($roomFile, function ($data, $save) use ($viewer) {
        if ($data) {
            $viewers = (array)($data['viewers'] ?? []);
            unset($viewers[$viewer]);
            $data['viewers'] = $viewers ?: new stdClass();
            $save($data);
        }
        out(['ok' => true]);
    });
    exit;
}

out(['error' => 'Неизвестное действие']);
