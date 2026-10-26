<?php
/**
 * Приём заявок с лендинга Schengen Visa (World Pass).
 * 1) Письмо на почту менеджера (если задана в config.php).
 * 2) Сделка в Kommo через API напрямую (если задан токен): контакт с телефоном,
 *    все остальные поля формы — одним примечанием (сообщением) в сделке.
 * 3) Лог в leads.log рядом (на случай сбоев почты/Kommo).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$cfg = file_exists(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : [];
$MAIL_TO   = $cfg['mail_to']   ?? '';
$MAIL_FROM = $cfg['mail_from'] ?? ('leads@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$KOMMO     = $cfg['kommo']     ?? null;
$TG        = $cfg['telegram']  ?? (file_exists(__DIR__ . '/telegram.php') ? include __DIR__ . '/telegram.php' : null);
$SITE      = 'https://' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'schengen') . '/';

$f = fn($k) => trim(mb_substr((string)($_POST[$k] ?? ''), 0, 500));
$name        = $f('fname');
$phone       = preg_replace('/[^\d+ ]/', '', $f('phone'));
$destination = $f('destination');
$dates       = $f('dates');
$prev        = $f('prev');
$alt         = $f('alt');
$lang        = $f('lang');
$service     = $f('service') ?: 'Schengen Visa';
$form_name   = $f('form') ?: 'Schengen — Consultation Request';
$url         = $f('url');
$utm = [];
foreach (['utm_source','utm_campaign','utm_medium','utm_term','utm_content'] as $u) if ($f($u) !== '') $utm[$u] = $f($u);

// защита от ботов: пустое имя/телефон или honeypot
if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 7 || !empty($_POST['website'])) {
  http_response_code(400); echo '{"ok":false,"error":"invalid"}'; exit;
}

$when = date('Y-m-d H:i:s');
$ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$lines = [
  "Новая заявка — $service",
  "Форма: $form_name",
  "Имя: $name",
  "Телефон: $phone",
  "Доп. связь: " . ($alt ?: '—'),
  "Страна поездки: " . ($destination ?: '—'),
  "Даты поездки: " . ($dates ?: '—'),
  "Был ли Шенген ранее: " . ($prev ?: '—'),
  "Страница: $url",
  "Язык формы: " . ($lang ?: '—'),
  $utm ? 'UTM: ' . http_build_query($utm, '', ', ') : 'UTM: —',
  "Время: $when (сервер), IP: $ip",
];
$body = implode("\n", $lines);

// 3) лог
@file_put_contents(__DIR__ . '/leads.log', str_replace("\n", ' | ', $body) . "\n", FILE_APPEND | LOCK_EX);

// 1) письмо
$mailed = null;
if ($MAIL_TO !== '') {
  $subject = '=?UTF-8?B?' . base64_encode("Заявка Schengen: $name, $phone") . '?=';
  $headers = "From: World Pass Leads <$MAIL_FROM>\r\nReply-To: $MAIL_FROM\r\nContent-Type: text/plain; charset=UTF-8\r\n";
  $mailed = @mail($MAIL_TO, $subject, $body, $headers);
}

// ---------- Kommo ----------
function kommo_call(array $k, string $method, string $path, $payload = null) {
  $ch = curl_init("https://{$k['subdomain']}.kommo.com/api/v4/$path");
  $opt = [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $k['token']],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
  ];
  if ($payload !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
  curl_setopt_array($ch, $opt);
  $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, json_decode((string)$resp, true), (string)$resp];
}

/** Уведомление в Telegram (копия каждой заявки + статус Kommo) */
function tg_send(?array $tg, string $html): void {
  if (!$tg || empty($tg['token']) || empty($tg['chat_id'])) return;
  $ch = curl_init("https://api.telegram.org/bot{$tg['token']}/sendMessage");
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $tg['chat_id'], 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true])]);
  $r = curl_exec($ch); curl_close($ch);
  if (!$r || strpos((string)$r, '"ok":true') === false) @file_put_contents(__DIR__ . '/leads.log', "TELEGRAM ERROR: " . substr((string)$r, 0, 200) . "\n", FILE_APPEND | LOCK_EX);
}

$kommo_ok = null; $kommo_lead_id = null; $kommo_err = '';
if ($KOMMO && !empty($KOMMO['token']) && !empty($KOMMO['subdomain'])) {
  $lead = [ 'name' => "$service — $name" . ($destination ? " ($destination)" : '') ];
  if (!empty($KOMMO['tags'])) $lead['_embedded'] = ['tags' => array_map(fn($t) => ['name' => $t], $KOMMO['tags'])];

  // «Неразобранное» (Incoming leads): сделка без ответственного, менеджер принимает её сам
  $host = parse_url($SITE, PHP_URL_HOST) ?: 'schengen';
  $payload = [[
    'source_uid'  => $host . '-' . bin2hex(random_bytes(8)),
    'source_name' => $host,
    'pipeline_id' => (int)($KOMMO['pipeline_id'] ?? 0) ?: null,
    'created_at'  => time(),
    'metadata' => [
      'form_id'      => 'schengen_landing',
      'form_name'    => $form_name,
      'form_page'    => $url ?: $SITE,
      'ip'           => $ip ?: '0.0.0.0',
      'form_sent_at' => time(),
      'referer'      => $url ?: $SITE,
    ],
    '_embedded' => [
      'leads'    => [$lead],
      'contacts' => [[
        'name' => $name,
        'custom_fields_values' => [[ 'field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']] ]],
      ]],
    ],
  ]];
  $payload[0] = array_filter($payload[0], fn($v) => $v !== null);

  [$code, $data, $raw] = kommo_call($KOMMO, 'POST', 'leads/unsorted/forms', $payload);
  $kommo_ok = ($code >= 200 && $code < 300);
  $kommo_err = $kommo_ok ? '' : "HTTP $code: " . substr(preg_replace('/\s+/', ' ', strip_tags((string)$raw)), 0, 160);
  if ($kommo_ok) {
    // все поля формы кроме имени и телефона — сообщением (примечанием) в сделку
    $lead_id = $data['_embedded']['unsorted'][0]['_embedded']['leads'][0]['id'] ?? null;
    $kommo_lead_id = $lead_id;
    if ($lead_id) kommo_call($KOMMO, 'POST', "leads/$lead_id/notes", [[ 'note_type' => 'common', 'params' => ['text' => $body] ]]);
  } else {
    @file_put_contents(__DIR__ . '/leads.log', "KOMMO ERROR $code: " . substr($raw, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
  }
}

// ---------- Telegram: копия заявки ----------
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$kline = $kommo_ok === null ? '⚪️ Kommo: не настроен'
       : ($kommo_ok ? '✅ Kommo: сделка #' . ($kommo_lead_id ?? '?') . ' создана' : '❌ Kommo: НЕ создана — ' . $h($kommo_err));
$utmline = $utm ? ($h($utm['utm_source'] ?? '—') . ' / ' . $h($utm['utm_campaign'] ?? '—')) : 'прямой заход';
tg_send($TG,
  "🇪🇺 <b>Schengen — новая заявка</b>\n" .
  "<b>Имя:</b> " . $h($name) . "\n" .
  "<b>Телефон:</b> " . $h($phone) . "\n" .
  ($alt !== '' ? "<b>Доп. связь:</b> " . $h($alt) . "\n" : '') .
  ($destination !== '' ? "<b>Страна поездки:</b> " . $h($destination) . "\n" : '') .
  ($dates !== '' ? "<b>Даты:</b> " . $h($dates) . "\n" : '') .
  ($prev !== '' ? "<b>Был Шенген:</b> " . $h($prev) . "\n" : '') .
  "<b>Форма:</b> " . $h($form_name) . "\n" .
  "<b>Источник:</b> " . $utmline . "\n" .
  ($mailed === null ? '' : ($mailed ? "📧 Письмо отправлено\n" : "⚠️ Письмо НЕ отправлено\n")) .
  $kline);

echo json_encode(['ok' => true, 'mail' => $mailed, 'kommo' => $kommo_ok]);
