<?php
// Recibe el formulario de contacto de index.html y lo envía por correo.
// Reemplaza a Netlify Forms: el sitio está en Hostinger, que no procesa formularios solo.
// Responde JSON; el front solo mira si el código HTTP es 2xx.

const DESTINO = 'contactos@i-core.com.ar';
const REMITENTE = 'web@icoregroup.tech';
const INTERESES = ['Nodo', 'Plexo', 'Otro proyecto'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, string $estado): void {
    http_response_code($codigo);
    echo json_encode(['estado' => $estado]);
    exit;
}

function campo(string $nombre, int $max): string {
    $valor = trim((string)($_POST[$nombre] ?? ''));
    return mb_substr($valor, 0, $max);
}

// Para encabezados: sin saltos de línea, así nadie puede inyectar destinatarios.
function linea(string $valor): string {
    return trim(preg_replace('/[\r\n]+/', ' ', $valor));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, 'metodo_no_permitido');
}

// Campo trampa: las personas no lo ven; si viene lleno es un bot. Se responde OK para no darle pistas.
if (campo('bot-field', 200) !== '') {
    responder(200, 'ok');
}

$interes = campo('interest', 40);
$nombre = linea(campo('name', 120));
$org = linea(campo('org', 160));
$email = linea(campo('email', 200));
$tel = linea(campo('tel', 40));
$mensaje = campo('message', 5000);

// Mismas reglas que valida el formulario en el navegador.
if (!in_array($interes, INTERESES, true)) $interes = 'Contacto';
if (mb_strlen($nombre) < 3 || mb_strlen($org) < 2 || mb_strlen($mensaje) < 10
    || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(400, 'datos_invalidos');
}

// Límite simple: un envío cada 30 segundos por IP.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'sin-ip';
$marca = sys_get_temp_dir() . '/icore-contacto-' . md5($ip);
if (is_file($marca) && time() - filemtime($marca) < 30) {
    responder(429, 'demasiados_envios');
}

$asunto = 'Consulta web sobre ' . $interes . ' · ' . $org;
$cuerpo = "Interés: $interes\n"
    . "Nombre: $nombre\n"
    . "Organización: $org\n"
    . "Correo: $email\n"
    . ($tel !== '' ? "Teléfono: $tel\n" : '')
    . "\n$mensaje\n\n"
    . "--\nEnviado desde el formulario de icoregroup.tech";

$encabezados = implode("\r\n", [
    'From: iCore web <' . REMITENTE . '>',
    'Reply-To: =?UTF-8?B?' . base64_encode($nombre) . '?= <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

$enviado = mail(DESTINO, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $encabezados, '-f' . REMITENTE);

if (!$enviado) {
    responder(500, 'no_enviado');
}

@touch($marca);
responder(200, 'ok');
