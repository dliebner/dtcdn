const puppeteer = require('puppeteer');

(async () => {

	// 2. Launch the browser
	// The --no-sandbox argument is critical for running in a Linux server environment
	const browser = await puppeteer.launch({
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});
	const page = await browser.newPage();

	// Create a CDP session to send commands
	const client = await page.target().createCDPSession();

	// Clear the browser cache
	await client.send('Network.clearBrowserCache');

})();
