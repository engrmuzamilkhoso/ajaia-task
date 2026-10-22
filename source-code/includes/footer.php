<?php /** @var string[] $scripts */ ?>
<div class="toast-region" id="toast-region" role="status" aria-live="polite"></div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($scripts ?? [] as $script): ?>
<script src="<?= e(asset('js/' . $script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
