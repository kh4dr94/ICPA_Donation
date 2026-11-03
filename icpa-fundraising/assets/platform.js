(function () {
    'use strict';
    document.addEventListener('click', async function (event) {
        const preset = event.target.closest('.icpa-amounts button');
        if (preset) {
            const form = preset.closest('form');
            const amount = form.querySelector('[name="amount"]');
            amount.value = preset.dataset.amount;
            form.querySelectorAll('.icpa-amounts button').forEach(button => button.setAttribute('aria-pressed', String(button === preset)));
            amount.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const share = event.target.closest('[data-share]');
        if (share) {
            const status = share.parentElement.querySelector('.icpa-share-status');
            try {
                await navigator.clipboard.writeText(share.dataset.share);
                status.textContent = 'Link copied.';
            } catch (_) {
                status.textContent = 'Campaign link: ' + share.dataset.share;
            }
        }
    });
    document.addEventListener('input', function (event) {
        if (event.target.matches('.icpa-donation-form [name="amount"]')) {
            event.target.closest('form').querySelectorAll('.icpa-amounts button').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.amount === event.target.value)));
        }
    });
}());
