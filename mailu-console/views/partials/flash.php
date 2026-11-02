<?php if (!empty($flashMessages)): ?>
    <?php foreach ($flashMessages as $message): ?>
        <div class="alert alert-<?= e($message['type']) ?>"><?= $message['message'] ?></div>
    <?php endforeach; ?>
<?php endif; ?>