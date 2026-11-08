<?php if (($layout ?? 'app') === 'auth'): ?>
  </div></main>
</div>
<?php elseif (!empty($isAdmin)): ?>
    </main>
  </div>
</div>
<?php else: ?>
</main>
<?php
$shopWa = '';
try { $shopWa = trim((string) (SettingService::all()['shop_whatsapp'] ?? '')); } catch (Throwable $e) { $shopWa = ''; }
$waDigits = preg_replace('/\D+/', '', $shopWa);
?>
<footer class="foot">
  <div class="foot-in">
    <div class="foot-col foot-about">
      <a class="brand" href="<?= e(url('/')) ?>"><?= logo_svg(24) ?><?= brand_ur() ?></a>
      <p>Crockery on rent for weddings, daawats and every gathering. Choose, check dates and get an instant quote.</p>
    </div>
    <div class="foot-col">
      <h3>Explore</h3>
      <a href="<?= e(url('catalog.php')) ?>">Catalog</a>
      <a href="<?= e(url('packages.php')) ?>">Event packages</a>
      <a href="<?= e(url('cart.php')) ?>">Your cart</a>
      <a href="<?= e(url(Auth::user() ? 'customer/bookings.php' : 'login.php')) ?>">My bookings</a>
    </div>
    <div class="foot-col">
      <h3>Account</h3>
      <?php if (Auth::user()): ?>
        <a href="<?= e(url(Auth::homeUrl())) ?>">Dashboard</a>
      <?php else: ?>
        <a href="<?= e(url('login.php')) ?>">Log in</a>
        <a href="<?= e(url('register.php')) ?>">Create account</a>
      <?php endif; ?>
    </div>
    <div class="foot-col">
      <h3>Contact us</h3>
      <?php if ($waDigits !== ''): ?>
        <a href="https://wa.me/<?= e($waDigits) ?>" rel="noopener">WhatsApp: <?= e($shopWa) ?></a>
      <?php endif; ?>
      <a href="tel:+920000000000">Phone: +92 000 0000000</a>
      <a href="mailto:info@dastarkhwan.example">info@dastarkhwan.example</a>
      <span>Shop address, City, Pakistan</span>
      <span>Open daily, 9 am to 9 pm</span>
    </div>
  </div>
  <div class="foot-base">&copy; <?= date('Y') ?> <?= brand_ur() ?> &middot; Crockery rental. All rights reserved.</div>
</footer>
<?php endif; ?>
<?php foreach ($scripts ?? [] as $s): ?>
<script src="<?= e(url($s)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
