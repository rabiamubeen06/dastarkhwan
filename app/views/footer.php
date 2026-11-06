<?php if (($layout ?? 'app') === 'auth'): ?>
  </div></main>
</div>
<?php else: ?>
</main>
<footer class="foot"><?= brand_ur() ?> &middot; Crockery rental</footer>
<?php endif; ?>
<?php foreach ($scripts ?? [] as $s): ?>
<script src="<?= e(url($s)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>