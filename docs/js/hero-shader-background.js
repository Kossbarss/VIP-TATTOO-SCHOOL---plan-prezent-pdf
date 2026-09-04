// Optimized Paper Design MeshGradient 0.0.77 output for the supplied two-layer component.
;(function () {
  'use strict'

  const hero = document.querySelector('.hero')
  if (!hero || hero.dataset.paperMeshExact) return

  // This shader does real per-pixel work -- up to ~20 pow()+trig calls
  // per pixel across roughly a million pixels, every frame, for two
  // full mesh layers (base + overlay). Plenty of phone GPUs can't
  // sustain that: the adaptive quality step below already tries to
  // cope by shrinking the canvas's real resolution (while it stays
  // stretched to the same CSS size via width/height:100%), which is
  // exactly what reads as the render turning blocky/pixelated, and on
  // hardware where even its lowest floor isn't enough, that fight
  // never resolves -- the stutter just continues. Skip WebGL
  // entirely below the same width this file already treats as
  // "mobile" and keep the plain CSS gradient there instead; it has
  // no such failure mode because there's nothing to fall behind on.
  if (window.matchMedia('(max-width: 700px)').matches) return

  hero.dataset.paperMeshExact = 'loading'

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  const VS = `#version 300 es
precision mediump float;
layout(location=0) in vec4 a_position;
uniform vec2 u_resolution;
out vec2 v_objectUV;
void main(){
  gl_Position=a_position;
  vec2 uv=gl_Position.xy*.5;
  float side=min(u_resolution.x,u_resolution.y);
  v_objectUV=uv*(u_resolution/vec2(side));
}`

  const FS = `#version 300 es
precision mediump float;
uniform float u_timeBase;
uniform float u_timeOverlay;
uniform vec4 u_colorsBase[10];
uniform vec4 u_colorsOverlay[10];
uniform float u_colorsBaseCount;
uniform float u_colorsOverlayCount;
in vec2 v_objectUV;
out vec4 fragColor;

vec2 rotate2d(vec2 uv,float th){
  return mat2(cos(th),sin(th),-sin(th),cos(th))*uv;
}

vec2 getPosition(int i,float t){
  float a=float(i)*.37;
  float b=.6+fract(float(i)/3.)*.9;
  float c=.8+fract(float(i+1)/4.);
  return .5+.5*vec2(sin(t*b+a),cos(t*c+a*1.5));
}

vec3 meshColor(vec2 sourceUV,float shaderTime,bool overlay){
  vec2 uv=sourceUV+.5;
  const float firstFrameOffset=41.5;
  float t=.5*(shaderTime+firstFrameOffset);
  float radius=smoothstep(0.,1.,length(uv-.5));
  float center=1.-radius;

  for(float i=1.;i<=2.;i++){
    uv.x+=.8*center/i*sin(t+i*.4*smoothstep(.0,1.,uv.y))*cos(.2*t+i*2.4*smoothstep(.0,1.,uv.y));
    uv.y+=.8*center/i*cos(t+i*2.*smoothstep(.0,1.,uv.x));
  }

  vec2 uvRotated=rotate2d(uv-.5,-.3*radius)+.5;
  vec3 color=vec3(0.);
  float totalWeight=0.;
  float count=overlay?u_colorsOverlayCount:u_colorsBaseCount;

  for(int i=0;i<10;i++){
    if(i>=int(count)) break;
    vec4 paletteColor=overlay?u_colorsOverlay[i]:u_colorsBase[i];
    vec2 pos=getPosition(i,t);
    float dist=pow(length(uvRotated-pos),3.5);
    float weight=1./(dist+1e-3);
    color+=paletteColor.rgb*weight;
    totalWeight+=weight;
  }

  return color/max(1e-4,totalWeight);
}

void main(){
  vec3 base=meshColor(v_objectUV,u_timeBase,false);
  vec3 overlay=meshColor(v_objectUV,u_timeOverlay,true);
  fragColor=vec4(mix(base,overlay,.6),1.);
}`

  const BASE_COLORS = ['#000000', '#06b6d4', '#0891b2', '#164e63', '#22d3ee']
  const OVERLAY_COLORS = ['#000000', '#ffffff', '#06b6d4', '#22d3ee']

  function palette(colors) {
    const values = new Float32Array(40)
    colors.forEach((hex, index) => {
      const h = hex.slice(1)
      const offset = index * 4
      values[offset] = parseInt(h.slice(0, 2), 16) / 255
      values[offset + 1] = parseInt(h.slice(2, 4), 16) / 255
      values[offset + 2] = parseInt(h.slice(4, 6), 16) / 255
      values[offset + 3] = 1
    })
    return values
  }

  const basePalette = palette(BASE_COLORS)
  const overlayPalette = palette(OVERLAY_COLORS)

  function compileShader(gl, type, source) {
    const shader = gl.createShader(type)
    if (!shader) throw new Error('Unable to create shader')
    gl.shaderSource(shader, source)
    gl.compileShader(shader)
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
      const message = gl.getShaderInfoLog(shader) || 'Shader compile error'
      gl.deleteShader(shader)
      throw new Error(message)
    }
    return shader
  }

  function createProgram(gl) {
    const vertex = compileShader(gl, gl.VERTEX_SHADER, VS)
    const fragment = compileShader(gl, gl.FRAGMENT_SHADER, FS)
    const program = gl.createProgram()
    if (!program) throw new Error('Unable to create WebGL program')
    gl.attachShader(program, vertex)
    gl.attachShader(program, fragment)
    gl.linkProgram(program)
    gl.deleteShader(vertex)
    gl.deleteShader(fragment)
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      const message = gl.getProgramInfoLog(program) || 'Program link error'
      gl.deleteProgram(program)
      throw new Error(message)
    }
    return program
  }

  const mount = document.createElement('div')
  mount.className = 'hero-paper-exact-shaders'
  mount.setAttribute('aria-hidden', 'true')
  mount.style.cssText = 'position:absolute;inset:0;z-index:0;overflow:hidden;pointer-events:none;opacity:0;visibility:hidden;contain:paint'

  const canvas = document.createElement('canvas')
  canvas.setAttribute('aria-hidden', 'true')
  canvas.style.cssText = 'display:block;width:100%;height:100%'
  mount.appendChild(canvas)
  hero.prepend(mount)

  let gl = null
  let program = null
  let buffer = null
  let locations = null
  let width = 0
  let height = 0
  let baseFrame = 0
  let overlayFrame = 0
  let lastTime = performance.now()
  let raf = 0
  let active = false
  let disposed = false
  let ready = false
  let resizePending = true
  let quality = 0.9
  let frameSamples = 0
  let frameTimeTotal = 0
  let stableSamples = 0
  let smoothedDelta = 16.67
  let cooldownBatches = 0

  // desynchronized:true trades presentation sync for lower input
  // latency -- a real win for a canvas the user is actively drawing on,
  // but this one is a passive background nobody touches, so it was
  // only paying that flag's cost: on several mobile GPU drivers,
  // decoupling the canvas from the compositor like this is a known
  // source of visible tearing/stutter.
  const contextOptions = {
    alpha: true,
    antialias: false,
    depth: false,
    stencil: false,
    powerPreference: 'high-performance',
    preserveDrawingBuffer: false,
  }

  function maxPixelCount() {
    return 1920 * 1080
  }

  function renderScale() {
    const dpr = Math.max(1, window.devicePixelRatio || 1)
    return Math.min(dpr, 1.4) * quality
  }

  function destroyResources() {
    if (!gl) return
    if (buffer) gl.deleteBuffer(buffer)
    if (program) gl.deleteProgram(program)
    buffer = null
    program = null
    locations = null
  }

  function initializeContext() {
    gl = canvas.getContext('webgl2', contextOptions)
    if (!gl) throw new Error('WebGL2 unavailable')

    program = createProgram(gl)
    gl.useProgram(program)

    buffer = gl.createBuffer()
    if (!buffer) throw new Error('Unable to create WebGL buffer')
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer)
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, -1, 1, 1, -1, 1, 1]), gl.STATIC_DRAW)
    gl.enableVertexAttribArray(0)
    gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0)

    const locate = (name) => gl.getUniformLocation(program, name)
    locations = {
      resolution: locate('u_resolution'),
      timeBase: locate('u_timeBase'),
      timeOverlay: locate('u_timeOverlay'),
      colorsBase: locate('u_colorsBase[0]'),
      colorsOverlay: locate('u_colorsOverlay[0]'),
      colorsBaseCount: locate('u_colorsBaseCount'),
      colorsOverlayCount: locate('u_colorsOverlayCount'),
    }

    gl.uniform4fv(locations.colorsBase, basePalette)
    gl.uniform4fv(locations.colorsOverlay, overlayPalette)
    gl.uniform1f(locations.colorsBaseCount, BASE_COLORS.length)
    gl.uniform1f(locations.colorsOverlayCount, OVERLAY_COLORS.length)
    gl.clearColor(0, 0, 0, 0)

    width = 0
    height = 0
    resizePending = true
  }

  function resizeCanvas() {
    if (!resizePending || !gl || !program || !locations) return false
    resizePending = false

    const rect = hero.getBoundingClientRect()
    const scale = renderScale()
    let nextWidth = Math.max(1, Math.round(rect.width * scale))
    let nextHeight = Math.max(1, Math.round(rect.height * scale))
    const pixels = nextWidth * nextHeight
    const limit = maxPixelCount()

    if (pixels > limit) {
      const cap = Math.sqrt(limit / pixels)
      nextWidth = Math.max(1, Math.round(nextWidth * cap))
      nextHeight = Math.max(1, Math.round(nextHeight * cap))
    }

    if (nextWidth === width && nextHeight === height) return false

    width = nextWidth
    height = nextHeight
    canvas.width = width
    canvas.height = height
    gl.viewport(0, 0, width, height)
    gl.useProgram(program)
    gl.uniform2f(locations.resolution, width, height)
    return true
  }

  function renderCurrentFrame() {
    if (!gl || !program || !locations || disposed || gl.isContextLost()) return false

    resizeCanvas()
    gl.useProgram(program)
    gl.uniform1f(locations.timeBase, baseFrame * 0.001)
    gl.uniform1f(locations.timeOverlay, overlayFrame * 0.001)
    gl.drawArrays(gl.TRIANGLES, 0, 6)

    if (!ready) {
      ready = true
      mount.style.opacity = '1'
      mount.style.visibility = 'visible'
      hero.dataset.paperMeshExact = 'true'
      hero.classList.add('hero-paper-mesh-exact')
    }
    return true
  }

  function adaptQuality(frameDelta) {
    frameTimeTotal += frameDelta
    frameSamples += 1

    if (frameSamples < 60) return

    const average = frameTimeTotal / frameSamples
    frameSamples = 0
    frameTimeTotal = 0

    // Each quality change forces a canvas resize (a real reallocation,
    // not free), so on a device whose frame time hovers right at the
    // threshold this loop could otherwise flip quality up and down
    // every batch, resizing every time -- reading as a stutter that
    // repeats roughly once a second. Holding off on re-evaluating for
    // a few batches after any change breaks that oscillation.
    if (cooldownBatches > 0) {
      cooldownBatches -= 1
      return
    }

    if (average > 22 && quality > 0.58) {
      quality = Math.max(0.58, quality * 0.84)
      resizePending = true
      stableSamples = 0
      cooldownBatches = 3
      return
    }

    if (average < 17.8 && quality < 0.94) {
      stableSamples += 1
      if (stableSamples >= 4) {
        quality = Math.min(0.94, quality + 0.06)
        resizePending = true
        stableSamples = 0
        cooldownBatches = 3
      }
    } else {
      stableSamples = 0
    }
  }

  function drawOnce() {
    if (disposed || !gl || gl.isContextLost()) return
    renderCurrentFrame()
    lastTime = performance.now()
  }

  function loop(now) {
    if (disposed || !active || !gl || gl.isContextLost()) return

    const rawDelta = Math.min(Math.max(now - lastTime, 0), 32)
    lastTime = now
    smoothedDelta += (rawDelta - smoothedDelta) * 0.18
    baseFrame += smoothedDelta * 0.3
    overlayFrame += smoothedDelta * 0.2

    renderCurrentFrame()
    adaptQuality(rawDelta)
    raf = requestAnimationFrame(loop)
  }

  function setActive(nextActive) {
    if (disposed) return
    const next = Boolean(nextActive) && !reducedMotion
    if (next === active) {
      if (next) drawOnce()
      return
    }

    active = next
    cancelAnimationFrame(raf)
    raf = 0

    drawOnce()
    if (active) {
      lastTime = performance.now()
      raf = requestAnimationFrame(loop)
    }
  }

  function handleContextLost(event) {
    event.preventDefault()
    cancelAnimationFrame(raf)
    raf = 0
    ready = false
    mount.style.opacity = '0'
    mount.style.visibility = 'hidden'
  }

  function handleContextRestored() {
    if (disposed) return
    try {
      destroyResources()
      initializeContext()
      drawOnce()
      if (active) {
        lastTime = performance.now()
        raf = requestAnimationFrame(loop)
      }
    } catch (error) {
      mount.style.opacity = '0'
      mount.style.visibility = 'hidden'
      console.error('Paper MeshGradient restore failed', error)
    }
  }

  canvas.addEventListener('webglcontextlost', handleContextLost, false)
  canvas.addEventListener('webglcontextrestored', handleContextRestored, false)

  try {
    initializeContext()
    drawOnce()
  } catch (error) {
    canvas.removeEventListener('webglcontextlost', handleContextLost, false)
    canvas.removeEventListener('webglcontextrestored', handleContextRestored, false)
    destroyResources()
    mount.remove()
    delete hero.dataset.paperMeshExact
    console.error('Paper MeshGradient failed', error)
    return
  }

  function heroIsVisible() {
    const rect = hero.getBoundingClientRect()
    return !document.hidden && rect.bottom > 0 && rect.top < window.innerHeight
  }

  function updateActivity() {
    setActive(heroIsVisible())
  }

  const resizeObserver = 'ResizeObserver' in window
    ? new ResizeObserver(() => {
        resizePending = true
        drawOnce()
      })
    : null
  resizeObserver?.observe(hero)

  const intersectionObserver = 'IntersectionObserver' in window
    ? new IntersectionObserver(updateActivity, { threshold: 0.01 })
    : null
  intersectionObserver?.observe(hero)

  const handleViewportChange = () => {
    resizePending = true
    drawOnce()
    updateActivity()
  }

  document.addEventListener('visibilitychange', updateActivity)
  window.addEventListener('orientationchange', handleViewportChange, { passive: true })

  updateActivity()
})()
