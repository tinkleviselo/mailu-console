/* Mailu Console - small vanilla helper script (no external dependencies). */
(function () {
    'use strict';

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-confirm]');
        if (target && !window.confirm(target.getAttribute('data-confirm'))) {
            event.preventDefault();
            return false;
        }
        var copy = event.target.closest('[data-copy]');
        if (copy) {
            var text = copy.getAttribute('data-copy');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text);
            } else {
                var area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                document.body.removeChild(area);
            }
            var old = copy.textContent;
            copy.textContent = '已复制';
            setTimeout(function () { copy.textContent = old; }, 1200);
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-generate-password]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        var length = parseInt(trigger.getAttribute('data-length') || '16', 10);
        var targetSelector = trigger.getAttribute('data-generate-password');
        var lower = 'abcdefghijkmnopqrstuvwxyz', upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var digits = '23456789', symbols = '!@#$%^&*-_=+';
        var all = lower + upper + digits + symbols;
        var out = '';
        var buffer = new Uint32Array(length);
        (window.crypto || window.msCrypto).getRandomValues(buffer);
        for (var i = 0; i < length; i++) {
            out += all[buffer[i] % all.length];
        }
        var field = document.querySelector(targetSelector);
        if (field) {
            field.value = out;
            field.type = 'text';
            var wrap = field.closest('.field');
            if (wrap) {
                var box = wrap.querySelector('.generated-password');
                if (!box) {
                    box = document.createElement('div');
                    box.className = 'hint generated-password';
                    wrap.appendChild(box);
                }
                box.textContent = '已生成密码（请立即保存）';
            }
        }
    });

    qsa('form[data-guard]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var button = form.querySelector('button[type=submit]');
            if (button) {
                button.disabled = true;
                button.textContent = button.getAttribute('data-busy') || '处理中...';
            }
        });
    });

    qsa('[data-select-all]').forEach(function (box) {
        box.addEventListener('change', function () {
            var name = box.getAttribute('data-select-all');
            qsa('input[name="' + name + '"]').forEach(function (input) {
                input.checked = box.checked;
            });
        });
    });

    // Tab deep links: keep the requested tab after a form post.
    if (window.location.hash) {
        var tab = document.querySelector('.tabs a[href="' + window.location.hash + '"]');
        if (tab) {
            tab.classList.add('active');
        }
    }
})();