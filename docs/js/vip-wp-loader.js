;(function () {
  'use strict'

  // window.VIP_TATTOO_LOCALE / VIP_TATTOO_ASSET_BASE / VIP_TATTOO_JS_BASE
  // are set by an inline <script> the plugin template prints right after
  // <body>, before any of the dist/*.js React bundles run -- those bundles
  // read VIP_TATTOO_ASSET_BASE synchronously at mount time, so it has to
  // exist before they execute, not after. This file replaces the static
  // build's script.js + particles.js loader chain, which guessed paths
  // from the page URL (only worked because GitHub Pages always serves the
  // UA page at a path containing "/ua/" -- not true on WordPress, where a
  // page can live at any slug).
  var jsBase = window.VIP_TATTOO_JS_BASE || ''

  var appModules = ['site-core.js', 'site-interactions.js']
  var effectModules = ['pricing-reference.js']
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (!reducedMotion) effectModules.push('legacy-effects.js')

  function loadSequence(files, onDone) {
    function step(index) {
      if (index >= files.length) {
        onDone()
        return
      }
      var script = document.createElement('script')
      script.src = jsBase + files[index] + '?v=2'
      script.async = false
      script.addEventListener('load', function () { step(index + 1) }, { once: true })
      script.addEventListener('error', function () {
        console.error('[VIP Tattoo] Failed to load module:', files[index])
        step(index + 1)
      }, { once: true })
      document.head.appendChild(script)
    }
    step(0)
  }

  loadSequence(appModules, function () {
    window.__vipTattooAppReady = true
    document.dispatchEvent(new CustomEvent('vip:app-ready'))
    loadSequence(effectModules, function () {
      window.__vipTattooEffectsLoaded = true
    })
  })
})()
