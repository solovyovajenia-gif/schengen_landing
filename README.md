# Schengen Visa landing — World Pass

Статический лендинг по ТЗ «Шенген тз сайт», сделан по структуре и дизайн-системе usatravel.ae.

## Файлы
- `index.html` — главная (EN, один файл, CSS/JS инлайн)
- `privacy.html`, `terms.html`, `refund.html`, `disclaimer.html`, `cookies.html` — юридические страницы
- `img/` — стоковые фото (Unsplash), сжаты; `logo.png` — логотип World Pass
- `deploy/send.php` — приём заявок: письмо + сделка в Kommo (имя и телефон — в контакт, остальные поля — примечанием в сделку) + `leads.log`
- `deploy/config.example.php` → скопировать в `deploy/config.php` на хостинге и заполнить
- `deploy/.htaccess` — https, без www, закрыты config/log

## Деплой
Залить в корень хостинга: `index.html`, все `*.html`, `img/`, `logo.png`, содержимое `deploy/` (send.php, config.php, .htaccess).
Формы шлют POST на `send.php` относительно корня сайта.

## Что ещё не заполнено
- Email компании: на странице стоит «will be added» (Contact и юрстраницы)
- Домен: не задан (в `.htaccess` редирект на текущий host, в `config.php` — `mail_from`)
- Аналитика (GTM/GA4/Метрика/Pixel): не подключена, добавить в `<head>` и в обработчик submit, когда будут ID
- Kommo: токен и subdomain в `config.php`

## Локальный просмотр
```bash
python3 -m http.server 8766
```
src/index.html — исходник со ссылками на img/
