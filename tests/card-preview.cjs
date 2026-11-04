const {chromium} = require('playwright');
const fs = require('fs');
(async () => {
    const browser = await chromium.launch({executablePath:'/usr/bin/chromium', args:['--no-sandbox']});
    try {
        const page = await browser.newPage();
        const html = fs.readFileSync('/tmp/icpa-fee-view.html', 'utf8');
        const script = fs.readFileSync('icpa-fundraising/assets/card-payment.js', 'utf8');
        let requests = [], tokenizations = [], fail = true;
        await page.route('**/*', async route => {
            if (route.request().method() === 'POST') {
                requests.push(new URLSearchParams(route.request().postData()));
                return route.fulfill({status: fail ? 502 : 200, contentType:'application/json', body: JSON.stringify(fail ? {success:false,data:{message:'Retry this attempt <script>unsafe</script>'}} : {success:true,data:{status:'completed',title:'Test donation completed',message:'Your test payment was confirmed. No real money was charged.',amount:'AUD $26.39',donation:'AUD $25.50',contribution:'AUD $0.89',reference:'ICPA-42'}})});
            }
            return route.fulfill({contentType:'text/html', body:'<meta charset="utf-8"><style>' + fs.readFileSync('icpa-fundraising/assets/platform.css','utf8') + '</style><style>button:hover{color:white!important}</style><div class="icpa-platform"><section class="icpa-general-widget"><span class="icpa-eyebrow">Giving back to the community</span><h2>Donate &amp; Support</h2><p>Choose your gift. Every donation supports ICPA and our community.</p>' + html + '</section></div>'});
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
        await emit('postalCode', {isEmpty:false,isCompletelyValid:false,isPotentiallyValid:true,hasErrorClass:true});
        if (await page.locator('[data-card-field="postalCode"]').getAttribute('data-state') !== 'invalid') throw Error('Square postcode error must show red even if input is potentially valid');
        await emit('postalCode', {isEmpty:false,isCompletelyValid:true,isPotentiallyValid:true,hasErrorClass:false});
        if (await page.locator('[data-card-field="postalCode"]').getAttribute('data-state') !== 'complete') throw Error('Corrected postcode must recover its green check');
        await emit('cvv', {isEmpty:false,isCompletelyValid:false,isPotentiallyValid:false});
        if (await page.locator('[data-card-field="cvv"]').getAttribute('data-state') !== 'invalid') throw Error('Edited invalid CVV must lose its tick');
        if (!(await page.locator('.icpa-card-validity').textContent()).includes('Check the highlighted')) throw Error('Invalid card indicator missing');
        for (const field of ['cardNumber','expirationDate','cvv','postalCode']) await emit(field, {isEmpty:true,isCompletelyValid:false});
        if (!(await page.locator('.icpa-card-validity').isHidden())) throw Error('Empty card indicator should be hidden');
        await page.locator('.icpa-amounts button').first().click();
        const selection = await page.locator('.icpa-amounts button').first().evaluate(button => ({pressed:button.getAttribute('aria-pressed'), color:getComputedStyle(button).color, background:getComputedStyle(button).backgroundColor, check:getComputedStyle(button,'::before').content}));
        if (selection.pressed !== 'true' || selection.color !== 'rgb(40, 28, 70)' || selection.background !== 'rgb(255, 242, 197)' || !selection.check.includes('✓')) throw Error('Selected amount must remain readable under theme hover styles: ' + JSON.stringify(selection));
        await page.locator('[name=amount]').fill('25.50');
        if (!(await page.locator('[data-summary-total]').textContent()).includes('25.50')) throw Error('Default summary must show donation only');
        await page.locator('[name=cover_fees][value="1"]').check();
        if (!(await page.locator('[data-summary-fee]').textContent()).includes('0.89') || !(await page.locator('[data-summary-total]').textContent()).includes('26.39')) throw Error('Contribution breakdown must match rounded total');
        if (await page.locator('.icpa-required').count() < 9) throw Error('Required sections need visible markers');
        await page.locator('[name=first_name]').fill('Test');
        await page.locator('[name=last_name]').fill('Donor');
        await page.locator('[name=email]').fill('test@example.invalid');
        for (const name of ['amount','first_name','last_name','email']) {
            if ((await page.locator('[data-validation-for="' + name + '"]').textContent()) !== '✓') throw Error('Contact field tick missing');
        }
        await page.locator('[name=email]').fill('invalid-email');
        if ((await page.locator('[data-validation-for="email"]').textContent()) !== '') throw Error('Invalid email retains tick');
        await page.locator('[name=email]').fill('test@example.invalid');
        await page.screenshot({path:'preview/donation-form-desktop.png',fullPage:true});
        await page.setViewportSize({width:390,height:844});
        if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) throw Error('Embedded form overflows on mobile');
        const mobileNames = await page.locator('.icpa-name-grid').evaluate(node => getComputedStyle(node).gridTemplateColumns);
        if (mobileNames.split(' ').length !== 1) throw Error('Mobile name fields should stack');
        await page.screenshot({path:'preview/donation-form-mobile.png',fullPage:true});
        await page.setViewportSize({width:1280,height:900});
        await page.getByRole('button',{name:'Donate',exact:true}).click();
        await page.waitForFunction(() => document.querySelector('.icpa-card-status').textContent.includes('Retry this attempt'));
        if (await page.locator('.icpa-card-status script').count()) throw Error('Unsafe error rendering');
        if (!(await page.locator('[name=amount]').isDisabled())) throw Error('Amount must stay bound to retry');
        fail = false;
        await page.getByRole('button',{name:'Donate',exact:true}).click();
        await page.getByRole('heading',{name:'Test donation completed'}).waitFor();
        if (!(await page.locator('.icpa-payment-entry').isHidden())) throw Error('Completed payment must collapse card form');
        if (!(await page.locator('.icpa-result-amount').textContent()).includes('26.39') || !(await page.locator('.icpa-result-reference').textContent()).includes('ICPA-42')) throw Error('Confirmation details missing');
        if (!(await page.locator('.icpa-result-message').textContent()).includes('No real money')) throw Error('Sandbox success must be explicit');
        if (!(await page.locator('.icpa-payment-result').evaluate(node => node === document.activeElement))) throw Error('Confirmation must receive focus');
        await page.screenshot({path:'preview/donation-success.png',fullPage:true});
        tokenizations = await page.evaluate(() => window.tokenizations);
        if (requests.length !== 2 || tokenizations.length !== 1) throw Error('Retries must not tokenize again');
        if (requests[0].get('checkout_key') !== requests[1].get('checkout_key') || requests[0].get('source_id') !== requests[1].get('source_id')) throw Error('Retry payment identity changed');
        if (tokenizations[0].amount !== '26.39' || tokenizations[0].currencyCode !== 'AUD' || !tokenizations[0].customerInitiated) throw Error('Buyer verification details missing');
        if (!(await page.locator('button[type=submit]').isDisabled())) throw Error('Completed donation remains payable');
        if (requests[0].get('cover_fees') !== '1' || !requests[0].get('fee_quote')) throw Error('Contribution choice and signed form quote missing');
        if (requests[0].has('first_name') || requests[0].has('card_number')) throw Error('Unexpected sensitive fields sent');
        await page.goto('https://donation.example.test');
        await page.addScriptTag({content:script});
        await page.waitForFunction(() => document.querySelector('.icpa-card-status').textContent.includes('unavailable'));
        if (!(await page.getByRole('button',{name:'Donate',exact:true}).isDisabled())) throw Error('SDK load failure must disable payment');
        await page.setContent('<meta charset="utf-8"><style>' + fs.readFileSync('icpa-fundraising/assets/fee-settings.css','utf8') + '</style>' + fs.readFileSync('/tmp/icpa-fee-settings.html','utf8'));
        await page.addScriptTag({content:fs.readFileSync('icpa-fundraising/assets/fee-settings.js','utf8')});
        await page.getByText('Add to donation',{exact:true}).click();
        if (!(await page.locator('[name="icpa_fundraising_settings[fee_mode]"][value="pass_on"]').isChecked())) throw Error('Add-to-donation button failed');
        if (!(await page.locator('[data-fee-mode-description]').textContent()).includes('full total')) throw Error('Fee explanation must update');
        await page.getByText('Donor chooses',{exact:true}).click();
        const selectedMode = page.locator('[name="icpa_fundraising_settings[fee_mode]"][value="customer_choice"]');
        if (!(await selectedMode.isChecked()) || await selectedMode.evaluate(input => getComputedStyle(input.nextElementSibling).backgroundColor) !== 'rgb(40, 28, 70)') throw Error('Selected fee mode should be highlighted');
        await page.locator('.icpa-fee-mode-picker').screenshot({path:'preview/fee-handling-settings.png'});
        console.log('PASS: individual field ticks, aggregate card validation, prominent payment confirmation, live card-completeness feedback, readable selected amount under theme overrides, embedded card browser flow, buyer verification details, safe retry token reuse, locked amount, success state, escaped errors and SDK failure (SDK mocked)');
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
