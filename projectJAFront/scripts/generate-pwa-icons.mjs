import sharp from 'sharp'
import { mkdir } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const src = path.join(root, 'public', 'logo-source.png')
const outDir = path.join(root, 'public')
const brandDir = path.join(root, 'src', 'assets', 'brand')
const navy = { r: 10, g: 27, b: 61, alpha: 1 }

await mkdir(outDir, { recursive: true })
await mkdir(brandDir, { recursive: true })

async function square(size, dest, pad = 0) {
  const inner = Math.max(1, Math.round(size * (1 - pad * 2)))
  const resized = await sharp(src)
    .resize(inner, inner, { fit: 'contain', background: navy })
    .png()
    .toBuffer()

  await sharp({
    create: { width: size, height: size, channels: 4, background: navy },
  })
    .composite([{ input: resized, gravity: 'center' }])
    .png()
    .toFile(path.isAbsolute(dest) ? dest : path.join(outDir, dest))
}

await square(16, 'favicon-16x16.png')
await square(32, 'favicon-32x32.png')
await square(48, 'favicon-48x48.png')
await square(48, 'favicon.png')
await square(64, 'pwa-64x64.png')
await square(180, 'apple-touch-icon.png')
await square(192, 'pwa-192x192.png')
await square(512, 'pwa-512x512.png')
await square(512, 'pwa-512x512-maskable.png', 0.12)
await square(512, 'logo.png')
await square(512, 'logo-512.png')
await square(256, 'logo-icon.png')
await square(512, path.join(brandDir, 'app-icon.png'))
await square(192, path.join(brandDir, 'app-icon-192.png'))

await sharp(path.join(outDir, 'logo.png')).webp({ quality: 90 }).toFile(path.join(outDir, 'logo.webp'))
await sharp(path.join(outDir, 'logo-icon.png')).webp({ quality: 90 }).toFile(path.join(outDir, 'logo-icon.webp'))

console.log('PWA icons generated in public/')
