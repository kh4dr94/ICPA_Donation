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
                return route.fulfill({status: fail ? 502 : 200, contentType:'application/json', body: JSON.stringify(fail ? {success:false,data:{message:'Retry this attempt <script>unsafe</script>'}} : {success:true,data:{status:'completed',message:'Thank you. Your donation has been received.'}})});
            }
            return route.fulfill({contentType:'text/html', body:html});
        });
        await page.goto('https://donation.example.test');
        await page.evaluate(() => {
            window.tokenizations = [];
            window.Square = { payments: () => ({card: async () => ({attach: async selector => {document.querySelector(selector).textContent='Secure card iframe (test double)';}, tokenize: async details => {window.tokenizations.push(details); return {status:'OK',token:'cnon:browser-fixture'};}})})};
        });
        await page.addScriptTag({content:script});
        await page.locator('[name=amount]').fill('25.50');
        await page.locator('[name=first_name]').fill('Test');
        await page.locator('[name=last_name]').fill('Donor');
        await page.locator('[name=email]').fill('test@example.invalid');
        await page.getByRole('button',{name:'Donate',exact:true}).click();
        await page.waitForFunction(() => document.querySelector('.icpa-card-status').textContent.includes('Retry this attempt'));
        if (await page.locator('.icpa-card-status script').count()) throw Error('Unsafe error rendering');
        if (!(await page.locator('[name=amount]').isDisabled())) throw Error('Amount must stay bound to retry');
        fail = false;
        await page.getByRole('button',{name:'Donate',exact:true}).click();
        await page.getByRole('button',{name:'Donation received'}).waitFor();
        tokenizations = await page.evaluate(() => window.tokenizations);
        if (requests.length !== 2 || tokenizations.length !== 1) throw Error('Retries must not tokenize again');
        if (requests[0].get('checkout_key') !== requests[1].get('checkout_key') || requests[0].get('source_id') !== requests[1].get('source_id')) throw Error('Retry payment identity changed');
        if (tokenizations[0].amount !== '25.50' || tokenizations[0].currencyCode !== 'AUD' || !tokenizations[0].customerInitiated) throw Error('Buyer verification details missing');
        if (!(await page.getByRole('button',{name:'Donation received'}).isDisabled())) throw Error('Completed donation remains payable');
        if (requests[0].has('first_name') || requests[0].has('card_number')) throw Error('Unexpected sensitive fields sent');
        await page.goto('https://donation.example.test');
        await page.addScriptTag({content:script});
        await page.waitForFunction(() => document.querySelector('.icpa-card-status').textContent.includes('unavailable'));
        if (!(await page.getByRole('button',{name:'Donate',exact:true}).isDisabled())) throw Error('SDK load failure must disable payment');
        console.log('PASS: embedded card browser flow, buyer verification details, safe retry token reuse, locked amount, success state, escaped errors and SDK failure (SDK mocked)');
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
