const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
    const root = path.resolve(__dirname, '..');
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] });
    const page = await browser.newPage({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1 });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => route.abort());
    await page.setContent(fs.readFileSync(path.join(root, 'preview/fundraising-platform.html'), 'utf8'));
    await page.evaluate(() => document.fonts.ready);
    await page.evaluate(() => Promise.all(Array.from(document.images, image => image.decode())));
    await page.locator('.icpa-amounts button[data-amount="50"]').click();
    await page.locator('[name=amount]').fill('37.25');
    await page.getByRole('button', { name: 'Donate', exact: true }).click();
    if (!(await page.locator('#toast-text').textContent()).includes('37.25')) throw new Error('Custom general donation preview failed');
    await page.getByRole('button', { name: 'Dismiss message' }).click();
    await page.locator('.icpa-recurring-option summary').first().click();
    await page.getByRole('button', { name: 'Continue with monthly giving' }).click();
    if (!(await page.locator('#toast-text').textContent()).includes('No subscription or payment has been created')) throw new Error('Recurring preview did not explain that no subscription was created');
    await page.getByRole('button', { name: 'Dismiss message' }).click();
    await page.screenshot({ path: path.join(root, 'preview/general-donation.png'), fullPage: true });
    await page.getByRole('button', { name: 'Discover', exact: true }).click();
    await page.evaluate(() => document.fonts.ready);
    await page.evaluate(() => Promise.all(Array.from(document.images, image => image.decode())));
    if (await page.locator('.icpa-card').count() !== 3) throw new Error('Expected 3 demo campaigns');
    await page.screenshot({ path: path.join(root, 'preview/fundraising-desktop.png'), fullPage: true });
    await page.locator('.icpa-faq summary').first().click();
    if (!await page.locator('.icpa-faq details').first().getAttribute('open').then(value => value !== null)) throw new Error('FAQ accordion failed');
    await page.locator('.icpa-faq summary').first().click();
    await page.locator('.icpa-filters a').filter({ hasText: 'Education' }).click();
    if (await page.locator('.icpa-card:visible').count() !== 1) throw new Error('Cause filter failed');
    await page.locator('.icpa-filters a').filter({ hasText: 'All causes' }).click();
    await page.locator('#icpa-search').fill('care');
    await page.getByRole('button', { name: 'Search campaigns', exact: true }).click();
    if (await page.locator('.icpa-card:visible').count() !== 1) throw new Error('Search failed');
    await page.locator('#icpa-search').fill('no-such-campaign');
    await page.getByRole('button', { name: 'Search campaigns', exact: true }).click();
    if (!await page.locator('.demo-no-results').isVisible()) throw new Error('Search empty state failed');
    await page.getByRole('button', { name: 'Campaign', exact: true }).click();
    await page.evaluate(() => Promise.all(Array.from(document.images, image => image.decode())));
    await page.locator('.icpa-amounts button[data-amount="50"]').click();
    if (await page.locator('[name=amount]').inputValue() !== '50') throw new Error('Amount preset failed');
    await page.screenshot({ path: path.join(root, 'preview/fundraising-campaign.png'), fullPage: true });
    await page.getByRole('button', { name: 'Donate', exact: true }).click();
    if (!(await page.locator('#toast-text').textContent()).includes('No payment has been made')) throw new Error('Demo checkout guard failed');
    await page.getByRole('button', { name: 'Dismiss message' }).click();
    await page.getByRole('button', { name: 'Start a fundraiser', exact: true }).click();
    await page.locator('[name=title]').fill('A demo campaign for our community');
    await page.locator('[name=goal]').fill('5000');
    await page.locator('[name=cause]').selectOption({ index: 1 });
    await page.locator('[name=story]').fill('This is a sample story describing how our community will use the funds to support local families and provide useful resources.');
    await page.locator('[name=consent]').check();
    await page.getByRole('button', { name: 'Submit for review' }).click();
    if (!(await page.locator('.icpa-account-section').last().textContent()).includes('A demo campaign for our community')) throw new Error('Demo submission/account flow failed');
    await page.getByRole('button', { name: 'Discover', exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    for (const view of ['donate', 'browse', 'campaign0', 'start', 'account', 'settings', 'recurring_settings']) {
        await page.evaluate(view => render(view), view);
        if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)) throw new Error('Mobile overflow: ' + view);
        if (view === 'browse') await page.screenshot({ path: path.join(root, 'preview/fundraising-mobile.png'), fullPage: true });
    }
    if (errors.length) throw new Error(errors.join('\n'));
    await page.setViewportSize({ width: 1400, height: 1000 });
    await page.evaluate(() => {
        render('browse');
        const host = document.getElementById('app');
        host.style.maxWidth = '500px';
        host.style.fontFamily = 'Georgia';
        const component = host.querySelector('.icpa-platform');
        component.classList.add('icpa-theme-integrated');
        component.style.setProperty('--icpa-green', '#ffffff');
        component.style.setProperty('--icpa-on-accent', '#000000');
    });
    const embedded = await page.locator('.icpa-platform').evaluate(component => ({
        font: getComputedStyle(component).fontFamily,
        columns: getComputedStyle(component.querySelector('.icpa-grid')).gridTemplateColumns.split(' ').length,
        buttonText: getComputedStyle(component.querySelector('.icpa-filters .is-active')).color
    }));
    if (!embedded.font.includes('Georgia') || embedded.columns !== 1 || embedded.buttonText !== 'rgb(0, 0, 0)') throw new Error('Theme typography, narrow container or custom-colour contrast failed');
    if (errors.length) throw new Error(errors.join('\n'));
    await page.evaluate(() => { document.getElementById('app').style.maxWidth = ''; document.getElementById('app').style.fontFamily = ''; render('settings'); });
    if (await page.locator('input[name="profiles[sandbox][access_token]"]').inputValue() !== '' || await page.locator('input[name="profiles[production][signature_key]"]').inputValue() !== '') throw new Error('Settings preview exposed credentials');
    await page.screenshot({ path: path.join(root, 'preview/donation-settings.png'), fullPage: true });
    await page.evaluate(() => render('recurring_settings'));
    if (await page.locator('.icpa-create-recurring-plan').count() !== 2) throw new Error('Recurring creation must have separate Sandbox and Production forms');
    for (const environment of ['sandbox','production']) {
        const form = page.locator('.icpa-create-recurring-plan').filter({has:page.locator('input[name=environment][value=' + environment + ']')});
        await form.locator('[name=cadence]').selectOption('MONTHLY');
        await form.locator('[name=amount]').fill('25.00');
        if (!(await form.locator('[name=amount]').evaluate(input => input.checkValidity()))) throw new Error('Valid recurring creation amount rejected');
    }
    if (await page.locator('[name="cadences[]"]').count() !== 4) throw new Error('Recurring settings must expose four intervals');
    if (await page.locator('select[name=plan_id]').count() !== 8) throw new Error('Recurring settings must separate four intervals across both Square environments');
    await page.screenshot({ path: path.join(root, 'preview/recurring-settings.png'), fullPage: true });
    console.log('PASS: recurring settings, subscription preview, general donation, backend settings, preview navigation, cause/search filters, empty state, amount selection, demo checkout, submission/account flow, and seven mobile layouts');
    await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
