// Dibuja el rastro decorativo del puntero; no participa en las funciones del sistema.
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const hasFinePointer = window.matchMedia('(pointer: fine)').matches;
// Cambia estos valores para personalizar las partículas doradas y rojas del puntero.
const pointerColors = { main: '#d4af37', secondary: '#e63946' };

if (!reducedMotion && hasFinePointer) {
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d');

  if (context) {
    canvas.className = 'pointer-effect-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.append(canvas);

    let particles = [];
    let animationFrame = 0;
    let lastEmission = 0;
    let pixelRatio = 1;

    function resizeCanvas() {
      pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
      canvas.width = Math.round(window.innerWidth * pixelRatio);
      canvas.height = Math.round(window.innerHeight * pixelRatio);
      context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    }

    function animate() {
      context.clearRect(0, 0, window.innerWidth, window.innerHeight);
      particles = particles.filter((particle) => particle.life > 0);

      for (const particle of particles) {
        particle.x += particle.vx;
        particle.y += particle.vy;
        particle.life -= 0.025;
        context.globalAlpha = particle.life;
        context.beginPath();
        context.arc(particle.x, particle.y, particle.size, 0, Math.PI * 2);
        context.fillStyle = particle.color;
        context.shadowBlur = 12;
        context.shadowColor = particle.color;
        context.fill();
      }

      context.globalAlpha = 1;
      context.shadowBlur = 0;
      for (let first = 0; first < particles.length; first += 1) {
        for (let second = first + 1; second < particles.length; second += 1) {
          const deltaX = particles[first].x - particles[second].x;
          const deltaY = particles[first].y - particles[second].y;
          const distance = Math.hypot(deltaX, deltaY);
          if (distance >= 72) continue;

          context.globalAlpha = (1 - distance / 72) * Math.min(particles[first].life, particles[second].life) * 0.45;
          context.beginPath();
          context.moveTo(particles[first].x, particles[first].y);
          context.lineTo(particles[second].x, particles[second].y);
          context.strokeStyle = pointerColors.main;
          context.lineWidth = 0.7;
          context.stroke();
        }
      }

      context.globalAlpha = 1;
      if (particles.length > 0) {
        animationFrame = window.requestAnimationFrame(animate);
      } else {
        animationFrame = 0;
      }
    }

    function handlePointerMove(event) {
      const now = performance.now();
      if (now - lastEmission < 28) return;
      lastEmission = now;

      for (let index = 0; index < 2; index += 1) {
        const angle = Math.random() * Math.PI * 2;
        const speed = Math.random() * 0.9 + 0.2;
        particles.push({
          x: event.clientX + (Math.random() - 0.5) * 8,
          y: event.clientY + (Math.random() - 0.5) * 8,
          vx: Math.cos(angle) * speed,
          vy: Math.sin(angle) * speed,
          size: Math.random() * 1.5 + 1,
          life: 1,
          color: Math.random() > 0.3 ? pointerColors.main : pointerColors.secondary,
        });
      }

      if (particles.length > 28) particles.splice(0, particles.length - 28);
      if (animationFrame === 0) animationFrame = window.requestAnimationFrame(animate);
    }

    resizeCanvas();
    window.addEventListener('resize', resizeCanvas, { passive: true });
    window.addEventListener('pointermove', handlePointerMove, { passive: true });
  }
}
