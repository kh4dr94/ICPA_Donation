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

(function () {
    'use strict';
    function totals(form) {
        const amount = form.elements.amount;
        if (!amount || !/^\d{1,7}(?:\.\d{1,2})?$/.test(amount.value) || !amount.validity.valid) return null;
        const donation = Math.round(Number(amount.value) * 100);
        const cover = form.elements.cover_fees;
        const bps = Number(form.dataset.feeBps || 0), fixed = Number(form.dataset.feeFixed || 0);
        const total = cover && cover.value === '1' ? Math.ceil((donation + fixed) * 10000 / (10000 - bps)) : donation;
        return {donation: donation, fee: total - donation, total: total};
    }
    function update(form) {
        const summary = form.querySelector('.icpa-donation-summary');
        if (!summary) return;
        const values = totals(form);
        const money = cents => new Intl.NumberFormat('en-AU', {style:'currency',currency:form.dataset.currency,currencyDisplay:'code'}).format(cents/100);
        summary.querySelector('[data-summary-donation]').textContent = values ? money(values.donation) : 'Choose an amount';
        summary.querySelector('[data-summary-fee]').textContent = values ? money(values.fee) : '—';
        summary.querySelector('[data-summary-total]').textContent = values ? money(values.total) : 'Choose an amount';
    }
    window.ICPAFundraising = {totals:totals};
    document.querySelectorAll('.icpa-donation-form').forEach(update);
    ['input','change'].forEach(type => document.addEventListener(type, event => {
        const form = event.target.closest('.icpa-donation-form');
        if (form) update(form);
    }));
}());
