const express = require('express');
const puppeteer = require('puppeteer');

const app = express();

// Use the PORT environment variable if available, otherwise default to 4101
const PORT = process.env.PORT || 4101;

app.get('/screenshot', async (req, res) => {

	const rq = req.query;
	const {
		url,
		format = 'jpeg',
	} = rq;

    const vw = parseInt(rq.viewportWidth, 10) || 1200;
    const vh = parseInt(rq.viewportHeight, 10) || 1000;
    const cropX = parseInt(rq.cropX, 10) || 0;
    const cropY = parseInt(rq.cropY, 10) || 0;
    const cropWidth = parseInt(rq.cropWidth, 10) || null;
    const cropHeight = parseInt(rq.cropHeight, 10) || null;
    const quality = parseInt(rq.quality, 10) || 90;
    const devicePixelRatio = parseInt(rq.devicePixelRatio, 10) || 2;

	// 1. Validate input
	if (!url) {
		return res.status(400).send({ error: 'Please provide a URL parameter.' });
	}

	let browser = null;
	try {
		// 2. Launch the browser
		// The --no-sandbox argument is critical for running in a Linux server environment
		browser = await puppeteer.launch({
			args: ['--no-sandbox', '--disable-setuid-sandbox']
		});
		const page = await browser.newPage();

		// 3. Configure the page and navigate
		await page.setViewport({ width: vw, height: vh });
		await page.goto(url, { waitUntil: 'networkidle0', timeout: 10000 });

        // --- Screenshot Options ---
        const screenshotOptions = {
            type: format === 'jpeg' ? 'jpeg' : 'png', // Default to png for safety
			devicePixelRatio: devicePixelRatio
        };

        // Add crop/clip options if all are provided
        if (cropX || cropY || cropWidth || cropHeight) {
            screenshotOptions.clip = {
                x: parseInt(cropX),
                y: parseInt(cropY),
                width: parseInt(cropWidth ?? (vw - cropX)),
                height: parseInt(cropHeight ?? (vh - cropY))
            };
        }

        // Add quality option only if it's a JPEG and quality is provided
        if (screenshotOptions.type === 'jpeg' && quality) {
            screenshotOptions.quality = parseInt(quality);
        }

		// 4. Capture the screenshot
        const imageBuffer = await page.screenshot(screenshotOptions);

		// 5. Send the successful response
        res.setHeader('Content-Type', `image/${screenshotOptions.type}`);
		res.send(imageBuffer);

	} catch (error) {
		// 6. Handle errors
		console.error(`[${new Date().toISOString()}] Screenshot failed for URL: ${url}`, error);
		res.status(500).send({ error: 'Failed to capture screenshot.', details: error.message });
	} finally {
		// 7. CRITICAL: Always close the browser to prevent memory leaks
		if (browser) {
			await browser.close();
		}
	}
});

app.listen(PORT, () => {
	console.log(`Screenshotter service listening on http://localhost:${PORT}`);
});
