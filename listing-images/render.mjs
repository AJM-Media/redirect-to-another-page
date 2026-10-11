import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');

const src = path.dirname(fileURLToPath(import.meta.url));
const out = path.join(path.dirname(src), '.wordpress-org');

const jobs = [
	{ html: 'icon.html', file: 'icon-256x256.png', width: 256, height: 256, scale: 1 },
	{ html: 'icon.html', file: 'icon-128x128.png', width: 128, height: 128, scale: 1 },
	{ html: 'banner.html', file: 'banner-772x250.png', width: 772, height: 250, scale: 1 },
	{ html: 'banner.html', file: 'banner-1544x500.png', width: 772, height: 250, scale: 2 },
];

const browser = await chromium.launch(process.env.PW_CHANNEL ? { channel: process.env.PW_CHANNEL } : {});
for (const job of jobs) {
	const page = await browser.newPage({ viewport: { width: job.width, height: job.height }, deviceScaleFactor: job.scale });
	await page.goto(pathToFileURL(path.join(src, job.html)).href);
	await page.screenshot({ path: path.join(out, job.file) });
	await page.close();
	console.log(job.file);
}
await browser.close();
