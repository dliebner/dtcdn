const puppeteer = require('puppeteer');

(async () => {

    console.log('Launching browser...');
    const browser = await puppeteer.launch({
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    const page = await browser.newPage();

    console.log('Creating CDP session...');
    const client = await page.target().createCDPSession();

    console.log('Sending command to clear browser cache...');
    await client.send('Network.clearBrowserCache');
    console.log('Browser cache cleared successfully.');

    // CRITICAL: Close the browser to terminate the script.
    await browser.close();
    console.log('Browser closed.');

})();
