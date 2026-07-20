import puppeteer from 'puppeteer-core';
const CHROME = '/home/claude/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome';
const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new',
  args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage'] });
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 2 });
await page.setRequestInterception(true);
page.on('request', (req) => {
  const u = req.url();
  const j = (o) => req.respond({ status:200, contentType:'application/json', body: JSON.stringify(o) });
  if (u.includes('session.php')) j({ status:'success', user:{ user_id:1, tenant_id:1, role:'advisor' } });
  else if (u.includes('goals_list.php')) j({ status:'success', goals:[
    { id:1, goal_type:'retirement', goal_label:'Retirement at 60', target_amount:50000000, target_date:'2050-04-01', initial_net_worth:12000000, inflation_rate:6, withdrawal_rate:3.5, drawdown_return_rate:8, projection_horizon_years:30 },
    { id:2, goal_type:'education', goal_label:"Daughter's UG education", target_amount:8000000, target_date:'2032-06-01', initial_net_worth:2000000, inflation_rate:8, withdrawal_rate:null, drawdown_return_rate:null, projection_horizon_years:10 },
  ]});
  else if (u.includes('goals_read.php')) j({ status:'success', goal:{ id:1, goal_type:'retirement', goal_label:'Retirement at 60', target_amount:50000000, target_date:'2050-04-01', initial_net_worth:12000000, inflation_rate:6, withdrawal_rate:3.5, drawdown_return_rate:8, projection_horizon_years:30 }});
  else if (u.includes('subscenarios_list.php')) j({ status:'success', sub_scenarios:[
    { id:1, custom_inflation:6, custom_withdrawal_rate:3.5, custom_drawdown_return_rate:8, is_overridden:false },
    { id:2, custom_inflation:8, custom_withdrawal_rate:3.0, custom_drawdown_return_rate:7, is_overridden:true },
  ]});
  else if (u.includes('goals_projection.php')) j({ status:'success',
    steady_return_series:[12000000,12960000,14000000,15120000,16330000,17640000,19050000,20580000,22220000,24000000,25920000,28000000,30240000,32660000,35270000,38100000,41150000,44440000,48000000,51840000,56000000,60480000,65320000,70550000,76200000,82300000,88880000,96000000,103680000,112000000,121000000],
    adverse_sequence_series:[12000000,10800000,9720000,10200000,10700000,11250000,11800000,12400000,13000000,13700000,14400000,15100000,15900000,16700000,17500000,18400000,19300000,20300000,21300000,22400000,23500000,24700000,25900000,27200000,28600000,30000000,31500000,33100000,34800000,36500000,38300000] });
  else req.continue();
});
async function shot(path, file, sel) {
  await page.goto('http://127.0.0.1:4174'+path, { waitUntil:'networkidle0', timeout:20000 });
  if (sel) await page.waitForSelector(sel, { timeout:8000 }).catch(()=>{});
  await new Promise(r=>setTimeout(r,700));
  await page.screenshot({ path:file, fullPage:true });
  console.log('shot', file);
}
await shot('/login', '/tmp/s-login.png', 'form');
await shot('/goals/1', '/tmp/s-goaldetail.png', 'main');
// expand a scenario on goal detail
await page.click('button').catch(()=>{});
await browser.close();
