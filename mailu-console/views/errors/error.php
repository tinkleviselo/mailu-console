<div class="card">
    <h1>出错了 (<?= (int) $status ?>)</h1>
    <p><?= e($message !== '' ? $message : '请求无法完成') ?></p>
    <div class="btn-row">
        <a class="btn btn-primary" href="<?= e(url('/')) ?>">返回总览</a>
    </div>
</div>