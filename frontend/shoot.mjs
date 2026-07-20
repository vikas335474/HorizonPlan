import puppeteer from 'puppeteer-core';
import { readdirSync } from 'fs';

const CHROME = '/home/claude/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome';

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: 'new',
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
});

const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 2 });

const url = process.argv[2];
const out = process.argv[3];
const waitFor = process.argv[4];

// Inject mock data via a route interceptor so we can see real screens
await page.setRequestInterception(true);
page.on('request', (req) => {
  const u = req.url();
  if (u.includes('/api/session.php')) {
    req.respond({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ status:'success', user:{ user_id:1, tenant_id:1, role:'advisor' } }) });
  } else if (u.includes('/api/clients_list.php')) {
    req.respond({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ status:'success',
        stats:{ total_clients:3, total_goals:5, total_aum:47500000 },
        clients:[
          { client_id:1, email:'ramesh.iyer@gmail.com', client_since:'2024-03-12', goal_count:2, total_net_worth:18500000 },
          { client_id:2, email:'priya.nair@outlook.com', client_since:'2024-07-01', goal_count:2, total_net_worth:22000000 },
          { client_id:3, email:'arjun.mehta@gmail.com', client_since:'2025-01-20', goal_count:1, total_net_worth:7000000 },
        ] }) });
  } else {
    req.continue();
  }
});

await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
if (waitFor) await page.waitForSelector(waitFor, { timeout: 8000 }).catch(()=>{});
await new Promise(r => setTimeout(r, 800));
await page.screenshot({ path: out, fullPage: true });
console.log('shot:', out);
await browser.close();
