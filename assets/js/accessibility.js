(function () {
  'use strict';

  const root = document.documentElement;
  const launcher = document.getElementById('accessibilityToggle');
  const panel = document.getElementById('accessibilityPanel');
  const closeButton = document.getElementById('accessibilityClose');
  if (!launcher || !panel) return;

  const defaults = {
    fontScale: 1, contrast: 'normal', grayscale: false, links: false,
    readable: false, lineSpacing: false, highlightHeadings: false,
    largeCursor: false, ruler: false, motion: false, speechRate: 1
  };
  let preferences = { ...defaults };
  try { preferences = { ...defaults, ...JSON.parse(localStorage.getItem('churchA11yPrefs') || '{}') }; } catch (e) {}

  const pressedControls = {
    grayscale: 'grayscale', links: 'links', readable: 'readable',
    'line-spacing': 'lineSpacing', 'highlight-headings': 'highlightHeadings',
    'large-cursor': 'largeCursor', ruler: 'ruler', motion: 'motion'
  };

  function apply() {
    root.style.fontSize = preferences.fontScale === 1 ? '' : (preferences.fontScale * 100) + '%';
    root.classList.toggle('a11y-high-contrast', preferences.contrast === 'high');
    root.classList.toggle('a11y-dark', preferences.contrast === 'dark');
    root.classList.toggle('a11y-yellow', preferences.contrast === 'yellow');
    root.classList.toggle('a11y-grayscale', !!preferences.grayscale);
    root.classList.toggle('a11y-underline-links', !!preferences.links);
    root.classList.toggle('a11y-readable', !!preferences.readable);
    root.classList.toggle('a11y-line-spacing', !!preferences.lineSpacing);
    root.classList.toggle('a11y-highlight-headings', !!preferences.highlightHeadings);
    root.classList.toggle('a11y-large-cursor', !!preferences.largeCursor);
    root.classList.toggle('a11y-ruler', !!preferences.ruler);
    root.classList.toggle('a11y-reduce-motion', !!preferences.motion);
    Object.entries(pressedControls).forEach(([action, key]) => {
      panel.querySelector('[data-accessibility="' + action + '"]')?.setAttribute('aria-pressed', preferences[key] ? 'true' : 'false');
    });
    const contrast = document.getElementById('accessibilityContrast');
    const scale = document.getElementById('accessibilityTextScale');
    if (contrast) contrast.value = preferences.contrast;
    if (scale) scale.value = Math.round(preferences.fontScale * 100) + '%';
    const rate = document.getElementById('accessibilityRate');
    const rateValue = document.getElementById('accessibilityRateValue');
    if (rate) rate.value = preferences.speechRate;
    if (rateValue) rateValue.value = preferences.speechRate + '×';
    try { localStorage.setItem('churchA11yPrefs', JSON.stringify(preferences)); } catch (e) {}
  }

  function open(opened) {
    panel.hidden = !opened;
    launcher.setAttribute('aria-expanded', opened ? 'true' : 'false');
    if (opened) closeButton?.focus();
    else launcher.focus();
  }
  apply();
  launcher.addEventListener('click', () => open(panel.hidden));
  closeButton?.addEventListener('click', () => open(false));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !panel.hidden) open(false); });

  panel.addEventListener('click', (event) => {
    const control = event.target.closest('[data-accessibility]');
    if (!control) return;
    switch (control.dataset.accessibility) {
      case 'font-up': preferences.fontScale = Math.min(1.6, +(preferences.fontScale + 0.1).toFixed(1)); break;
      case 'font-down': preferences.fontScale = Math.max(0.8, +(preferences.fontScale - 0.1).toFixed(1)); break;
      case 'grayscale': preferences.grayscale = !preferences.grayscale; break;
      case 'links': preferences.links = !preferences.links; break;
      case 'readable': preferences.readable = !preferences.readable; break;
      case 'line-spacing': preferences.lineSpacing = !preferences.lineSpacing; break;
      case 'highlight-headings': preferences.highlightHeadings = !preferences.highlightHeadings; break;
      case 'large-cursor': preferences.largeCursor = !preferences.largeCursor; break;
      case 'ruler': preferences.ruler = !preferences.ruler; break;
      case 'motion': preferences.motion = !preferences.motion; break;
      case 'reset': preferences = { ...defaults }; stopSpeech(); break;
      default: return;
    }
    apply();
  });

  document.getElementById('accessibilityContrast')?.addEventListener('change', (event) => {
    preferences.contrast = ['normal', 'high', 'dark', 'yellow'].includes(event.target.value) ? event.target.value : 'normal';
    apply();
  });

  const ruler = document.createElement('div');
  ruler.id = 'accessibilityReadingRuler';
  ruler.setAttribute('aria-hidden', 'true');
  document.body.appendChild(ruler);
  document.addEventListener('pointermove', (event) => { ruler.style.top = Math.max(0, Math.min(window.innerHeight - 34, event.clientY - 17)) + 'px'; }, { passive: true });

  const speechStatus = document.getElementById('accessibilitySpeechStatus');
  const speechRate = document.getElementById('accessibilityRate');
  let voices = [];
  const africanEnglishLocales = /^(en-(ZA|NG|KE|UG|GH|ZW|BW|NA|TZ|RW|SL|LR|ET|ZM|MW|LS|SZ))(?:-|$)/i;
  const likelyFemaleVoice = (voice) => /\b(female|woman|tessa|nia|zuri|amara|maya|samantha|susan|zira|aria|jenny|ava|victoria|karen|moira|fiona|salli|joanna|lerato|zola|nomsa|thandi|lindiwe|naledi|akosua|ama|aisha|chiamaka)\b/i.test(voice.name);
  function preferredEnglishVoice() {
    const africanWomen = voices.filter((voice) => africanEnglishLocales.test(voice.lang) && likelyFemaleVoice(voice));
    return africanWomen[0]
      || voices.find(likelyFemaleVoice)
      || null;
  }
  function loadVoices() {
    if (!('speechSynthesis' in window)) return;
    voices = window.speechSynthesis.getVoices().filter((voice) => /^en(?:-|$)/i.test(voice.lang));
  }
  function stopSpeech() {
    if ('speechSynthesis' in window) window.speechSynthesis.cancel();
    if (speechStatus) speechStatus.textContent = 'Reading stopped.';
  }
  function readAloud() {
    if (!('speechSynthesis' in window)) {
      if (speechStatus) speechStatus.textContent = 'Read aloud is not supported by this browser.';
      return;
    }
    const selection = window.getSelection()?.toString().trim() || '';
    const source = document.querySelector('main, .main-content, [role="main"]') || document.body;
    const text = selection || (source.innerText || '').trim();
    if (!text) { if (speechStatus) speechStatus.textContent = 'There is no text to read on this page.'; return; }
    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text.slice(0, 12000));
    utterance.rate = Number(speechRate?.value || preferences.speechRate || 1);
    utterance.voice = preferredEnglishVoice();
    utterance.lang = utterance.voice?.lang || 'en-ZA';
    utterance.onstart = () => { if (speechStatus) speechStatus.textContent = selection ? 'Reading selected text.' : 'Reading page content.'; };
    utterance.onend = () => { if (speechStatus) speechStatus.textContent = 'Finished reading.'; };
    utterance.onerror = () => { if (speechStatus) speechStatus.textContent = 'Unable to read this text aloud.'; };
    window.speechSynthesis.speak(utterance);
  }
  if ('speechSynthesis' in window) {
    loadVoices();
    window.speechSynthesis.addEventListener?.('voiceschanged', loadVoices);
  } else if (speechStatus) speechStatus.textContent = 'Read aloud is not supported by this browser.';
  speechRate?.addEventListener('input', () => { preferences.speechRate = Number(speechRate.value); apply(); });
  document.getElementById('speakSelection')?.addEventListener('click', readAloud);
  document.getElementById('pauseSpeech')?.addEventListener('click', () => {
    if (!('speechSynthesis' in window)) return;
    if (window.speechSynthesis.paused) window.speechSynthesis.resume();
    else if (window.speechSynthesis.speaking) window.speechSynthesis.pause();
    if (speechStatus) speechStatus.textContent = window.speechSynthesis.paused ? 'Reading paused.' : 'Reading resumed.';
  });
  document.getElementById('stopSpeech')?.addEventListener('click', stopSpeech);

  let installPrompt = null;
  const installButton = document.getElementById('installAppButton');
  const installHelp = document.getElementById('installAppHelp');
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault(); installPrompt = event;
    if (installButton) installButton.hidden = false;
    if (installHelp) installHelp.hidden = true;
  });
  installButton?.addEventListener('click', async () => {
    if (!installPrompt) return;
    installPrompt.prompt(); await installPrompt.userChoice; installPrompt = null; installButton.hidden = true;
  });
  window.addEventListener('appinstalled', () => {
    if (installButton) installButton.hidden = true;
    if (installHelp) installHelp.hidden = true;
  });
  window.addEventListener('load', () => {
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    if (!window.matchMedia('(display-mode: standalone)').matches && !installPrompt && installHelp) installHelp.hidden = false;
  }, { once: true });
})();
