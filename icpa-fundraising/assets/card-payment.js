(function () {
    'use strict';
    document.querySelectorAll('form[data-card-payment]').forEach(async function (form) {
        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('.icpa-card-status');
        const fields = () => Array.from(form.querySelectorAll('input:not([type="hidden"]), .icpa-amounts button'));
        let card, busy = false, token = null, finished = false;
        // Stop native form navigation even when Square fails to load.
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || finished || !card || !form.reportValidity()) { return; }
            busy = true;
            button.disabled = true;
            fields().forEach(field => { field.disabled = true; });
            status.textContent = 'Processing your donation. Please keep this page open.';
            try {
                if (!token) {
                    const result = await card.tokenize({
                        amount: Number(form.elements.amount.value).toFixed(2),
                        currencyCode: form.dataset.currency,
                        intent: 'CHARGE', customerInitiated: true, sellerKeyedIn: false,
                        billingContact: { givenName: form.elements.first_name.value, familyName: form.elements.last_name.value, email: form.elements.email.value }
                    });
                    if (result.status !== 'OK') {
                        fields().forEach(field => { field.disabled = false; });
                        throw new Error('Please check your card details and complete any verification, then try again.');
                    }
                    token = result.token;
                }
                // Explicit field selection: card details never enter this request.
                const body = new URLSearchParams({ action: 'icpa_card_payment', source_id: token });
                ['_wpnonce', 'environment', 'campaign_id', 'checkout_key', 'amount', 'email'].forEach(name => body.set(name, form.elements[name].value));
                const response = await fetch(form.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body: body });
                let result;
                try { result = await response.json(); } catch (_) { throw new Error('Confirmation is unavailable. Retry this same attempt safely; contact ICPA before starting a new donation.'); }
                if (!response.ok || !result.success) { throw new Error(result.data && result.data.message ? result.data.message : 'Payment was not confirmed. Reload the page if your session expired.'); }
                finished = true;
                status.textContent = result.data.message;
                button.textContent = result.data.status === 'completed' ? 'Donation received' : 'Payment processing';
            } catch (error) {
                status.textContent = error instanceof TypeError ? 'Connection interrupted. Retry this same attempt safely; contact ICPA before starting a new donation.' : (error.message || 'Payment was not confirmed. Please contact ICPA before starting a new donation.');
            } finally {
                busy = false;
                button.disabled = finished;
                // A tokenized attempt retains its exact amount and token for safe retries.
                if (!token) { fields().forEach(field => { field.disabled = false; }); }
            }
        });
        try {
            if (!window.isSecureContext) { throw new Error('Card payments require a secure HTTPS connection.'); }
            if (!window.Square) { throw new Error('The secure card form could not load. Refresh the page or contact ICPA.'); }
            const payments = window.Square.payments(form.dataset.appId, form.dataset.locationId);
            card = await payments.card();
            await card.attach('#' + form.querySelector('.icpa-card-container').id);
            status.textContent = '';
            button.disabled = false;
        } catch (error) {
            status.textContent = 'Card payments are unavailable. Please refresh the page or contact ICPA.';
        }
    });
}());
