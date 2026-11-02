<?php if (!empty($pagination) && $pagination['pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pagination['page'] > 1): ?>
            <a href="<?= e(qs(array('page' => $pagination['page'] - 1))) ?>">上一页</a>
        <?php endif; ?>
        <?php
        $start = max(1, $pagination['page'] - 2);
        $end = min($pagination['pages'], $start + 4);
        for ($i = $start; $i <= $end; $i++):
        ?>
            <?php if ($i === $pagination['page']): ?>
                <span class="current"><?= (int) $i ?></span>
            <?php else: ?>
                <a href="<?= e(qs(array('page' => $i))) ?>"><?= (int) $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($pagination['page'] < $pagination['pages']): ?>
            <a href="<?= e(qs(array('page' => $pagination['page'] + 1))) ?>">下一页</a>
        <?php endif; ?>
        <span class="badge badge-muted">共 <?= (int) $pagination['total'] ?> 条 / <?= (int) $pagination['pages'] ?> 页</span>
    </div>
<?php endif; ?>