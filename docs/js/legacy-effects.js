// Footer logo particle animation and hero gallery border.
// Restored from the last working implementation. The old levelCards loop is intentionally excluded.

;(function () {
  const canvas = document.getElementById('logoParticles')
  if (!canvas) return

  const ctx = canvas.getContext('2d')
  if (!ctx) return

  const size = 300
  const dpr = Math.min(window.devicePixelRatio || 1, 2)
  canvas.width = size * dpr
  canvas.height = size * dpr
  canvas.style.width = size + 'px'
  canvas.style.height = size + 'px'
  ctx.scale(dpr, dpr)

  const palette = ['#c9a24a', '#e8d5a8', '#ff0003', '#dd0003']
  let time = 0
  let particles = []
  let shockwaves = []
  let nextPulseAt = 3
  let globeRadius = 135
  let rafId = null

  const SPIN_SPEED = 0.45
  const FACE_OFFSETS = [0, Math.PI]
  const DISINTEGRATION_CYCLE = 10
  const STABLE_END = 0.55
  const DIS_FULL = STABLE_END + 0.15
  const HOLD_END = DIS_FULL + 0.1

  function hexToRgb(hex) {
    const value = parseInt(hex.slice(1), 16)
    return {
      r: (value >> 16) & 255,
      g: (value >> 8) & 255,
      b: value & 255,
    }
  }

  function lerpColor(colors, progress) {
    const count = colors.length
    const scaled = ((((progress % 1) + 1) % 1) * count)
    const firstIndex = Math.floor(scaled) % count
    const secondIndex = (firstIndex + 1) % count
    const amount = scaled - Math.floor(scaled)
    const first = hexToRgb(colors[firstIndex])
    const second = hexToRgb(colors[secondIndex])

    return {
      r: first.r + (second.r - first.r) * amount,
      g: first.g + (second.g - first.g) * amount,
      b: first.b + (second.b - first.b) * amount,
    }
  }

  function sampleLogoPoints(image, count) {
    const offscreen = document.createElement('canvas')
    offscreen.width = 200
    offscreen.height = 200

    const offscreenContext = offscreen.getContext('2d')
    if (!offscreenContext) return []

    offscreenContext.drawImage(image, 0, 0, 200, 200)
    const data = offscreenContext.getImageData(0, 0, 200, 200).data
    const points = []

    for (let y = 0; y < 200; y += 1) {
      for (let x = 0; x < 200; x += 1) {
        const alpha = data[(y * 200 + x) * 4 + 3]
        if (alpha > 80) {
          points.push({
            x: (x - 100) * 1.35,
            y: (y - 100) * 1.35,
          })
        }
      }
    }

    if (!points.length) return []

    for (let index = points.length - 1; index > 0; index -= 1) {
      const randomIndex = Math.floor(Math.random() * (index + 1))
      const temporary = points[index]
      points[index] = points[randomIndex]
      points[randomIndex] = temporary
    }

    const selected = []
    for (let index = 0; index < count; index += 1) {
      selected.push(points[index % points.length])
    }
    return selected
  }

  function initParticles(points, count) {
    if (!points.length) return

    globeRadius = points.reduce((maximum, point) => Math.max(maximum, Math.abs(point.x)), 1)
    particles = []

    FACE_OFFSETS.forEach((faceOffset, faceIndex) => {
      points.forEach((point, index) => {
        const angle = Math.random() * Math.PI * 2
        const distance = 60 + Math.random() * 110
        const disintegrationStrength = 40 + Math.random() * 55
        const disintegrationAngle = Math.random() * Math.PI * 2

        particles.push({
          homeX: point.x,
          homeY: point.y,
          baseAngle: Math.asin(Math.max(-1, Math.min(1, point.x / globeRadius))),
          faceOffset,
          x: point.x + Math.cos(angle) * distance,
          y: point.y + Math.sin(angle) * distance,
          size: 1.2 + Math.random() * 1.7,
          colorOffset: Math.random(),
          seed: faceIndex * count + index,
          cycleOffset: (index / count) * DISINTEGRATION_CYCLE * 0.5,
          disintegrationOffsetX: Math.cos(disintegrationAngle) * disintegrationStrength,
          disintegrationOffsetY: Math.sin(disintegrationAngle) * disintegrationStrength,
        })
      })
    })
  }

  function triggerShockwave(amplitude) {
    shockwaves.push({
      startedAt: time,
      amplitude,
      speed: 55,
      width: 18,
      decay: 1.15,
    })
    if (shockwaves.length > 5) shockwaves.shift()
  }

  function drawFrame() {
    rafId = requestAnimationFrame(drawFrame)
    time += 0.02

    if (time >= nextPulseAt) {
      triggerShockwave(28)
      nextPulseAt = time + 4.5 + Math.random() * 1.5
    }

    const spinPhase = time * SPIN_SPEED
    ctx.clearRect(0, 0, size, size)
    ctx.save()
    ctx.translate(size / 2, size / 2)
    ctx.globalCompositeOperation = 'lighter'
    shockwaves = shockwaves.filter((shockwave) => time - shockwave.startedAt < 4)

    particles.forEach((particle) => {
      const globeAngle = particle.baseAngle + spinPhase + particle.faceOffset
      const globeX = globeRadius * Math.sin(globeAngle)
      const depth = Math.cos(globeAngle)
      if (depth <= 0) return

      const visibility = depth
      const cycleProgress = ((time * 0.6 + particle.cycleOffset) % DISINTEGRATION_CYCLE) / DISINTEGRATION_CYCLE
      let disintegration = 0

      if (cycleProgress < STABLE_END) disintegration = 0
      else if (cycleProgress < DIS_FULL) disintegration = (cycleProgress - STABLE_END) / (DIS_FULL - STABLE_END)
      else if (cycleProgress < HOLD_END) disintegration = 1
      else disintegration = 1 - (cycleProgress - HOLD_END) / (1 - HOLD_END)

      disintegration = Math.sin(disintegration * Math.PI * 0.5)

      const distance = Math.sqrt(globeX * globeX + particle.homeY * particle.homeY) + 1e-6
      let additionalX = 0
      let additionalY = 0

      shockwaves.forEach((shockwave) => {
        const elapsed = time - shockwave.startedAt
        const radius = shockwave.speed * elapsed
        const gaussian = Math.exp(-((distance - radius) ** 2) / (2 * shockwave.width ** 2))
        const decay = Math.exp(-shockwave.decay * elapsed)
        const amplitude = shockwave.amplitude * gaussian * decay
        additionalX += (globeX / distance) * amplitude
        additionalY += (particle.homeY / distance) * amplitude
      })

      let targetX = globeX + additionalX
      let targetY = particle.homeY + additionalY
      let easing = 0.09

      if (disintegration > 0.001) {
        targetX += particle.disintegrationOffsetX * disintegration
        targetY += particle.disintegrationOffsetY * disintegration
        easing = 0.05 + disintegration * 0.02
      }

      particle.x += (targetX - particle.x) * easing
      particle.y += (targetY - particle.y) * easing

      const color = lerpColor(palette, particle.colorOffset + time * 0.04)
      const brightness = (0.55 + Math.sin(time * 3 + particle.seed) * 0.35) * (1 - disintegration * 0.7)
      const currentSize = particle.size * (1 - disintegration * 0.75) * (0.6 + 0.4 * visibility)
      const edgeFade = Math.sqrt(visibility)
      const boost = 1.25

      ctx.beginPath()
      ctx.fillStyle = `rgba(${Math.min(255, color.r * boost) | 0}, ${Math.min(255, color.g * boost) | 0}, ${Math.min(255, color.b * boost) | 0}, ${(0.8 + brightness * 0.4) * edgeFade})`
      ctx.arc(particle.x, particle.y, Math.max(0.2, currentSize), 0, Math.PI * 2)
      ctx.fill()
    })

    ctx.globalCompositeOperation = 'source-over'
    ctx.restore()
  }

  const image = new Image()
  image.crossOrigin = 'anonymous'

  image.addEventListener('load', () => {
    const count = 1300

    try {
      initParticles(sampleLogoPoints(image, count), count)
    } catch (error) {
      console.error('[VIP Tattoo] Footer particle logo could not be sampled:', error)
      return
    }

    if (!particles.length) return

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting && rafId === null) {
          rafId = requestAnimationFrame(drawFrame)
        } else if (!entry.isIntersecting && rafId !== null) {
          cancelAnimationFrame(rafId)
          rafId = null
        }
      }, { threshold: 0.05 })

      observer.observe(canvas)
    } else {
      rafId = requestAnimationFrame(drawFrame)
    }
  }, { once: true })

  image.addEventListener('error', () => {
    console.error('[VIP Tattoo] Footer particle logo image failed to load:', canvas.dataset.logo)
  }, { once: true })

  image.src = canvas.dataset.logo
})()

;(function () {
  if (document.querySelector('style[data-feature="hero-gallery-brown-gold-flow"]')) return

  const style = document.createElement('style')
  style.dataset.feature = 'hero-gallery-brown-gold-flow'
  style.textContent = `
    @keyframes hero-gallery-brown-gold-flow {
      0% { background-position: 0 0, 0% 50%; }
      100% { background-position: 0 0, 200% 50%; }
    }

    .hero-shot {
      border: 2px solid transparent !important;
      background-image:
        linear-gradient(#17110c, #17110c),
        linear-gradient(115deg, #352619 0%, #4c3723 18%, #5b4028 34%, #d8bd83 46%, #7a5835 53%, #4c3723 68%, #3a2a1c 84%, #b99557 100%) !important;
      background-origin: border-box !important;
      background-clip: padding-box, border-box !important;
      background-size: 100% 100%, 300% 300% !important;
      animation: hero-gallery-brown-gold-flow 3.4s linear infinite !important;
      box-shadow: 0 0 0 1px rgba(204, 170, 105, 0.12), 0 0 13px rgba(181, 143, 75, 0.18), 0 24px 54px -26px rgba(0, 0, 0, 0.95) !important;
      will-change: background-position;
    }

    .hero-shot:nth-child(2n) { animation-delay: -0.8s !important; }
    .hero-shot:nth-child(3n) { animation-delay: -1.6s !important; }
    .hero-shot:nth-child(4n) { animation-delay: -2.4s !important; }

    .hero-shot img {
      border-radius: calc(clamp(20px, 1.62vw, 30px) - 2px) !important;
    }

    @media (max-width: 700px) {
      .hero-shot {
        border-width: 1.5px !important;
        animation-duration: 4.2s !important;
        box-shadow: 0 0 0 1px rgba(204, 170, 105, 0.1), 0 0 9px rgba(181, 143, 75, 0.14) !important;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .hero-shot { animation: none !important; }
    }
  `

  document.head.appendChild(style)
})()
