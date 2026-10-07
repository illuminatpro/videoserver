<?php
/**
 * Сигналинг: 1 хост + несколько зрителей + host_secret для смены устройства
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$roomsDir = __DIR__ . '/rooms';
if (!is_dir($roomsDir)) mkdir($roomsDir, 0755, true);

// Чистим только очень старые (7 дней) — комнаты живут дольше
foreach (glob($roomsDir . '/*.json') as $file) {
    if (filemtime($file) < time() - 86400 * 7) @unlink($file);
}

$action = $_GET['action'] ?? '';
$room   = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['room'] ?? '');
$viewer = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['viewer'] ?? '');

function loadRoom($file) {
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true) ?: null;
}
function saveRoom($file, $data) {
    file_put_contents($file, json_encode($data));
}
function make_secret() {
    return bin2hex(random_bytes(16));
}

// ===== CREATE =====
if ($action === 'create') {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $room = '';
        for ($i = 0; $i < 6; $i++) $room .= $chars[random_int(0, strlen($chars) - 1)];
        $roomFile = $roomsDir . '/' . $room . '.json';
    } while (file_exists($roomFile));

    $secret = make_secret();
    saveRoom($roomFile, [
        'created' => time(),
        'host_secret' => $secret,
        'host_online' => false,
        'viewers' => []
    ]);
    echo json_encode(['room' => $room, 'host_secret' => $secret]);
    exit;
}

// ===== CLAIM HOST (продолжить с другого устройства) =====
if ($action === 'claim_host' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $code = preg_replace('/[^a-zA-Z0-9]/', '', $input['room'] ?? $room);
    $secret = preg_replace('/[^a-zA-Z0-9]/', '', $input['host_secret'] ?? '');
    if (!$code || !$secret) {
        echo json_encode(['error' => 'Нужны код комнаты и секрет хоста']);
        exit;
    }
    $roomFile = $roomsDir . '/' . $code . '.json';
    $data = loadRoom($roomFile);
    if (!$data) {
        echo json_encode(['error' => 'Комната не найдена (истекла или неверный код)']);
        exit;
    }
    if (($data['host_secret'] ?? '') !== $secret) {
        echo json_encode(['error' => 'Неверный секрет хоста']);
        exit;
    }
    // Сбрасываем старых зрителей' offers — новый хост начнёт заново
    $viewers = (array)($data['viewers'] ?? []);
    foreach ($viewers as $vid => $v) {
        $v = (array)$v;
        $v['offer'] = null;
        $v['answer'] = null;
        $v['answer_id'] = 0;
        $viewers[$vid] = $v;
    }
    $data['viewers'] = $viewers;
    $data['host_online'] = true;
    $data['claimed_at'] = time();
    saveRoom($roomFile, $data);
    echo json_encode(['ok' => true, 'room' => $code, 'host_secret' => $secret]);
    exit;
}

if (!$room && !in_array($action, ['create', 'claim_host'], true)) {
    echo json_encode(['error' => 'Нет кода комнаты']);
    exit;
}
$roomFile = $roomsDir . '/' . $room . '.json';

// ===== JOIN =====
if ($action === 'join') {
    $data = loadRoom($roomFile);
    if (!$data) {
        echo json_encode(['error' => 'Комната не найдена']);
        exit;
    }
    $viewerId = '';
    $chars = 'abcdefghijkmnpqrstuvwxyz23456789';
    for ($i = 0; $i < 8; $i++) $viewerId .= $chars[random_int(0, strlen($chars) - 1)];

    $viewers = (array)($data['viewers'] ?? []);
    $viewers[$viewerId] = [
        'joined' => time(),
        'offer' => null,
        'answer' => null,
        'answer_id' => 0
    ];
    $data['viewers'] = $viewers;
    saveRoom($roomFile, $data);
    echo json_encode(['viewer' => $viewerId]);
    exit;
}

// ===== HOST POLL =====
if ($action === 'host_poll') {
    $data = loadRoom($roomFile);
    if (!$data) {
        echo json_encode(['error' => 'Комната не найдена']);
        exit;
    }
    // проверка секрета опциональна (чтобы старый клиент не ломался), но желательна
    $secret = $_GET['host_secret'] ?? '';
    if ($secret && ($data['host_secret'] ?? '') !== $secret) {
        echo json_encode(['error' => 'Другой хост перехватил комнату']);
        exit;
    }

    $viewers = (array)($data['viewers'] ?? []);
    $needOffer = [];
    $hasAnswer = [];
    foreach ($viewers as $vid => $v) {
        $v = (array)$v;
        if (($v['joined'] ?? 0) < time() - 1800 && empty($v['answer']) && empty($v['offer'])) {
            unset($viewers[$vid]);
            continue;
        }
        if (empty($v['offer'])) $needOffer[] = $vid;
        if (!empty($v['answer'])) {
            $hasAnswer[] = [
                'viewer' => $vid,
                'answer' => $v['answer'],
                'answer_id' => $v['answer_id'] ?? 1
            ];
        }
    }
    $data['viewers'] = $viewers;
    $data['host_online'] = true;
    saveRoom($roomFile, $data);
    echo json_encode(['need_offer' => $needOffer, 'answers' => $hasAnswer]);
    exit;
}

// ===== SET OFFER =====
if ($action === 'set_offer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$viewer) { echo json_encode(['error' => 'Нет viewer id']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['sdp'])) { echo json_encode(['error' => 'Нет SDP']); exit; }
    $data = loadRoom($roomFile);
    if (!$data) { echo json_encode(['error' => 'Комната не найдена']); exit; }
    $viewers = (array)($data['viewers'] ?? []);
    if (!isset($viewers[$viewer])) { echo json_encode(['error' => 'Зритель не найден']); exit; }
    $v = (array)$viewers[$viewer];
    $v['offer'] = $input;
    $v['answer'] = null;
    $v['answer_id'] = 0;
    $viewers[$viewer] = $v;
    $data['viewers'] = $viewers;
    saveRoom($roomFile, $data);
    echo json_encode(['ok' => true]);
    exit;
}

// ===== GET OFFER =====
if ($action === 'get_offer') {
    if (!$viewer) { echo json_encode(['error' => 'Нет viewer id']); exit; }
    $data = loadRoom($roomFile);
    if (!$data) { echo json_encode(['error' => 'Комната не найдена']); exit; }
    $viewers = (array)($data['viewers'] ?? []);
    if (!isset($viewers[$viewer])) { echo json_encode(['error' => 'Сначала join']); exit; }
    $v = (array)$viewers[$viewer];
    if (empty($v['offer'])) { echo json_encode(['wait' => true]); exit; }
    echo json_encode(['offer' => $v['offer']]);
    exit;
}

// ===== SET ANSWER =====
if ($action === 'set_answer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$viewer) { echo json_encode(['error' => 'Нет viewer id']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['sdp'])) { echo json_encode(['error' => 'Нет SDP']); exit; }
    $data = loadRoom($roomFile);
    if (!$data) { echo json_encode(['error' => 'Комната не найдена']); exit; }
    $viewers = (array)($data['viewers'] ?? []);
    if (!isset($viewers[$viewer])) { echo json_encode(['error' => 'Зритель не найден']); exit; }
    $v = (array)$viewers[$viewer];
    $v['answer'] = $input;
    $v['answer_id'] = ($v['answer_id'] ?? 0) + 1;
    $viewers[$viewer] = $v;
    $data['viewers'] = $viewers;
    saveRoom($roomFile, $data);
    echo json_encode(['ok' => true]);
    exit;
}

// ===== ACK ANSWER =====
if ($action === 'ack_answer') {
    if (!$viewer) { echo json_encode(['error' => 'Нет viewer id']); exit; }
    $data = loadRoom($roomFile);
    if ($data) {
        $viewers = (array)($data['viewers'] ?? []);
        if (isset($viewers[$viewer])) {
            $v = (array)$viewers[$viewer];
            $v['answer'] = null;
            $viewers[$viewer] = $v;
            $data['viewers'] = $viewers;
            saveRoom($roomFile, $data);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ===== LEAVE =====
if ($action === 'leave') {
    if (!$viewer) { echo json_encode(['error' => 'Нет viewer id']); exit; }
    $data = loadRoom($roomFile);
    if ($data) {
        $viewers = (array)($data['viewers'] ?? []);
        unset($viewers[$viewer]);
        $data['viewers'] = $viewers;
        saveRoom($roomFile, $data);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'Неизвестное действие']);
