</main>
<?php if (Auth::user()): ?>
<div id="installBar" class="d-none position-fixed bottom-0 start-0 end-0 bg-white border-top shadow-sm p-2 d-print-none" style="z-index:1050;padding-bottom:calc(.5rem + env(safe-area-inset-bottom,0px))!important">
  <div class="container-xxl d-flex align-items-center gap-2">
    <span class="small me-auto" data-install-text></span>
    <button class="btn btn-sm btn-primary d-none" data-install>Install</button>
    <button class="btn btn-sm btn-link text-secondary" data-install-close aria-label="Dismiss">Not now</button>
  </div>
</div>
<?php endif; ?>
<div id="toast" class="position-fixed bottom-0 start-50 translate-middle-x d-none" style="margin-bottom:4.5rem" role="status" aria-live="polite"></div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js?v=5.3.3"></script>
<script src="assets/app.js?v=1"></script>
</body>
</html>
