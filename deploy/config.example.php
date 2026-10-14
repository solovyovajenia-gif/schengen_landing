<?php
// Настройки приёма заявок (Schengen). Скопировать в config.php и заполнить.
// Файл config.php закрыт от веба через .htaccess и не попадает в git.
return [
  'mail_to'   => '',                 // почта менеджера — будет добавлена
  'mail_from' => 'leads@DOMAIN.ae',  // подставить домен сайта

  // Kommo напрямую: Настройки → Интеграции → Создать интеграцию → Долгосрочный токен.
  'kommo' => [
    'subdomain'   => '',   // например 'worldpass'
    'token'       => '',   // долгосрочный токен
    'pipeline_id' => 0,    // 0 — основная воронка
    'tags'        => ['schengen', 'Schengen Visa'],
  ],
];
