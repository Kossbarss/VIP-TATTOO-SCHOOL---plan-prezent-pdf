# VIP Tattoo — План курсу (плагін)

WordPress-плагін, що публікує PDF-презентацію `plan-kursu.pdf` як окрему, повністю незалежну сторінку (без хедера/футера/меню теми) — за тим самим принципом, що й основний плагін лендингу `vip-tattoo-landing`.

## Встановлення

1. Заархівуй папку `vip-tattoo-plan-viewer/` у `.zip`.
2. WordPress admin → «Плагіни» → «Додати новий» → «Завантажити плагін» → обери zip → «Встановити» → «Активувати».
3. Створи нову сторінку («Сторінки» → «Додати нову»), у боковій панелі «Атрибути сторінки» → «Шаблон» обери **«VIP Tattoo — План курсу (презентація)»**, опублікуй.

## Налаштування

В адмінці зліва з'явиться пункт меню **«VIP Tattoo План»**:

- **PDF і кнопки** — за замовчуванням використовується файл, вбудований у плагін (`assets/plan-kursu.pdf`). Щоб підмінити на власний — заванантаж PDF у «Медіафайли», встав ID вкладення.
- **SEO** — title, description, Open Graph, canonical, robots, JSON-LD Course schema.
- **Піксели / аналітика** — GA4, Google Tag Manager, Meta (Facebook) Pixel, TikTok Pixel, Pinterest Tag, LinkedIn Insight Tag. Кожен вмикається лише якщо заповнене відповідне поле.
- **Вебхук** — на кожен перегляд/завантаження сторінки шле підписаний (HMAC-SHA256) POST-запит на вказаний URL (Zapier / Make / власна CRM).
- **Telegram-сповіщення** — надсилає повідомлення адміну в Telegram при кожному завантаженні PDF.

## Архітектура (коротко)

- `vip-tattoo-plan-viewer.php` — реєстрація шаблону сторінки, SEO/пікселі head-виводу.
- `includes/settings.php` — адмін-сторінка налаштувань (nonce + санітизація всіх полів).
- `includes/tracking.php` — таблиця подій, REST-роут `/vip-tattoo-plan/v1/track`, ідемпотентний інсерт (UNIQUE KEY на visit_token+event_type), вихідний вебхук, Telegram-нотифікація.
- `templates/vip-tattoo-plan-viewer.php` — сама сторінка (повністю самодостатня, без залежності від теми).
- `js/track.js` — клієнтський трекер (view/download події + піксель-виклики).
- `assets/plan-kursu.pdf` — вбудована копія презентації (fallback).

Ця гілка (`wp-plugin-development`) розробляється незалежно від гілки `pdf-plan-development` — зміни тут не зачіпають сам PDF-файл в іншій гілці.
