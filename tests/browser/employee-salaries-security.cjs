// Browser-only rendering regression. No server, account or database writes.
// Run with playwright-core available to Node and CHROME_BIN pointing to Chrome.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright-core');
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
  try {
    for (const locale of ['ar', 'en']) {
      const page = await browser.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      const textIds = ['pName','pRole','pId','pAvatar','month','earnItems','deductItems','earnSum','deductSum','netAmount','formula'];
      await page.setContent(`<html lang="${locale}" dir="${locale === 'ar' ? 'rtl' : 'ltr'}"><body><input id="search"><select id="dept"><option value=""></option></select><select id="state"><option value=""></option></select><table><tbody id="employees"></tbody></table><div id="empty"></div><div id="drawer"></div><div id="drawerOverlay"></div>${textIds.map(id => `<div id="${id}"></div>`).join('')}</body></html>`);
      const payload = '<img src=x onerror="window.__salaryXssMarker=1">';
      await page.evaluate(({payload, locale}) => {
        window.masarT = value => value;
        window.masarStaticHtml = value => value;
        window.masarEmployeeSalaries = { isCurrentMonth: true, people: [{ id: 1, recordId: 1, name: payload, initials: payload, code: payload, structureLabel: payload, effectiveFrom: payload, salaryUrl: 'javascript:window.__salaryXssMarker=1', items: [[payload, 10]], deductions: [[payload, 2]], basic: 10, allow: 0, net: 8, state: 'Draft' }] };
      }, { payload, locale });
      await page.addScriptTag({ content: fs.readFileSync(path.resolve(__dirname, '../../public/payroll-assets/employee-salaries.js'), 'utf8') });
      assert.equal(await page.evaluate(() => window.__salaryXssMarker), undefined);
      assert.equal(await page.locator('#employees img, #earnItems img, #deductItems img').count(), 0);
      assert.equal(await page.locator('#employees b').first().textContent(), payload);
      assert.equal(await page.locator('#earnItems span').textContent(), payload);
      assert.equal(await page.locator('#deductItems span').textContent(), payload);
      assert.equal(await page.locator('.salary-structure').getAttribute('href'), '#');
      assert.equal(await page.locator('#employees tr').getAttribute('aria-label'), 'عرض مسير راتب ' + payload);
      assert.deepEqual(errors, []);
      await page.close();
      console.log(locale + ': unsafe text rendered literally; no markup, execution or unsafe URL');
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
