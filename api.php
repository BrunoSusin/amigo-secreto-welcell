<?php
// Amigo Secreto da Welcell - backend simples (PHP, sem banco de dados)
// >>> TROQUE O PIN ABAIXO antes de subir para a hospedagem <<<
const ADMIN_PIN = 'troque-este-pin';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
}
$file = $dir . '/estado.json';

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function with_state($file, $fn) {
    $fp = fopen($file, 'c+');
    if (!$fp) out(['error' => 'Sem permissão para gravar na pasta data.'], 500);
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $s = json_decode($raw ?: '', true);
    if (!is_array($s)) $s = ['phase' => 'open', 'people' => []];
    $res = $fn($s);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($s, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $res;
}

function norm($n) {
    $n = mb_strtolower(trim($n), 'UTF-8');
    if (class_exists('Normalizer')) $n = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($n, Normalizer::FORM_D));
    return $n;
}
function pretty($n) {
    $n = trim(preg_replace('/\s+/u', ' ', $n));
    return mb_convert_case($n, MB_CASE_TITLE, 'UTF-8');
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? 'state');

function need_admin($in) {
    $pin = (string)($in['pin'] ?? '');
    if (!hash_equals(ADMIN_PIN, $pin)) out(['error' => 'PIN do organizador incorreto.'], 403);
}

switch ($action) {
    case 'state':
        out(with_state($file, function (&$s) {
            $drawn = 0;
            return [
                'phase' => $s['phase'],
                'names' => array_map(fn($p) => $p['name'], $s['people']),
            ];
        }));

    case 'join':
        $name = pretty((string)($in['name'] ?? ''));
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 40) out(['error' => 'Digite um nome válido.'], 400);
        out(with_state($file, function (&$s) use ($name) {
            if ($s['phase'] !== 'open') return ['error' => 'O sorteio já começou.'];
            foreach ($s['people'] as $p) if (norm($p['name']) === norm($name)) return ['error' => 'Já tem alguém com esse nome. Use o sobrenome também.'];
            $token = bin2hex(random_bytes(16));
            $s['people'][] = ['name' => $name, 'token' => $token, 'friend' => null];
            return ['token' => $token, 'name' => $name];
        }));

    case 'me':
        $token = (string)($in['token'] ?? '');
        out(with_state($file, function (&$s) use ($token) {
            foreach ($s['people'] as $p) {
                if (hash_equals($p['token'], $token)) {
                    return ['name' => $p['name'], 'phase' => $s['phase'], 'friend' => $s['phase'] === 'drawing' ? $p['friend'] : null];
                }
            }
            return ['error' => 'not_found'];
        }));

    case 'admin_check':
        need_admin($in); out(['ok' => true]);

    case 'admin_start':
        need_admin($in);
        out(with_state($file, function (&$s) {
            $n = count($s['people']);
            if ($n < 3) return ['error' => 'Precisa de pelo menos 3 participantes.'];
            $idx = range(0, $n - 1); shuffle($idx);
            for ($i = 0; $i < $n; $i++) {
                $s['people'][$idx[$i]]['friend'] = $s['people'][$idx[($i + 1) % $n]]['name'];
            }
            $s['phase'] = 'drawing';
            return ['ok' => true];
        }));

    case 'admin_reset':
        need_admin($in);
        out(with_state($file, function (&$s) {
            $s['phase'] = 'open';
            foreach ($s['people'] as &$p) $p['friend'] = null;
            return ['ok' => true];
        }));

    case 'admin_remove':
        need_admin($in);
        $name = (string)($in['name'] ?? '');
        out(with_state($file, function (&$s) use ($name) {
            if ($s['phase'] !== 'open') return ['error' => 'Refaça o sorteio antes de remover alguém.'];
            $s['people'] = array_values(array_filter($s['people'], fn($p) => $p['name'] !== $name));
            return ['ok' => true];
        }));

    case 'admin_clear':
        need_admin($in);
        out(with_state($file, function (&$s) { $s = ['phase' => 'open', 'people' => []]; return ['ok' => true]; }));

    default:
        out(['error' => 'Ação inválida.'], 400);
}
