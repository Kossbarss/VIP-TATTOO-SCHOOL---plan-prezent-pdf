// Animated ring/glow canvas behind the pricing card. Deliberately does not
// touch .pricing-section or .order-box styling -- those stay on the plain
// site CSS. This module only adds the WebGL canvas layer.
;(function () {
  function initPricingGlow() {
    const section = document.getElementById('pricing')
    if (!section || section.dataset.pricingGlowReady === 'true') return

    const clip = section.querySelector('.section-clip') || section
    section.dataset.pricingGlowReady = 'true'

    const style = document.createElement('style')
    style.dataset.feature = 'pricing-glow-canvas'
    style.textContent = `
      .pricing-glow-canvas {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        z-index: 0;
        pointer-events: none;
        display: block;
      }
    `
    document.head.appendChild(style)

    const canvas = document.createElement('canvas')
    canvas.className = 'pricing-glow-canvas'
    canvas.setAttribute('aria-hidden', 'true')
    clip.insertBefore(canvas, clip.firstChild)

    const gl = canvas.getContext('webgl', { alpha: true, antialias: false, preserveDrawingBuffer: true })
    if (!gl) return

    const vertexSource = 'attribute vec2 aPosition; void main(){ gl_Position=vec4(aPosition,0.0,1.0); }'
    const fragmentSource = `
      precision highp float;
      uniform float iTime;
      uniform vec2 iResolution;

      mat2 rotate2d(float a){
        float c=cos(a),s=sin(a);
        return mat2(c,-s,s,c);
      }

      float variation(vec2 v1,vec2 v2,float strength,float speed){
        return sin(dot(normalize(v1),normalize(v2))*strength+iTime*speed)/100.0;
      }

      float circle(vec2 uv,vec2 center,float rad,float width){
        vec2 diff=center-uv;
        float len=length(diff);
        len+=variation(diff,vec2(0.0,1.0),5.0,2.0);
        len-=variation(diff,vec2(1.0,0.0),5.0,2.0);
        return smoothstep(rad-width,rad,len)-smoothstep(rad,rad+width,len);
      }

      void main(){
        vec2 uv=gl_FragCoord.xy/iResolution.xy;
        float aspect=iResolution.x/max(iResolution.y,1.0);
        uv.x*=aspect;

        vec2 center=vec2(aspect*.5,.5);
        vec2 shifted=uv-center;
        float radius=.46;
        float mask=0.0;
        mask+=circle(uv,center,radius,.038);
        mask+=circle(uv,center,radius-.022,.012);
        mask+=circle(uv,center,radius+.022,.006);

        vec2 v=rotate2d(iTime*.20)*shifted;
        float sweep=.5+.5*sin(iTime*.72+v.x*5.2-v.y*3.4);
        float pulse=.5+.5*cos(iTime*.48+v.y*4.1);

        vec3 deepBurgundy=vec3(.055,.004,.010);
        vec3 burgundy=vec3(.24,.008,.016);
        vec3 redColor=vec3(.95,.035,.015);
        vec3 orangeColor=vec3(1.0,.28,.10);
        vec3 goldColor=vec3(.93,.61,.22);

        float horizontalGlow=smoothstep(aspect*.95,aspect*.28,abs(shifted.x));
        float verticalGlow=smoothstep(.82,.10,abs(shifted.y));
        vec3 bg=mix(deepBurgundy,burgundy,clamp(horizontalGlow*.42+verticalGlow*.16,0.0,1.0));
        bg=mix(bg,redColor,clamp((uv.x/aspect)*.16,0.0,.16));

        vec3 ringColor=mix(redColor,orangeColor,sweep);
        ringColor=mix(ringColor,goldColor,pulse*.48);

        float ringDistance=abs(length(shifted)-radius);
        float halo=exp(-ringDistance*18.0);
        vec3 color=bg;
        color+=ringColor*(mask*.92+halo*.24);
        color+=goldColor*circle(uv,center,radius,.003)*.72;

        float vignette=smoothstep(1.05,.24,length(vec2(shifted.x/max(aspect,1.0),shifted.y)));
        color*=.72+.28*vignette;

        gl_FragColor=vec4(color,1.0);
      }
    `

    function compile(type, source) {
      const shader = gl.createShader(type)
      if (!shader) return null
      gl.shaderSource(shader, source)
      gl.compileShader(shader)
      if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        console.error(gl.getShaderInfoLog(shader) || 'Pricing shader compile error')
        gl.deleteShader(shader)
        return null
      }
      return shader
    }

    const vertexShader = compile(gl.VERTEX_SHADER, vertexSource)
    const fragmentShader = compile(gl.FRAGMENT_SHADER, fragmentSource)
    if (!vertexShader || !fragmentShader) return

    const program = gl.createProgram()
    if (!program) return
    gl.attachShader(program, vertexShader)
    gl.attachShader(program, fragmentShader)
    gl.linkProgram(program)
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) return
    gl.useProgram(program)

    const buffer = gl.createBuffer()
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer)
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1,1,-1,-1,1,-1,1,1,-1,1,1]), gl.STATIC_DRAW)
    const position = gl.getAttribLocation(program, 'aPosition')
    gl.enableVertexAttribArray(position)
    gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0)

    const timeLocation = gl.getUniformLocation(program, 'iTime')
    const resolutionLocation = gl.getUniformLocation(program, 'iResolution')
    const dpr = Math.min(window.devicePixelRatio || 1, 2)
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    let frameId = 0
    let visible = true

    function resize() {
      const rect = clip.getBoundingClientRect()
      const width = Math.max(1, Math.round(rect.width * dpr))
      const height = Math.max(1, Math.round(rect.height * dpr))
      if (canvas.width !== width || canvas.height !== height) {
        canvas.width = width
        canvas.height = height
        gl.viewport(0, 0, width, height)
      }
    }

    function render(now) {
      resize()
      gl.uniform1f(timeLocation, now * .001)
      gl.uniform2f(resolutionLocation, canvas.width, canvas.height)
      gl.drawArrays(gl.TRIANGLES, 0, 6)
      if (visible && !reducedMotion) frameId = requestAnimationFrame(render)
    }

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        const nextVisible = entries[0].isIntersecting
        if (nextVisible && !visible && !reducedMotion) {
          visible = true
          frameId = requestAnimationFrame(render)
        } else if (!nextVisible && visible) {
          visible = false
          cancelAnimationFrame(frameId)
        }
      }, { threshold: .02 }).observe(section)
    }

    function drawFrame() {
      resize()
      gl.uniform1f(timeLocation, performance.now() * .001)
      gl.uniform2f(resolutionLocation, canvas.width, canvas.height)
      gl.drawArrays(gl.TRIANGLES, 0, 6)
    }

    // The canvas can only size itself correctly once .section-clip has its
    // final layout box, which may not be true yet at init time (fonts/images
    // still loading). Under prefers-reduced-motion there is no continuous
    // rAF loop to self-correct a stale/zero size later, so redraw on every
    // layout change the section box goes through, not just on window resize.
    if ('ResizeObserver' in window) {
      new ResizeObserver(drawFrame).observe(clip)
    } else {
      window.addEventListener('resize', drawFrame, { passive: true })
    }
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(drawFrame)
    }
    window.addEventListener('load', drawFrame, { once: true })

    resize()
    frameId = requestAnimationFrame(render)
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPricingGlow, { once: true })
  } else {
    initPricingGlow()
  }
})()
