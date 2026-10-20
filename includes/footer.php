    </div><!-- /.content-area -->
  </div><!-- /.main-content -->
</div><!-- /.cms-layout -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/statistics.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bulk-delete.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accessibility.css?v=1">
<div class="accessibility-widget" id="accessibilityWidget">
  <button type="button" class="accessibility-launcher" id="accessibilityToggle" aria-expanded="false" aria-controls="accessibilityPanel" aria-label="Open accessibility options"><i class="fas fa-universal-access" aria-hidden="true"></i><span>Accessibility</span></button>
  <section class="accessibility-panel" id="accessibilityPanel" aria-labelledby="accessibilityTitle" hidden>
    <div class="accessibility-panel-head"><h2 id="accessibilityTitle">Accessibility options</h2><button type="button" id="accessibilityClose" aria-label="Close accessibility options">&times;</button></div>
    <div class="accessibility-controls">
      <div class="accessibility-control-row"><span>Text size</span><div><button type="button" data-accessibility="font-down" aria-label="Decrease text size">A−</button><button type="button" data-accessibility="font-up" aria-label="Increase text size">A+</button></div></div>
      <button type="button" data-accessibility="contrast">High contrast</button>
      <button type="button" data-accessibility="grayscale">Grayscale</button>
      <button type="button" data-accessibility="links">Underline links</button>
      <button type="button" data-accessibility="readable">Readable font</button>
      <button type="button" data-accessibility="motion">Reduce motion</button>
      <button type="button" class="accessibility-reset" data-accessibility="reset">Reset options</button>
      <button type="button" class="accessibility-install" id="installAppButton" hidden><i class="fas fa-download" aria-hidden="true"></i> Install app</button>
      <p class="accessibility-install-help" id="installAppHelp" hidden>To install, use your browser menu and choose “Install app” or “Add to Home Screen”.</p>
    </div>
  </section>
</div>
<script src="<?= BASE_URL ?>assets/js/main.js"></script>
<script src="<?= BASE_URL ?>assets/js/accessibility.js?v=1"></script>
<script src="<?= BASE_URL ?>assets/js/bulk-delete.js?v=1"></script>
</body>
</html>
