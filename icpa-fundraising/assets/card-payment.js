(function () {
    'use strict';
    document.querySelectorAll('form[data-card-payment]').forEach(async function (form) {
        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('.icpa-card-status');
        const validity = form.querySelector('.icpa-card-validity');
        const cardStates = {};
        const checkContact = () => {
            ['amount', 'first_name', 'last_name', 'email'].forEach(name => {
                const field = form.elements[name];
                const indicator = form.querySelector('[data-validation-for="' + name + '"]');
                if (!field || !indicator) { return; }
                const valid = field.value.trim() !== '' && field.validity.valid;
                indicator.textContent = valid ? '✓' : '';
                indicator.setAttribute('aria-label', valid ? 'Complete' : '');
            });
        };
        form.addEventListener('input', checkContact);
        form.addEventListener('change', checkContact);
        checkContact();
        const fields = () => Array.from(form.querySelectorAll('input:not([type="hidden"]), .icpa-amounts button'));
        let card, busy = false, token = null, finished = false;
        // Stop native form navigation even when Square fails to load.
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || finished || !card || !form.reportValidity()) { return; }
            const breakdown = window.ICPAFundraising && window.ICPAFundraising.totals(form);
            if (!breakdown) { status.textContent = 'Enter a valid donation amount.'; return; }
            if (breakdown.total > Math.round(Number(form.elements.amount.max) * 100)) { status.textContent = 'The total exceeds the donation limit. Reduce your amount or choose donation only.'; return; }
            busy = true;
            button.disabled = true;
            fields().forEach(field => { field.disabled = true; });
            status.textContent = 'Processing your donation. Please keep this page open.';
            try {
                if (!token) {
                    const result = await card.tokenize({
                        amount: (breakdown.total / 100).toFixed(2),
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
                ['_wpnonce', 'environment', 'campaign_id', 'checkout_key', 'amount', 'email', 'fee_quote'].forEach(name => body.set(name, form.elements[name].value));
                const contribution = form.elements.cover_fees;
                body.set('cover_fees', contribution ? contribution.value : '0');
                const response = await fetch(form.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body: body });
                let result;
                try { result = await response.json(); } catch (_) { throw new Error('Confirmation is unavailable. Retry this same attempt safely; contact ICPA before starting a new donation.'); }
                if (!response.ok || !result.success) { throw new Error(result.data && result.data.message ? result.data.message : 'Payment was not confirmed. Reload the page if your session expired.'); }
                finished = true;
                status.textContent = result.data.message;
                const panel = form.querySelector('.icpa-payment-result');
                if (panel) {
                    const complete = result.data.status === 'completed';
                    panel.dataset.state = complete ? 'completed' : 'pending';
                    panel.querySelector('.icpa-result-icon').textContent = complete ? '✓' : '…';
                    panel.querySelector('.icpa-result-title').textContent = result.data.title;
                    panel.querySelector('.icpa-result-message').textContent = result.data.message;
                    panel.querySelector('.icpa-result-amount').textContent = result.data.amount;
                    panel.querySelector('.icpa-result-breakdown').textContent = 'Donation: ' + result.data.donation + ' · Processing-cost contribution: ' + result.data.contribution;
                    panel.querySelector('.icpa-result-reference').textContent = 'Donation reference: ' + result.data.reference;
                    panel.hidden = false;
                    form.querySelector('.icpa-payment-entry').hidden = true;
                    panel.focus({preventScroll: true});
                    panel.scrollIntoView({block:'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
                    try { await card.destroy(); } catch (_) { /* Confirmation is already saved; display it even if iframe cleanup fails. */ }
                }
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
            card = await payments.card({ style: { input: { fontSize: '16px', fontFamily: 'Arial, sans-serif', color: '#333333' }, 'input::placeholder': { color: '#808080' }, '.input-container': { borderColor: '#d8dce3', borderRadius: '6px' }, '.input-container.is-focus': { borderColor: '#281c46' } } });
            const updateCardField = function (event) {
                if (!validity || busy || finished) { return; }
                const detail = event.detail;
                if (!detail || !detail.currentState) { return; }
                const indicator = Array.from(form.querySelectorAll('[data-card-field]')).find(node => node.dataset.cardField === detail.field);
                if (!indicator) { return; }
                indicator.hidden = false;
                const state = detail.currentState;
                const invalidField = state.hasErrorClass === true || detail.eventType === 'errorClassAdded' || state.isPotentiallyValid === false;
                const value = invalidField ? 'invalid' : (state.isEmpty ? 'empty' : (state.isCompletelyValid ? 'complete' : 'incomplete'));
                cardStates[detail.field] = value;
                indicator.dataset.state = value;
                indicator.querySelector('.icpa-check-icon').textContent = value === 'complete' ? '✓' : (value === 'invalid' ? '!' : '○');
                indicator.querySelector('.icpa-check-description').textContent = value === 'complete' ? ': complete' : (value === 'invalid' ? ': check this field' : ': not completed');
                const values = Object.values(cardStates);
                const allComplete = ['cardNumber', 'expirationDate', 'cvv', 'postalCode'].every(field => cardStates[field] === 'complete');
                const invalid = values.includes('invalid');
                validity.hidden = values.every(value => value === 'empty');
                validity.dataset.state = allComplete ? 'complete' : (invalid ? 'invalid' : 'incomplete');
                validity.textContent = allComplete ? '✓ Card details complete' : (invalid ? 'Check the highlighted card details' : 'Enter the remaining card details');
            };
            ['focusClassAdded', 'focusClassRemoved', 'errorClassAdded', 'errorClassRemoved', 'cardBrandChanged', 'postalCodeChanged'].forEach(type => card.addEventListener(type, updateCardField));
            await card.attach('#' + form.querySelector('.icpa-card-container').id);
            status.textContent = '';
            button.disabled = false;
        } catch (error) {
            status.textContent = 'Card payments are unavailable. Please refresh the page or contact ICPA.';
        }
    });
}());
