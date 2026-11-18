
let elementoVozActivo = null;

function hablarAviso(elemento) {
  if (!('speechSynthesis' in window) || !('SpeechSynthesisUtterance' in window)) return false;

  const sintesis = window.speechSynthesis;
  const texto = elemento.dataset.voiceMessage ?? '';
  if (texto === '') return false;

  if (elementoVozActivo) {
    const estadoAnterior = elementoVozActivo.querySelector('[data-voice-status]');
    if (estadoAnterior) estadoAnterior.textContent = 'Lectura detenida.';
  }
  sintesis.cancel();
  elementoVozActivo = elemento;
  const utterance = new SpeechSynthesisUtterance(texto);
  const vocesEspanol = sintesis.getVoices().filter((voz) => voz.lang.toLowerCase().startsWith('es'));
  const vozEspanol = vocesEspanol.find((voz) => voz.lang.toLowerCase() === 'es-pe')
    ?? vocesEspanol.find((voz) => voz.lang.toLowerCase() === 'es-mx')
    ?? vocesEspanol.find((voz) => voz.lang.toLowerCase() === 'es-419')
    ?? vocesEspanol.find((voz) => voz.lang.toLowerCase() === 'es-es')
    ?? vocesEspanol[0];
  utterance.lang = vozEspanol?.lang ?? 'es-PE';
  utterance.rate = 0.95;
  if (vozEspanol) utterance.voice = vozEspanol;
  const estado = elemento.querySelector('[data-voice-status]');
  const boton = elemento.querySelector('[data-voice-replay]');
  utterance.onstart = () => {
    if (elementoVozActivo !== elemento) return;
    if (estado) estado.textContent = 'Reproduciendo voz.';
  };
  utterance.onend = () => {
    if (elementoVozActivo !== elemento) return;
    if (estado) estado.textContent = 'Lectura finalizada.';
    elementoVozActivo = null;
  };
  utterance.onerror = (event) => {
    if (event.error === 'canceled' || event.error === 'interrupted'
      || elementoVozActivo !== elemento
    ) return;
    if (estado) {
      estado.textContent = event.error === 'not-allowed'
        ? 'El navegador bloqueó la reproducción de voz.'
        : 'No se pudo reproducir la voz en español en este navegador.';
    }
    if (boton instanceof HTMLButtonElement) boton.hidden = false;
    elementoVozActivo = null;
  };
  sintesis.speak(utterance);
  return true;
}

window.hablarAviso = hablarAviso;

document.querySelectorAll('[data-voice-message]').forEach((elemento) => {
  const boton = elemento.querySelector('[data-voice-replay]');
  const estado = elemento.querySelector('[data-voice-status]');
  const vozDisponible = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  const reproduccionManual = elemento.dataset.voiceManual === 'true';
  if (boton instanceof HTMLButtonElement) {
    boton.hidden = !reproduccionManual;
    boton.addEventListener('click', () => {
      if (!hablarAviso(elemento) && estado) {
        estado.textContent = 'La voz no está disponible en este navegador.';
      }
    });

  }

  if (!vozDisponible && reproduccionManual && estado) {
    estado.textContent = 'La voz no está disponible en este navegador.';
  }

  if (elemento.dataset.voiceAutoplay === 'true' && !hablarAviso(elemento)) {
    if (estado) estado.textContent = 'La voz no está disponible en este navegador.';
    if (boton instanceof HTMLButtonElement) boton.hidden = false;
  }
});