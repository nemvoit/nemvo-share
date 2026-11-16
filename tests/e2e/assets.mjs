// Regenerates .wordpress-org/: icons and banners from icon.svg, and the six screenshots
// listed in readme.txt, taken from the dev container (http://localhost:8070).
// The site title is set to a neutral name while the pictures are taken, then restored.
//
//   cd tests/e2e && npm install && node assets.mjs
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const here = dirname(fileURLToPath(import.meta.url));
const OUT = join(here, '../../.wordpress-org');
const BASE = process.env.BASE || 'http://localhost:8070';
const CHROME = process.env.CHROME || ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => existsSync(p));
const wp = (...args) => execFileSync('docker', ['compose', '-f', join(here, '../../.devcontainer/docker-compose.yml'), 'exec', '-T', 'wordpress', 'wp', ...args], { encoding: 'utf8' }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const icon = readFileSync(join(OUT, 'icon.svg'), 'utf8');
const browser = await puppeteer.launch({ executablePath: CHROME, headless: true });
const blogname = wp('option', 'get', 'blogname');
const theme = wp('theme', 'list', '--status=active', '--field=name');
try {
	wp('option', 'update', 'blogname', 'Northwind Studio');
	wp('theme', 'activate', 'twentytwentyfive');
	const page = await browser.newPage();

	/* Icons and banners */
	for (const size of [128, 256]) {
		await page.setViewport({ width: size, height: size });
		await page.setContent(`<style>body{margin:0}svg{width:${size}px;height:${size}px;display:block}</style>${icon}`);
		await page.screenshot({ path: join(OUT, `icon-${size}x${size}.png`) });
	}
	for (const [w, h] of [[1544, 500], [772, 250]]) {
		const s = w / 1544;
		await page.setViewport({ width: w, height: h });
		await page.setContent(`<style>
			body{margin:0;width:${w}px;height:${h}px;display:flex;align-items:center;gap:${56 * s}px;padding:0 ${110 * s}px;box-sizing:border-box;
			background:linear-gradient(120deg,#0b5f58 0%,#0f766e 55%,#14958a 100%);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#fff}
			svg{width:${210 * s}px;height:${210 * s}px;flex:none;filter:drop-shadow(0 ${12 * s}px ${30 * s}px rgba(0,0,0,.25))}
			h1{font-size:${92 * s}px;margin:0 0 ${14 * s}px;letter-spacing:-.02em;font-weight:700}
			p{font-size:${34 * s}px;margin:0;opacity:.92;line-height:1.35;max-width:${900 * s}px}
		</style>${icon.replace('rx="56" fill="#0f766e"', 'rx="56" fill="#0f766e" stroke="rgba(255,255,255,.35)" stroke-width="6"')}<div><h1>Nemvo Share</h1><p>Notes and files through secret links. Passwords, expiry and burn after reading. Encrypted on your own server.</p></div>`);
		await page.screenshot({ path: join(OUT, `banner-${w}x${h}.png`) });
	}

	/* Screenshots */
	const work = join(tmpdir(), 'nemvo-share-assets');
	mkdirSync(work, { recursive: true });
	const files = [['Brand guidelines 2026.pdf', 2_480_000], ['Website photos (originals).zip', 14_200_000]].map(([name, size]) => {
		const path = join(work, name);
		writeFileSync(path, randomBytes(size));
		return path;
	});

	const admin = await browser.newPage();
	await admin.setViewport({ width: 1440, height: 980 });
	await admin.goto(`${BASE}/wp-login.php`);
	await admin.type('#user_login', 'admin');
	await admin.type('#user_pass', 'admin');
	await Promise.all([admin.waitForNavigation(), admin.click('#wp-submit')]);

	await admin.goto(`${BASE}/wp-admin/admin.php?page=nemvo-share-new`);
	await admin.type('#nvs-title', 'Brand assets for the new website');
	await admin.type('#nvs-note', 'Hi Maria,\n\nHere are the brand guidelines and the original photos.\nThe CMS login for the staging site is:\n\n  user:     maria\n  password: Lt8v-Qe4n-Hb2x\n\nThanks!');
	const cdp = await admin.createCDPSession();
	await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 20, downloadThroughput: -1, uploadThroughput: 4 * 1024 * 1024 });
	await (await admin.$('#nvs-file-input')).uploadFile(...files);
	await admin.waitForFunction(() => document.querySelector('#nvs-uploads .is-done') && document.querySelector('#nvs-uploads .is-uploading'), { timeout: 60000 });
	await sleep(1200);
	await admin.screenshot({ path: join(OUT, 'screenshot-1.png') });
	await admin.waitForFunction(() => document.querySelectorAll('#nvs-uploads .is-done').length === 2, { timeout: 120000 });
	await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
	await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle0' }), admin.click('#nvs-submit')]);
	await admin.waitForSelector('.nvs-linkpanel.is-created');
	await sleep(500);
	await admin.screenshot({ path: join(OUT, 'screenshot-2.png'), clip: { x: 160, y: 32, width: 1280, height: 600 } });
	const link = await admin.$eval('#nvs-link', (e) => e.value);
	const password = await admin.$eval('#nvs-link-password', (e) => e.value);

	const visitor = await (await browser.createBrowserContext()).newPage();
	await visitor.setViewport({ width: 1280, height: 900 });
	await visitor.goto(link);
	await visitor.screenshot({ path: join(OUT, 'screenshot-4.png') });
	await visitor.type('#nvs-password', password);
	await Promise.all([visitor.waitForNavigation(), visitor.click('.nvs-form button[type="submit"]')]);
	await visitor.setViewport({ width: 1280, height: 1080 });
	await visitor.screenshot({ path: join(OUT, 'screenshot-3.png') });

	const burn = wp('nemvo-share', 'create', '--title=Database password for staging', '--note=Zx9q-Lm4w-Tt7p-Hc2v', '--burn', '--expires=1d', '--porcelain');
	await visitor.setViewport({ width: 1280, height: 900 });
	await visitor.goto(burn);
	await visitor.screenshot({ path: join(OUT, 'screenshot-5.png') });

	await admin.goto(`${BASE}/wp-admin/admin.php?page=nemvo-share`);
	await admin.screenshot({ path: join(OUT, 'screenshot-6.png') });
	console.log('Assets written to .wordpress-org/');
} finally {
	wp('option', 'update', 'blogname', blogname);
	wp('theme', 'activate', theme);
	await browser.close();
}
