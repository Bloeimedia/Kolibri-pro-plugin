import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const SOURCE_URL =
  process.env.PARARIUS_SOURCE_URL ||
  'https://www.pararius.nl/makelaars/woltersum/vici-vastgoed';
const OUTPUT_FILE =
  process.env.FEED_OUTPUT_FILE ||
  path.resolve(process.cwd(), '..', 'docs', 'feed.json');
const LIMIT = Math.max(1, Math.min(100, Number(process.env.FEED_LIMIT || 30)));

function normalizeUrl(url) {
  try {
    return new URL(url, 'https://www.pararius.nl').toString();
  } catch {
    return '';
  }
}

function normalizePrice(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  const m = raw.match(/([0-9][0-9\.\,]*)/);
  return m ? m[1].replace(/[^\d]/g, '') : '';
}

function stableId(url) {
  return 'pararius_' + crypto.createHash('sha1').update(url).digest('hex').slice(0, 16);
}

function cleanTitle(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function isDetailPath(url) {
  try {
    const pathname = new URL(url).pathname || '';
    return /\/[a-z-]+-te-huur\/[a-z0-9-]+\/[0-9a-f]{8}\//i.test(pathname);
  } catch {
    return false;
  }
}

function collectFromJsonLd(node, out) {
  if (Array.isArray(node)) {
    for (const item of node) collectFromJsonLd(item, out);
    return;
  }
  if (!node || typeof node !== 'object') return;

  const url = normalizeUrl(node.url || node['@id'] || node.mainEntityOfPage);
  const title = cleanTitle(node.name || node.headline || node.title || '');
  if (url && isDetailPath(url) && title) {
    const image = Array.isArray(node.image) ? node.image[0] : node.image;
    const imageUrl =
      typeof image === 'string'
        ? normalizeUrl(image)
        : image && typeof image.url === 'string'
          ? normalizeUrl(image.url)
          : '';
    out.push({
      title,
      url,
      price: normalizePrice(node.price || node.offers?.price || ''),
      image: imageUrl || '',
    });
  }

  for (const value of Object.values(node)) {
    collectFromJsonLd(value, out);
  }
}

async function scrapeCards() {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
      viewport: { width: 1600, height: 1200 },
    });

    await page.goto(SOURCE_URL, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(4000);

    const extracted = await page.evaluate(() => {
      const items = [];

      const pushItem = (item) => {
        if (!item || !item.url || !item.title) return;
        items.push(item);
      };

      const priceRegex = /€\s*([0-9\.\,]+)/i;

      for (const script of document.querySelectorAll('script[type="application/ld+json"]')) {
        const text = script.textContent || '';
        if (!text.trim()) continue;
        try {
          const json = JSON.parse(text);
          const stack = [json];
          while (stack.length) {
            const node = stack.pop();
            if (Array.isArray(node)) {
              for (const n of node) stack.push(n);
              continue;
            }
            if (!node || typeof node !== 'object') continue;

            const maybeUrl = String(node.url || node['@id'] || '').trim();
            const maybeTitle = String(
              node.name || node.headline || node.title || node.address?.streetAddress || ''
            )
              .replace(/\s+/g, ' ')
              .trim();
            if (maybeUrl && maybeTitle) {
              const image = Array.isArray(node.image) ? node.image[0] : node.image;
              const imageUrl =
                typeof image === 'string'
                  ? image
                  : image && typeof image.url === 'string'
                    ? image.url
                    : '';
              const p = String(node.price || node.offers?.price || '').trim();
              pushItem({ title: maybeTitle, url: maybeUrl, price: p, image: imageUrl });
            }
            for (const val of Object.values(node)) stack.push(val);
          }
        } catch {
          // ignore malformed json-ld
        }
      }

      const anchors = Array.from(document.querySelectorAll('a[href]'));
      for (const a of anchors) {
        const href = a.getAttribute('href') || '';
        const abs = new URL(href, location.origin).toString();
        const path = new URL(abs).pathname || '';
        if (!/\/[a-z-]+-te-huur\/[a-z0-9-]+\/[0-9a-f]{8}\//i.test(path)) continue;

        const card = a.closest('article, li, div') || a.parentElement;
        const text = (card?.textContent || '').replace(/\s+/g, ' ').trim();
        const heading = card?.querySelector('h1,h2,h3,h4');
        const title = (heading?.textContent || a.textContent || '').replace(/\s+/g, ' ').trim();
        const match = text.match(priceRegex);
        const price = match ? match[1] : '';
        const img = card?.querySelector('img');
        const image = (img?.currentSrc || img?.getAttribute('src') || '').trim();

        pushItem({ title, url: abs, price, image });
      }

      return items;
    });

    const cards = [];
    const seen = new Set();

    for (const raw of extracted) {
      const url = normalizeUrl(raw.url);
      if (!url || !isDetailPath(url) || seen.has(url)) continue;
      seen.add(url);

      const title = cleanTitle(raw.title);
      if (!title) continue;

      cards.push({
        external_id: stableId(url),
        title,
        url,
        price: normalizePrice(raw.price),
        image: normalizeUrl(raw.image),
      });
    }

    return cards.slice(0, LIMIT);
  } finally {
    await browser.close();
  }
}

async function main() {
  const cards = await scrapeCards();
  if (!cards.length) {
    throw new Error('Geen objecten gevonden op de Pararius pagina.');
  }

  const payload = {
    generated_at: new Date().toISOString(),
    source_url: SOURCE_URL,
    cards,
  };

  await fs.mkdir(path.dirname(OUTPUT_FILE), { recursive: true });
  await fs.writeFile(OUTPUT_FILE, JSON.stringify(payload, null, 2) + '\n', 'utf8');
  console.log(`Saved ${cards.length} cards to ${OUTPUT_FILE}`);
}

main().catch((err) => {
  console.error(err?.stack || String(err));
  process.exit(1);
});
