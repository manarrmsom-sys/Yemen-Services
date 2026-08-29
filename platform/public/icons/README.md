# App icons

`icon.svg` is the master (Yemen tricolor + a wrench mark).

Generate the raster sizes the manifest expects before deploying:

```bash
# requires: npm i -g sharp-cli   (or use any exporter / Figma)
npx sharp-cli -i icon.svg -o icon-192.png resize 192 192
npx sharp-cli -i icon.svg -o icon-512.png resize 512 512
npx sharp-cli -i icon.svg -o icon-maskable-512.png resize 512 512
```

Also add `public/favicon.ico` (32x32) — most build pipelines (e.g. `real-favicon`) can emit
all of these from the single SVG.
