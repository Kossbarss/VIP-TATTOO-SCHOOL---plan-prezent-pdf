# VIP Tattoo — План курсу (плагін)

WordPress-плагін, що публікує інтерактивну 23-слайдову презентацію плану курсу як окрему, повністю незалежну сторінку (без хедера/футера/меню теми) — за тим самим принципом, що й основний плагін лендингу `vip-tattoo-landing`. Кнопка «Открыть доступ к обучению» веде напряму на готове посилання Stripe Payment Link — оплату повністю приймає Stripe, плагін лише фіксує клік.

## Встановлення

1. Заархівуй папку `vip-tattoo-plan-viewer/` у `.zip`.
2. WordPress admin → «Плагіни» → «Додати новий» → «Завантажити плагін» → обери zip → «Встановити» → «Активувати».
3. Створи нову сторінку («Сторінки» → «Додати нову»), у боковій панелі «Атрибути сторінки» → «Шаблон» обери **«VIP Tattoo — План курсу (презентація)»**, опублікуй.

## Налаштування

В адмінці зліва з'явиться пункт меню **«VIP Tattoo План»**:

- **Оплата і кнопки** — посилання на Stripe Payment Link (кнопка «Открыть доступ к обучению»), текст обох кнопок, посилання другої кнопки (особистий Telegram).
- **SEO** — title, description, Open Graph, canonical, robots, JSON-LD Course schema.
- **Піксели / аналітика** — GA4, Google Tag Manager, Meta (Facebook) Pixel, TikTok Pixel, Pinterest Tag, LinkedIn Insight Tag. Кожен вмикається лише якщо заповнене відповідне поле.
- **Вебхук** — на кожну подію (перегляд/клік оплати/клік «Написати») шле підписаний (HMAC-SHA256) POST-запит на вказаний URL (Zapier / Make / власна CRM).
- **Telegram-сповіщення** — надсилає повідомлення адміну в Telegram при кожному кліку на кнопку оплати.

## Архітектура (коротко)

- `vip-tattoo-plan-viewer.php` — реєстрація шаблону сторінки, SEO/пікселі head-виводу, підстановка плейсхолдерів у `content/body-plan.html`.
- `includes/settings.php` — адмін-сторінка налаштувань (nonce + санітизація всіх полів).
- `includes/tracking.php` — таблиця подій, REST-роут `/vip-tattoo-plan/v1/track`, ідемпотентний інсерт (UNIQUE KEY на visit_token+event_type), вихідний вебхук, Telegram-нотифікація.
- `templates/vip-tattoo-plan-viewer.php` — сама сторінка (повністю самодостатня, без залежності від теми).
- `content/body-plan.html` — розмітка усіх 23 слайдів з плейсхолдерами (`{{ASSET_URL}}`, `{{STRIPE_CHECKOUT_URL}}`, `{{CTA_PRIMARY_TEXT}}`, `{{CTA_SECONDARY_TEXT}}`, `{{CONTACT_URL}}`).
- `css/plan-viewer.css` — стилі презентації (ідентичні `docs/index.html`).
- `js/track.js` — клієнтський трекер (view/checkout_click/contact_click події + піксель-виклики).
- `assets/slides/`, `assets/icons/` — зображення слайдів та іконок, вбудовані в плагін (не залежить від папки `docs/`).

Ця гілка (`wp-plugin-development`) розробляється незалежно від гілки `pdf-plan-development` — зміни тут не зачіпають сам PDF-файл в іншій гілці.
