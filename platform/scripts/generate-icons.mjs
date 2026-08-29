/**
 * Generates the PWA raster icons + favicon from public/icons/icon.svg.
 *
 *   npm run generate:icons
 *
 * Outputs (into public/):
 *   icons/icon-192.png            icons/icon-512.png
 *   icons/icon-maskable-512.png   icons/apple-touch-icon.png
 *   favicon-16.png  favicon-32.png  favicon-48.png  favicon.ico
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import pngToIco from 'png-to-ico';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const svgPath = resolve(root, 'public/icons/icon.svg');
const svg = readFileSync(svgPath);

const CRIMSON = '#CE1126';
const OBSIDIAN = '#0F172A';

mkdirSync(resolve(root, 'public/icons'), { recursive: true });

async function render(size, { padding = 0, background = null } = {}) {
    const inner = Math.round(size * (1 - padding * 2));
    let img = sharp(svg, { density: 384 }).resize(inner, inner, { fit: 'contain', background: '#00000000' });
    if (padding > 0 || background) {
        const pad = Math.round((size - inner) / 2);
        img = img.extend({ top: pad, bottom: pad, left: pad, right: pad, background: background || '#00000000' });
    }
    return img.png().toBuffer();
}

async function write(name, buf) {
    writeFileSync(resolve(root, 'public', name), buf);
    console.log('  ✓', name);
}

console.log('Generating icons from', svgPath);

await write('icons/icon-192.png', await render(192));
await write('icons/icon-512.png', await render(512));
// Maskable: 12.5% safe-zone padding on an opaque crimson field.
await write('icons/icon-maskable-512.png', await render(512, { padding: 0.14, background: CRIMSON }));
await write('icons/apple-touch-icon.png', await render(180, { padding: 0.08, background: OBSIDIAN }));

const fav16 = await render(16);
const fav32 = await render(32);
const fav48 = await render(48);
await write('favicon-16.png', fav16);
await write('favicon-32.png', fav32);
await write('favicon-48.png', fav48);
await write('favicon.ico', await pngToIco([fav16, fav32, fav48]));

console.log('Done.');
