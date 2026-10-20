(function () {
  'use strict';

  const root = document.documentElement;
  const launcher = document.getElementById('accessibilityToggle');
  const panel = document.getElementById('accessibilityPanel');
  const closeButton = document.getElementById('accessibilityClose');
  if (!launcher || !panel) return;

  const defaults = { fontScale: 1, contrast: false, grayscale: false, links: false, readable: false, motion: false };
  let preferences = { ...defaults };
  try { preferences = { ...defaults, ...JSON.parse(localStorage.getItem('churchA11yPrefs') || '{}') }; } catch (e) {}

  function apply() {
    root.style.fontSize = preferences.fontScale === 1 ? '' : (preferences.fontScale * 100) + '%';
    root.classList.toggle('a11y-high-contrast', !!preferences.contrast);
    root.classList.toggle('a11y-grayscale', !!preferences.grayscale);
    root.classList.toggle('a11y-underline-links', !!preferences.links);
    root.classList.toggle('a11y-readable', !!preferences.readable);
    root.classList.toggle('a11y-reduce-motion', !!preferences.motion);
    Object.entries({ contrast: 'contrast', grayscale: 'grayscale', links: 'links', readable: 'readable', motion: 'motion' }).forEach(([key, action]) => {
      const button = panel.querySelector('[data-accessibility="' + action + '"]');
      if (button) button.setAttribute('aria-pressed', preferences[key] ? 'true' : 'false');
    });
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
      case 'font-up': preferences.fontScale = Math.min(1.5, +(preferences.fontScale + 0.1).toFixed(1)); break;
      case 'font-down': preferences.fontScale = Math.max(0.8, +(preferences.fontScale - 0.1).toFixed(1)); break;
      case 'contrast': preferences.contrast = !preferences.contrast; break;
      case 'grayscale': preferences.grayscale = !preferences.grayscale; break;
      case 'links': preferences.links = !preferences.links; break;
      case 'readable': preferences.readable = !preferences.readable; break;
      case 'motion': preferences.motion = !preferences.motion; break;
      case 'reset': preferences = { ...defaults }; break;
      default: return;
    }
    apply();
  });

  let installPrompt = null;
  const installButton = document.getElementById('installAppButton');
  const installHelp = document.getElementById('installAppHelp');
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    if (installButton) installButton.hidden = false;
    if (installHelp) installHelp.hidden = true;
  });
  installButton?.addEventListener('click', async () => {
    if (!installPrompt) return;
    installPrompt.prompt();
    await installPrompt.userChoice;
    installPrompt = null;
    installButton.hidden = true;
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
