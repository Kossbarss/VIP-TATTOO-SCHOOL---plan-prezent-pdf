;(function () {
  'use strict'

  if (window.__vipTattooCoreLoaded) return
  window.__vipTattooCoreLoaded = true

  const isUk = window.VIP_TATTOO_LOCALE === 'uk' || document.documentElement.lang === 'uk'
  const contacts = {
    instagram: 'https://www.instagram.com/viktoriia_ponikarova?igsh=MWZya3dvY215dGFocA==',
    telegram: 'https://t.me/+48733341364',
    mentorship: 'https://t.me/mentor_tatoo_Viktoria_bot',
  }
  const copy = isUk ? {
    order: 'Купити курс і отримати всі 4 бонуси',
    popupOrder: 'Отримати доступ',
    instagram: 'Instagram Вікторії Понікарової',
    telegram: 'Telegram-адміністратор VIP tattoo school',
    carousel: 'Фотогалерея навчання VIP tattoo school',
    previousCase: 'Попередній кейс',
    nextCase: 'Наступний кейс',
  } : {
    order: 'Купить курс и получить все 4 бонуса',
    popupOrder: 'Получить доступ',
    instagram: 'Instagram Виктории Поникаровой',
    telegram: 'Telegram-администратор VIP tattoo school',
    carousel: 'Фотогалерея обучения VIP tattoo school',
    previousCase: 'Предыдущий кейс',
    nextCase: 'Следующий кейс',
  }

  function setExternalLink(link, href, label) {
    if (!link) return
    link.href = href
    link.target = '_blank'
    link.rel = 'noopener noreferrer'
    if (label) link.setAttribute('aria-label', label)
  }

  function focusableElements(container) {
    return [...container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])')]
      .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true')
  }

  function trapFocus(event, container) {
    if (event.key !== 'Tab') return
    const focusable = focusableElements(container)
    if (!focusable.length) return
    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  window.VIP_TATTOO_APP = { isUk, contacts, copy, setExternalLink, focusableElements, trapFocus }

  document.querySelectorAll('a[aria-label="Instagram"]').forEach((link) => setExternalLink(link, contacts.instagram, copy.instagram))
  document.querySelectorAll('a[aria-label="Telegram"]').forEach((link) => setExternalLink(link, contacts.telegram, copy.telegram))
  document.querySelectorAll('.lang-toggle-opt.is-active[href="#"]').forEach((link) => {
    link.href = window.location.href.split('#')[0]
    link.setAttribute('aria-current', 'page')
  })

  // .order-form is the standalone "Заполни форму..." section CTA -- it
  // always just opens the popup (site-interactions.js wires up the
  // actual popup-open behaviour on any [data-popup-open] element), on
  // both the static build and WordPress. The popup is the single entry
  // point either way; only what happens once *inside* it differs.
  document.querySelectorAll('.order-form').forEach((form) => {
    form.removeAttribute('onsubmit')
    form.classList.add('popup-order-form')
    form.setAttribute('aria-label', copy.order)
    form.replaceChildren()

    const orderButton = document.createElement('button')
    orderButton.type = 'button'
    orderButton.className = 'btn btn-stardust btn-block'
    orderButton.setAttribute('data-popup-open', '')
    const orderText = document.createElement('span')
    orderText.className = 'btn-stardust-wrap'
    orderText.textContent = copy.order
    orderButton.appendChild(orderText)

    form.append(orderButton)
    form.addEventListener('submit', (event) => event.preventDefault())
  })

  // On the static build (no real backend), .popup-form's submit button
  // gets swapped for a styled link straight to Telegram -- there's
  // nothing to submit to. window.VIP_TATTOO_REST_URL is only set on
  // the WordPress build (see vip_tattoo_render_globals() in the
  // plugin), where vip-payments.js wires the popup form's real submit
  // event to a Stripe Checkout session instead. Skip the static-only
  // swap there, or vip-payments.js's submit listener never fires --
  // the button gets removed before it can attach.
  if (!window.VIP_TATTOO_REST_URL) {
    document.querySelectorAll('.popup-form').forEach((form) => {
      form.removeAttribute('onsubmit')
      form.classList.add('direct-order-form')
      form.setAttribute('aria-label', copy.popupOrder)
      form.querySelector('button[type="submit"]')?.remove()

      const orderLink = document.createElement('a')
      orderLink.className = 'btn btn-stardust btn-block'
      const orderText = document.createElement('span')
      orderText.className = 'btn-stardust-wrap'
      orderText.textContent = copy.popupOrder
      orderLink.appendChild(orderText)
      setExternalLink(orderLink, contacts.mentorship, copy.popupOrder)

      form.append(orderLink)
      form.addEventListener('submit', (event) => event.preventDefault())
    })
  }

  const hero = document.querySelector('.hero')
  const stickyBar = document.getElementById('stickyBar')
  if (stickyBar) {
    if (hero && 'IntersectionObserver' in window) {
      new IntersectionObserver(([entry]) => stickyBar.classList.toggle('is-visible', !entry.isIntersecting), { threshold: 0.04 }).observe(hero)
    } else {
      stickyBar.classList.add('is-visible')
    }
  }


  const ribbonTrack = document.getElementById('ribbonTrack')
  if (ribbonTrack) {
    const items = isUk
      ? ['8 тижнів навчання', '40+ годин практики', '300+ випускників', 'Сертифікат VIP Tattoo School', 'Довічний доступ до записів', '22+ країн, де цінують роботи', 'Особистий фідбек від куратора', 'Практика на моделях']
      : ['8 недель обучения', '40+ часов практики', '300+ выпускников', 'Сертификат VIP Tattoo School', 'Пожизненный доступ к записям', '22+ стран, где ценят работы', 'Личная обратная связь от куратора', 'Практика на моделях']
    // Nesting depth is independent of language -- UA is the root page and
    // RU lives under /ru/, so this has to key off the URL, not isUk.
    const assetsPrefix =
      window.VIP_TATTOO_ASSET_BASE ||
      (/\/ru(?:\/|$)/.test(location.pathname) ? '../assets/' : 'assets/')
    ribbonTrack.replaceChildren()
    ;[...items, ...items].forEach((text) => {
      const item = document.createElement('span')
      item.append(document.createTextNode(text))
      const dot = document.createElement('span')
      dot.className = 'dot'
      const dotLogo = document.createElement('img')
      dotLogo.src = `${assetsPrefix}logo-mark-black.png`
      dotLogo.alt = ''
      dotLogo.setAttribute('aria-hidden', 'true')
      dotLogo.width = 28
      dotLogo.height = 28
      dot.appendChild(dotLogo)
      item.appendChild(dot)
      ribbonTrack.appendChild(item)
    })
  }

  const topicsTrack = document.getElementById('topicsTrack')
  if (topicsTrack && !topicsTrack.dataset.duplicated) {
    topicsTrack.dataset.duplicated = 'true'
    ;[...topicsTrack.children].forEach((child) => topicsTrack.appendChild(child.cloneNode(true)))
  }
})()
