const express = require('express');
const puppeteer = require('puppeteer');
const PQueue = require('p-queue').default;

const app = express();
const PORT = process.env.PORT || 4101;

// Limit concurrent screenshots (prevents CPU/RAM spikes)
const queue = new PQueue({ concurrency: 10 });

// Launch one shared browser instance at startup
let browserPromise;
let reloading = false;

async function reloadBrowser() {
	if (reloading) return;
	reloading = true;

	try {
		browserPromise = puppeteer.launch({
			args: [
				'--no-sandbox',
				'--disable-setuid-sandbox',
				'--disable-dev-shm-usage',
				'--disable-gpu',
			],
			headless: true,
		});

		const browser = await browserPromise;
		console.log('Puppeteer browser launched');

		browser.on('disconnected', () => {
			console.log('Browser disconnected, restarting soon...');
			setTimeout(reloadBrowser, 0); // <-- decouple call stack
		});

	} catch (err) {
		console.error('Browser failed to launch:', err);
		setTimeout(reloadBrowser, 5000); // retry later
	} finally {
		reloading = false;
	}
}

reloadBrowser();

// Graceful shutdown
process.on('SIGTERM', async () => {
	console.log('SIGTERM received, waiting for queue to drain...');
	await queue.onIdle();
	const browser = await browserPromise;
	await browser.close();
	process.exit(0);
});

// Helper: take screenshot using an existing page
async function takeScreenshot(opts) {

	const {
		url,
		format = 'jpeg',
	} = opts;

	const debug = !!opts.debug;
	const vw = parseInt(opts.viewportWidth, 10) || 1200;
	const vh = parseInt(opts.viewportHeight, 10) || 1000;
	const cropX = parseInt(opts.cropX, 10) || 0;
	const cropY = parseInt(opts.cropY, 10) || 0;
	const cropWidth = parseInt(opts.cropWidth, 10) || null;
	const cropHeight = parseInt(opts.cropHeight, 10) || null;
	const quality = parseInt(opts.quality, 10) || 90;
	const devicePixelRatio = parseInt(opts.devicePixelRatio, 10) || 1;

	if (!url) throw new Error('Missing URL parameter');

	const browser = await browserPromise;

	const page = await browser.newPage();

	try {
		if (debug) {
			page.on('console', msg => {
				console.log(`[BROWSER ${msg.type().toUpperCase()}] ${msg.text()}`);
			});
		}

		// 3. Configure the page and navigate
		await page.setViewport({ width: vw, height: vh, deviceScaleFactor: devicePixelRatio });
		await page.goto(url, { waitUntil: 'networkidle0', timeout: 10000 });

		const screenshotOptions = {
			type: format === 'jpeg' ? 'jpeg' : 'png',
		};

		// Cropping options
		if (cropX || cropY || cropWidth || cropHeight) {
			screenshotOptions.clip = {
				x: cropX,
				y: cropY,
				width: cropWidth ?? (vw - cropX),
				height: cropHeight ?? (vh - cropY),
			};
		}

		if (screenshotOptions.type === 'jpeg' && quality) {
			screenshotOptions.quality = quality;
		}

		return await page.screenshot(screenshotOptions);
	} finally {
		await page.close();
	}
}

app.get('/screenshot', async (req, res) => {
	// Define preconfigs keyed by domain+page
	const configs = {
		'domo.town:user': {
			url: (slug) => `https://domo.town/@${slug}/#/ogScreenshot`,
			slugRegex: /^[\-\._0-9a-z]+$/i,
			viewportWidth: 1200,
			viewportHeight: 688,
			cropY: 60,
		},
	};

	const { domain, page, slug, format } = req.query;

	if (!domain || !page) {
		return res.status(400).json({ error: 'Missing required params: domain, page' });
	}

	const configKey = `${domain}:${page}`;
	const config = configs[configKey];

	if (!config) {
		return res.status(400).json({ error: `No preconfig found for ${configKey}` });
	}

	const {url, slugRegex, ...rest} = config;

	// Validate slug format
	if (slugRegex && !slugRegex.test(slug)) {
		return res.status(400).json({ error: `Invalid slug format for ${configKey}` });
	}

	const ssOpts = {
		url: url( slug ),
		format: (format || 'jpeg') === 'jpeg' ? 'jpeg' : 'png',
		...rest
	};

	// Add each job to the queue; the queue enforces concurrency
	queue.add(async () => {
		try {
			const buffer = await takeScreenshot( ssOpts );
			res.setHeader('Content-Type', `image/${ssOpts.format}`);
			res.send(buffer);
		} catch (err) {
			console.error(`[${new Date().toISOString()}] Screenshot failed:`, err);
			res.status(500).json({ error: err.message });
		}
	});
});

app.listen(PORT, () => {
	console.log(`Screenshotter service listening on http://localhost:${PORT}`);
});
