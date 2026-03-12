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

function cleanText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function normalizeUrl(value) {
  const raw = cleanText(value);
  if (!raw) return '';
  try {
    return new URL(raw, 'https://www.pararius.nl').toString();
  } catch {
    return '';
  }
}

function isDetailPath(url) {
  try {
    const pathname = new URL(url).pathname || '';
    return /\/[a-z-]+-te-huur\/[a-z0-9-]+\/[0-9a-f]{8}\//i.test(pathname);
  } catch {
    return false;
  }
}

function normalizePrice(value) {
  const raw = cleanText(value);
  if (!raw) return '';
  const m = raw.match(/([0-9][0-9\.\,]*)/);
  return m ? m[1].replace(/[^\d]/g, '') : '';
}

function normalizeInt(value) {
  const raw = cleanText(value);
  if (!raw) return '';
  const digits = raw.match(/\d+/);
  return digits ? String(Number(digits[0])) : '';
}

function stableId(url) {
  return 'pararius_' + crypto.createHash('sha1').update(url).digest('hex').slice(0, 16);
}

function extractDetailUrlsFromText(text) {
  const out = [];
  const seen = new Set();
  const regex = /https?:\/\/www\.pararius\.nl\/[a-z-]+-te-huur\/[a-z0-9-]+\/[0-9a-f]{8}\/[a-z0-9-]+/gi;
  let m;
  while ((m = regex.exec(String(text || ''))) !== null) {
    const url = normalizeUrl(m[0]);
    if (!url || seen.has(url)) continue;
    seen.add(url);
    out.push(url);
  }
  return out;
}

async function fetchViaJina(url) {
  const normalized = normalizeUrl(url);
  if (!normalized) return '';
  const target = 'https://r.jina.ai/http://' + normalized.replace(/^https?:\/\//, '');
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 25000);
  try {
    const res = await fetch(target, {
      signal: controller.signal,
      headers: {
        Accept: 'text/plain',
      },
    });
    if (!res.ok) return '';
    return await res.text();
  } catch {
    return '';
  } finally {
    clearTimeout(timeout);
  }
}

function parseJinaListingItems(markdown) {
  const urls = extractDetailUrlsFromText(markdown);
  return urls.map((url) => ({
    title: '',
    url,
    price: '',
    image: '',
  }));
}

function parseJinaDetail(markdown, detailUrl) {
  const text = String(markdown || '');
  const titleMatch = text.match(/^Title:\s*(.+)$/m);
  const title = cleanText(titleMatch ? titleMatch[1] : '');

  const priceMatch = text.match(/€\s*([0-9\.\,]+)/i);
  const price = normalizePrice(priceMatch ? priceMatch[1] : '');

  const imageCandidates = [];
  const imageRegex = /!\[[^\]]*\]\((https?:\/\/[^\s)]+)\)/g;
  let im;
  while ((im = imageRegex.exec(text)) !== null) {
    imageCandidates.push(im[1]);
  }

  let description = '';
  const descMatch = text.match(
    /Beschrijving\s*\n[-=]+\n([\s\S]*?)(?:\n(?:Meer|Overdracht|Oppervlakte en inhoud|Bouw|Indeling|Buitenruimte)\n[-=]+)/i
  );
  if (descMatch) {
    description = cleanText(descMatch[1]);
  }

  let city = '';
  const cityFromTitle = title.match(/\bin\s+([A-Za-zÀ-ÿ' -]+)$/u);
  if (cityFromTitle) city = cleanText(cityFromTitle[1]);

  const roomsMatch = text.match(/(\d+)\s*kamer/i);
  const areaMatch = text.match(/(\d+)\s*m(?:²|2)/i);

  return {
    title,
    price,
    description,
    images: pickImages(imageCandidates, 20),
    city,
    rooms: normalizeInt(roomsMatch ? roomsMatch[1] : ''),
    area: normalizeInt(areaMatch ? areaMatch[1] : ''),
    url: normalizeUrl(detailUrl),
  };
}

async function readExistingFeedCards() {
  try {
    const raw = await fs.readFile(OUTPUT_FILE, 'utf8');
    const json = JSON.parse(raw);
    if (!json || !Array.isArray(json.cards)) return [];
    return json.cards
      .map((c) => ({
        title: cleanText(c?.title || ''),
        url: normalizeUrl(c?.url || ''),
        price: normalizePrice(c?.price || ''),
        image: normalizeUrl(c?.image || ''),
      }))
      .filter((c) => c.url);
  } catch {
    return [];
  }
}

function isLikelyImageUrl(url) {
  try {
    const u = new URL(url);
    const p = (u.pathname || '').toLowerCase();
    if (!p || p === '/' || p === '/favicon.ico') return false;
    if (/\.(jpg|jpeg|png|gif|webp|avif|bmp|heic|heif)(?:$|\?)/i.test(url)) return true;
    if (p.includes('/media/') || p.includes('/image/') || p.includes('/img/')) return true;
    if (p.includes('/logo')) return false;
    return true;
  } catch {
    return false;
  }
}

function dedupeStrings(values) {
  const out = [];
  const seen = new Set();
  for (const v of values) {
    const c = cleanText(v);
    if (!c) continue;
    if (seen.has(c)) continue;
    seen.add(c);
    out.push(c);
  }
  return out;
}

function pickBestDescription(candidates) {
  const clean = dedupeStrings(candidates).filter((v) => v.length >= 40);
  if (!clean.length) return '';
  clean.sort((a, b) => b.length - a.length);
  return clean[0];
}

function pickBestTitle(candidates, fallback = '') {
  const clean = dedupeStrings(candidates).filter((v) => {
    if (v.length < 4) return false;
    const lc = v.toLowerCase();
    if (lc.includes('pararius.nl')) return false;
    if (lc === 'www.pararius.nl') return false;
    return true;
  });
  if (clean.length) return clean[0];
  return cleanText(fallback);
}

function pickImages(candidates, limit = 20) {
  const normalized = [];
  const seen = new Set();
  for (const raw of candidates) {
    const url = normalizeUrl(raw);
    if (!url || !isLikelyImageUrl(url)) continue;
    if (seen.has(url)) continue;
    seen.add(url);
    normalized.push(url);
    if (normalized.length >= limit) break;
  }
  return normalized;
}

function pickCity(candidates) {
  const clean = dedupeStrings(candidates);
  for (const c of clean) {
    if (/^[a-zA-ZÀ-ÿ' -]{2,}$/u.test(c)) return c;
  }
  return '';
}

async function collectListingItems(page) {
  return page.evaluate(() => {
    const clean = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const isDetail = (url) => {
      try {
        const path = new URL(url).pathname || '';
        return /\/[a-z-]+-te-huur\/[a-z0-9-]+\/[0-9a-f]{8}\//i.test(path);
      } catch {
        return false;
      }
    };
    const abs = (v) => {
      try {
        return new URL(String(v || ''), location.origin).toString();
      } catch {
        return '';
      }
    };
    const parseSrcset = (srcset) => {
      const first = String(srcset || '').split(',')[0] || '';
      return first.trim().split(/\s+/)[0] || '';
    };

    const items = [];
    const pushed = new Set();
    const priceRegex = /€\s*([0-9\.\,]+)/i;

    for (const a of document.querySelectorAll('a[href]')) {
      const url = abs(a.getAttribute('href'));
      if (!url || !isDetail(url)) continue;
      if (pushed.has(url)) continue;
      pushed.add(url);

      const card = a.closest('article,li,div') || a.parentElement;
      const heading = card?.querySelector('h1,h2,h3,h4');
      const title = clean(heading?.textContent || a.textContent || '');

      const blockText = clean(card?.textContent || '');
      const priceMatch = blockText.match(priceRegex);
      const price = priceMatch ? priceMatch[1] : '';

      const img =
        card?.querySelector('img') ||
        card?.querySelector('source[srcset]') ||
        a.querySelector('img') ||
        a.querySelector('source[srcset]');

      let image = '';
      if (img) {
        image = clean(
          img.currentSrc ||
            img.getAttribute?.('src') ||
            img.getAttribute?.('data-src') ||
            img.getAttribute?.('data-original') ||
            parseSrcset(img.getAttribute?.('srcset') || '') ||
            ''
        );
      }

      if (title) {
        items.push({ title, url, price, image });
      }
    }

    return items;
  });
}

async function looksLikeChallenge(page) {
  try {
    const state = await page.evaluate(() => {
      const title = String(document.title || '').toLowerCase();
      const body = String(document.body?.innerText || '').toLowerCase();
      const blocked =
        title.includes('just a moment') ||
        body.includes('enable javascript and cookies to continue') ||
        body.includes('challenge-platform') ||
        body.includes('cf_chl_opt');
      return { blocked, title };
    });
    return !!state?.blocked;
  } catch {
    return false;
  }
}

async function waitForListingItems(page) {
  for (let i = 0; i < 8; i++) {
    const blocked = await looksLikeChallenge(page);
    if (!blocked) {
      const items = await collectListingItems(page);
      if (items.length) return items;
    }
    await page.waitForTimeout(5000);
  }
  return [];
}

async function collectDetailData(context, url) {
  const page = await context.newPage({
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    viewport: { width: 1600, height: 1200 },
  });

  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 });
    for (let i = 0; i < 8; i++) {
      const blocked = await looksLikeChallenge(page);
      if (!blocked) break;
      await page.waitForTimeout(4000);
    }
    await page.waitForTimeout(1500);

    return await page.evaluate(() => {
      const clean = (v) => String(v || '').replace(/\s+/g, ' ').trim();
      const abs = (v) => {
        try {
          return new URL(String(v || ''), location.origin).toString();
        } catch {
          return '';
        }
      };
      const parseSrcset = (srcset) => {
        const out = [];
        const parts = String(srcset || '')
          .split(',')
          .map((p) => p.trim())
          .filter(Boolean);
        for (const part of parts) {
          out.push(part.split(/\s+/)[0] || '');
        }
        return out;
      };
      const descCandidates = [];
      const titleCandidates = [];
      const priceCandidates = [];
      const imageCandidates = [];
      const cityCandidates = [];
      const roomsCandidates = [];
      const areaCandidates = [];

      const push = (arr, value) => {
        const c = clean(value);
        if (c) arr.push(c);
      };

      const imagePush = (value) => {
        const url = abs(value);
        if (url) imageCandidates.push(url);
      };

      for (const script of document.querySelectorAll('script[type="application/ld+json"]')) {
        const text = script.textContent || '';
        if (!text.trim()) continue;
        try {
          const data = JSON.parse(text);
          const stack = [data];
          while (stack.length) {
            const node = stack.pop();
            if (Array.isArray(node)) {
              for (const n of node) stack.push(n);
              continue;
            }
            if (!node || typeof node !== 'object') continue;

            push(titleCandidates, node.name || node.headline || node.title || '');
            push(descCandidates, node.description || node.text || '');
            push(priceCandidates, node.price || node.offers?.price || '');
            push(cityCandidates, node.address?.addressLocality || node.addressLocality || '');
            push(roomsCandidates, node.numberOfRooms || node.numberofrooms || '');
            push(areaCandidates, node.floorSize?.value || node.floorSize || node.area || '');

            const image = node.image;
            if (Array.isArray(image)) {
              for (const i of image) {
                if (typeof i === 'string') imagePush(i);
                else if (i && typeof i.url === 'string') imagePush(i.url);
              }
            } else if (typeof image === 'string') {
              imagePush(image);
            } else if (image && typeof image.url === 'string') {
              imagePush(image.url);
            }

            for (const val of Object.values(node)) stack.push(val);
          }
        } catch {
          // ignore invalid json-ld blocks
        }
      }

      push(titleCandidates, document.querySelector('h1')?.textContent || '');
      push(titleCandidates, document.querySelector('meta[property="og:title"]')?.content || '');
      push(descCandidates, document.querySelector('meta[property="og:description"]')?.content || '');
      push(descCandidates, document.querySelector('meta[name="description"]')?.content || '');
      imagePush(document.querySelector('meta[property="og:image"]')?.content || '');

      const fullText = clean(document.body?.innerText || '');
      const pmPriceMatch = fullText.match(/€\s*([0-9\.\,]+)\s*p\/?m/i);
      const anyPriceMatch = fullText.match(/€\s*([0-9\.\,]+)/i);
      if (pmPriceMatch) push(priceCandidates, pmPriceMatch[1]);
      else if (anyPriceMatch) push(priceCandidates, anyPriceMatch[1]);

      const roomsMatch = fullText.match(/(\d+)\s*kamer/i);
      if (roomsMatch) push(roomsCandidates, roomsMatch[1]);
      const areaMatch = fullText.match(/(\d+)\s*m2/i) || fullText.match(/(\d+)\s*m²/i);
      if (areaMatch) push(areaCandidates, areaMatch[1]);

      for (const img of document.querySelectorAll('img')) {
        imagePush(img.currentSrc || img.getAttribute('src') || img.getAttribute('data-src') || '');
        imagePush(img.getAttribute('data-original') || '');
      }
      for (const source of document.querySelectorAll('source[srcset]')) {
        for (const src of parseSrcset(source.getAttribute('srcset') || '')) imagePush(src);
      }

      return {
        titleCandidates,
        descCandidates,
        priceCandidates,
        imageCandidates,
        cityCandidates,
        roomsCandidates,
        areaCandidates,
      };
    });
  } catch {
    return {
      titleCandidates: [],
      descCandidates: [],
      priceCandidates: [],
      imageCandidates: [],
      cityCandidates: [],
      roomsCandidates: [],
      areaCandidates: [],
    };
  } finally {
    await page.close();
  }
}

async function scrapeCards() {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
      viewport: { width: 1600, height: 1200 },
    });
    const page = await context.newPage();

    await page.goto(SOURCE_URL, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(2000);

    let listingItems = await waitForListingItems(page);
    if (!listingItems.length) {
      const jinaIndex = await fetchViaJina(SOURCE_URL);
      if (jinaIndex) {
        listingItems = parseJinaListingItems(jinaIndex);
      }
    }
    if (!listingItems.length) {
      // Fallback: keep existing listing URLs so feed does not go empty on temporary anti-bot challenge.
      listingItems = await readExistingFeedCards();
    }
    const deduped = [];
    const seen = new Set();
    for (const item of listingItems) {
      const url = normalizeUrl(item.url);
      if (!url || !isDetailPath(url) || seen.has(url)) continue;
      seen.add(url);
      deduped.push({
        title: cleanText(item.title),
        url,
        price: normalizePrice(item.price),
        image: normalizeUrl(item.image),
      });
      if (deduped.length >= LIMIT) break;
    }

    const cards = [];
    for (const item of deduped) {
      const detail = await collectDetailData(context, item.url);
      let jinaDetail = null;
      if (
        !detail.imageCandidates?.length ||
        !detail.descCandidates?.length ||
        !detail.titleCandidates?.length
      ) {
        const jinaText = await fetchViaJina(item.url);
        if (jinaText) {
          jinaDetail = parseJinaDetail(jinaText, item.url);
        }
      }

      const title = pickBestTitle(
        [
          ...(jinaDetail?.title ? [jinaDetail.title] : []),
          ...(detail.titleCandidates || []),
          item.title,
        ],
        item.title || item.url
      );
      const price = normalizePrice(
        (jinaDetail?.price || '') || detail.priceCandidates?.[0] || item.price
      );
      const description = pickBestDescription([
        ...(jinaDetail?.description ? [jinaDetail.description] : []),
        ...(detail.descCandidates || []),
      ]);
      const images = pickImages(
        [item.image, ...(detail.imageCandidates || []), ...(jinaDetail?.images || [])],
        20
      );
      const image = images[0] || '';
      const city = pickCity([...(jinaDetail?.city ? [jinaDetail.city] : []), ...(detail.cityCandidates || [])]);
      const rooms = normalizeInt((jinaDetail?.rooms || '') || detail.roomsCandidates?.[0] || '');
      const area = normalizeInt((jinaDetail?.area || '') || detail.areaCandidates?.[0] || '');

      cards.push({
        external_id: stableId(item.url),
        title,
        url: item.url,
        price,
        image,
        images,
        description,
        city,
        rooms,
        area,
      });
    }

    return cards.filter((c) => c.url && c.title).slice(0, LIMIT);
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
