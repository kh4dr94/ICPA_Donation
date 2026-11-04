const {chromium} = require('playwright');
const fs = require('fs');
(async () => {
    const browser = await chromium.launch({executablePath:'/usr/bin/chromium', args:['--no-sandbox']});
    try {
        const page = await browser.newPage();
        const html = fs.readFileSync('/tmp/icpa-card-view.html', 'utf8');
        const script = fs.readFileSync('icpa-fundraising/assets/card-payment.js', 'utf8');
        let requests = [], tokenizations = [], fail = true;
        await page.route('**/*', async route => {
            if (route.request().method() === 'POST') {
                requests.push(new URLSearchParams(route.request().postData()));
                return route.fulfill({status: fail ? 502 : 200, contentType:'application/json', body: JSON.stringify(fail ? {success:false,data:{message:'Retry this attempt <script>unsafe</script>'}} : {success:true,data:{status:'completed',title:'Test donation completed',message:'Your test payment was confirmed. No real money was charged.',amount:'AUD $25.50',reference:'ICPA-42'}})});
            }
            return route.fulfill({contentType:'text/html', body:'<meta charset="utf-8"><style>' + fs.readFileSync('icpa-fundraising/assets/platform.css','utf8') + '</style><style>button:hover{color:white!important}</style><div class="icpa-platform">' + html + '</div>'});
        });
        await page.goto('https://donation.example.test');
        await page.evaluate(() => {
            window.tokenizations = []; window.cardListeners = {};
            window.Square = { payments: () => ({card: async () => ({addEventListener: (name, callback) => {if (!['focusClassAdded','focusClassRemoved','errorClassAdded','errorClassRemoved','cardBrandChanged','postalCodeChanged'].includes(name)) throw Error('Unsupported Square card event'); window.cardListeners[name] = callback;}, attach: async selector => {document.querySelector(selector).textContent='Secure card iframe (test double)';}, tokenize: async details => {window.tokenizations.push(details); return {status:'OK',token:'cnon:browser-fixture'};}})})};
        });
        await page.addScriptTag({content:script});
        await page.addScriptTag({content:fs.readFileSync('icpa-fundraising/assets/platform.js', 'utf8')});
        const emit = async (field, state) => page.evaluate(({field,state}) => window.cardListeners.focusClassRemoved({detail:{field,currentState:state}}), {field,state});
        await emit('cardNumber', {isEmpty:false,isCompletelyValid:true,isPotentiallyValid:true});
        if (!(await page.locator('[data-card-field="cardNumber"]').textContent()).includes('✓')) throw Error('Card number tick missing');
        if ((await page.locator('.icpa-card-validity').textContent()).includes('✓ Card details complete')) throw Error('A valid card number alone must not validate every field');
        for (const field of ['expirationDate','cvv','postalCode']) {
            await emit(field, {isEmpty:false,isCompletelyValid:true,isPotentiallyValid:true});
            if (await page.locator('[data-card-field="' + field + '"]').getAttribute('data-state') !== 'complete') throw Error('Per-field card tick missing');
        }
        if (!(await page.locator('.icpa-card-validity').textContent()).includes('✓ Card details complete')) throw Error('Complete card indicator missing');
        await emit('cvv', {isEmpty:false,isCompletelyValid:false,isPotentiallyValid:false});
        if (await page.locator('[data-card-field="cvv"]').getAttribute('data-state') !== 'invalid') throw Error('Edited invalid CVV must lose its tick');
        if (!(await page.locator('.icpa-card-validity').textContent()).includes('Check the highlighted')) throw Error('Invalid card indicator missing');
        for (const field of ['cardNumber','expirationDate','cvv','postalCode']) await emit(field, {isEmpty:true,isCompletelyValid:false});
        if (!(await page.locator('.icpa-card-validity').isHidden())) throw Error('Empty card indicator should be hidden');
        await page.locator('.icpa-amounts button').first().click();
        const selection = await page.locator('.icpa-amounts button').first().evaluate(button => ({pressed:button.getAttribute('aria-pressed'), color:getComputedStyle(button).color, background:getComputedStyle(button).backgroundColor, check:getComputedStyle(button,'::before').content}));
        if (selection.pressed !== 'true' || selection.color !== 'rgb(40, 28, 70)' || selection.background !== 'rgb(255, 242, 197)' || !selection.check.includes('✓')) throw Error('Selected amount must remain readable under theme hover styles: ' + JSON.stringify(selection));
        await page.locator('[name=amount]').fill('25.50');
        await page.locator('[name=first_name]').fill('Test');
        await page.locator('[name=last_name]').fill('Donor');
        await page.locator('[name=email]').fill('test@example.invalid');
        for (const name of ['amount','first_name','last_name','email']) {
            if ((await page.locator('[data-validation-for="' + name + '"]').textContent()) !== '✓') throw Error('Contact field tick missing');
        }
        await page.locator('[name=email]').fill('invalid-email');
        if ((await page.locator('[data-validation-for="email"]').textContent()) !== '') throw Error('Invalid email retains tick');
        await page.locator('[name=email]').fill('test@example.invalid');
        await page.getByRole('button',{name:'Donate',exact:true}).click();
        await page.waitForFunction(() => document.querySelector('.icpa-card-status').textContent.includes('Retry this attempt'));
        if (await page.locator('.icpa-card-status script').count()) throw Error('Unsafe error rendering');
        if (!(await page.locator('[name=amount]').isDisabled())) throw Error('Amount must stay bound to retry');
        fail = false;
        await page.getByRole('button',{name:'Donate',exact:true}).click();
        await page.getByRole('heading',{name:'Test donation completed'}).waitFor();
        if (!(await page.locator('.icpa-payment-entry').isHidden())) throw Error('Completed payment must collapse card form');
        if (!(await page.locator('.icpa-result-amount').textContent()).includes('25.50') || !(await page.locator('.icpa-result-reference').textContent()).includes('ICPA-42')) throw Error('Confirmation details missing');
        if (!(await page.locator('.icpa-result-message').textContent()).includes('No real money')) throw Error('Sandbox success must be explicit');
        if (!(await page.locator('.icpa-payment-result').evaluate(node => node === document.activeElement))) throw Error('Confirmation must receive focus');
        await page.screenshot({path:'preview/donation-success.png',fullPage:true});
        tokenizations = await page.evaluate(() => window.tokenizations);
        if (requests.length !== 2 || tokenizations.length !== 1) throw Error('Retries must not tokenize again');
        if (requests[0].get('checkout_key') !== requests[1].get('checkout_key') || requests[0].get('source_id') !== requests[1].get('source_id')) throw Error('Retry payment identity changed');
        if (tokenizations[0].amount !== '25.50' || tokenizations[0].currencyCode !== 'AUD' || !tokenizations[0].customerInitiated) throw Error('Buyer verification details missing');
        if (!(await page.locator('button[type=submit]').isDisabled())) throw Error('Completed donation remains payable');
        if (requests[0].has('first_name') || requests[0].has('card_number')) throw Error('Unexpected sensitive fields sent');
        await page.goto('https://donation.example.test');
        await page.addScriptTag({content:script});
        await page.waitForFunction(() => document.querySelector('.icpa-card-status').textContent.includes('unavailable'));
        if (!(await page.getByRole('button',{name:'Donate',exact:true}).isDisabled())) throw Error('SDK load failure must disable payment');
        console.log('PASS: individual field ticks, aggregate card validation, prominent payment confirmation, live card-completeness feedback, readable selected amount under theme overrides, embedded card browser flow, buyer verification details, safe retry token reuse, locked amount, success state, escaped errors and SDK failure (SDK mocked)');
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
