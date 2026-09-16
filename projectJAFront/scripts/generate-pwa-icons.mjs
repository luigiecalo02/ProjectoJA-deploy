import sharp from 'sharp'
import { mkdir } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const src = path.join(root, 'public', 'logo-source.png')
const outDir = path.join(root, 'public')
const brandDir = path.join(root, 'src', 'assets', 'brand')
const navy = { r: 10, g: 27, b: 61, alpha: 1 }
const clear = { r: 0, g: 0, b: 0, alpha: 0 }

await mkdir(outDir, { recursive: true })
await mkdir(brandDir, { recursive: true })

function circleMask(size) {
  const r = size / 2
  return Buffer.from(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}">
      <circle cx="${r}" cy="${r}" r="${r}" fill="white"/>
    </svg>`,
  )
}

async function circularPng(size) {
  const resized = await sharp(src)
    .ensureAlpha()
    .resize(size, size, { fit: 'contain', background: clear })
    .png()
    .toBuffer()

  return sharp(resized)
    .composite([{ input: circleMask(size), blend: 'dest-in' }])
    .png({ compressionLevel: 9 })
}

async function writeTransparent(size, dest) {
  await (await circularPng(size)).toFile(path.isAbsolute(dest) ? dest : path.join(outDir, dest))
}

async function writeSolid(size, dest, pad = 0) {
  const inner = Math.max(1, Math.round(size * (1 - pad * 2)))
  const badge = await (await circularPng(inner)).toBuffer()
  await sharp({
    create: { width: size, height: size, channels: 4, background: navy },
  })
    .composite([{ input: badge, gravity: 'center' }])
    .png({ compressionLevel: 9 })
    .toFile(path.isAbsolute(dest) ? dest : path.join(outDir, dest))
}

await writeTransparent(16, 'favicon-16x16.png')
await writeTransparent(32, 'favicon-32x32.png')
await writeTransparent(48, 'favicon-48x48.png')
await writeTransparent(48, 'favicon.png')
await writeTransparent(64, 'pwa-64x64.png')
await writeTransparent(192, 'pwa-192x192.png')
await writeTransparent(512, 'pwa-512x512.png')
await writeTransparent(512, 'logo.png')
await writeTransparent(512, 'logo-512.png')
await writeTransparent(256, 'logo-icon.png')
await writeTransparent(512, path.join(brandDir, 'app-icon.png'))
await writeTransparent(192, path.join(brandDir, 'app-icon-192.png'))

await writeSolid(180, 'apple-touch-icon.png')
await writeSolid(512, 'pwa-512x512-maskable.png', 0.12)

await sharp(path.join(outDir, 'logo.png')).webp({ quality: 90 }).toFile(path.join(outDir, 'logo.webp'))
await sharp(path.join(outDir, 'logo-icon.png')).webp({ quality: 90 }).toFile(path.join(outDir, 'logo-icon.webp'))

console.log('PWA icons generated as transparent PNG')
